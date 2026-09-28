<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Core\HttpException;
use Edm\Models\Template;

/**
 * Template Library (templates/). Actions:
 *   templates_(list|create|update|delete)  list page (name / category / thumbnail)
 *   load   one template (?template=) for the editor page (templates/edit.php)
 *   save   settings + design in one call: name, category, thumbnail_url,
 *          html (rendered email) and editor_json (EmailBuilder.js block tree)
 */
final class TemplateController extends Controller
{
    private const RULES = [
        'name'            => ['required', 'string', 'max:255'],
        'category'        => ['nullable', 'string', 'max:255'],
        'thumbnail_url'   => ['nullable', 'string', 'max:255'],
        'html'            => ['nullable', 'string'],
        'created_by'      => ['nullable', 'integer'],
        'created_by_name' => ['nullable', 'string', 'max:150'],
    ];

    protected function handle(string $action): mixed
    {
        if ($action === 'load' || $action === 'save') {
            $id = (int) ($this->request->query('template') ?? $this->request->get('template', 0));
            if ($id <= 0) {
                throw new HttpException('A template id is required', 422);
            }
            $template = Template::findOrFail($id);
            if ($action === 'load') {
                return $template;
            }

            return Template::update($id, $this->validator->validate($this->designPayload(), [
                'name'          => ['required', 'string', 'max:255'],
                'category'      => ['nullable', 'string', 'max:255'],
                'thumbnail_url' => ['nullable', 'string', 'max:255'],
                'html'          => ['nullable', 'string'],
                'editor_json'   => ['sometimes', 'nullable', 'array'],
            ], $id));
        }

        if (!preg_match('/^templates_(list|create|update|delete)$/', $action, $m)) {
            $this->unknown();
        }
        $payload = $this->request->only(['name', 'category', 'thumbnail_url'])
            + ($m[1] === 'create' ? $this->stamp() : []);

        return $this->crud($m[1], Template::class, $payload, self::RULES, ['name' => ['sometimes', 'string', 'max:255']] + self::RULES);
    }

    /** @return array<string, mixed> */
    private function designPayload(): array
    {
        $payload = $this->request->only(['name', 'category', 'thumbnail_url']);
        // Not trimmed: the rendered HTML is stored exactly as exported.
        $payload['html'] = (string) $this->request->get('html', '');
        $json = $this->request->get('editor_json');
        if (is_array($json)) {
            $payload['editor_json'] = $json;
        }

        return $payload;
    }
}
