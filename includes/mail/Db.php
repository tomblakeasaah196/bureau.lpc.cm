<?php

namespace LPC\Mail;

use PDO;
use Throwable;

/**
 * The database for Mail: the app's Database singleton (utf8mb4, exceptions on), or the connection a test
 * hands over with Db::use(). Small helpers only; every query is plain SQL with ? placeholders.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/../classes/Database.php';
            }
            self::$pdo = \Database::getInstance()->getConnection();
        }

        return self::$pdo;
    }

    public static function use(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($params));

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($params));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($params));
        $value = $st->fetchColumn();
        $st->closeCursor();

        return $value === false ? null : $value;
    }

    /** @return list<mixed> the first column of every row */
    public static function column(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($params));

        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return int rows affected */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(array_values($params));

        return $st->rowCount();
    }

    /** INSERT one row; @return int the new AUTO_INCREMENT id (0 for tables without one) */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . self::marks(count($cols)) . ')';
        self::exec($sql, array_map([self::class, 'scalar'], array_values($row)));

        return (int) self::pdo()->lastInsertId();
    }

    /** INSERT several rows with the same columns in one statement */
    public static function insertMany(string $table, array $rows): void
    {
        if (!$rows) {
            return;
        }
        $cols = array_keys(reset($rows));
        $params = [];
        $groups = [];
        foreach ($rows as $row) {
            $groups[] = '(' . self::marks(count($cols)) . ')';
            foreach ($cols as $c) {
                $params[] = self::scalar($row[$c] ?? null);
            }
        }
        self::exec('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ' . implode(', ', $groups), $params);
    }

    /** UPDATE … SET $row WHERE id = $id; @return int rows changed */
    public static function update(string $table, array $row, int|string $id, string $key = 'id'): int
    {
        if (!$row) {
            return 0;
        }
        $sets = implode(', ', array_map(fn ($c) => $c . ' = ?', array_keys($row)));
        $params = array_map([self::class, 'scalar'], array_values($row));
        $params[] = $id;

        return self::exec('UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $key . ' = ?', $params);
    }

    /** Run $work in a transaction (or inside the one already open) and return what it returns */
    public static function tx(callable $work): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** "?, ?, ?" */
    public static function marks(int $n): string
    {
        return implode(', ', array_fill(0, max(1, $n), '?'));
    }

    /** A value for a JSON column */
    public static function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** A JSON column back to PHP (arrays as arrays) */
    public static function decode(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }
        $out = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $out : null;
    }

    /** Booleans as 0/1 and arrays as JSON, so every value binds as a plain scalar */
    private static function scalar(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return self::json($value);
        }

        return $value;
    }

    /**
     * A named lock held by this connection (MySQL/MariaDB GET_LOCK), released by release() or when the
     * connection closes. Used so one mailbox is never synced twice at once.
     */
    public static function lock(string $name, int $waitSeconds = 0): bool
    {
        return (int) self::value('SELECT GET_LOCK(?, ?)', [substr($name, 0, 64), $waitSeconds]) === 1;
    }

    public static function release(string $name): void
    {
        try {
            self::value('SELECT RELEASE_LOCK(?)', [substr($name, 0, 64)]);
        } catch (Throwable) {
            // the connection is gone, and the lock with it
        }
    }
}
