<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Positions;
use App\Services\Settings;

/**
 * Inventory → Arrange menu: put POS categories, menu items and discounts in order by dragging
 * (or with the move buttons) instead of typing sort numbers.
 */
class ArrangeController
{
    public function index(Request $req)
    {
        $cats = DB::all("SELECT id, name, color, active FROM categories WHERE kind IN ('menu','both') ORDER BY sort_order, name");
        $items = DB::all('SELECT id, name, category_id, price, active FROM items WHERE sellable = 1 ORDER BY sort_order, name');
        $byCat = [];
        foreach ($items as $i) $byCat[(int) $i['category_id']][] = ['id' => (int) $i['id'], 'name' => $i['name'], 'price' => (float) $i['price'], 'active' => (int) $i['active']];
        $data = [
            'categories' => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'active' => (int) $c['active'],
                'items' => $byCat[(int) $c['id']] ?? []], $cats),
            'uncategorized' => $byCat[0] ?? [],
            'discounts' => array_map(fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name'], 'active' => (int) $d['active']],
                DB::all('SELECT id, name, active FROM discounts ORDER BY sort_order, name')),
        ];
        return view('inventory/arrange/index', ['title' => 'Arrange Menu', 'arrange' => $data, 'menuSort' => Settings::get('pos_menu_sort', 'custom')]);
    }

    /** JSON {list: categories|items|discounts, ids: [..]} -> saves the order. */
    public function save(Request $req): Response
    {
        $list = (string) $req->input('list');
        $table = ['categories' => 'categories', 'items' => 'items', 'discounts' => 'discounts'][$list] ?? null;
        if (!$table) throw HttpException::bad('Unknown list');
        $ids = array_map('intval', (array) $req->input('ids', []));
        if ($table === 'items') {
            // One category at a time: the items swap the positions they already had, so other categories are untouched
            if (!$ids) throw HttpException::bad('Nothing to arrange');
            $slots = array_map('intval', array_column(DB::all('SELECT sort_order FROM items WHERE id IN (' . DB::placeholders($ids) . ') ORDER BY sort_order, id', $ids), 'sort_order'));
            if (count($slots) !== count(array_unique($ids)) || count(array_unique($slots)) !== count($slots)) {
                $base = Positions::next('items');                    // duplicates / unknown ids: give them fresh positions at the end
                $slots = array_map(fn ($i) => $base + $i * 10, array_keys($ids));
            }
            DB::transaction(function () use ($ids, $slots) {
                foreach (array_values($ids) as $i => $id) DB::run('UPDATE items SET sort_order = ? WHERE id = ?', [$slots[$i], $id]);
            });
        } else {
            Positions::save($table, $ids);
        }
        Audit::log('arrange', $table, null, ['ids' => $ids]);
        return json(['ok' => true]);
    }
}
