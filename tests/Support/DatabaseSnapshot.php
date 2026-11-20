<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Photographie du contenu réel des tables (club, member, order) et comparaison entre deux photographies :
 * ce qui a été créé, modifié (colonne par colonne) ou supprimé. Sert à journaliser ce qu'une opération écrit en base.
 */
final class DatabaseSnapshot
{
    public const TABLES = ["club", "member", "order"];

    /**
     * @return array<string, array<int, array<string, mixed>>> table => [id => ligne]
     */
    public static function take(Connection $connection): array
    {
        $platform = $connection->getDatabasePlatform();
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $rows = $connection->fetchAllAssociative("SELECT * FROM " . $platform->quoteIdentifier($table) . " ORDER BY id");
            $snapshot[$table] = array_column($rows, null, "id");
        }

        return $snapshot;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $before
     * @param array<string, array<int, array<string, mixed>>> $after
     * @return array<string, array{created: array<int, array>, modified: array<int, array<string, array{0: mixed, 1: mixed}>>, deleted: array<int, array>, unchanged: int}>
     */
    public static function diff(array $before, array $after): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $b = $before[$table] ?? [];
            $a = $after[$table] ?? [];
            $modified = [];
            $unchanged = 0;
            foreach ($a as $id => $row) {
                if (!isset($b[$id])) {
                    continue;
                }
                $changes = [];
                foreach ($row as $column => $value) {
                    $old = $b[$id][$column] ?? null;
                    if (self::normalize($old) !== self::normalize($value)) {
                        $changes[$column] = [$old, $value];
                    }
                }
                $changes ? $modified[$id] = $changes : $unchanged++;
            }
            $result[$table] = [
                "created" => array_diff_key($a, $b),
                "modified" => $modified,
                "deleted" => array_diff_key($b, $a),
                "unchanged" => $unchanged,
            ];
        }

        return $result;
    }

    private static function normalize(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
