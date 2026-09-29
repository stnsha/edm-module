<?php

declare(strict_types=1);

namespace Edm\Services\Qa;

use DOMDocument;
use DOMElement;
use Edm\Core\Database;
use Edm\Core\ValidationException;
use Edm\Models\Campaign;
use Edm\Models\CampaignContent;
use Edm\Models\CustomField;
use Edm\Services\SegmentQuery;
use Edm\Services\Ses\SesGateway;
use Throwable;

/**
 * Automated QA checklist for a campaign (spec 5.2 step 5, section 11), run
 * on the saved design by QaQueue. Each check returns
 * { key, label, result: pass|warn|fail, summary, details[] }; any fail blocks
 * scheduling, warnings do not.
 *
 *   subject        subject present and at most 60 characters
 *   unsubscribe    {{unsubscribe_url}} in the design (the sender adds a
 *                  footer otherwise, so this is a warning at most)
 *   variables      every {{variable}} is known (MessageRenderer + custom fields)
 *   empty_values   recipients with an empty value for a variable used
 *   links          every link loads (UrlProbe)
 *   images         every image loads and is an image
 *   utm            web links carry utm_source / utm_medium / utm_campaign
 *   mobile         layout hints: widths over 600px, tiny fonts
 *   spam           in-house spam score (rules below), 3+ warn, 5+ fail
 *   deliverability SES configured, sending enabled, quota covers the list
 */
final class QaChecker
{
    public const SUBJECT_MAX = 60;
    private const SPAM_WARN = 3.0;
    private const SPAM_FAIL = 5.0;

    private const BUILT_IN_VARS = [
        'email', 'name', 'first_name', 'firstname', 'member_code', 'membercode',
        'unsubscribe_url', 'unsubscribelink', 'unsubscribe',
    ];
    private const UNSUBSCRIBE_VARS = ['unsubscribe_url', 'unsubscribelink', 'unsubscribe'];

    private const SPAM_PHRASES = [
        'free money', 'act now', 'winner', 'you have won', 'guarantee', '100% free', 'click here',
        'limited time', 'risk-free', 'risk free', 'cash bonus', 'no cost', 'urgent', 'double your',
        'earn money', 'extra income', 'this is not spam', 'dear friend', 'order now',
    ];
    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly'];

    public function __construct(private Database $db)
    {
    }

    /** @return list<array{key: string, label: string, result: string, summary: string, details: list<string>}> */
    public function run(int $campaignId): array
    {
        $campaign = Campaign::findOrFail($campaignId);
        $html = (string) (CampaignContent::forCampaign($campaignId)['html'] ?? '');
        $subject = trim((string) ($campaign['subject'] ?? ''));

        if (trim($html) === '') {
            return [$this->check('design', 'Design', 'fail', 'The campaign has no saved design yet.')];
        }

        [$links, $images, $doc] = $this->parse($html);
        $tokens = $this->tokens($subject . ' ' . $html);

        return [
            $this->subject($subject),
            $this->unsubscribe($tokens),
            $this->variables($tokens),
            $this->emptyValues($campaign, $tokens),
            $this->links($links),
            $this->images($images),
            $this->utm($links),
            $this->mobile($doc),
            $this->spam($subject, $html, $links, $images),
            $this->deliverability($campaign),
        ];
    }

    /** Overall status from the checks: 3 passed, 4 passed with warnings, 5 failed. */
    public static function status(array $checks): int
    {
        $results = array_column($checks, 'result');

        return in_array('fail', $results, true) ? 5 : (in_array('warn', $results, true) ? 4 : 3);
    }

    // ---- checks ----

    private function subject(string $subject): array
    {
        $len = mb_strlen($subject);
        if ($len === 0) {
            return $this->check('subject', 'Subject line length', 'fail', 'The campaign has no subject line.');
        }

        return $len > self::SUBJECT_MAX
            ? $this->check('subject', 'Subject line length', 'fail', $len . ' characters (limit ' . self::SUBJECT_MAX . ').', [$subject])
            : $this->check('subject', 'Subject line length', 'pass', $len . ' characters (limit ' . self::SUBJECT_MAX . ').');
    }

