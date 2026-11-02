<?php
declare(strict_types=1);

namespace Corder\Http;

use Corder\Auth;

/**
 * Routeur minimal : get('/articles/{slug}', [Controller::class, 'show']).
 * Les paramètres {nom} sont passés au contrôleur après la requête, dans l'ordre.
 * auth: true → la route exige une session d'administration (401 sinon).
 */
final class Router
{
    /** @var list<array{string, string, callable|array, bool}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler, bool $auth = false): void
    {
        $this->routes[] = ['GET', $pattern, $handler, $auth];
    }

    public function post(string $pattern, callable|array $handler, bool $auth = false): void
    {
        $this->routes[] = ['POST', $pattern, $handler, $auth];
    }

    public function put(string $pattern, callable|array $handler, bool $auth = false): void
    {
        $this->routes[] = ['PUT', $pattern, $handler, $auth];
    }

    public function delete(string $pattern, callable|array $handler, bool $auth = false): void
    {
        $this->routes[] = ['DELETE', $pattern, $handler, $auth];
    }

    public function dispatch(Request $request): never
    {
        $pathFound = false;

        foreach ($this->routes as [$method, $pattern, $handler, $auth]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '([^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $pathFound = true;
            if ($method !== $request->method) {
                continue;
            }
            if ($auth) {
                Auth::require($request);
            }
            $params = array_map('rawurldecode', array_slice($m, 1));
            if (is_array($handler)) {
                $handler = [new $handler[0](), $handler[1]];
            }
            $handler($request, ...$params);
            // Un contrôleur termine toujours par Response::… (exit) ; sécurité si ce n'est pas le cas
            Response::json(['ok' => true]);
        }

        throw $pathFound ? new HttpException(405, 'method_not_allowed') : HttpException::notFound();
    }
}
