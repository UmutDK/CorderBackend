<?php
declare(strict_types=1);

namespace Corder;

use Corder\Http\HttpException;
use Corder\Http\Request;

/**
 * Session d'administration (cookie de session PHP).
 * - Cookie HttpOnly (illisible en JavaScript), SameSite=Strict, Secure en HTTPS.
 * - Les requêtes qui modifient des données doivent être en JSON : un autre site ne peut pas
 *   en envoyer sans autorisation CORS, ce qui protège contre le CSRF avec SameSite=Strict.
 * - Déconnexion automatique après IDLE_TIMEOUT secondes d'inactivité.
 */
final class Auth
{
    private const IDLE_TIMEOUT = 2 * 3600;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('corder_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    /** @return array{id: int, email: string, name: string}|null */
    public static function user(): ?array
    {
        // Pas de cookie : inutile d'ouvrir une session
        if (!isset($_COOKIE['corder_admin'])) {
            return null;
        }
        self::start();
        $user = $_SESSION['user'] ?? null;
        if ($user === null) {
            return null;
        }
        if (time() - ($_SESSION['last_activity'] ?? 0) > self::IDLE_TIMEOUT) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();
        return $user;
    }

    /** @return array{id: int, email: string, name: string} */
    public static function require(Request $request): array
    {
        $user = self::user();
        if ($user === null) {
            throw new HttpException(401, 'unauthenticated');
        }
        self::requireJson($request);
        return $user;
    }

    /** @param array{id: int, email: string, name: string} $user */
    public static function login(array $user): void
    {
        self::start();
        // Nouvel identifiant de session à la connexion (protection contre la fixation de session)
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }

    /** POST / PUT avec un corps : uniquement du JSON (voir la note CSRF ci-dessus) */
    public static function requireJson(Request $request): void
    {
        if (!in_array($request->method, ['POST', 'PUT'], true)) {
            return;
        }
        $type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_starts_with($type, 'application/json') && !str_starts_with($type, 'multipart/form-data')) {
            throw new HttpException(415, 'unsupported_media_type');
        }
    }
}
