<?php
namespace App\Core;

/**
 * One column definition drives both the HTML table and the Excel export.
 *
 * $columns = [
 *   ['key' => 'name',  'label' => 'Item'],
 *   ['key' => 'price', 'label' => 'Price', 'type' => 'money', 'total' => true],
 *   ['key' => 'status','label' => 'Status', 'type' => 'badge'],
 *   ['key' => 'name',  'label' => 'Item', 'html' => fn ($r) => '<a href="...">' . e($r['name']) . '</a>'],
 * ];
 * Types: text (default), money, qty, int, percent, date, datetime, badge.
 * 'total' => true sums the column; or pass a callable fn(array $rows) => value.
 * 'html'  => callable returning already-escaped HTML for the screen.
 * 'value' => callable returning the raw value for Excel (defaults to $row[key]).
 * 'export' => false hides the column in Excel (e.g. action buttons).
 *
 * In a controller:
 *   if ($x = Table::export($req, 'items', 'Item list', $subtitle, $columns, $rows)) return $x;
 * In a view:
 *   <?= Table::html($columns, $rows, ['export' => true, 'link' => fn ($r) => url('/items/' . $r['id'])]) ?>
 *
 * Every table gets a "Sort by" dropdown (A→Z, Z→A, low→high ...) and clickable headers (app.js).
 * Bulk actions: tick rows, then press an action button. The ids are POSTed to the action's url as ids[] plus
 * action=<key> (rows with '_nobulk' => true get no checkbox):
 *   'bulk' => ['actions' => [['key' => 'delete', 'label' => 'Delete', 'url' => url('/inventory/items/bulk'),
 *                             'confirm' => 'Delete the selected items?', 'danger' => true]]]
 */
class Table
{
    public const NUMERIC = ['money', 'qty', 'int', 'percent'];

