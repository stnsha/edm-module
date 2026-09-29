<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\ValidationException;
use Edm\Models\ContactList;
use Edm\Models\CustomField;
use Edm\Models\ListMember;
use Edm\Models\Segment;
use Edm\Models\Tag;
use Edm\Services\Import\ContactImport;
use Edm\Services\Import\ImportFileReader;
use Edm\Services\SegmentQuery;

/**
 * Contacts (audience/): Lists, Segments, Tags, Custom fields, Import.
 * Actions: (lists|segments|tags|fields)_(list|create|update|delete),
 * segments_fields, segments_count, members_list, members_delete,
 * import_template, import_preview, import_run.
 */
final class AudienceController extends Controller
{
    protected function handle(string $action): mixed
    {
        if ($action === 'segments_fields') {
            return [
                'fields'    => SegmentQuery::fields(),
                'ops'       => SegmentQuery::OPS,
                'op_labels' => SegmentQuery::OP_LABELS,
                'no_value'  => SegmentQuery::NO_VALUE,
            ];
        }
        if ($action === 'segments_count') {
            return $this->segmentCount();
        }
        if ($action === 'members_list' || $action === 'members_delete') {
            return $this->members($action);
        }
        if ($action === 'import_template') {
            $this->importTemplate();
        }
        if ($action === 'import_preview' || $action === 'import_run') {
            return $this->import($action);
        }
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

    /**
     * Segments. definition is validated and stored in normal form by
     * SegmentQuery::normalize(); list_id (optional) limits the segment to
     * one list. The listing adds the list name, a one-line summary and the
     * number of subscribed contacts that match.
     */
    private function segments(string $verb): mixed
    {
        $payload = $this->request->only(['name', 'description']) + $this->request->ids(['list_id']);
        if ($this->request->has('definition')) {
            $payload['definition'] = SegmentQuery::normalize($this->request->get('definition'));
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

        if ($verb === 'list') {
            $lists = array_column(ContactList::all(), 'name', 'id');
            $query = new SegmentQuery($this->db);

            return array_map(static function (array $row) use ($lists, $query): array {
                $listId = $row['list_id'] !== null ? (int) $row['list_id'] : null;
                $row['list_name'] = $listId !== null ? ($lists[$listId] ?? null) : null;
                $row['summary'] = SegmentQuery::describe((array) $row['definition']);
                try {
                    $row['matched'] = $query->count(SegmentQuery::normalize($row['definition']), $listId, 0)['matched'];
                } catch (ValidationException) {
                    $row['matched'] = null;
                }

                return $row;
            }, Segment::all());
        }

        return $this->crud($verb, Segment::class, $payload + ($creating ? $this->stamp() : []), $rules);
    }

    /**
     * segments_count { definition?, segment_id?, list_id? }: subscribed
     * contacts matching a definition being edited, or a saved segment, or
     * (neither) the whole list; plus a few matching contacts.
     *
     * @return array{matched: int, total: int, sample: list<array<string, mixed>>}
     */
    private function segmentCount(): array
    {
        $ids = $this->request->ids(['segment_id', 'list_id']);
        $listId = $ids['list_id'] ?? null;
        $definition = null;
        if (($ids['segment_id'] ?? null) !== null) {
            $segment = Segment::findOrFail((int) $ids['segment_id']);
            $definition = SegmentQuery::normalize($segment['definition']);
        } elseif ($this->request->has('definition')) {
            $definition = SegmentQuery::normalize($this->request->get('definition'));
        }

        return (new SegmentQuery($this->db))->count($definition, $listId);
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

    /**
     * Contacts on one list (audience/contacts.php).
     *   members_list    ?list_id= -> the list's contacts, newest first
     *   members_delete  { id } -> soft-deletes one contact
     */
    private function members(string $action): mixed
    {
        if ($action === 'members_delete') {
            $id = $this->requireId('Contact');
            ListMember::findOrFail($id);
            ListMember::delete($id);

            return null;
        }
        $listId = (int) ($_GET['list_id'] ?? 0);
        ContactList::findOrFail($listId);

        return ListMember::where('`list_id` = ?', [$listId], '`id` DESC');
    }

    /**
     * Contacts > Import contacts (audience/import.php).
     *   import_preview  multipart: list_id, file | paste -> token, preview, mapping
     *   import_run      { token, list_id, mapping[], has_header, mode, offset, limit, dry_run }
     *                   -> one batch: { total, offset, processed, next, sum, issues[] };
     *                   call again with offset = next until next is null.
     *                   mode: add_update (default) | add | update (ContactImport::MODES);
     *                   dry_run: check only, nothing is written.
     */
    private function import(string $action): array
    {
        $import = new ContactImport($this->db, (int) $this->auth->staffId);

        if ($action === 'import_preview') {
            $data = $this->validator->validate($this->request->ids(['list_id']), [
                'list_id' => ['required', 'integer', 'exists:edm_lists,id'],
            ]);
            $rows = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                ? ImportFileReader::fromUpload($_FILES['file'])
                : ImportFileReader::fromPaste((string) $this->request->get('paste', ''));

            return ['list_id' => $data['list_id']] + $import->stage($rows);
        }

        $data = $this->validator->validate(
            $this->request->only(['token', 'mode']) + $this->request->ids(['list_id']) + ['mapping' => $this->request->get('mapping')],
            [
                'token'   => ['required', 'string', 'max:64'],
                'list_id' => ['required', 'integer', 'exists:edm_lists,id'],
                'mapping' => ['required', 'array'],
                'mode'    => ['sometimes', 'string', 'in:' . implode(',', array_keys(ContactImport::MODES))],
            ]
        );

        return $import->run(
            (string) $data['token'],
            (int) $data['list_id'],
            array_map('strval', $data['mapping']),
            !empty($this->request->get('has_header')),
            (string) ($data['mode'] ?? ContactImport::MODE_ADD_UPDATE),
            max(0, (int) $this->request->get('offset', 0)),
            (int) $this->request->get('limit', ContactImport::BATCH_ROWS),
            !empty($this->request->get('dry_run'))
        );
    }

    /**
     * import_template: CSV (UTF-8 with BOM, so Excel keeps accents) with the
     * column headers the import matches automatically and two sample rows.
     */
    private function importTemplate(): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="edm-contacts-import-template.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach (ContactImport::template() as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        fclose($out);
        exit;
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
