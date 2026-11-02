<?php
declare(strict_types=1);

namespace Corder;

use Corder\Http\HttpException;

/**
 * Anti-spam des formulaires : nombre d'envois par adresse IP et par heure.
 * L'IP est stockée hachée (RGPD) et les entrées de plus d'un jour sont effacées.
 */
final class RateLimiter
{
    public static function hit(string $action, string $ip, ?int $max = null): void
    {
        $max ??= Env::int('RATE_LIMIT_PER_HOUR', 5);
        $hash = hash('sha256', $ip . '|' . Env::get('DB_NAME'));

        Database::run('DELETE FROM rate_limits WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);

        $row = Database::one(
            'SELECT COUNT(*) AS n FROM rate_limits WHERE action = ? AND ip_hash = ? AND created_at >= ?',
            [$action, $hash, date('Y-m-d H:i:s', time() - 3600)],
        );
        if ((int) $row['n'] >= $max) {
            throw new HttpException(429, 'too_many_requests');
        }

        Database::run(
            'INSERT INTO rate_limits (action, ip_hash, created_at) VALUES (?, ?, ?)',
            [$action, $hash, Dates::now()],
        );
    }
}
