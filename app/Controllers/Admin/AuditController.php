<?php
namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\Request;
use App\Core\Table;

/** /admin/audit — who did what and when (most recent 2,000 entries of the period). */
class AuditController
{
    private const LIMIT = 2000;
    private const MAX_DETAILS = 120;
    private const COLORS = ['create' => 'green', 'update' => 'blue', 'delete' => 'red', 'void' => 'red', 'disable' => 'red',
        'delete_backup' => 'red', 'restore' => 'amber', 'override' => 'amber', 'backup' => 'green', 'login' => 'gray'];

    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        if (!$req->query('from')) $from = add_days($to, -6); // default: last 7 days
        $action = (string) $req->query('action', '');
        $userId = (int) $req->query('user');

        $where = 'a.ts >= ? AND a.ts < ?';
        $params = [$from . ' 00:00:00', add_days($to, 1) . ' 00:00:00'];
        $actions = DB::all("SELECT DISTINCT a.action FROM audit_log a WHERE $where ORDER BY a.action", $params);
        if ($action !== '') { $where .= ' AND a.action = ?'; $params[] = $action; }
        if ($userId) { $where .= ' AND a.user_id = ?'; $params[] = $userId; }
        $rows = DB::all(
            "SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE $where ORDER BY a.id DESC LIMIT " . self::LIMIT,
            $params
        );
        foreach ($rows as &$r) {
            $r['user_label'] = $r['username'] ?? ($r['user_id'] ? '#' . $r['user_id'] : 'system');
            $r['details_text'] = self::compact($r['details']);
        }
        unset($r);

        $columns = [
            ['key' => 'ts', 'label' => 'Time', 'type' => 'datetime'],
            ['key' => 'user_label', 'label' => 'User'],
            ['key' => 'action', 'label' => 'Action', 'html' => fn ($r) => badge($r['action'], self::COLORS[$r['action']] ?? 'gray')],
            ['key' => 'entity', 'label' => 'Entity'],
            ['key' => 'entity_id', 'label' => 'ID', 'align' => 'right'],
            ['key' => 'details_text', 'label' => 'Details', 'html' => fn ($r) => mb_strlen($r['details_text']) > self::MAX_DETAILS
                ? '<span class="small" title="' . e($r['details_text']) . '">' . e(mb_substr($r['details_text'], 0, self::MAX_DETAILS)) . '…</span>'
                : '<span class="small">' . e($r['details_text']) . '</span>'],
        ];
        $users = [];
        foreach (DB::all('SELECT id, username FROM users ORDER BY username') as $u) $users[$u['id']] = $u['username'];
        $subtitle = range_label($from, $to) . ($action !== '' ? " · action: $action" : '') . ($userId ? ' · user: ' . ($users[$userId] ?? "#$userId") : '');
        if ($x = Table::export($req, 'audit-trail', 'Audit trail', $subtitle, $columns, $rows)) return $x;

        return view('admin/audit/index', [
            'title' => 'Audit Trail', 'columns' => $columns, 'rows' => $rows, 'from' => $from, 'to' => $to,
            'action' => $action, 'actions' => array_column($actions, 'action', 'action'), 'userId' => $userId ?: '', 'users' => $users,
            'limited' => count($rows) >= self::LIMIT,
        ]);
    }

    /** One-line text of the JSON details: {"a":1,"b":"x"} -> "a: 1, b: x". */
    private static function compact(?string $details): string
    {
        if ($details === null || $details === '') return '';
        $v = json_decode($details, true);
        if ($v === null && json_last_error() !== JSON_ERROR_NONE) return $details;
        if (!is_array($v)) return is_string($v) ? $v : json_encode($v);
        if (array_is_list($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        $parts = [];
        foreach ($v as $k => $x) $parts[] = "$k: " . (is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : (is_bool($x) ? ($x ? 'true' : 'false') : $x));
        return implode(', ', $parts);
    }
}
