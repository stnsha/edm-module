<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Models\Autoresponder;
use Edm\Models\Workflow;

/**
 * Automation (automation/). Actions:
 *   workflows_(list|create|update|delete)
 *   autoresponders_(list|create|update|delete)
 */
final class AutomationController extends Controller
{
    private const STATUS_RULE = ['sometimes', 'integer', 'in:1,2,3'];

    protected function handle(string $action): mixed
    {
        if (!preg_match('/^(workflows|autoresponders)_(list|create|update|delete)$/', $action, $m)) {
            $this->unknown();
        }

        return $m[1] === 'workflows' ? $this->workflows($m[2]) : $this->autoresponders($m[2]);
    }

    private function workflows(string $verb): mixed
    {
        $payload = $this->request->only(['name', 'description', 'trigger']) + $this->request->ids(['status']);
        if ($this->request->has('definition') && is_array($this->request->get('definition'))) {
            $payload['definition'] = $this->request->get('definition');
        }
        $triggers = 'in:' . implode(',', Workflow::TRIGGERS);
        $rules = [
            'name'            => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:255'],
            'trigger'         => ['required', $triggers],
            'status'          => self::STATUS_RULE,
            'definition'      => ['nullable', 'array'],
            'created_by'      => ['nullable', 'integer'],
            'created_by_name' => ['nullable', 'string', 'max:150'],
        ];
        $update = [
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'trigger'     => ['sometimes', $triggers],
            'status'      => self::STATUS_RULE,
            'definition'  => ['nullable', 'array'],
        ];

        return $this->crud($verb, Workflow::class, $payload + ($verb === 'create' ? $this->stamp() : []), $rules, $update);
    }

    private function autoresponders(string $verb): mixed
    {
        $payload = $this->request->only(['name', 'subject'])
            + $this->request->ids(['status', 'list_id', 'offset_days']);
        $rules = [
            'name'        => ['required', 'string', 'max:255'],
            'list_id'     => ['nullable', 'integer', 'exists:edm_lists,id'],
            'offset_days' => ['required', 'integer', 'min:-365', 'max:365'],
            'subject'     => ['nullable', 'string', 'max:255'],
            'status'      => self::STATUS_RULE,
        ];
        $update = [
            'name'        => ['sometimes', 'string', 'max:255'],
            'offset_days' => ['sometimes', 'integer', 'min:-365', 'max:365'],
        ] + $rules;

        return $this->crud($verb, Autoresponder::class, $payload, $rules, $update);
    }
}
