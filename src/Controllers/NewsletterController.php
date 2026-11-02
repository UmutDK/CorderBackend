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

/** Inscription à la newsletter avec double confirmation (RGPD). */
final class NewsletterController
{
    /**
     * POST /newsletter/subscriptions
     * Répond toujours 201, même si l'adresse est déjà inscrite, pour ne pas révéler qui est abonné.
     */
    public function subscribe(Request $request): never
    {
        $v = new Validator($request->body);
        $email = $v->email('email');
        $v->consent();
        $v->validate();

        RateLimiter::hit('newsletter', $request->ip);

        $existing = Database::one('SELECT * FROM newsletter_subscribers WHERE email = ?', [$email]);
        if ($existing !== null && $existing['confirmed_at'] !== null) {
            Response::created();
        }

        $token = $existing['token'] ?? bin2hex(random_bytes(32));
        if ($existing === null) {
            Database::run(
                'INSERT INTO newsletter_subscribers (email, token, created_at) VALUES (?, ?, ?)',
                [$email, $token, Dates::now()],
            );
        }

        $link = rtrim(Env::get('API_URL'), '/') . '/newsletter/confirm?token=' . $token;
        $body = <<<TXT
            Bonjour,

            Vous avez demandé à recevoir la newsletter de Corder.
            Pour confirmer votre inscription, cliquez sur ce lien :

            {$link}

            Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet e-mail.

            L'équipe Corder
            TXT;

        try {
            Mailer::send($email, 'Confirmez votre inscription à la newsletter Corder', $body);
        } catch (\Throwable $e) {
            error_log('Envoi e-mail newsletter impossible : ' . $e->getMessage());
        }

        Response::created();
    }

    /** GET /newsletter/confirm?token= — lien cliqué dans l'e-mail : petite page HTML de confirmation */
    public function confirm(Request $request): never
    {
        $token = $request->query['token'] ?? '';
        $subscriber = preg_match('/^[a-f0-9]{64}$/', $token) === 1
            ? Database::one('SELECT id FROM newsletter_subscribers WHERE token = ?', [$token])
            : null;
        $confirmed = $subscriber !== null;
        if ($confirmed) {
            // Un second clic sur le lien garde la date de la première confirmation
            Database::run(
                'UPDATE newsletter_subscribers SET confirmed_at = COALESCE(confirmed_at, ?) WHERE id = ?',
                [Dates::now(), $subscriber['id']],
            );
        }

        $site = htmlspecialchars(rtrim(Env::get('APP_URL', 'https://corder.be'), '/'));
        [$title, $text] = $confirmed
            ? ['Inscription confirmée', 'Merci ! Vous recevrez désormais la newsletter de Corder.']
            : ['Lien invalide', 'Ce lien de confirmation est invalide ou a expiré. Réinscrivez-vous depuis le site.'];

        http_response_code($confirmed ? 200 : 400);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
            <!doctype html>
            <html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>{$title} — Corder</title>
            <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f7ee;color:#14163a;font:16px/1.6 system-ui,sans-serif}
            main{max-width:28rem;margin:1rem;padding:2rem;border-radius:22px;background:#fff;text-align:center}
            a{display:inline-block;margin-top:1rem;padding:.625rem 1.25rem;border-radius:8px;background:#3f8213;color:#fff;font-weight:600;text-decoration:none}</style>
            </head><body><main><h1>{$title}</h1><p>{$text}</p><a href="{$site}">Retour au site</a></main></body></html>
            HTML;
        exit;
    }
}
