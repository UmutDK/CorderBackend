<?php
declare(strict_types=1);

namespace Corder\Controllers\Admin;

use Corder\Auth;
use Corder\Database;
use Corder\Dates;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;
use Corder\RateLimiter;
use Corder\Validator;

final class AuthController
{
    /** Tentatives de connexion autorisées par adresse IP et par heure */
    private const MAX_ATTEMPTS = 10;

    /** POST /admin/login { email, password } */
    public function login(Request $request): never
    {
        Auth::requireJson($request);

        $v = new Validator($request->body);
        $email = $v->email('email');
        $password = is_string($request->body['password'] ?? null) ? $request->body['password'] : '';
        $v->validate();

        RateLimiter::hit('login', $request->ip, self::MAX_ATTEMPTS);

        $row = Database::one('SELECT * FROM admin_users WHERE email = ?', [$email]);
        // Vérification faite même si le compte n'existe pas : même durée de réponse dans les deux cas
        $hash = $row['password_hash'] ?? '$2y$10$We5wc./JwO3xDxeDuvxI5eY.hU3LWwiOkseHKu4inyFseQBq.Leru';
        if (!password_verify($password, $hash) || $row === null) {
            throw new HttpException(401, 'invalid_credentials');
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Database::run('UPDATE admin_users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        Database::run('UPDATE admin_users SET last_login_at = ? WHERE id = ?', [Dates::now(), $row['id']]);

        $user = ['id' => (int) $row['id'], 'email' => $row['email'], 'name' => $row['name']];
        Auth::login($user);
        Response::item($user);
    }

    /** GET /admin/me — utilisateur connecté, ou 401 */
    public function me(Request $request): never
    {
        $user = Auth::user();
        if ($user === null) {
            throw new HttpException(401, 'unauthenticated');
        }
        Response::item($user);
    }

    /** POST /admin/logout */
    public function logout(Request $request): never
    {
        Auth::logout();
        Response::json(['ok' => true]);
    }
}
