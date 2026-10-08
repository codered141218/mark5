<?php
namespace App\Services;

use App\Core\DB;

/**
 * Document numbering.  Sequence::next('OR', 'OR', 8) -> "OR-00000001", then "OR-00000002" ...
 * Uses SELECT ... FOR UPDATE so two cashiers never get the same number.
 */
class Sequence
{
    public static function next(string $name, ?string $prefix = null, int $pad = 6): string
    {
        return DB::transaction(function () use ($name, $prefix, $pad) {
            $seq = DB::one('SELECT * FROM sequences WHERE name = ? FOR UPDATE', [$name]);
            if (!$seq) {
                DB::insert('sequences', ['name' => $name, 'prefix' => $prefix ?: $name, 'next_no' => 1, 'pad' => $pad]);
                $seq = DB::one('SELECT * FROM sequences WHERE name = ? FOR UPDATE', [$name]);
            } elseif ($prefix && $prefix !== $seq['prefix']) {
                // e.g. the receipt prefix was changed in Settings
                DB::run('UPDATE sequences SET prefix = ? WHERE name = ?', [$prefix, $name]);
                $seq['prefix'] = $prefix;
            }
            DB::run('UPDATE sequences SET next_no = next_no + 1 WHERE name = ?', [$name]);
            return $seq['prefix'] . '-' . str_pad((string) $seq['next_no'], (int) $seq['pad'], '0', STR_PAD_LEFT);
        });
    }
}