    public static function html(array $columns, array $rows, array $opts = []): string
    {
        $totals = self::totals($columns, $rows);
        $link = $opts['link'] ?? null;
        $bulk = !empty($opts['bulk']['actions']) && $rows ? $opts['bulk'] : null;
        $idKey = $bulk['key'] ?? 'id';
        $h = '<div class="datatable">';
        if (($opts['search'] ?? true) || !empty($opts['export']) || !empty($opts['toolbar']) || $bulk) {
            $h .= '<div class="dt-toolbar">';
            if ($opts['search'] ?? true) $h .= '<input class="input dt-search" type="search" placeholder="Search…" data-table-search>';
            if (($opts['sort'] ?? true) && count($rows) > 1) $h .= '<select class="input dt-sort" data-table-sort aria-label="Sort by"><option value="">Sort by…</option></select>';
            if (!empty($opts['toolbar'])) $h .= '<div class="row gap-sm wrap">' . $opts['toolbar'] . '</div>';
            $h .= '<div class="grow"></div><span class="muted small">' . count($rows) . ' record' . (count($rows) === 1 ? '' : 's') . '</span>';
            if (!empty($opts['export']) && $rows) {
                $h .= '<a class="btn btn-sm" href="' . e(current_url(['export' => 'xlsx'])) . '">⬇ Excel</a>';
            }
            $h .= '</div>';
            if ($bulk) {
                $h .= '<div class="dt-bulk" data-bulk-bar hidden><span><b data-bulk-count>0</b> selected</span>';
                foreach ($bulk['actions'] as $a) {
                    $h .= '<button type="button" class="btn btn-sm' . (!empty($a['danger']) ? ' btn-danger' : '') . '" data-bulk-action="' . e($a['key'])
                        . '" data-url="' . e($a['url']) . '" data-confirm="' . e($a['confirm'] ?? '') . '">' . e($a['label']) . '</button>';
                }
                $h .= '<button type="button" class="btn btn-sm btn-ghost" data-bulk-clear>Clear selection</button></div>';
            }
        }
        $h .= '<div class="table-wrap"><table class="table' . ($link ? ' clickable' : '') . '" data-table><thead><tr>';
        if ($bulk) $h .= '<th class="dt-check" data-nosort><input type="checkbox" data-bulk-all aria-label="Select all"></th>';
        foreach ($columns as $c) {
            $nosort = $c['label'] === '' || ($c['sortable'] ?? true) === false ? ' data-nosort' : '';
            $h .= '<th class="' . self::align($c) . '" data-type="' . e($c['type'] ?? 'text') . '"' . $nosort . '>' . e($c['label']) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $cls = trim(($r['_class'] ?? '') . (!empty($r['_bold']) ? ' bold' : ''));
            $h .= '<tr' . ($cls ? ' class="' . e($cls) . '"' : '') . ($link ? ' data-href="' . e($link($r)) . '"' : '') . '>';
            if ($bulk) {
                $h .= '<td class="dt-check">' . (empty($r['_nobulk']) ? '<input type="checkbox" data-bulk-id value="' . e($r[$idKey]) . '" aria-label="Select">' : '') . '</td>';
            }
            foreach ($columns as $i => $c) {
                $raw = $r[$c['key']] ?? null;
                $style = $i === 0 && !empty($r['_indent']) ? ' style="padding-left:' . (12 + 18 * (int) $r['_indent']) . 'px"' : '';
                $h .= '<td class="' . self::align($c) . '" data-sort="' . e(is_scalar($raw) ? $raw : '') . '"' . $style . '>' . self::cell($c, $r) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody>';
        if ($totals && $rows) {
            $h .= '<tfoot><tr>' . ($bulk ? '<td></td>' : '');
            foreach ($columns as $c) {
                $v = $totals[$c['key']] ?? '';
                $h .= '<td class="' . self::align($c) . '">' . (is_string($v) ? e($v) : self::format($c['type'] ?? 'money', $v)) . '</td>';
            }
            $h .= '</tr></tfoot>';
        }
        $h .= '</table>';
        if (!$rows) $h .= '<div class="empty">' . e($opts['empty'] ?? 'No records found.') . '</div>';
        return $h . '</div></div>';
    }

    /** Returns an Excel download Response when ?export=xlsx is present, otherwise null. */
    public static function export(Request $req, string $filename, string $title, string $subtitle, array $columns, array $rows, array $extraRows = []): ?Response
    {
        if ($req->query('export') !== 'xlsx') return null;
        $cols = array_values(array_filter($columns, fn ($c) => ($c['export'] ?? true) !== false));
        $data = [];
        foreach (array_merge($rows, $extraRows) as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = isset($c['value']) ? $c['value']($r) : ($r[$c['key']] ?? null);
                $line[] = in_array($c['type'] ?? 'text', self::NUMERIC, true) && $v !== null && $v !== '' ? (float) $v : $v;
            }
            $data[] = ['cells' => $line, 'bold' => !empty($r['_bold'])];
        }
        $totals = self::totals($cols, $rows);
        if ($totals && $rows) {
            $data[] = ['cells' => array_map(fn ($c) => $totals[$c['key']] ?? null, $cols), 'bold' => true];
        }
        return Excel::download($filename, $title, $subtitle, $cols, $data);
    }

    public static function totals(array $columns, array $rows): ?array
    {
        $has = false;
        $t = [];
        foreach ($columns as $c) {
            if (empty($c['total'])) continue;
            $has = true;
            $t[$c['key']] = is_callable($c['total']) ? $c['total']($rows) : array_sum(array_map(fn ($r) => (float) ($r[$c['key']] ?? 0), $rows));
        }
        if (!$has) return null;
        $first = $columns[0]['key'];
        if (!isset($t[$first])) $t[$first] = 'TOTAL';
        return $t;
    }

    public static function cell(array $c, array $r): string
    {
        if (isset($c['html'])) return (string) $c['html']($r);
        $v = $r[$c['key']] ?? null;
        $type = $c['type'] ?? 'text';
        if ($type === 'badge') return badge($v === null ? null : (string) $v);
        return self::format($type, $v);
    }

    public static function format(string $type, $v): string
    {
        if ($v === null || $v === '') return '';
        return match ($type) {
            'money' => money($v),
            'qty' => qty($v),
            'int' => number_format((float) $v),
            'percent' => number_format((float) $v, 2) . '%',
            'date' => e(fmt_date($v)),
            'datetime' => e(fmt_datetime($v)),
            default => e($v),
        };
    }

    private static function align(array $c): string
    {
        return $c['align'] ?? (in_array($c['type'] ?? 'text', self::NUMERIC, true) ? 'right' : '');
    }
}
