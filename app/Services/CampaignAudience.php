<?php

declare(strict_types=1);

namespace Edm\Services;

use Edm\Core\Database;
use Edm\Core\ValidationException;
use Edm\Models\Campaign;
use Edm\Models\ContactList;
use Edm\Models\Segment;

/**
 * Who a campaign goes to, for BI/CRM audience validation (spec 5.2 step 4):
 * its list (or all lists), segment and conditions, how many subscribed
 * contacts match and how many of those are suppressed.
 */
final class CampaignAudience
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{list: string, segment: ?string, conditions: ?string, recipients: int,
     *               suppressed: int, problem: ?string}
     */
    public function summary(array $campaign): array
    {
        $out = ['list' => 'None chosen', 'segment' => null, 'conditions' => null, 'recipients' => 0, 'suppressed' => 0, 'problem' => null];
        if (!Campaign::hasAudience($campaign)) {
            $out['problem'] = 'No recipient list chosen.';
            return $out;
        }
        $listId = Campaign::audienceListId($campaign);
        $out['list'] = $listId === null ? Campaign::ALL_LISTS_LABEL : (string) (ContactList::find($listId)['name'] ?? 'Deleted list');

        $segmentId = $campaign['segment_id'] !== null ? (int) $campaign['segment_id'] : null;
        try {
            $definition = SegmentQuery::forCampaign($segmentId, $listId);
        } catch (ValidationException $e) {
            $out['problem'] = $e->getMessage();
            return $out;
        }
        if ($segmentId !== null) {
            $segment = Segment::find($segmentId);
            $out['segment'] = (string) ($segment['name'] ?? '');
            $out['conditions'] = $definition !== null ? SegmentQuery::describe($definition) : null;
        }

        $query = new SegmentQuery($this->db);
        $out['recipients'] = $query->count($definition, $listId, 0)['matched'];
        $out['suppressed'] = $query->suppressedCount($definition, $listId);

        return $out;
    }
}
