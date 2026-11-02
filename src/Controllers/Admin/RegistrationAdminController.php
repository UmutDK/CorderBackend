<?php
declare(strict_types=1);

namespace Corder\Controllers\Admin;

use Corder\Database;
use Corder\Dates;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;
use Corder\Registrations;
use Corder\Validator;

/** Inscrits aux formations : liste, ajout manuel, annulation, export pour Excel. */
final class RegistrationAdminController
{
    /** GET /admin/registrations?trainingId= — les plus récentes d'abord */
    public function index(Request $request): never
    {
        Response::list(array_map([self::class, 'toRow'], $this->rows($request)));
    }

    /**
     * POST /admin/trainings/{id}/registrations — inscription saisie par l'équipe.
     * La date limite et le masquage ne s'appliquent pas ; la capacité, si.
     * { ..., sendConfirmation: true } envoie l'e-mail de confirmation à la personne.
     */
    public function create(Request $request, string $trainingId): never
    {
        $v = new Validator($request->body);
        $person = Registrations::person($v);
        $v->validate();

        $training = Registrations::create((int) $trainingId, $person, fromSite: false);
        if (($request->body['sendConfirmation'] ?? false) === true) {
            Registrations::sendConfirmation($training, $person);
        }
        Response::created();
    }

    /** DELETE /admin/registrations/{id} — annule l'inscription et libère la place */
    public function delete(Request $request, string $id): never
    {
        if (Database::run('DELETE FROM registrations WHERE id = ?', [(int) $id]) === 0) {
            throw HttpException::notFound();
        }
        Response::json(['ok' => true]);
    }

    /**
     * GET /admin/registrations/export?trainingId= — fichier CSV pour Excel
     * (séparateur « ; » et BOM UTF-8 : Excel en français l'ouvre directement avec les accents).
     */
    public function export(Request $request): never
    {
        $rows = $this->rows($request);
        $training = isset($request->query['trainingId'])
            ? Database::one('SELECT slug FROM trainings WHERE id = ?', [(int) $request->query['trainingId']])
            : null;
        $name = 'inscriptions-' . ($training['slug'] ?? 'toutes') . '-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\u{FEFF}");
        $line = static fn (array $fields) => fputcsv($out, array_map([self::class, 'safeCell'], $fields), ';', '"', '');
        $line(['Formation', 'Date de la formation', 'Prénom', 'Nom', 'E-mail', 'Téléphone',
            'N° de phytolicence', 'Organisation', 'Inscrit le']);
        foreach ($rows as $r) {
            $line([
                $r['training_title'],
                date('d/m/Y H:i', strtotime($r['starts_at'])),
                $r['first_name'],
                $r['last_name'],
                $r['email'],
                $r['phone'],
                $r['licence_number'] ?? '',
                $r['organization'] ?? '',
                date('d/m/Y H:i', strtotime($r['created_at'])),
            ]);
        }
        fclose($out);
        exit;
    }

    /** @return list<array<string, mixed>> */
    private function rows(Request $request): array
    {
        $sql = 'SELECT r.*, t.title AS training_title, t.slug AS training_slug, t.starts_at
                FROM registrations r JOIN trainings t ON t.id = r.training_id';
        $params = [];
        if (($request->query['trainingId'] ?? '') !== '') {
            $sql .= ' WHERE r.training_id = ?';
            $params[] = (int) $request->query['trainingId'];
        }
        return Database::all($sql . ' ORDER BY r.created_at DESC, r.id DESC', $params);
    }

    /**
     * Une cellule commençant par = + - @ serait exécutée comme une formule par Excel
     * (injection de formule) : on la préfixe d'une apostrophe.
     */
    private static function safeCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /** @return array<string, mixed> */
    private static function toRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'firstName' => $r['first_name'],
            'lastName' => $r['last_name'],
            'email' => $r['email'],
            'phone' => $r['phone'],
            'licenceNumber' => $r['licence_number'],
            'organization' => $r['organization'],
            'createdAt' => Dates::iso($r['created_at']),
            'training' => [
                'id' => (int) $r['training_id'],
                'title' => $r['training_title'],
                'slug' => $r['training_slug'],
                'startsAt' => Dates::iso($r['starts_at']),
            ],
        ];
    }
}
