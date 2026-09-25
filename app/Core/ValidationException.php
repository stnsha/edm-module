<?php

declare(strict_types=1);

namespace Edm\Core;

/**
 * Field validation failure: { success: false, message, errors: { field: [msg] } }
 * with HTTP 422 - the shape the page scripts already read (firstError()).
 */
final class ValidationException extends HttpException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private array $errors)
    {
        $first = reset($errors);
        $count = array_sum(array_map('count', $errors));
        $message = ($first[0] ?? 'The given data was invalid.')
            . ($count > 1 ? ' (and ' . ($count - 1) . ' more error' . ($count > 2 ? 's' : '') . ')' : '');
        parent::__construct($message, 422);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }
}
