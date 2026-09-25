<?php

declare(strict_types=1);

namespace Edm\Controllers;

use Edm\Core\Controller;
use Edm\Models\Template;

/**
 * Template Library (templates/). Actions: templates_(list|create|update|delete).
 */
final class TemplateController extends Controller
{
    protected function handle(string $action): mixed
    {
        if (!preg_match('/^templates_(list|create|update|delete)$/', $action, $m)) {
            $this->unknown();
        }
        $payload = $this->request->only(['name', 'category', 'thumbnail_url', 'html'])
            + ($m[1] === 'create' ? $this->stamp() : []);
        $rules = [
            'name'            => ['required', 'string', 'max:255'],
            'category'        => ['nullable', 'string', 'max:255'],
            'thumbnail_url'   => ['nullable', 'string', 'max:255'],
            'html'            => ['nullable', 'string'],
            'created_by'      => ['nullable', 'integer'],
            'created_by_name' => ['nullable', 'string', 'max:150'],
        ];

        return $this->crud($m[1], Template::class, $payload, $rules, ['name' => ['sometimes', 'string', 'max:255']] + $rules);
    }
}
