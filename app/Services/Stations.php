<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Prep stations (Kitchen, Grill, Bar ...): where an item is prepared, so the order slip printed when the cashier
 * presses "Done" can be split per station and sent to that station's printer.
 * Each item uses its own station, or else its category's; items with no station print on the printers that
 * take "items without a station" (see Printer setup on each tablet).
 */
class Stations
{
    public static function seedDefaults(): void
    {
        if (DB::value('SELECT COUNT(*) FROM prep_stations') > 0) return;
        foreach (['Kitchen', 'Grill'] as $i => $name) {
            DB::insert('prep_stations', ['name' => $name, 'sort_order' => ($i + 1) * 10, 'active' => 1]);
        }
    }

    public static function all(bool $activeOnly = false): array
    {
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'active' => (int) $r['active'], 'sort_order' => (int) $r['sort_order']] + $r, DB::all(
            'SELECT * FROM prep_stations' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name'
        ));
    }

    /** [id => name] for dropdowns. */
    public static function options(): array
    {
        return array_column(self::all(true), 'name', 'id');
    }

    public static function save(array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') throw HttpException::bad('Enter the station name');
        $id = (int) ($d['id'] ?? 0);
        if (DB::value('SELECT id FROM prep_stations WHERE name = ? AND id <> ?', [$name, $id])) throw HttpException::bad("A station named \"$name\" already exists");
        $row = ['name' => mb_substr($name, 0, 40), 'active' => !empty($d['active']) ? 1 : 0];
        $isNew = !$id;
        if (!$isNew) {
            DB::update('prep_stations', $id, $row);
        } else {
            $row['sort_order'] = Positions::next('prep_stations');
            $id = DB::insert('prep_stations', $row);
        }
        Audit::log($isNew ? 'create' : 'update', 'prep_station', $id, $name);
        return $id;
    }

    public static function delete(int $id): void
    {
        DB::run('UPDATE items SET station_id = NULL WHERE station_id = ?', [$id]);
        DB::run('UPDATE categories SET station_id = NULL WHERE station_id = ?', [$id]);
        DB::run('DELETE FROM prep_stations WHERE id = ?', [$id]);
        Audit::log('delete', 'prep_station', $id);
    }
}
