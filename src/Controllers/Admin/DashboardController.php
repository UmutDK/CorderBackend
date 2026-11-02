<?php
declare(strict_types=1);

namespace Corder\Controllers\Admin;

use Corder\Database;
use Corder\Dates;
use Corder\Http\Request;
use Corder\Http\Response;

final class DashboardController
{
    /** GET /admin/stats — chiffres du tableau de bord */
    public function stats(Request $request): never
    {
        $now = Dates::now();
        $monthAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $count = static fn (string $sql, array $params = []) => (int) Database::one($sql, $params)['n'];

        Response::item([
            'articlesPublished' => $count('SELECT COUNT(*) AS n FROM articles WHERE published_at <= ?', [$now]),
            'articlesDraft' => $count('SELECT COUNT(*) AS n FROM articles WHERE published_at IS NULL OR published_at > ?', [$now]),
            'trainingsUpcoming' => $count('SELECT COUNT(*) AS n FROM trainings WHERE starts_at > ?', [$now]),
            'registrationsLast30Days' => $count('SELECT COUNT(*) AS n FROM registrations WHERE created_at >= ?', [$monthAgo]),
            'messagesLast30Days' => $count('SELECT COUNT(*) AS n FROM contact_messages WHERE created_at >= ?', [$monthAgo]),
            'newsletterSubscribers' => $count('SELECT COUNT(*) AS n FROM newsletter_subscribers WHERE confirmed_at IS NOT NULL'),
        ]);
    }
}
