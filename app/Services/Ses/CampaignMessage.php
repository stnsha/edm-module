<?php

declare(strict_types=1);

namespace Edm\Services\Ses;

use Edm\Models\Campaign;
use Edm\Services\Qa\UtmTagger;

/**
 * The newsletter-wide part of an outgoing email, built once per run from the
 * saved design and the wizard's settings; MessageRenderer then fills each
 * recipient's {{variables}}. Used by the send queue (CampaignSender), Send
 * test and the review page preview, so all three show the same email.
 *
 *   preview text  (preheader) a hidden first line inbox lists show next to
 *                 the subject
 *   UTM           utm_source / medium / campaign / content on every web link
 *                 (utm_enabled + Campaign::utm()) - Google Analytics and
 *                 site-visit tracking
 *   clicks        track_clicks off: ses:no-track on every link, so SES does
 *                 not rewrite them
 *   opens         track_opens off: the SES_CONFIGURATION_SET_NO_OPENS set
 *                 (no tracking pixel); without that set opens stay tracked
 */
final class CampaignMessage
{
    /** Invisible padding after the preview text, so inboxes do not show body text after it. */
    private const PREVIEW_PAD = 90;

    /** The design with preview text, UTM tags and click tracking applied. */
    public static function html(array $campaign, string $html): string
    {
        if (!empty($campaign['utm_enabled'])) {
            $html = (new UtmTagger((string) $campaign['name'], Campaign::utm($campaign)))->html($html);
        }
        if (array_key_exists('track_clicks', $campaign) && !$campaign['track_clicks']) {
            $html = self::noTrack($html);
        }
        $preview = trim((string) ($campaign['preheader'] ?? ''));

        return $preview !== '' ? self::withPreview($html, $preview) : $html;
    }

    /**
     * The configuration set to send with: null = the default one
     * (SesConfig::configurationSet).
     */
    public static function configurationSet(array $campaign, SesConfig $config): ?string
    {
        if (array_key_exists('track_opens', $campaign) && !$campaign['track_opens'] && $config->configurationSetNoOpens !== null) {
            return $config->configurationSetNoOpens;
        }

        return null;
    }

    /** True when open tracking is off but SES has no set to honour it. */
    public static function opensForcedOn(array $campaign, SesConfig $config): bool
    {
        return array_key_exists('track_opens', $campaign) && !$campaign['track_opens'] && $config->configurationSetNoOpens === null;
    }

    /** Adds ses:no-track to every <a> that does not have it. */
    private static function noTrack(string $html): string
    {
        return (string) preg_replace_callback(
            '/<a\b(?![^>]*\bses:no-track\b)([^>]*)>/i',
            static fn (array $m): string => '<a ses:no-track' . $m[1] . '>',
            $html
        );
    }

    /** Hidden preview text as the first thing inside <body> (or the start). */
    private static function withPreview(string $html, string $preview): string
    {
        $block = '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
            . htmlspecialchars($preview, ENT_QUOTES, 'UTF-8')
            . str_repeat('&#847;&zwnj;&nbsp;', self::PREVIEW_PAD)
            . '</div>';
        if (preg_match('/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1] + strlen($m[0][0]);

            return substr($html, 0, $at) . $block . substr($html, $at);
        }

        return $block . $html;
    }
}
