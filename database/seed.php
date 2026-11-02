<?php
declare(strict_types=1);

/**
 * Crée les tables (schema.sql) puis les remplit avec les données d'exemple du frontend.
 * Usage : php database/seed.php [dossier des JSON]
 * Par défaut, les JSON sont lus dans ../frontend/public/data (dépôt frontend à côté du backend).
 *
 * ATTENTION : vide toutes les tables avant de les remplir. À ne pas lancer sur la base de production
 * une fois que Corder y a saisi du contenu.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Corder\Database;
use Corder\Dates;
use Corder\HtmlSanitizer;

$dataDir = rtrim($argv[1] ?? dirname(__DIR__, 2) . '/frontend/public/data', '/\\');
if (!is_file($dataDir . '/articles.json')) {
    fwrite(STDERR, "Données introuvables dans {$dataDir}\nUsage : php database/seed.php <dossier public/data du frontend>\n");
    exit(1);
}

/** @return array<mixed> contenu de "data" d'un fichier JSON du frontend */
function data(string $file): array
{
    $json = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    return $json['data'];
}

$db = Database::pdo();

// ── Tables ──
// Commentaires retirés d'abord : ils peuvent contenir des « ; »
$schema = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    $db->exec($statement);
}
echo "Tables créées.\n";

$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['registrations', 'trainings', 'articles', 'categories', 'faq_items', 'key_figures', 'team_members'] as $table) {
    $db->exec("TRUNCATE TABLE {$table}");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

$now = Dates::now();

// ── Catégories et articles (les alertes sont des articles de la catégorie « alertes ») ──
$alertLevels = array_column(data("{$dataDir}/alerts.json"), 'level', 'slug');
$categories = [];

foreach (glob("{$dataDir}/articles/*.json") as $file) {
    $a = data($file);
    $catSlug = $a['category']['slug'];
    $categories[$catSlug] ??= Database::insert(
        'INSERT INTO categories (slug, label) VALUES (?, ?)',
        [$catSlug, $a['category']['label']],
    );

    Database::insert(
        'INSERT INTO articles (id, slug, title, excerpt, body, category_id, pole, image_url, image_alt,
                               featured, alert_level, published_at, updated_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $a['id'], $a['slug'], $a['title'], $a['excerpt'], HtmlSanitizer::clean($a['body']),
            $categories[$catSlug], $a['pole'], $a['image']['url'] ?? null, $a['image']['alt'] ?? '',
            (int) $a['featured'], $alertLevels[$a['slug']] ?? null,
            Dates::toDb($a['publishedAt']), $a['updatedAt'] ? Dates::toDb($a['updatedAt']) : null, $now,
        ],
    );
}
echo count(glob("{$dataDir}/articles/*.json")) . ' articles, ' . count($categories) . " catégories.\n";

// ── Formations, avec de fausses inscriptions pour retrouver les places restantes des exemples ──
$fakeRegistrations = 0;
foreach (glob("{$dataDir}/trainings/*.json") as $file) {
    $t = data($file);
    Database::insert(
        'INSERT INTO trainings (id, slug, title, summary, audience, objectives, program, starts_at, ends_at,
                                location, licence_type, price, capacity, registration_deadline,
                                venue_name, venue_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $t['id'], $t['slug'], $t['title'], $t['summary'], $t['audience'],
            json_encode($t['objectives'], JSON_UNESCAPED_UNICODE),
            json_encode($t['program'], JSON_UNESCAPED_UNICODE),
            Dates::toDb($t['startsAt']), Dates::toDb($t['endsAt']), $t['location'], $t['licenceType'],
            $t['price'], $t['capacity'], Dates::toDb($t['registrationDeadline']),
            $t['venue']['name'] ?? null, $t['venue']['address'] ?? null, $now,
        ],
    );

    for ($i = 1; $i <= $t['capacity'] - $t['seatsLeft']; $i++) {
        Database::run(
            'INSERT INTO registrations (training_id, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$t['id'], 'Exemple', "Inscrit {$i}", "exemple{$i}@example.invalid", '000 00 00 00', $now],
        );
        $fakeRegistrations++;
    }
}
echo count(glob("{$dataDir}/trainings/*.json")) . " formations, {$fakeRegistrations} inscriptions d'exemple.\n";

// ── Contenus ──
foreach (data("{$dataDir}/faq.json") as $i => $f) {
    Database::run('INSERT INTO faq_items (id, question, answer, position) VALUES (?, ?, ?, ?)',
        [$f['id'], $f['question'], $f['answer'], $i]);
}
foreach (data("{$dataDir}/key-figures.json") as $i => $k) {
    Database::run('INSERT INTO key_figures (id, value, label, position) VALUES (?, ?, ?, ?)',
        [$k['id'], $k['value'], $k['label'], $i]);
}
foreach (data("{$dataDir}/team.json") as $i => $m) {
    Database::run(
        'INSERT INTO team_members (id, first_name, last_name, role, member_group, email, photo_url, position)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$m['id'], $m['firstName'], $m['lastName'], $m['role'], $m['group'], $m['email'], $m['photo'], $i],
    );
}
echo "FAQ, chiffres clés et équipe importés.\nTerminé.\n";
