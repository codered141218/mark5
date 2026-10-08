<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Audit;

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
            'SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS item_count
             FROM categories c ORDER BY c.sort_order, c.name'
        );
        $columns = [
            ['key' => 'name', 'label' => 'Name', 'html' => fn ($r) => '<span class="legend-dot" style="background:' . e($r['color'] ?: '#9ca3af') . '"></span>' . e($r['name'])],
            ['key' => 'kind', 'label' => 'Used for', 'value' => fn ($r) => self::KINDS[$r['kind']], 'html' => fn ($r) => e(self::KINDS[$r['kind']])],
            ['key' => 'sort_order', 'label' => 'Sort', 'type' => 'int'],
            ['key' => 'item_count', 'label' => 'Items', 'type' => 'int'],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('inventory/categories/_actions', ['r' => $r], null)],
        ];
        if ($x = Table::export($req, 'categories', 'Categories', '', $columns, $rows)) return $x;
        return view('inventory/categories/index', ['title' => 'Categories', 'rows' => $rows, 'columns' => $columns, 'kinds' => self::KINDS]);
    }

    public function save(Request $req): Response
    {
        $data = $req->all();
        required($data, 'name');
        $row = [
            'name' => trim($data['name']),
            'kind' => array_key_exists($data['kind'] ?? '', self::KINDS) ? $data['kind'] : 'menu',
            'color' => $data['color'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
        $id = (int) ($data['id'] ?? 0);
        if ($id) {
            DB::update('categories', $id, $row);
            Audit::log('update', 'category', $id, $row);
        } else {
            $id = DB::insert('categories', $row);
            Audit::log('create', 'category', $id, $row);
        }
        flash('success', 'Category saved.');
        return redirect('/inventory/categories');
    }

    public function delete(Request $req, string $id): Response
    {
        if (DB::value('SELECT COUNT(*) FROM items WHERE category_id = ?', [$id]) > 0) {
            throw HttpException::bad('This category still has items. Move or delete them first.');
        }
        DB::run('DELETE FROM categories WHERE id = ?', [$id]);
        Audit::log('delete', 'category', (int) $id);
        flash('success', 'Category deleted.');
        return redirect('/inventory/categories');
    }
}
