<?php
declare(strict_types=1);

/**
 * Crée un compte d'administration, ou réinitialise son mot de passe s'il existe déjà.
 * Usage : php database/create-admin.php prenom.nom@corder.be "Prénom Nom" [mot-de-passe]
 * Sans mot de passe, un mot de passe aléatoire est généré et affiché une seule fois.
 * Un mot de passe passé en argument reste dans l'historique du terminal : à réserver au développement.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Corder\Database;
use Corder\Dates;

$email = mb_strtolower(trim($argv[1] ?? ''));
$name = trim($argv[2] ?? '');
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
    fwrite(STDERR, "Usage : php database/create-admin.php prenom.nom@corder.be \"Prénom Nom\" [mot-de-passe]\n");
    exit(1);
}

$password = $argv[3] ?? '';
$generated = $password === '';
if ($generated) {
    // 16 caractères sans caractères ambigus (0/O, 1/l/I)
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 16; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
} elseif (mb_strlen($password) < 12) {
    fwrite(STDERR, "Mot de passe trop court : 12 caractères minimum.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = Database::one('SELECT id FROM admin_users WHERE email = ?', [$email]);
if ($existing === null) {
    Database::run(
        'INSERT INTO admin_users (email, name, password_hash, created_at) VALUES (?, ?, ?, ?)',
        [$email, $name, $hash, Dates::now()],
    );
    echo "Compte créé pour {$name} <{$email}>.\n";
} else {
    Database::run('UPDATE admin_users SET name = ?, password_hash = ? WHERE id = ?', [$name, $hash, $existing['id']]);
    echo "Mot de passe réinitialisé pour {$name} <{$email}>.\n";
}
if ($generated) {
    echo "Mot de passe : {$password}\n(à transmettre de façon sûre ; il n'est plus affiché ensuite)\n";
}
