<?php
declare(strict_types=1);

namespace Corder;

use PDO;

/** Connexion PDO unique à MySQL / MariaDB. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::int('DB_PORT', 3306),
                Env::get('DB_NAME', 'corder'),
            );
            self::$pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Les DATETIME de la base sont en heure belge, comme PHP
            self::$pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");
        }
        return self::$pdo;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Insère ($id null) ou met à jour une ligne à partir d'un tableau colonne => valeur.
     * Les noms de colonnes viennent toujours du code (jamais de la requête HTTP).
     */
    public static function save(string $table, array $data, ?int $id): int
    {
        $columns = array_keys($data);
        if ($id === null) {
            return self::insert(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $table,
                    implode(', ', $columns),
                    implode(', ', array_fill(0, count($columns), '?')),
                ),
                array_values($data),
            );
        }
        self::run(
            "UPDATE {$table} SET " . implode(', ', array_map(static fn ($c) => "{$c} = ?", $columns)) . ' WHERE id = ?',
            [...array_values($data), $id],
        );
        return $id;
    }
}
