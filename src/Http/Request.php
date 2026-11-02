<?php
declare(strict_types=1);

namespace Corder\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed>  $body
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly string $ip,
        public readonly ?string $origin,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // L'API peut vivre dans un sous-dossier (public_html/api) : on retire ce préfixe
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $body = [];
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new HttpException(400, 'invalid_json');
            }
            $body = $decoded;
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            array_map(static fn ($v) => is_string($v) ? $v : '', $_GET),
            $body,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_ORIGIN'] ?? null,
        );
    }

    /** Paramètre ?limit= borné entre 1 et $max ; null s'il est absent */
    public function limit(int $max = 200): ?int
    {
        if (!isset($this->query['limit']) || $this->query['limit'] === '') {
            return null;
        }
        return max(1, min($max, (int) $this->query['limit']));
    }

    public function flag(string $name): bool
    {
        return in_array($this->query[$name] ?? '', ['1', 'true'], true);
    }
}