    /** @param list<string> $tokens */
    private function unsubscribe(array $tokens): array
    {
        return array_intersect($tokens, self::UNSUBSCRIBE_VARS) !== []
            ? $this->check('unsubscribe', 'Unsubscribe footer', 'pass', '{{unsubscribe_url}} link found in the design.')
            : $this->check('unsubscribe', 'Unsubscribe footer', 'warn', 'No {{unsubscribe_url}} link in the design - a standard unsubscribe footer will be added when sending.');
    }

    /** @param list<string> $tokens */
    private function variables(array $tokens): array
    {
        if ($tokens === []) {
            return $this->check('variables', 'Dynamic variables', 'pass', 'No personalisation variables used.');
        }
        $known = array_merge(self::BUILT_IN_VARS, array_map(
            static fn (array $f): string => strtolower((string) $f['key']),
            CustomField::where('`is_active` = 1')
        ));
        $unknown = array_values(array_diff($tokens, $known));

        return $unknown !== []
            ? $this->check('variables', 'Dynamic variables', 'fail', count($unknown) . ' unknown variable(s) - they would be sent blank.',
                array_map(static fn (string $t): string => '{{' . $t . '}}', $unknown))
            : $this->check('variables', 'Dynamic variables', 'pass',
                implode(', ', array_map(static fn (string $t): string => '{{' . $t . '}}', $tokens)));
    }

