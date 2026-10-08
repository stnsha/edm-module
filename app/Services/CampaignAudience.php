<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Database;
use Edm\Core\ValidationException;
use Edm\Models\Segment;

/**
 * Who a newsletter goes to, for BI/CRM audience validation (spec 5.2 step 4):
 * its included / excluded segments and contacts (CampaignRecipients), each
 * segment's conditions, how many subscribed contacts that reaches and how
 * many of those are on the exclusion list.
 */
final class CampaignAudience
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{label: string, include: list<string>, exclude: list<string>,
     *               conditions: list<string>, recipients: int, suppressed: int, problem: ?string}
     *         include / exclude: "Segment: name" and "Contact: email" lines;
     *         conditions: "name: conditions" per segment used
     */
    public function summary(array $campaign): array
    {
        $audience = CampaignRecipients::of($campaign);
        $names = CampaignRecipients::names($audience);
        $lines = static fn (array $side): array => [
            ...array_map(static fn (string $n): string => 'Segment: ' . $n, $side['segments']),
            ...array_map(static fn (string $e): string => 'Contact: ' . $e, $side['contacts']),
        ];
        $out = [
            'label'      => CampaignRecipients::describe($audience),
            'include'    => CampaignRecipients::includesEveryone($audience) ? ['All subscribed contacts'] : $lines($names['include']),
            'exclude'    => $lines($names['exclude']),
            'conditions' => [],
            'recipients' => 0,
            'suppressed' => 0,
            'problem'    => null,
        ];
        try {
            $definitions = CampaignRecipients::definitions($audience);
        } catch (ValidationException $e) {
            $out['problem'] = $e->getMessage();
            return $out;
        }
        foreach ($definitions as $id => $definition) {
            $out['conditions'][] = (string) (Segment::find($id)['name'] ?? '#' . $id) . ': ' . SegmentQuery::describe($definition);
        }

        $recipients = new CampaignRecipients($this->db);
        $out['recipients'] = $recipients->count($audience)['matched'];
        $out['suppressed'] = $recipients->suppressedCount($audience);

        return $out;
    }
}
