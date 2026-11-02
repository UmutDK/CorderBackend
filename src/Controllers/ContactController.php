<?php
declare(strict_types=1);

namespace Corder\Controllers;

use Corder\Database;
use Corder\Dates;
use Corder\Env;
use Corder\Http\Request;
use Corder\Http\Response;
use Corder\Mailer;
use Corder\RateLimiter;
use Corder\Validator;

final class ContactController
{
    private const POLES = [
        'clinique' => 'Clinique des plantes',
        'crp' => 'Comité régional PHYTO',
        'phytolicence' => 'Phytolicence',
        'recherche' => 'Recherche',
        'formation' => 'Formations',
    ];

    /** POST /contact — enregistre le message puis l'envoie au service concerné */
    public function send(Request $request): never
    {
        $v = new Validator($request->body);
        $data = [
            'name' => $v->required('name', 190),
            'email' => $v->email('email'),
            'phone' => $v->phone('phone', required: false),
            'profile' => $v->required('profile', 100),
            'pole' => $v->in('pole', array_keys(self::POLES), nullable: true),
            'message' => $v->required('message', 5000),
        ];
        $v->consent();
        $v->validate();

        RateLimiter::hit('contact', $request->ip);

        Database::run(
            'INSERT INTO contact_messages (name, email, phone, profile, pole, message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [...array_values($data), Dates::now()],
        );

        $service = $data['pole'] === null ? 'À orienter' : self::POLES[$data['pole']];
        $to = $data['pole'] === null
            ? Env::get('CONTACT_TO_DEFAULT', 'info@corder.be')
            : Env::get('CONTACT_TO_' . strtoupper($data['pole']), Env::get('CONTACT_TO_DEFAULT', 'info@corder.be'));

        $body = <<<TXT
            Nouveau message envoyé depuis le formulaire de contact de corder.be

            Nom : {$data['name']}
            E-mail : {$data['email']}
            Téléphone : {$this->orDash($data['phone'])}
            Profil : {$data['profile']}
            Service : {$service}

            {$data['message']}
            TXT;

        try {
            Mailer::send($to, "[Contact] {$service} — {$data['name']}", $body, $data['email']);
        } catch (\Throwable $e) {
            // Le message reste dans la table contact_messages
            error_log('Envoi e-mail contact impossible : ' . $e->getMessage());
        }

        Response::created();
    }

    private function orDash(?string $value): string
    {
        return $value ?? '-';
    }
}
