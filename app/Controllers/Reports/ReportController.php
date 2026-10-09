<?php
namespace App\Controllers\Reports;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Table;
use App\Services\Reports\SalesReports;
use App\Services\Settings;

/**
 * Report pages: /reports/sales, /reports/inventory, /reports/finance (?report=key picks one from the Catalog),
 * and the printable X/Z reading of a business day (/reports/eod/{id}).
 */
class ReportController
{
    /** Permission needed for reports in each group unless the report lists its own 'perms'. */
    private const GROUP_PERMS = ['sales' => ['reports.sales'], 'inventory' => ['reports.inventory'], 'finance' => ['reports.finance']];

    public function sales(Request $req)
    {
        return $this->show($req, 'sales');
    }

    public function inventory(Request $req)
    {
        return $this->show($req, 'inventory');
    }

    public function finance(Request $req)
    {
        return $this->show($req, 'finance');
    }

    /** Printable X-reading (open day) or Z-reading (closed day). */
    public function eod(Request $req, string $id): string
    {
        $report = SalesReports::reading((int) $id);
        $z = ($report['session']['status'] ?? '') === 'closed';
        $settings = Settings::all();
        return view('reports/eod', [
            'title' => ($z ? 'Z' : 'X') . '-Reading · ' . fmt_date($report['session']['business_date'] ?? null),
            'r' => $report, 'z' => $z, 'settings' => $settings, 'vatRegistered' => ($settings['vat_registered'] ?? '1') === '1',
            'detail' => SalesReports::dayDetail((int) $id),
        ]);
    }

    private function show(Request $req, string $group)
    {
        $cfg = Catalog::group($group);
        $reports = array_filter($cfg['reports'], fn ($r) => Auth::can(...($r['perms'] ?? self::GROUP_PERMS[$group])));
        if (!$reports) throw HttpException::forbidden();
        $key = isset($reports[$req->query('report')]) ? $req->query('report') : array_key_first($reports);
        $rep = $reports[$key];
        $mode = $rep['params'] ?? 'range';

        [$from, $to] = date_range($req);
        $f = [
            'from' => $from, 'to' => $to,
            'item_id' => (int) $req->query('item_id') ?: null,
            'account_id' => (int) $req->query('account_id') ?: null,
            'bank_account_id' => (int) $req->query('bank_account_id') ?: null,
            'category_id' => (int) $req->query('category_id') ?: null,
            'status' => in_array($req->query('status'), ['paid', 'void'], true) ? $req->query('status') : null,
        ];
        $subtitle = match ($mode) {
            'asof' => 'As of ' . fmt_date($to),
            'none' => 'As of ' . fmt_datetime(now()),
            default => range_label($from, $to),
        };
        $filename = $key . ($mode === 'range' ? "_{$from}_{$to}" : ($mode === 'asof' ? "_$to" : ''));

        $data = ['title' => $cfg['title'], 'group' => $group, 'groupTitle' => $cfg['title'], 'reports' => $reports, 'key' => $key, 'rep' => $rep,
            'mode' => $mode, 'from' => $from, 'to' => $to, 'f' => $f, 'subtitle' => $subtitle, 'options' => $this->filterOptions($rep['filters'] ?? [])];

        if (!empty($rep['requires']) && !$f[$rep['requires']]) return view('reports/index', $data + ['needsPick' => true]);

        $result = $rep['rows']($f);
        if (($rep['view'] ?? '') === 'statement') {
            $lines = $key === 'is' ? self::incomeStatementLines($result) : self::balanceSheetLines($result);
            $columns = [
                ['key' => 'name', 'label' => 'Account', 'value' => fn ($r) => str_repeat('    ', $r['_indent'] ?? 0) . $r['name']],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ];
            $title = $key === 'is' ? 'Income Statement' : 'Balance Sheet';
            if ($x = Table::export($req, $filename, $title, $subtitle, $columns, $lines)) return $x;
            return view('reports/index', $data + ['statement' => $result, 'lines' => $lines, 'statementTitle' => $title]);
        }
        if (($rep['view'] ?? '') === 'petty') {
            if ($req->query('part') === 'accounts') {
                if ($x = Table::export($req, $filename . '_by-account', 'Petty cash by account', $subtitle, $rep['by_account'], $result['by_account'])) return $x;
            }
            if ($x = Table::export($req, $filename, $rep['label'], $subtitle, $rep['columns'], $result['rows'])) return $x;
            return view('reports/index', $data + ['petty' => $result]);
        }
        if ($x = Table::export($req, $filename, $rep['label'], $subtitle, $rep['columns'], $result)) return $x;
        return view('reports/index', $data + ['rows' => $result]);
    }

