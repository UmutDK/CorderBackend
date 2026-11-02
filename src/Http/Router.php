<?php
declare(strict_types=1);

namespace Corder\Http;

/**
 * Routeur minimal : get('/articles/{slug}', [Controller::class, 'show']).
 * Les paramètres {nom} sont passés au contrôleur après la requête, dans l'ordre.
 */
final class Router
{
    /** @var list<array{string, string, callable|array}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(Request $request): never
    {
        $pathFound = false;

        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '([^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $pathFound = true;
            if ($method !== $request->method) {
                continue;
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
