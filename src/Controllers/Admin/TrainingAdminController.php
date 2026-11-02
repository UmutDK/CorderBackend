<?php
declare(strict_types=1);

namespace Corder\Controllers\Admin;

use Corder\Database;
use Corder\Dates;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;
use Corder\Slug;
use Corder\Validator;

/** Gestion des formations dans l'administration (sessions passées et masquées comprises). */
final class TrainingAdminController
{
    private const LICENCES = ['NP', 'P1', 'P2', 'P3'];

    private const SELECT = '
        SELECT t.*, COUNT(r.id) AS registered
        FROM trainings t
        LEFT JOIN registrations r ON r.training_id = t.id';

    /** GET /admin/trainings — toutes les sessions, de la plus proche à la plus lointaine */
    public function index(Request $request): never
    {
        $rows = Database::all(self::SELECT . ' GROUP BY t.id ORDER BY t.starts_at, t.id');

        Response::list(array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'startsAt' => Dates::iso($r['starts_at']),
            'endsAt' => Dates::iso($r['ends_at']),
            'location' => $r['location'],
            'licenceType' => $r['licence_type'],
            'capacity' => (int) $r['capacity'],
            'registered' => (int) $r['registered'],
            'registrationDeadline' => Dates::iso($r['registration_deadline']),
            'isPublished' => (bool) $r['is_published'],
        ], $rows));
    }

    /** GET /admin/trainings/{id} */
    public function show(Request $request, string $id): never
    {
        Response::item(self::toEditable($this->find((int) $id)));
    }

    /** POST /admin/trainings */
    public function create(Request $request): never
    {
        $data = $this->validate($request->body, null, 0);
        $data['created_at'] = Dates::now();

        $id = Database::save('trainings', $data, null);
        Response::json(['data' => self::toEditable($this->find($id))], 201);
    }

    /** PUT /admin/trainings/{id} */
    public function update(Request $request, string $id): never
    {
        $training = $this->find((int) $id);
        $data = $this->validate($request->body, (int) $training['id'], (int) $training['registered']);

        Database::save('trainings', $data, (int) $training['id']);
        Response::item(self::toEditable($this->find((int) $training['id'])));
    }

    /**
     * DELETE /admin/trainings/{id}
     * Refusé s'il y a des inscrits (409 has_registrations) : il faut d'abord les annuler
     * ou masquer la formation, pour ne pas perdre la liste des participants par erreur.
     */
    public function delete(Request $request, string $id): never
    {
        $training = $this->find((int) $id);
        if ((int) $training['registered'] > 0) {
            throw new HttpException(409, 'has_registrations');
        }
        Database::run('DELETE FROM trainings WHERE id = ?', [(int) $training['id']]);
        Response::json(['ok' => true]);
    }

    /** @return array<string, mixed> colonnes de la table, validées */
    private function validate(array $body, ?int $id, int $registered): array
    {
        $v = new Validator($body);
        $title = $v->required('title', 255);
        $summary = $v->required('summary', 500);
        $audience = $v->required('audience', 500);
        $location = $v->required('location', 120);
        $licence = $v->in('licenceType', self::LICENCES);

        $objectives = $this->stringList($v, $body['objectives'] ?? null, 'objectives');
        $program = $this->program($v, $body['program'] ?? null);

        $startsAt = $this->date($v, $body, 'startsAt');
        $endsAt = $this->date($v, $body, 'endsAt');
        $deadline = $this->date($v, $body, 'registrationDeadline');
        if ($startsAt && $endsAt && $endsAt <= $startsAt) {
            $v->error('endsAt', 'La fin doit être après le début.');
        }
        if ($startsAt && $deadline && $deadline > $startsAt) {
            $v->error('registrationDeadline', 'La clôture des inscriptions doit précéder le début.');
        }

        $price = $body['price'] ?? null;
        if (!is_int($price) && !is_float($price) || $price < 0 || $price > 10000) {
            $v->error('price', 'Prix invalide (0 pour une formation gratuite).');
        }
        $capacity = $body['capacity'] ?? null;
        if (!is_int($capacity) || $capacity < 1 || $capacity > 1000) {
            $v->error('capacity', 'Entre 1 et 1000 places.');
        } elseif ($capacity < $registered) {
            $v->error('capacity', "Déjà {$registered} inscrits : la capacité ne peut pas être inférieure.");
        }

        // En ligne : pas de lieu physique, lien de connexion facultatif (envoyé dans la confirmation)
        $online = ($body['online'] ?? false) === true;
        $venueName = $online ? null : $v->required('venueName', 255);
        $venueAddress = $online ? null : $v->optional('venueAddress', 500);
        $onlineUrl = $online ? $v->optional('onlineUrl', 500) : null;
        if ($onlineUrl !== null && !preg_match('#^https://#', $onlineUrl)) {
            $v->error('onlineUrl', 'Adresse commençant par https://');
        }

        $slug = $v->optional('slug', 160)
            ?? Slug::unique('trainings', Slug::from($title . ' ' . ($startsAt ? substr($startsAt, 0, 10) : '')), $id);
        if (!Slug::isValid($slug)) {
            $v->error('slug', 'Lettres minuscules, chiffres et tirets uniquement.');
        } elseif (Database::one('SELECT id FROM trainings WHERE slug = ? AND id <> ?', [$slug, $id ?? 0]) !== null) {
            $v->error('slug', 'Cette adresse est déjà utilisée par une autre formation.');
        }

        $v->validate();

        return [
            'slug' => $slug,
            'title' => $title,
            'summary' => $summary,
            'audience' => $audience,
            'objectives' => json_encode($objectives, JSON_UNESCAPED_UNICODE),
            'program' => json_encode($program, JSON_UNESCAPED_UNICODE),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => $location,
            'licence_type' => $licence,
            'price' => $price,
            'capacity' => $capacity,
            'registration_deadline' => $deadline,
            'venue_name' => $venueName,
            'venue_address' => $venueAddress,
            'online_url' => $onlineUrl,
            'is_published' => ($body['isPublished'] ?? false) === true ? 1 : 0,
        ];
    }

    private function date(Validator $v, array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || $value === '') {
            $v->error($field, 'Date obligatoire.');
            return null;
        }
        try {
            return Dates::toDb($value);
        } catch (\Exception) {
            $v->error($field, 'Date invalide.');
            return null;
        }
    }

    /** @return list<string> */
    private function stringList(Validator $v, mixed $value, string $field): array
    {
        if (!is_array($value)) {
            $v->error($field, 'Liste invalide.');
            return [];
        }
        $items = array_values(array_filter(array_map(
            static fn ($s) => is_string($s) ? trim($s) : '',
            $value,
        )));
        if (count($items) > 20 || array_filter($items, static fn ($s) => mb_strlen($s) > 300)) {
            $v->error($field, '20 éléments de 300 caractères maximum.');
        }
        return $items;
    }

    /** @return list<array{time: string, title: string}> */
    private function program(Validator $v, mixed $value): array
    {
        if (!is_array($value)) {
            $v->error('program', 'Programme invalide.');
            return [];
        }
        $steps = [];
        foreach ($value as $step) {
            $time = trim((string) ($step['time'] ?? ''));
            $title = trim((string) ($step['title'] ?? ''));
            if ($time === '' && $title === '') {
                continue;
            }
            if ($title === '' || mb_strlen($title) > 200 || mb_strlen($time) > 20) {
                $v->error('program', 'Chaque étape du programme a besoin d’un intitulé.');
            }
            $steps[] = ['time' => $time, 'title' => $title];
        }
        return $steps;
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $row = Database::one(self::SELECT . ' WHERE t.id = ? GROUP BY t.id', [$id]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private static function toEditable(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'summary' => $row['summary'],
            'audience' => $row['audience'],
            'objectives' => json_decode($row['objectives'], true) ?? [],
            'program' => json_decode($row['program'], true) ?? [],
            'startsAt' => Dates::iso($row['starts_at']),
            'endsAt' => Dates::iso($row['ends_at']),
            'registrationDeadline' => Dates::iso($row['registration_deadline']),
            'location' => $row['location'],
            'licenceType' => $row['licence_type'],
            'price' => (float) $row['price'],
            'capacity' => (int) $row['capacity'],
            'registered' => (int) $row['registered'],
            'online' => $row['venue_name'] === null,
            'venueName' => $row['venue_name'],
            'venueAddress' => $row['venue_address'],
            'onlineUrl' => $row['online_url'],
            'isPublished' => (bool) $row['is_published'],
        ];
    }
}
