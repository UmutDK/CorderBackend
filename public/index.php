<?php
declare(strict_types=1);

// Point d'entrée unique de l'API. Sur cPanel, ce dossier public/ devient public_html/api ;
// le reste du projet (src, vendor, .env) se place hors de public_html, voir README.md.

use Corder\Env;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;

// En local, le projet est le dossier parent. Sur cPanel, il est dans ~/corder-api,
// à côté de public_html (ce fichier est alors dans ~/public_html/api).
$root = is_file(dirname(__DIR__) . '/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__, 2) . '/corder-api';
require $root . '/bootstrap.php';

// Serveur PHP intégré (développement) : laisser passer les fichiers réels
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

// ── CORS : seules les origines listées dans CORS_ORIGINS peuvent appeler l'API ──
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin !== null && in_array($origin, Env::list('CORS_ORIGINS'), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
}
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $router = require BASE_PATH . '/routes.php';
    $router->dispatch(Request::fromGlobals());
} catch (HttpException $e) {
    Response::error($e);
} catch (Throwable $e) {
    error_log((string) $e);
    Response::json(
        Env::isProduction()
            ? ['ok' => false, 'error' => 'server_error']
            : ['ok' => false, 'error' => 'server_error', 'debug' => $e->getMessage()],
        500,
    );
}
