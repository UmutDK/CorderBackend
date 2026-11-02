<?php
declare(strict_types=1);

namespace Corder\Http;

/** Réponses JSON aux formats du contrat (docs/api-contract.md du frontend). */
final class Response
{
    /** Liste : { data: [...], meta: { total } } */
    public static function list(array $items, ?int $total = null): never
    {
        self::json(['data' => array_values($items), 'meta' => ['total' => $total ?? count($items)]]);
    }

    /** Élément seul : { data: {...} } */
    public static function item(array $item): never
    {
        self::json(['data' => $item]);
    }

    /** Envoi réussi : 201 { ok: true } */
    public static function created(): never
    {
        self::json(['ok' => true], 201);
    }

    public static function error(HttpException $e): never
    {
        $body = ['ok' => false, 'error' => $e->error];
        if ($e->fields !== []) {
            $body['fields'] = $e->fields;
        }
        self::json($body, $e->status);
    }

    public static function json(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
