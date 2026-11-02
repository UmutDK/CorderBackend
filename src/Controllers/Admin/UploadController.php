<?php
declare(strict_types=1);

namespace Corder\Controllers\Admin;

use Corder\Env;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;

/**
 * Envoi d'images (illustrations d'articles). Fichiers rangés dans public/uploads/AAAA/MM/,
 * soit public_html/api/uploads sur cPanel : ce dossier ne doit pas être écrasé lors d'une mise à jour.
 */
final class UploadController
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** POST /admin/uploads (multipart, champ « file ») → { data: { url } } */
    public function store(Request $request): never
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $tooBig = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw HttpException::validation(['file' => $tooBig ? 'Image trop lourde (5 Mo maximum).' : 'Aucune image reçue.']);
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw HttpException::validation(['file' => 'Image trop lourde (5 Mo maximum).']);
        }

        // Type réel lu dans le contenu du fichier, pas dans son nom
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) {
            throw HttpException::validation(['file' => 'Formats acceptés : JPEG, PNG ou WebP.']);
        }

        $folder = 'uploads/' . date('Y/m');
        $dir = dirname(__DIR__, 3) . '/public/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new \RuntimeException("Impossible de créer {$dir}");
        }
        $name = bin2hex(random_bytes(12)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$name}")) {
            throw new \RuntimeException('Impossible d\'enregistrer le fichier envoyé');
        }

        Response::json(['data' => ['url' => rtrim(Env::get('API_URL'), '/') . "/{$folder}/{$name}"]], 201);
    }
}
