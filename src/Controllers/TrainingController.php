<?php
declare(strict_types=1);

namespace Corder\Controllers;

use Corder\Database;
use Corder\Dates;
use Corder\Env;
use Corder\Http\HttpException;
use Corder\Http\Request;
use Corder\Http\Response;
use Corder\Mailer;
use Corder\RateLimiter;
use Corder\Validator;
use PDOException;

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
        $data = [
            'first_name' => $v->required('firstName', 100),
            'last_name' => $v->required('lastName', 100),
            'email' => $v->email('email'),
            'phone' => $v->phone('phone', required: true),
            'licence_number' => $v->optional('licenceNumber', 50),
            'organization' => $v->optional('organization', 190),
        ];
        $v->consent();
        $v->validate();

        RateLimiter::hit('registration', $request->ip);

        $db = Database::pdo();
        $db->beginTransaction();
        try {
            // Verrou sur la formation : deux inscriptions simultanées ne peuvent pas prendre la dernière place
            $training = Database::one(
                'SELECT * FROM trainings WHERE slug = ? AND is_published = 1 FOR UPDATE',
                [$slug],
            );
            if ($training === null) {
                throw HttpException::notFound();
            }
            if ($training['registration_deadline'] < Dates::now()) {
                throw new HttpException(409, 'closed');
            }
            $taken = (int) Database::one(
                'SELECT COUNT(*) AS n FROM registrations WHERE training_id = ?',
                [$training['id']],
            )['n'];
            if ($taken >= (int) $training['capacity']) {
                throw new HttpException(409, 'full');
            }

            Database::run(
                'INSERT INTO registrations
                   (training_id, first_name, last_name, email, phone, licence_number, organization, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$training['id'], ...array_values($data), Dates::now()],
            );
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            // Contrainte unique (formation, e-mail) : déjà inscrit
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'already_registered');
            }
            throw $e;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->sendConfirmation($training, $data);
        Response::created();
    }

    private function sendConfirmation(array $training, array $person): void
    {
        $start = new \DateTimeImmutable($training['starts_at']);
        $end = new \DateTimeImmutable($training['ends_at']);

        $where = $training['venue_name'] === null
            ? 'En ligne' . ($training['online_url'] ? "\nLien de connexion : {$training['online_url']}" : '')
            : $training['venue_name'] . ($training['venue_address'] ? "\n{$training['venue_address']}" : '');

        $body = <<<TXT
            Bonjour {$person['first_name']},

            Votre inscription à la formation suivante est enregistrée :

            {$training['title']}
            {$this->longDate($start)}, de {$start->format('G\hi')} à {$end->format('G\hi')}
            {$where}

            Détails : {$this->siteUrl()}/formations/{$training['slug']}

            En cas d'empêchement, merci de nous prévenir en répondant à cet e-mail.

            L'équipe Corder
            TXT;

        $this->trySend($person['email'], 'Confirmation d\'inscription — ' . $training['title'], $body);

        $notify = Env::get('REGISTRATIONS_NOTIFY');
        if ($notify !== '') {
            $this->trySend(
                $notify,
                'Nouvelle inscription — ' . $training['title'],
                "{$person['first_name']} {$person['last_name']} <{$person['email']}>, {$person['phone']}\n"
                    . 'Phytolicence : ' . ($person['licence_number'] ?? '-') . "\n"
                    . 'Organisation : ' . ($person['organization'] ?? '-'),
                $person['email'],
            );
        }
    }

    /** L'inscription est enregistrée même si l'e-mail échoue : on journalise sans renvoyer d'erreur */
    private function trySend(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        try {
            Mailer::send($to, $subject, $body, $replyTo);
        } catch (\Throwable $e) {
            error_log('Envoi e-mail impossible (' . $subject . ') : ' . $e->getMessage());
        }
    }

    /** « jeudi 5 novembre 2026 » (sans dépendre de l'extension intl, absente sur certains hébergements) */
    private function longDate(\DateTimeImmutable $date): string
    {
        $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
            'septembre', 'octobre', 'novembre', 'décembre'];
        return sprintf(
            '%s %d %s %d',
            $days[(int) $date->format('w')],
            (int) $date->format('j'),
            $months[(int) $date->format('n') - 1],
            (int) $date->format('Y'),
        );
    }

    private function siteUrl(): string
    {
        return rtrim(Env::get('APP_URL', 'https://corder.be'), '/');
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
