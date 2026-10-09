<?php
namespace App\Core;

use PDO;

/**
 * Small static wrapper around PDO.
 *
 *   DB::all('SELECT * FROM items WHERE category_id = ?', [$id]);   // list of rows
 *   DB::one('SELECT * FROM items WHERE id = ?', [$id]);            // one row or null
 *   DB::value('SELECT COUNT(*) FROM items');                       // single value
 *   DB::insert('items', ['name' => 'Rice']);                        // returns new id
 *   DB::update('items', $id, ['name' => 'Rice']);
 *   DB::transaction(function () { ... });                           // commit or roll back
 */
class DB
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = self::$config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['database']);
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Keep MySQL's NOW()/CURDATE() in the same timezone as PHP.
            self::$pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");
            // MySQL 8 enables ONLY_FULL_GROUP_BY by default; the reports rely on MariaDB / MySQL 5.x grouping rules.
            self::$pdo->exec("SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'ONLY_FULL_GROUP_BY', '')");
        }
        return self::$pdo;
    }

    /** Connect to the server without selecting a database (used by the installer). */
    public static function server(): PDO
    {
        $c = self::$config;
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port'] ?? 3306);
        return new PDO($dsn, $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public static function reconnect(): void
    {
        self::$pdo = null;
        self::$txDepth = 0;
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $type = is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            if (is_bool($v)) { $v = $v ? 1 : 0; $type = PDO::PARAM_INT; }
            if (is_float($v)) { $v = (string) $v; }
            $stmt->bindValue($i + 1, $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [])
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $table, implode('`,`', $cols), implode(',', array_fill(0, count($cols), '?')));
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data): void
    {
        if (!$data) return;
        $set = implode(', ', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
        self::query("UPDATE `$table` SET $set WHERE id = ?", [...array_values($data), $id]);
    }

    /** Run $fn inside a transaction. Nested calls use savepoints. */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $sp = 'sp' . self::$txDepth;
        if (self::$txDepth === 0) $pdo->beginTransaction();
        else $pdo->exec("SAVEPOINT $sp");
        self::$txDepth++;
        try {
            $result = $fn();
            self::$txDepth--;
            if (self::$txDepth === 0) $pdo->commit();
            else $pdo->exec("RELEASE SAVEPOINT $sp");
            return $result;
        } catch (\Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0) { if ($pdo->inTransaction()) $pdo->rollBack(); }
            else $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
            throw $e;
        }
    }

    /** Build "?,?,?" for IN (...) clauses. */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(count($values), 1), '?'));
    }
}
