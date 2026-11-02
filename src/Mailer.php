<?php
declare(strict_types=1);

namespace Corder;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envoi des e-mails en texte brut.
 * MAIL_DRIVER=smtp : envoi réel (compte e-mail cPanel) · MAIL_DRIVER=log : écrit dans storage/logs/mail.log.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        if (Env::get('MAIL_DRIVER', 'log') !== 'smtp') {
            $entry = sprintf(
                "===== %s =====\nÀ : %s\nRépondre à : %s\nObjet : %s\n\n%s\n\n",
                date('Y-m-d H:i:s'),
                $to,
                $replyTo ?? '-',
                $subject,
                $body,
            );
            file_put_contents(BASE_PATH . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX);
            return;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = Env::get('MAIL_HOST');
        $mail->Port = Env::int('MAIL_PORT', 465);
        $mail->SMTPAuth = true;
        $mail->Username = Env::get('MAIL_USERNAME');
        $mail->Password = Env::get('MAIL_PASSWORD');
        $mail->SMTPSecure = Env::get('MAIL_ENCRYPTION', 'ssl') === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(Env::get('MAIL_FROM'), Env::get('MAIL_FROM_NAME', 'Corder'));
        $mail->addAddress($to);
        if ($replyTo !== null) {
            $mail->addReplyTo($replyTo);
        }
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML(false);
        $mail->send();
    }
}