    /**
     * Recipients (list + segment, subscribed) with no value for a variable
     * the campaign uses - spec: "no blank fields sent to customers".
     *
     * @param list<string> $tokens
     */
    private function emptyValues(array $campaign, array $tokens): array
    {
        $label = 'Empty variable values';
        $tokens = array_values(array_diff($tokens, array_merge(['email'], self::UNSUBSCRIBE_VARS)));
        if ($tokens === []) {
            return $this->check('empty_values', $label, 'pass', 'No contact data variables used.');
        }
        if ($campaign['list_id'] === null) {
            return $this->check('empty_values', $label, 'warn', 'No recipient list chosen yet - cannot check the contacts.');
        }
        try {
            $segment = SegmentQuery::forCampaign(
                $campaign['segment_id'] !== null ? (int) $campaign['segment_id'] : null,
                (int) $campaign['list_id']
            );
        } catch (ValidationException $e) {
            return $this->check('empty_values', $label, 'fail', $e->getMessage());
        }
        [$where, $params] = ['m.`deleted_at` IS NULL AND m.`status` = 1 AND m.`list_id` = ?', [(int) $campaign['list_id']]];
        if ($segment !== null) {
            [$segSql, $segParams] = (new SegmentQuery($this->db))->where($segment, 'm');
            $where .= ' AND ' . $segSql;
            $params = [...$params, ...$segParams];
        }
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM `edm_list_members` m WHERE ' . $where, $params);
        if ($total === 0) {
            return $this->check('empty_values', $label, 'warn', 'The campaign currently reaches no subscribed contacts.');
        }

        $details = [];
        foreach ($tokens as $t) {
            $expr = match ($t) {
                'name', 'first_name', 'firstname' => ['m.`name`', []],
                'member_code', 'membercode'       => ['m.`member_code`', []],
                default                           => ['JSON_UNQUOTE(JSON_EXTRACT(m.`fields`, ?))', ['$."' . $t . '"']],
            };
            $empty = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM `edm_list_members` m WHERE ' . $where . ' AND TRIM(COALESCE(' . $expr[0] . ", '')) = ''",
                [...$params, ...$expr[1]]
            );
            if ($empty > 0) {
                $details[] = number_format($empty) . ' of ' . number_format($total) . ' contacts have no {{' . $t . '}}';
            }
        }

        return $details !== []
            ? $this->check('empty_values', $label, 'warn', count($details) . ' variable(s) would be blank for some contacts.', $details)
            : $this->check('empty_values', $label, 'pass', 'Every contact has a value for each variable used.');
    }

    /** @param list<string> $links */
    private function links(array $links): array
    {
        $web = array_values(array_filter($links, static fn (string $u): bool => (bool) preg_match('#^https?://#i', $u)));
        if ($web === []) {
            return $this->check('links', 'Broken links', 'pass', 'No web links in the design.');
        }
        $results = (new UrlProbe())->probe($web);
        $bad = [];
        $local = [];
        foreach ($results as $url => $r) {
            if ($r['local']) {
                $local[] = $url . ' - ' . $r['error'];
            } elseif (!$r['ok']) {
                $bad[] = $url . ' - ' . ($r['error'] ?? 'failed');
            }
        }
        if ($bad !== []) {
            return $this->check('links', 'Broken links', 'fail', count($bad) . ' of ' . count($results) . ' links failed.', array_merge($bad, $local));
        }

        return $local !== []
            ? $this->check('links', 'Broken links', 'warn', count($local) . ' link(s) point to a local address.', $local)
            : $this->check('links', 'Broken links', 'pass', count($results) . ' of ' . count($results) . ' links OK.');
    }

    /** @param list<string> $images */
    private function images(array $images): array
    {
        if ($images === []) {
            return $this->check('images', 'Images load', 'pass', 'No images in the design.');
        }
        $results = (new UrlProbe())->probe($images);
        $bad = [];
        $local = [];
        foreach ($results as $url => $r) {
            if ($r['local']) {
                $local[] = $url . ' - ' . $r['error'];
            } elseif (!$r['ok']) {
                $bad[] = $url . ' - ' . ($r['error'] ?? 'failed');
            } elseif ($r['type'] !== null && !str_starts_with(strtolower($r['type']), 'image/')) {
                $bad[] = $url . ' - not an image (' . $r['type'] . ')';
            }
        }
        if ($bad !== []) {
            return $this->check('images', 'Images load', 'fail', count($bad) . ' of ' . count($results) . ' images failed.', array_merge($bad, $local));
        }

        return $local !== []
            ? $this->check('images', 'Images load', 'warn', count($local) . ' image(s) on a local address - they load here but not for recipients.', $local)
            : $this->check('images', 'Images load', 'pass', count($results) . ' of ' . count($results) . ' images OK.');
    }

    /** @param list<string> $links */
    private function utm(array $links): array
    {
        $web = array_values(array_unique(array_filter($links, static fn (string $u): bool => (bool) preg_match('#^https?://#i', $u))));
        if ($web === []) {
            return $this->check('utm', 'UTM parameters', 'pass', 'No web links to tag.');
        }
        $untagged = array_values(array_filter($web, static fn (string $u): bool => !UtmTagger::isTagged($u)));

        return $untagged !== []
            ? $this->check('utm', 'UTM parameters', 'warn', count($untagged) . ' of ' . count($web) . ' links have no UTM tags.', $untagged)
            : $this->check('utm', 'UTM parameters', 'pass', 'All ' . count($web) . ' links are tagged.');
    }

    private function mobile(DOMDocument $doc): array
    {
        $issues = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            $style = strtolower($el->getAttribute('style'));
            $width = $el->getAttribute('width');
            $px = null;
            if (preg_match('/(?:^|;)\s*(?:min-)?width\s*:\s*(\d+)px/', $style, $m)) {
                $px = (int) $m[1];
            } elseif (ctype_digit($width)) {
                $px = (int) $width;
            }
            $maxWidth = preg_match('/max-width\s*:/', $style) === 1;
            if ($px !== null && $px > 600 && !$maxWidth) {
                $issues[] = '<' . $el->tagName . '> is ' . $px . 'px wide (more than 600px)';
            }
            if (preg_match('/font-size\s*:\s*(\d+(?:\.\d+)?)px/', $style, $m) && (float) $m[1] < 12) {
                $issues[] = 'Text at ' . $m[1] . 'px is hard to read on a phone';
            }
        }
        $issues = array_values(array_unique($issues));

        return $issues !== []
            ? $this->check('mobile', 'Mobile layout', 'warn', count($issues) . ' layout hint(s) - check the mobile preview in the Email creator.', array_slice($issues, 0, 10))
            : $this->check('mobile', 'Mobile layout', 'pass', 'No fixed widths over 600px or tiny text found.');
    }

