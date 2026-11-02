<?php
declare(strict_types=1);

namespace Corder\Controllers\Admin;

use Corder\Database;
use Corder\Dates;
use Corder\HtmlSanitizer;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;
use Corder\Slug;
use Corder\Validator;

/**
 * Gestion des actualités et alertes dans l'administration.
 * Statut : brouillon (published_at NULL), programmé (date future) ou publié.
 */
final class ArticleAdminController
{
    private const POLES = ['clinique', 'crp', 'phytolicence', 'recherche', 'formation'];
    private const ALERTS = 'alertes';

    /** GET /admin/articles — tous les articles, brouillons compris, les plus récents d'abord */
    public function index(Request $request): never
    {
        $rows = Database::all(
            'SELECT a.id, a.slug, a.title, a.pole, a.featured, a.alert_level, a.published_at, a.updated_at,
                    a.created_at, c.slug AS category_slug, c.label AS category_label
             FROM articles a JOIN categories c ON c.id = a.category_id
             ORDER BY a.published_at IS NULL DESC, a.published_at DESC, a.id DESC',
        );

        Response::list(array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'category' => ['slug' => $r['category_slug'], 'label' => $r['category_label']],
            'pole' => $r['pole'],
            'featured' => (bool) $r['featured'],
            'alertLevel' => $r['alert_level'],
            'status' => self::status($r['published_at']),
            'publishedAt' => Dates::iso($r['published_at']),
            'updatedAt' => Dates::iso($r['updated_at'] ?? $r['created_at']),
        ], $rows));
    }

    /** GET /admin/articles/{id} — tous les champs modifiables */
    public function show(Request $request, string $id): never
    {
        Response::item(self::toEditable($this->find((int) $id)));
    }

    /** POST /admin/articles */
    public function create(Request $request): never
    {
        $data = $this->validate($request->body, null);
        $data['created_at'] = Dates::now();

        $id = $this->save($data, null);
        Response::json(['data' => self::toEditable($this->find($id))], 201);
    }

    /** PUT /admin/articles/{id} */
    public function update(Request $request, string $id): never
    {
        $article = $this->find((int) $id);
        $data = $this->validate($request->body, (int) $article['id']);
        // La date de mise à jour n'est affichée que pour un article déjà publié
        $data['updated_at'] = $article['published_at'] !== null && $article['published_at'] <= Dates::now()
            ? Dates::now()
            : null;

        $this->save($data, (int) $article['id']);
        Response::item(self::toEditable($this->find((int) $article['id'])));
    }

    /** DELETE /admin/articles/{id} */
    public function delete(Request $request, string $id): never
    {
        if (Database::run('DELETE FROM articles WHERE id = ?', [(int) $id]) === 0) {
            throw HttpException::notFound();
        }
        Response::json(['ok' => true]);
    }

    /** GET /admin/categories */
    public function categories(Request $request): never
    {
        $rows = Database::all('SELECT id, slug, label FROM categories ORDER BY label');
        Response::list(array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $rows));
    }

    /** @return array<string, mixed> colonnes de la table, validées */
    private function validate(array $body, ?int $id): array
    {
        $v = new Validator($body);
        $title = $v->required('title', 255);
        $excerpt = $v->required('excerpt', 500);
        $bodyHtml = HtmlSanitizer::clean(is_string($body['body'] ?? null) ? $body['body'] : '');
        if (trim(strip_tags($bodyHtml)) === '') {
            $v->error('body', 'Le contenu est obligatoire.');
        }
        $pole = $v->in('pole', self::POLES, nullable: true);
        $imageUrl = $v->optional('imageUrl', 500);
        $imageAlt = $v->optional('imageAlt', 255) ?? '';

        $category = Database::one('SELECT id, slug FROM categories WHERE id = ?', [(int) ($body['categoryId'] ?? 0)]);
        if ($category === null) {
            $v->error('categoryId', 'Choisissez une catégorie.');
        }
        $isAlert = ($category['slug'] ?? '') === self::ALERTS;
        $alertLevel = $isAlert ? $v->in('alertLevel', ['urgent', 'info']) : null;

        // Adresse laissée vide : générée depuis le titre, rendue unique (-2, -3…)
        $slug = $v->optional('slug', 160) ?? Slug::unique('articles', Slug::from($title), $id);
        if (!Slug::isValid($slug)) {
            $v->error('slug', 'Lettres minuscules, chiffres et tirets uniquement.');
        } elseif (Database::one('SELECT id FROM articles WHERE slug = ? AND id <> ?', [$slug, $id ?? 0]) !== null) {
            $v->error('slug', 'Cette adresse est déjà utilisée par un autre article.');
        }

        // publishedAt : null = brouillon, sinon date ISO (future = publication programmée)
        $publishedAt = null;
        if (($body['publishedAt'] ?? null) !== null) {
            try {
                $publishedAt = Dates::toDb((string) $body['publishedAt']);
            } catch (\Exception) {
                $v->error('publishedAt', 'Date invalide.');
            }
        }

        $v->validate();

        return [
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $excerpt,
            'body' => $bodyHtml,
            'category_id' => (int) $category['id'],
            'pole' => $isAlert ? ($pole ?? 'crp') : $pole,
            'image_url' => $imageUrl,
            'image_alt' => $imageUrl === null ? '' : $imageAlt,
            'featured' => !$isAlert && ($body['featured'] ?? false) === true ? 1 : 0,
            'alert_level' => $alertLevel,
            'published_at' => $publishedAt,
        ];
    }

    private function save(array $data, ?int $id): int
    {
        $db = Database::pdo();
        $db->beginTransaction();
        try {
            // Un seul article mis en avant à la fois sur l'accueil
            if ($data['featured'] === 1) {
                Database::run('UPDATE articles SET featured = 0 WHERE id <> ?', [$id ?? 0]);
            }
            $id = Database::save('articles', $data, $id);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $id;
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $row = Database::one(
            'SELECT a.*, c.slug AS category_slug FROM articles a JOIN categories c ON c.id = a.category_id WHERE a.id = ?',
            [$id],
        );
        if ($row === null) {
            throw HttpException::notFound();
        }
        return $row;
    }

    private static function status(?string $publishedAt): string
    {
        if ($publishedAt === null) {
            return 'draft';
        }
        return $publishedAt > Dates::now() ? 'scheduled' : 'published';
    }

    /** @return array<string, mixed> */
    private static function toEditable(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'excerpt' => $row['excerpt'],
            'body' => $row['body'],
            'categoryId' => (int) $row['category_id'],
            'pole' => $row['pole'],
            'imageUrl' => $row['image_url'],
            'imageAlt' => $row['image_alt'],
            'featured' => (bool) $row['featured'],
            'alertLevel' => $row['alert_level'],
            'status' => self::status($row['published_at']),
            'publishedAt' => Dates::iso($row['published_at']),
            'updatedAt' => Dates::iso($row['updated_at']),
        ];
    }
}
