<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Audit;
use App\Services\GlSetup;
use App\Services\Positions;
use App\Services\Stations;

/**
 * Item categories (menu groups on the POS and inventory groups).
 * This controller is the reference example of the module pattern:
 *   index()  -> list page (+ Excel export)      GET  /inventory/categories
 *   save()   -> create or update from a dialog  POST /inventory/categories
 *   delete() -> delete with checks              POST /inventory/categories/{id}/delete
 */
class CategoryController
{
    private const KINDS = ['menu' => 'Menu (POS)', 'inventory' => 'Inventory only', 'both' => 'Both'];

    public function index(Request $req)
    {
        $rows = DB::all(
            "SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS item_count, s.name AS station_name,
                    CONCAT_WS(', ', IF(c.sales_account_id IS NULL, NULL, 'Sales'), IF(c.cogs_account_id IS NULL, NULL, 'COGS'),
                                    IF(c.inventory_account_id IS NULL, NULL, 'Inventory')) AS gl_overrides
             FROM categories c LEFT JOIN prep_stations s ON s.id = c.station_id ORDER BY c.sort_order, c.name"
        );
        $columns = [
            ['key' => 'name', 'label' => 'Name', 'html' => fn ($r) => '<span class="legend-dot" style="background:' . e($r['color'] ?: '#9ca3af') . '"></span>' . e($r['name'])],
            ['key' => 'kind', 'label' => 'Used for', 'value' => fn ($r) => self::KINDS[$r['kind']], 'html' => fn ($r) => e(self::KINDS[$r['kind']])],
            ['key' => 'station_name', 'label' => 'Prep station', 'html' => fn ($r) => $r['station_name'] ? e($r['station_name']) : '<span class="muted">—</span>'],
            ['key' => 'gl_overrides', 'label' => 'Own GL accounts', 'html' => fn ($r) => $r['gl_overrides'] ? e($r['gl_overrides']) : '<span class="muted">default</span>'],
            ['key' => 'item_count', 'label' => 'Items', 'type' => 'int'],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('inventory/categories/_actions', ['r' => $r], null)],
        ];
        if ($x = Table::export($req, 'categories', 'Categories', '', $columns, $rows)) return $x;
        return view('inventory/categories/index', ['title' => 'Categories', 'rows' => $rows, 'columns' => $columns, 'kinds' => self::KINDS,
            'stations' => Stations::options(),
            'accounts' => ['sales' => GlSetup::accountOptions(['income']), 'cogs' => GlSetup::accountOptions(['expense']), 'inventory' => GlSetup::accountOptions(['asset'])],
            'bulk' => ['actions' => [['key' => 'delete', 'label' => 'Delete', 'url' => url('/inventory/categories/bulk'), 'danger' => true,
                'confirm' => 'Delete {n} categor(ies)? Categories that still have items are kept.']]]]);
    }

    public function save(Request $req): Response
    {
        $data = $req->all();
        required($data, 'name');
        $row = [
            'name' => trim($data['name']),
            'kind' => array_key_exists($data['kind'] ?? '', self::KINDS) ? $data['kind'] : 'menu',
            'color' => $data['color'] ?: null,
            'active' => !empty($data['active']) ? 1 : 0,
            'station_id' => isset(Stations::options()[(int) ($data['station_id'] ?? 0)]) ? (int) $data['station_id'] : null,
        ] + GlSetup::categoryFields($data);
        $id = (int) ($data['id'] ?? 0);
        if (DB::value('SELECT id FROM categories WHERE name = ? AND id <> ?', [$row['name'], $id])) throw HttpException::bad("A category named \"{$row['name']}\" already exists");
        if ($id) {
            DB::update('categories', $id, $row);
            Audit::log('update', 'category', $id, $row);
        } else {
            $row['sort_order'] = Positions::next('categories');   // new categories go last
            $id = DB::insert('categories', $row);
            Audit::log('create', 'category', $id, $row);
        }
        flash('success', 'Category saved.');
        return redirect('/inventory/categories');
    }

    public function delete(Request $req, string $id): Response
    {
        self::remove((int) $id);
        flash('success', 'Category deleted.');
        return redirect('/inventory/categories');
    }

    /** Bulk delete from the list (categories that still have items are skipped with a message). */
    public function bulk(Request $req): Response
    {
        if ($req->input('action') !== 'delete') throw HttpException::bad('Unknown action');
        bulk_apply((array) $req->input('ids', []), 'category', fn (int $id) => self::remove($id));
        return redirect('/inventory/categories');
    }

    private static function remove(int $id): string
    {
        $name = DB::value('SELECT name FROM categories WHERE id = ?', [$id]);
        if ($name === null) throw HttpException::notFound('Category');
        if (DB::value('SELECT COUNT(*) FROM items WHERE category_id = ?', [$id]) > 0) {
            throw HttpException::bad("“{$name}” still has items. Move or delete them first.");
        }
        DB::run('DELETE FROM categories WHERE id = ?', [$id]);
        Audit::log('delete', 'category', $id, $name);
        return 'deleted';
    }
}