    /**
     * In-house spam score: points per rule, 3+ warns, 5+ fails.
     *
     * @param list<string> $links
     * @param list<string> $images
     */
    private function spam(string $subject, string $html, array $links, array $images): array
    {
        $score = 0.0;
        $hits = [];
        $add = static function (float $points, string $why) use (&$score, &$hits): void {
            $score += $points;
            $hits[] = '+' . rtrim(rtrim(number_format($points, 1), '0'), '.') . ' ' . $why;
        };

        $words = preg_split('/\s+/', trim($subject)) ?: [];
        $caps = array_filter($words, static fn (string $w): bool => mb_strlen($w) > 2 && preg_match('/\p{L}/u', $w) && mb_strtoupper($w) === $w);
        if ($words !== [] && count($caps) / max(1, count($words)) > 0.5) {
            $add(2.0, 'subject mostly in CAPITALS');
        }
        if (preg_match('/!{2,}|\${2,}|\?{3,}/', $subject)) {
            $add(1.5, 'repeated !, $ or ? in the subject');
        }

        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
        $body = mb_strtolower($subject) . ' ' . $text;
        $found = array_values(array_filter(self::SPAM_PHRASES, static fn (string $p): bool => str_contains($body, $p)));
        if ($found !== []) {
            $add(min(3.0, 0.5 * count($found)), 'spam-like phrases: "' . implode('", "', $found) . '"');
        }

        $textLen = mb_strlen(preg_replace('/\{\{[^}]*\}\}/', '', $text) ?? '');
        if ($images !== [] && $textLen < 300) {
            $add(2.0, 'mostly images with little text (' . $textLen . ' characters)');
        }
        $short = array_values(array_filter($links, static function (string $u): bool {
            $host = strtolower((string) parse_url($u, PHP_URL_HOST));
            return in_array($host, self::SHORTENERS, true);
        }));
        if ($short !== []) {
            $add(min(3.0, 1.5 * count($short)), count($short) . ' link shortener URL(s)');
        }
        if (count($links) > 30) {
            $add(1.0, count($links) . ' links (more than 30)');
        }

        $summary = 'Score ' . number_format($score, 1) . ' (warn at ' . number_format(self::SPAM_WARN, 1) . ', fail at ' . number_format(self::SPAM_FAIL, 1) . ').';
        $result = $score >= self::SPAM_FAIL ? 'fail' : ($score >= self::SPAM_WARN ? 'warn' : 'pass');

        return $this->check('spam', 'Spam score', $result, $summary, $hits);
    }

    private function deliverability(array $campaign): array
    {
        $label = 'Deliverability';
        try {
            $ses = SesGateway::fromEnv();
            $missing = $ses->config->missingForSending();
            if ($missing !== []) {
                return $this->check('deliverability', $label, 'warn', 'Sending is not configured: set ' . implode(' and ', $missing) . ' in edm/.env.');
            }
            $a = $ses->account();
        } catch (Throwable $e) {
            return $this->check('deliverability', $label, 'warn', 'Could not reach Amazon SES to check the quota: ' . mb_substr($e->getMessage(), 0, 150));
        }
        if (!$a['sending_enabled']) {
            return $this->check('deliverability', $label, 'fail', 'Sending is disabled on the SES account.');
        }
        $left = (int) max(0, $a['max_24h'] - $a['sent_24h']);
        $summary = 'SES quota OK: ' . number_format($left) . ' of ' . number_format((int) $a['max_24h']) . ' left in the last 24 hours'
            . ($a['production_access'] ? '.' : ' (sandbox - only verified recipients).');
        $recipients = $campaign['list_id'] !== null
            ? (int) $this->db->scalar('SELECT COUNT(*) FROM `edm_list_members` WHERE `list_id` = ? AND `status` = 1 AND `deleted_at` IS NULL', [(int) $campaign['list_id']])
            : 0;

        return $recipients > $left
            ? $this->check('deliverability', $label, 'warn', 'The list (' . number_format($recipients) . ') is larger than today\'s remaining quota (' . number_format($left) . ') - sending will spread over more than one day.')
            : $this->check('deliverability', $label, 'pass', $summary);
    }

    // ---- helpers ----

    /** @return array{0: list<string>, 1: list<string>, 2: DOMDocument} links, images, parsed document */
    private function parse(string $html): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $links = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|tel):#i', $href) || str_contains($href, '{{')) {
                continue;
            }
            $links[] = $href;
        }
        $images = [];
        foreach ($doc->getElementsByTagName('img') as $img) {
            $src = trim($img->getAttribute('src'));
            if ($src !== '' && !str_starts_with($src, 'data:') && !str_contains($src, '{{')) {
                $images[] = $src;
            }
        }

        return [array_values(array_unique($links)), array_values(array_unique($images)), $doc];
    }

    /** @return list<string> distinct lower-cased {{variable}} names */
    private function tokens(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', $text, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** @param list<string> $details */
    private function check(string $key, string $label, string $result, string $summary, array $details = []): array
    {
        return ['key' => $key, 'label' => $label, 'result' => $result, 'summary' => $summary, 'details' => array_values($details)];
    }
}
