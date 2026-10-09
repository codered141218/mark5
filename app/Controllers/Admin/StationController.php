<?php
namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Stations;

/** Prep stations (Kitchen, Grill, Bar ...): where order slips print. Administration → Prep Stations. */
class StationController
{
    public function index(Request $req)
    {
        $rows = array_map(fn ($r) => $r + [
            'categories' => (int) DB::value('SELECT COUNT(*) FROM categories WHERE station_id = ?', [$r['id']]),
            'items' => (int) DB::value('SELECT COUNT(*) FROM items WHERE station_id = ?', [$r['id']]),
        ], Stations::all());
        $columns = [
            ['key' => 'name', 'label' => 'Station', 'html' => fn ($r) => '<b>' . e($r['name']) . '</b>'],
            ['key' => 'categories', 'label' => 'Categories', 'type' => 'int'],
            ['key' => 'items', 'label' => 'Items tagged directly', 'type' => 'int'],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('admin/stations/_actions', ['r' => $r], null)],
        ];
        if ($x = Table::export($req, 'prep-stations', 'Prep stations', '', $columns, $rows)) return $x;
        $untagged = DB::all(
            "SELECT i.name, c.name AS category FROM items i LEFT JOIN categories c ON c.id = i.category_id
             WHERE i.active = 1 AND i.sellable = 1 AND i.station_id IS NULL AND c.station_id IS NULL ORDER BY c.sort_order, i.sort_order, i.name"
        );
        return view('admin/stations/index', ['title' => 'Prep Stations', 'rows' => $rows, 'columns' => $columns, 'untagged' => $untagged,
            'bulk' => ['actions' => [['key' => 'delete', 'label' => 'Delete', 'url' => url('/admin/stations/bulk'), 'danger' => true,
                'confirm' => 'Delete {n} station(s)? Their items and categories become “no station”.']]]]);
    }

    public function save(Request $req): Response
    {
        Stations::save($req->all());
        flash('success', 'Station saved.');
        return redirect('/admin/stations');
    }

    public function delete(Request $req, string $id): Response
    {
        Stations::delete((int) $id);
        flash('success', 'Station deleted.');
        return redirect('/admin/stations');
    }

    public function bulk(Request $req): Response
    {
        bulk_apply((array) $req->input('ids', []), 'station', function (int $id) { Stations::delete($id); return 'deleted'; });
        return redirect('/admin/stations');
    }
}
