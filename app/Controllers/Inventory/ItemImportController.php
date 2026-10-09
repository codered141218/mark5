<?php
namespace App\Controllers\Inventory;

use App\Core\Excel;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ItemImport;

/**
 * Inventory → Items & Recipes → Import from Excel:
 *   GET  /inventory/items/import            upload page (+ ?template=1 downloads the template)
 *   POST /inventory/items/import            read the file and show a preview of what will happen
 *   POST /inventory/items/import/confirm    import the previewed rows (kept in the session)
 */
class ItemImportController
{
    public function form(Request $req)
    {
        if ($req->query('template')) {
            $cols = array_map(fn ($h) => ['key' => $h, 'label' => $h], ItemImport::TEMPLATE[0]);
            $rows = array_map(fn ($r) => ['cells' => $r, 'bold' => false], array_slice(ItemImport::TEMPLATE, 1));
            return Excel::download('item-import-template', 'Item import template', 'Fill one item per row; only Name is required. Delete the sample rows.', $cols, $rows);
        }
        return view('inventory/import/form', ['title' => 'Import items from Excel']);
    }

    public function preview(Request $req)
    {
        $file = $req->file('file');
        if (!$file) throw HttpException::bad('Choose the Excel (.xlsx) or CSV file to import');
        $update = $req->input('update_existing') === '1';
        $rows = ItemImport::analyze(ItemImport::parse($file['tmp_name'], $file['name']), $update);
        $_SESSION['item_import'] = ['rows' => array_map(fn ($r) => array_filter($r, fn ($k) => $k[0] !== '_' || $k === '_line', ARRAY_FILTER_USE_KEY), $rows),
            'update' => $update, 'file' => $file['name']];
        $count = array_count_values(array_column($rows, '_action'));
        return view('inventory/import/preview', ['title' => 'Import items — check', 'rows' => $rows, 'count' => $count, 'file' => $file['name'], 'update' => $update]);
    }

    public function confirm(Request $req): Response
    {
        $data = $_SESSION['item_import'] ?? null;
        if (!$data) throw HttpException::bad('Nothing to import — upload the file again');
        $c = ItemImport::import($data['rows'], $data['update']);
        unset($_SESSION['item_import']);
        flash('success', "Import done: {$c['created']} new item(s), {$c['updated']} updated" . ($c['skipped'] ? ", {$c['skipped']} skipped" : '')
            . ($c['errors'] ? ", {$c['errors']} row(s) with errors not imported" : '') . ($c['stock_lines'] ? ". Beginning stock posted for {$c['stock_lines']} item(s)." : '.'));
        return redirect('/inventory/items');
    }
}
