<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\ValidationException;
use Edm\Models\ContactList;
use Edm\Models\CustomField;
use Edm\Models\Segment;
use Edm\Models\Tag;

/**
 * Contacts (audience/): Lists, Segments, Tags, Custom fields.
 * Actions: (lists|segments|tags|fields)_(list|create|update|delete).
 */
final class AudienceController extends Controller
{
    protected function handle(string $action): mixed
    {
        if (!preg_match('/^(lists|segments|tags|fields)_(list|create|update|delete)$/', $action, $m)) {
            $this->unknown();
        }

        return match ($m[1]) {
            'lists' => $this->lists($m[2]),
            'segments' => $this->segments($m[2]),
            'tags' => $this->tags($m[2]),
            'fields' => $this->fields($m[2]),
        };
    }

    private function lists(string $verb): mixed
    {
        $payload = $this->request->only(['name', 'description']);
        if ($this->request->has('is_active')) {
            $payload['is_active'] = !empty($this->request->get('is_active'));
        }
        $rules = [
            'name'            => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:255'],
            'is_active'       => ['sometimes', 'boolean'],
            'created_by'      => ['nullable', 'integer'],
            'created_by_name' => ['nullable', 'string', 'max:150'],
        ];

        switch ($verb) {
            case 'list':
                return ContactList::allWithMemberCount();
            case 'create':
                $row = ContactList::create($this->validator->validate($payload + $this->stamp(), $rules));
                return ContactList::withMemberCount((int) $row['id']);
            case 'update':
                $id = $this->requireId();
                ContactList::findOrFail($id);
                ContactList::update($id, $this->validator->validate($payload, [
                    'name'        => ['sometimes', 'string', 'max:255'],
                    'description' => ['nullable', 'string', 'max:255'],
                    'is_active'   => ['sometimes', 'boolean'],
                ], $id));
                return ContactList::withMemberCount($id);
        }

        return $this->crud($verb, ContactList::class, [], []);
    }

    private function segments(string $verb): mixed
    {
        $payload = $this->request->only(['name', 'description']) + $this->request->ids(['list_id']);
        $def = $this->request->get('definition');
        if (is_array($def)) {
            $payload['definition'] = [
                'match' => $def['match'] ?? 'all',
                'rules' => isset($def['rules']) && is_array($def['rules']) ? array_values($def['rules']) : [],
            ];
        }
        if (isset($payload['definition']) && !in_array($payload['definition']['match'], ['all', 'any'], true)) {
            throw ValidationException::single('definition.match', 'The selected definition.match is invalid.');
        }
        $creating = $verb === 'create';
        $req = $creating ? 'required' : 'sometimes';
        $rules = [
            'name'            => [$req, 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:255'],
            'definition'      => [$req, 'array'],
            'list_id'         => ['nullable', 'integer', 'exists:edm_lists,id'],
            'created_by'      => ['nullable', 'integer'],
            'created_by_name' => ['nullable', 'string', 'max:150'],
        ];

        return $this->crud($verb, Segment::class, $payload + ($creating ? $this->stamp() : []), $rules);
    }

    private function tags(string $verb): mixed
    {
        $payload = $this->request->only(['name', 'color', 'description']);
        $rules = [
            'name'        => ['required', 'string', 'max:255', 'unique:edm_tags,name'],
            'color'       => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
        $update = ['name' => ['sometimes', 'string', 'max:255', 'unique:edm_tags,name']] + $rules;

        return $this->crud($verb, Tag::class, $payload, $rules, $update);
    }

    private function fields(string $verb): mixed
    {
        $payload = $this->request->only(['label', 'type']);
        if ($this->request->has('is_active')) {
            $payload['is_active'] = !empty($this->request->get('is_active'));
        }
        if ($this->request->has('options')) {
            $lines = self::lines($this->request->get('options'));
            $payload['options'] = $lines ?: null;
        }
        $types = 'in:' . implode(',', CustomField::TYPES);

        switch ($verb) {
            case 'list':
                // Options go back to the edm-crud textarea as newline text.
                return array_map(static function (array $row): array {
                    if (is_array($row['options'] ?? null)) {
                        $row['options'] = implode("\n", $row['options']);
                    }
                    return $row;
                }, CustomField::all());
            case 'create':
                $data = $this->validator->validate($payload, [
                    'label'     => ['required', 'string', 'max:255'],
                    'type'      => ['required', $types],
                    'options'   => ['nullable', 'array'],
                    'is_active' => ['sometimes', 'boolean'],
                ]);
                self::checkOptions($data['options'] ?? null);
                $data['key'] = CustomField::uniqueKey((string) $data['label']);
                if ($data['type'] !== 'select') {
                    $data['options'] = null;
                }
                return CustomField::create($data);
            case 'update':
                $id = $this->requireId();
                $existing = CustomField::findOrFail($id);
                $data = $this->validator->validate($payload, [
                    'label'     => ['sometimes', 'string', 'max:255'],
                    'type'      => ['sometimes', $types],
                    'options'   => ['nullable', 'array'],
                    'is_active' => ['sometimes', 'boolean'],
                ], $id);
                self::checkOptions($data['options'] ?? null);
                // A toggle-only update (Set active / inactive) leaves options alone.
                if (array_key_exists('type', $data) || array_key_exists('options', $data)) {
                    if (($data['type'] ?? $existing['type']) !== 'select') {
                        $data['options'] = null;
                    }
                }
                return CustomField::update($id, $data);
        }

        return $this->crud($verb, CustomField::class, [], []);
    }

    /** @return list<string> non-empty trimmed lines from a textarea string or array */
    private static function lines(mixed $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string) $value);

        return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $parts), 'strlen'));
    }

    private static function checkOptions(?array $options): void
    {
        foreach ($options ?? [] as $opt) {
            if (mb_strlen((string) $opt) > 255) {
                throw ValidationException::single('options', 'Each option must not be greater than 255 characters.');
            }
        }
    }
}
