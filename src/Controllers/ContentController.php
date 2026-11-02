<?php
declare(strict_types=1);

namespace Corder\Controllers;

use Corder\Database;
use Corder\Http\Request;
use Corder\Http\Response;

/** Petits contenus éditables : FAQ, chiffres clés, équipe (dans l'ordre choisi par Corder). */
final class ContentController
{
    /** GET /faq */
    public function faq(Request $request): never
    {
        $rows = Database::all('SELECT id, question, answer FROM faq_items ORDER BY position, id');
        Response::list(array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $rows));
    }

    /** GET /key-figures */
    public function keyFigures(Request $request): never
    {
        $rows = Database::all('SELECT id, value, label FROM key_figures ORDER BY position, id');
        Response::list(array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $rows));
    }

    /** GET /team */
    public function team(Request $request): never
    {
        $rows = Database::all('SELECT * FROM team_members ORDER BY position, id');
        Response::list(array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'firstName' => $r['first_name'],
            'lastName' => $r['last_name'],
            'role' => $r['role'],
            'group' => $r['member_group'],
            'email' => $r['email'],
            'photo' => $r['photo_url'],
        ], $rows));
    }
}
