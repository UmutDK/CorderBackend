<?php
declare(strict_types=1);

namespace Corder;

use Corder\Http\HttpException;
use PDOException;

/**
 * Inscriptions aux formations : règles communes au formulaire public et à l'ajout manuel
 * depuis l'administration (inscription par téléphone, par exemple).
 */
final class Registrations
{
    /**
     * Champs de la personne, validés (sans le consentement, propre au formulaire public).
     * @return array{first_name: string, last_name: string, email: string, phone: ?string, licence_number: ?string, organization: ?string}
     */
    public static function person(Validator $v): array
    {
        return [
            'first_name' => $v->required('firstName', 100),
            'last_name' => $v->required('lastName', 100),
            'email' => $v->email('email'),
            'phone' => $v->phone('phone', required: true),
            'licence_number' => $v->optional('licenceNumber', 50),
            'organization' => $v->optional('organization', 190),
        ];
    }

    /**
     * Prend une place dans la formation. Verrou sur la ligne de la formation : deux inscriptions
     * simultanées ne peuvent pas prendre la dernière place.
     * $fromSite = true (formulaire public) : formation publiée et date limite non dépassée obligatoires.
     * Erreurs 409 : full, closed, already_registered.
     *
     * @return array<string, mixed> la formation (ligne de la table)
     */
    public static function create(int $trainingId, array $person, bool $fromSite): array
    {
        $db = Database::pdo();
        $db->beginTransaction();
        try {
            $training = Database::one(
                'SELECT * FROM trainings WHERE id = ?' . ($fromSite ? ' AND is_published = 1' : '') . ' FOR UPDATE',
                [$trainingId],
            );
            if ($training === null) {
                throw HttpException::notFound();
            }
            if ($fromSite && $training['registration_deadline'] < Dates::now()) {
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
                [$training['id'], ...array_values($person), Dates::now()],
            );
            $db->commit();
            return $training;
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
    }

    /** E-mail de confirmation à la personne, et copie à REGISTRATIONS_NOTIFY si défini */
    public static function sendConfirmation(array $training, array $person): void
    {
        $start = new \DateTimeImmutable($training['starts_at']);
        $end = new \DateTimeImmutable($training['ends_at']);
        $date = self::longDate($start);
        $site = rtrim(Env::get('APP_URL', 'https://corder.be'), '/');

        $where = $training['venue_name'] === null
            ? 'En ligne' . ($training['online_url'] ? "\nLien de connexion : {$training['online_url']}" : '')
            : $training['venue_name'] . ($training['venue_address'] ? "\n{$training['venue_address']}" : '');

        $body = <<<TXT
            Bonjour {$person['first_name']},

            Votre inscription à la formation suivante est enregistrée :

            {$training['title']}
            {$date}, de {$start->format('G\hi')} à {$end->format('G\hi')}
            {$where}

            Détails : {$site}/formations/{$training['slug']}

            En cas d'empêchement, merci de nous prévenir en répondant à cet e-mail.

            L'équipe Corder
            TXT;

        self::trySend($person['email'], 'Confirmation d\'inscription — ' . $training['title'], $body);

        $notify = Env::get('REGISTRATIONS_NOTIFY');
        if ($notify !== '') {
            self::trySend(
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
    private static function trySend(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        try {
            Mailer::send($to, $subject, $body, $replyTo);
        } catch (\Throwable $e) {
            error_log('Envoi e-mail impossible (' . $subject . ') : ' . $e->getMessage());
        }
    }

    /** « jeudi 5 novembre 2026 » (sans dépendre de l'extension intl, absente sur certains hébergements) */
    private static function longDate(\DateTimeImmutable $date): string
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
}
