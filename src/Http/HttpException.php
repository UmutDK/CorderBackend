<?php
declare(strict_types=1);

namespace Corder\Http;

use RuntimeException;

/**
 * Erreur renvoyée telle quelle au frontend :
 * { "ok": false, "error": "<code>", "fields": { … } } avec le statut HTTP donné.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly array $fields = [],
    ) {
        parent::__construct($error, $status);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found');
    }

    /** @param array<string, string> $fields */
    public static function validation(array $fields): self
    {
        return new self(422, 'validation_failed', $fields);
    }
}
