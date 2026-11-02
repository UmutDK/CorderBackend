<?php
declare(strict_types=1);

namespace Corder;

/** Identifiants d'URL : « Zones tampons : ce qui change » → « zones-tampons-ce-qui-change » */
final class Slug
{
    public static function from(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        return substr(trim($text, '-'), 0, 150) ?: 'sans-titre';
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 && strlen($slug) <= 160;
    }

    /** Ajoute -2, -3… si le slug est déjà pris dans la table (en ignorant la ligne $exceptId) */
    public static function unique(string $table, string $slug, ?int $exceptId = null): string
    {
        $candidate = $slug;
        for ($i = 2; ; $i++) {
            $taken = Database::one(
                "SELECT id FROM {$table} WHERE slug = ? AND id <> ?",
                [$candidate, $exceptId ?? 0],
            );
            if ($taken === null) {
                return $candidate;
            }
            $candidate = "{$slug}-{$i}";
        }
    }
}
