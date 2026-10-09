<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Display order of categories, menu items, discounts and prep stations (the sort_order column).
 * Users never type numbers: new records go to the end, and Inventory → Arrange menu saves a new order
 * by drag & drop / move buttons.
 */
class Positions
{
    public const TABLES = ['categories', 'items', 'discounts', 'prep_stations'];

    /** Position for a new record: after the last one (in steps of 10). */
    public static function next(string $table, ?string $where = null, array $params = []): int
    {
        self::check($table);
        return (int) DB::value("SELECT COALESCE(MAX(sort_order), 0) FROM `$table`" . ($where ? " WHERE $where" : ''), $params) + 10;
    }

    /** Save the order of the given ids: the first id gets position 10, the next 20 ... */
    public static function save(string $table, array $ids): void
    {
        self::check($table);
        DB::transaction(function () use ($table, $ids) {
            $pos = 0;
            foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
                if ($id > 0) DB::run("UPDATE `$table` SET sort_order = ? WHERE id = ?", [$pos += 10, $id]);
            }
        });
    }

    private static function check(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) throw HttpException::bad('Unknown list');
    }
}
