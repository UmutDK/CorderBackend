<?php
declare(strict_types=1);

namespace Corder\Controllers;

use Corder\Database;
use Corder\Dates;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;
use Corder\RateLimiter;
use Corder\Registrations;
use Corder\Validator;

/** Formations et inscriptions. Places restantes = capacité - inscriptions enregistrées. */
final class TrainingController
{
    private const SELECT = '
        SELECT t.*, GREATEST(CAST(t.capacity AS SIGNED) - COUNT(r.id), 0) AS seats_left
        FROM trainings t
        LEFT JOIN registrations r ON r.training_id = t.id
        WHERE t.is_published = 1';

    /** GET /trainings?upcoming=true — de la plus proche à la plus lointaine */
    public function index(Request $request): never
    {
        $sql = self::SELECT;
        $params = [];
        if ($request->flag('upcoming')) {
            $sql .= ' AND t.starts_at > :now';
            $params['now'] = Dates::now();
        }
        $rows = Database::all($sql . ' GROUP BY t.id ORDER BY t.starts_at, t.id', $params);

        Response::list(array_map([self::class, 'toTraining'], $rows));
    }

    /** GET /trainings/{slug} */
    public function show(Request $request, string $slug): never
    {
        $row = Database::one(self::SELECT . ' AND t.slug = :slug GROUP BY t.id', ['slug' => $slug]);
        if ($row === null) {
            throw HttpException::notFound();
        }

        Response::item(self::toTraining($row) + [
            'summary' => $row['summary'],
            'audience' => $row['audience'],
            'objectives' => json_decode($row['objectives'], true) ?? [],
            'program' => json_decode($row['program'], true) ?? [],
            'venue' => $row['venue_name'] === null
                ? null
                : ['name' => $row['venue_name'], 'address' => $row['venue_address'] ?? ''],
            'capacity' => (int) $row['capacity'],
            'registrationDeadline' => Dates::iso($row['registration_deadline']),
        ]);
    }

    /** POST /trainings/{slug}/registrations */
    public function register(Request $request, string $slug): never
    {
        $v = new Validator($request->body);
        $person = Registrations::person($v);
        $v->consent();
        $v->validate();

        RateLimiter::hit('registration', $request->ip);

        $row = Database::one('SELECT id FROM trainings WHERE slug = ?', [$slug]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        $training = Registrations::create((int) $row['id'], $person, fromSite: true);
        Registrations::sendConfirmation($training, $person);
        Response::created();
    }

    /** @return array<string, mixed> */
    private static function toTraining(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'startsAt' => Dates::iso($row['starts_at']),
            'endsAt' => Dates::iso($row['ends_at']),
            'location' => $row['location'],
            'licenceType' => $row['licence_type'],
            'price' => (float) $row['price'],
            'seatsLeft' => (int) $row['seats_left'],
        ];
    }
}
