<?php
declare(strict_types=1);

namespace Corder\Controllers;

use Corder\Database;
use Corder\Dates;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;

/** Actualités et alertes (une alerte = un article de la catégorie « alertes »). */
final class ArticleController
{
    private const ALERTS = 'alertes';

    private const SELECT = '
        SELECT a.*, c.slug AS category_slug, c.label AS category_label
        FROM articles a
        JOIN categories c ON c.id = a.category_id
        WHERE a.published_at IS NOT NULL AND a.published_at <= :now';

    /** GET /articles?limit=&category= — hors alertes, les plus récents d'abord */
    public function index(Request $request): never
    {
        $where = ' AND c.slug <> :alerts';
        $params = ['now' => Dates::now(), 'alerts' => self::ALERTS];

        if (($request->query['category'] ?? '') !== '') {
            $where .= ' AND c.slug = :category';
            $params['category'] = $request->query['category'];
        }

        [$rows, $total] = $this->paged(self::SELECT . $where, $params, $request->limit());
        Response::list(array_map([self::class, 'toArticle'], $rows), $total);
    }

    /** GET /articles/{slug} — répond aussi pour une alerte */
    public function show(Request $request, string $slug): never
    {
        $row = Database::one(self::SELECT . ' AND a.slug = :slug', ['now' => Dates::now(), 'slug' => $slug]);
        if ($row === null) {
            throw HttpException::notFound();
        }

        Response::item(self::toArticle($row) + [
            'body' => $row['body'],
            'updatedAt' => Dates::iso($row['updated_at']),
        ]);
    }

    /** GET /alerts?limit= */
    public function alerts(Request $request): never
    {
        [$rows, $total] = $this->paged(
            self::SELECT . ' AND c.slug = :alerts',
            ['now' => Dates::now(), 'alerts' => self::ALERTS],
            $request->limit(),
        );

        Response::list(array_map(static fn (array $row) => [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'publishedAt' => Dates::iso($row['published_at']),
            'level' => $row['alert_level'] ?? 'info',
        ], $rows), $total);
    }

    /** @return array{list<array<string, mixed>>, int} lignes (limitées) et total avant limite */
    private function paged(string $sql, array $params, ?int $limit): array
    {
        $total = (int) Database::one("SELECT COUNT(*) AS n FROM ({$sql}) AS t", $params)['n'];
        $sql .= ' ORDER BY a.published_at DESC, a.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        return [Database::all($sql, $params), $total];
    }

    /** @return array<string, mixed> */
    private static function toArticle(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'excerpt' => $row['excerpt'],
            'publishedAt' => Dates::iso($row['published_at']),
            'category' => ['slug' => $row['category_slug'], 'label' => $row['category_label']],
            'pole' => $row['pole'],
            'image' => $row['image_url'] === null ? null : ['url' => $row['image_url'], 'alt' => $row['image_alt']],
            'featured' => (bool) $row['featured'],
        ];
    }
}
