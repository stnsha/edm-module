<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\ValidationException;
use Edm\Models\Autoresponder;
use Edm\Models\Trigger;
use Edm\Models\Workflow;

/**
 * Automation (automation/). Actions:
 *   workflows_(list|create|update|delete)
 *     content: "campaign:<id>" | "template:<id>" | "" - the email the
 *     workflow sends (Workflow::contentInput); list rows add content /
 *     content_label (Workflow::withContent)
 *   autoresponders_(list|create|update|delete)
 *     trigger_type event|schedule + flat timing fields (Workflow::timingInput):
 *     event = trigger + send_mode now|delay, delay_value, delay_unit, send_time;
 *     schedule = repeat once|daily|weekly|monthly, schedule_date / _time /
 *     _weekday / _day. List rows add when_label.
 *     trigger "__new" + new_trigger (a name): "+ Create new trigger" - the
 *     custom trigger is created with the workflow (an existing name is reused)
 */
final class AutomationController extends Controller
{
    private const STATUS_RULE = ['sometimes', 'integer', 'in:1,2,3'];

    /** Trigger select value for "+ Create new trigger" (automation/index.php). */
    private const NEW_TRIGGER = '__new';

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
        // When to send: trigger_type + timing from the form's flat fields.
        if ($this->request->has('trigger_type')) {
            $payload += Workflow::timingInput($this->request->input);
            if ($payload['trigger_type'] === 'schedule') {
                $payload['trigger'] = null;
            }
        }
        if ($this->request->has('definition') && is_array($this->request->get('definition'))) {
            $payload['definition'] = $this->request->get('definition');
        }
        if ($verb === 'list') {
            $triggerLabels = Workflow::triggers();
            return array_map(static fn (array $w): array => Workflow::withContent($w, $triggerLabels), Workflow::all());
        }
        $content = $this->request->has('content') ? Workflow::contentInput($this->request->get('content')) : null;

        // "+ Create new trigger": trigger = NEW_TRIGGER plus new_trigger (its
        // name). The trigger is created with the workflow, in one transaction,
        // so a workflow that fails validation leaves no trigger behind.
        return $this->db->transaction(function () use ($verb, $payload, $content): mixed {
            if (($payload['trigger'] ?? null) === self::NEW_TRIGGER) {
                $payload['trigger'] = $this->triggerKey((string) $this->request->get('new_trigger', ''));
            }

            return $this->saveWorkflow($verb, $payload, $content);
        });
    }

    /** @param array<string, mixed> $payload */
    private function saveWorkflow(string $verb, array $payload, ?array $content): mixed
    {
        $triggers = 'in:' . implode(',', array_keys(Workflow::triggers()));
        $rules = [
            'name'            => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:255'],
            'trigger'         => [($payload['trigger_type'] ?? 'event') === 'schedule' ? 'nullable' : 'required', $triggers],
            'trigger_type'    => ['sometimes', 'in:event,schedule'],
            'timing'          => ['nullable', 'array'],
            'status'          => self::STATUS_RULE,
            'definition'      => ['nullable', 'array'],
            'created_by'      => ['nullable', 'integer'],
            'created_by_name' => ['nullable', 'string', 'max:150'],
        ];
        $update = [
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'trigger'     => [($payload['trigger_type'] ?? 'event') === 'schedule' ? 'nullable' : 'sometimes', $triggers],
            'trigger_type' => ['sometimes', 'in:event,schedule'],
            'timing'      => ['nullable', 'array'],
            'status'      => self::STATUS_RULE,
            'definition'  => ['nullable', 'array'],
        ];

        $rules['content_type'] = $update['content_type'] = ['nullable', 'in:' . implode(',', Workflow::CONTENT_TYPES)];
        $rules['content_id'] = $update['content_id'] = ['nullable', 'integer'];
        $saved = $this->crud($verb, Workflow::class, $payload + ($content ?? []) + ($verb === 'create' ? $this->stamp() : []), $rules, $update);

        return is_array($saved) ? Workflow::withContent($saved) : $saved;
    }

    /**
     * The key for a trigger name typed in the workflow form: an existing
     * trigger with that name (any case) is reused, otherwise a custom
     * trigger is created (key from the name, Trigger::uniqueKey).
     *
     * @throws ValidationException on new_trigger when the name is empty / too long
     */
    private function triggerKey(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            throw ValidationException::single('new_trigger', 'Enter a name for the new trigger.');
        }
        if (mb_strlen($label) > 100) {
            throw ValidationException::single('new_trigger', 'The trigger name must not be longer than 100 characters.');
        }
        foreach (Workflow::triggers() as $key => $existing) {
            if (mb_strtolower($existing) === mb_strtolower($label)) {
                return (string) $key;
            }
        }

        return (string) Trigger::create(['key' => Trigger::uniqueKey($label), 'label' => $label] + $this->stamp())['key'];
    }

    private function autoresponders(string $verb): mixed
    {
        $payload = $this->request->only(['name', 'subject'])
            + $this->request->ids(['status', 'workflow_id', 'offset_days']);
        $rules = [
            'name'        => ['required', 'string', 'max:255'],
            'workflow_id' => ['nullable', 'integer', 'exists:edm_workflows,id'],
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