    /** Choices for the extra filters a report uses. */
    private function filterOptions(array $filters): array
    {
        $o = [];
        if (in_array('item', $filters, true)) {
            foreach (DB::all("SELECT i.id, i.name, u.abbr FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE i.item_type IN ('raw','retail') ORDER BY i.name") as $r) {
                $o['item'][$r['id']] = $r['name'] . ($r['abbr'] ? " ({$r['abbr']})" : '');
            }
        }
        if (in_array('account', $filters, true)) {
            foreach (DB::all('SELECT id, code, name FROM accounts ORDER BY code') as $r) $o['account'][$r['id']] = "{$r['code']} · {$r['name']}";
        }
        if (in_array('bank', $filters, true)) {
            foreach (DB::all('SELECT id, bank_name, account_no FROM bank_accounts ORDER BY bank_name') as $r) $o['bank'][$r['id']] = trim("{$r['bank_name']} {$r['account_no']}");
        }
        if (in_array('category', $filters, true)) {
            foreach (DB::all("SELECT id, name FROM categories WHERE kind <> 'menu' ORDER BY sort_order, name") as $r) $o['category'][$r['id']] = $r['name'];
        }
        if (in_array('status', $filters, true)) $o['status'] = ['paid' => 'Paid only', 'void' => 'Void only'];
        return $o;
    }

    // ------------------------------------------------------------------ statement layout
    /**
     * Section of a statement as flat lines: heading, indented accounts, bold total.
     * The same lines feed the screen (CSS classes via 'kind') and the Excel export (_bold / _indent).
     */
    private static function section(string $title, array $accounts, string $totalLabel, float $total): array
    {
        $lines = [['name' => $title, 'amount' => null, 'kind' => 'sec', '_bold' => true]];
        foreach ($accounts as $a) {
            $lines[] = ['name' => trim($a['code'] . ' ' . $a['name']), 'amount' => $a['amount'], 'kind' => 'line', '_indent' => 1, 'account_id' => $a['account_id']];
        }
        $lines[] = ['name' => $totalLabel, 'amount' => $total, 'kind' => 'tot', '_bold' => true];
        return $lines;
    }

    private static function incomeStatementLines(array $d): array
    {
        $pct = fn ($v) => $d['revenue'] ? ' (' . number_format($v / $d['revenue'] * 100, 1) . '%)' : '';
        return array_merge(
            self::section('Revenue', $d['income'], 'Net Revenue', $d['revenue']),
            self::section('Cost of Sales', $d['cogs'], 'Total Cost of Sales', $d['total_cogs']),
            [['name' => 'GROSS PROFIT', 'note' => $pct($d['gross_profit']), 'amount' => $d['gross_profit'], 'kind' => 'grand', '_bold' => true]],
            self::section('Operating Expenses', $d['opex'], 'Total Operating Expenses', $d['total_opex']),
            [['name' => 'NET INCOME (LOSS)', 'note' => $pct($d['net_income']), 'amount' => $d['net_income'], 'kind' => 'grand', '_bold' => true]]
        );
    }

    private static function balanceSheetLines(array $d): array
    {
        return array_merge(
            self::section('Assets', $d['assets'], 'TOTAL ASSETS', $d['total_assets']),
            self::section('Liabilities', $d['liabilities'], 'Total Liabilities', $d['total_liabilities']),
            self::section('Equity', $d['equity'], 'Total Equity', $d['total_equity']),
            [['name' => 'TOTAL LIABILITIES & EQUITY', 'amount' => r2($d['total_liabilities'] + $d['total_equity']), 'kind' => 'grand', '_bold' => true]]
        );
    }
}
