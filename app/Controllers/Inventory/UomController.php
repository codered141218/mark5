<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Audit;

/**
 * Units of measure (kg, pc, sack ...) and global conversions that apply to every item (1 kg = 1000 g).
 * Item-specific purchase units (1 sack = 50 kg) are edited on the item form.
 */
class UomController
{
    public function index(Request $req)
    {
        $units = DB::all(
            'SELECT u.*, (SELECT COUNT(*) FROM items i WHERE i.base_uom_id = u.id) AS item_count FROM uoms u ORDER BY u.name'
        );
        $conversions = DB::all(
            'SELECT c.*, f.abbr AS from_abbr, t.abbr AS to_abbr FROM uom_conversions c
             JOIN uoms f ON f.id = c.from_uom_id JOIN uoms t ON t.id = c.to_uom_id ORDER BY f.abbr, t.abbr'
        );
        foreach ($conversions as &$c) {
            $c['text'] = "1 {$c['from_abbr']} = " . qty($c['factor']) . " {$c['to_abbr']}";
            $c['reverse'] = "1 {$c['to_abbr']} = " . qty(1 / (float) $c['factor']) . " {$c['from_abbr']}";
        }
        unset($c);

        $unitColumns = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'abbr', 'label' => 'Abbreviation', 'html' => fn ($r) => '<b>' . e($r['abbr']) . '</b>'],
            ['key' => 'item_count', 'label' => 'Items (base unit)', 'type' => 'int'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('inventory/uom/_unit_actions', ['r' => $r], null)],
        ];
        $convColumns = [
            ['key' => 'text', 'label' => 'Conversion', 'html' => fn ($r) => '<b>' . e($r['text']) . '</b>'],
            ['key' => 'reverse', 'label' => 'Reverse', 'html' => fn ($r) => '<span class="muted">' . e($r['reverse']) . '</span>'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('inventory/uom/_conversion_actions', ['r' => $r], null)],
        ];
        if ($req->query('list') === 'conversions') {
            if ($x = Table::export($req, 'unit-conversions', 'Unit Conversions', '', $convColumns, $conversions)) return $x;
        } elseif ($x = Table::export($req, 'units-of-measure', 'Units of Measure', '', $unitColumns, $units)) {
            return $x;
        }

        return view('inventory/uom/index', [
            'title' => 'Units & Conversions', 'units' => $units, 'unitColumns' => $unitColumns,
            'conversions' => $conversions, 'convColumns' => $convColumns,
            'uomOptions' => array_column(array_map(fn ($u) => ['id' => $u['id'], 'label' => "{$u['abbr']} — {$u['name']}"], $units), 'label', 'id'),
        ]);
    }

    public function save(Request $req): Response
    {
        $data = $req->all();
        required($data, 'name', 'abbr');
        $row = ['name' => mb_substr(trim($data['name']), 0, 40), 'abbr' => mb_substr(trim($data['abbr']), 0, 12)];
        $id = (int) ($data['id'] ?? 0);
        if ($id) {
            DB::update('uoms', $id, $row);
            Audit::log('update', 'uom', $id, $row);
        } else {
            $id = DB::insert('uoms', $row);
            Audit::log('create', 'uom', $id, $row);
        }
        flash('success', 'Unit saved.');
        return redirect('/inventory/uom');
    }

    public function delete(Request $req, string $id): Response
    {
        $id = (int) $id;
        $used = DB::value(
            'SELECT 1 FROM items WHERE base_uom_id = ? UNION SELECT 1 FROM item_uoms WHERE uom_id = ?
             UNION SELECT 1 FROM item_components WHERE uom_id = ? UNION SELECT 1 FROM inv_doc_lines WHERE uom_id = ? LIMIT 1',
            [$id, $id, $id, $id]
        );
        if ($used) throw HttpException::bad('Unit is in use and cannot be deleted');
        DB::transaction(function () use ($id) {
            DB::run('DELETE FROM uom_conversions WHERE from_uom_id = ? OR to_uom_id = ?', [$id, $id]);
            DB::run('DELETE FROM uoms WHERE id = ?', [$id]);
        });
        Audit::log('delete', 'uom', $id);
        flash('success', 'Unit deleted.');
        return redirect('/inventory/uom');
    }

    /** Add a global conversion, or update its factor when the pair already exists. */
    public function saveConversion(Request $req): Response
    {
        $data = $req->all();
        required($data, 'from_uom_id', 'to_uom_id', 'factor');
        $factor = num($data['factor']);
        if ($factor <= 0) throw HttpException::bad('Factor must be greater than zero');
        if ((int) $data['from_uom_id'] === (int) $data['to_uom_id']) throw HttpException::bad('Choose two different units');
        DB::run(
            'INSERT INTO uom_conversions (from_uom_id, to_uom_id, factor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE factor = VALUES(factor)',
            [(int) $data['from_uom_id'], (int) $data['to_uom_id'], $factor]
        );
        Audit::log('save', 'uom_conversion', null, $data);
        flash('success', 'Conversion saved.');
        return redirect('/inventory/uom');
    }

    public function deleteConversion(Request $req, string $id): Response
    {
        DB::run('DELETE FROM uom_conversions WHERE id = ?', [(int) $id]);
        Audit::log('delete', 'uom_conversion', (int) $id);
        flash('success', 'Conversion removed.');
        return redirect('/inventory/uom');
    }
}
