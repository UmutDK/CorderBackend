<?php
declare(strict_types=1);

namespace Corder;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Nettoie le HTML des articles avant de l'enregistrer : seules les balises du contrat sont gardées
 * (pas de script, pas d'attributs on…, pas de h1). À appeler à chaque écriture d'un « body ».
 */
final class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(string $html): string
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em', 'blockquote',
                'a[href|title]', 'img[src|alt|width|height]',
                'table', 'thead', 'tbody', 'tr', 'th', 'td',
            ]));
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
            $config->set('Output.Newline', "\n");
            $config->set('Cache.SerializerPath', self::cacheDir());
            self::$purifier = new HTMLPurifier($config);
        }
        return self::$purifier->purify($html);
    }

    private static function cacheDir(): string
    {
        $dir = BASE_PATH . '/storage/cache/htmlpurifier';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
