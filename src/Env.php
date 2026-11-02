<?php
declare(strict_types=1);

namespace Corder;

/** Lecture du fichier .env (format CLE=valeur, # pour les commentaires). */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            // Guillemets facultatifs autour de la valeur
            if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
                $value = $m[2];
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = self::$values[$key] ?? getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === '' ? $default : (int) $value;
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::get($key)))));
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }
}
