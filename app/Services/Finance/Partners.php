<?php
namespace App\Services\Finance;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;

/** Suppliers (accounts payable) and charge-account customers (accounts receivable). */
class Partners
{
    /** kind => table, document table and party column used for the outstanding balance. */
    private const KINDS = [
        'suppliers' => ['docs' => 'ap_bills', 'party' => 'supplier_id', 'fields' => ['name', 'contact_person', 'phone', 'email', 'address', 'tin', 'terms_days', 'notes']],
        'customers' => ['docs' => 'ar_invoices', 'party' => 'customer_id', 'fields' => ['name', 'contact_person', 'phone', 'email', 'address', 'tin', 'terms_days', 'credit_limit', 'notes']],
    ];

    /** Partners with their unpaid balance (open + partial documents). */
    public static function list(string $kind, bool $includeInactive = false): array
    {
        $k = self::kind($kind);
        return DB::all(
            "SELECT x.*, (SELECT COALESCE(SUM(d.amount - d.paid_amount), 0) FROM {$k['docs']} d
                          WHERE d.{$k['party']} = x.id AND d.status IN ('open','partial')) AS balance
             FROM $kind x" . ($includeInactive ? '' : ' WHERE x.active = 1') . ' ORDER BY x.name'
        );
    }

    /** [id => name] of active partners for selects. */
    public static function options(string $kind): array
    {
        self::kind($kind);
        return array_column(DB::all("SELECT id, name FROM $kind WHERE active = 1 ORDER BY name"), 'name', 'id');
    }

    /** Create ($d['id'] empty) or update a partner. Returns the id. */
    public static function save(string $kind, array $d): int
    {
        $k = self::kind($kind);
        required($d, 'name');
        $row = pick($d, $k['fields']);
        $row['name'] = trim($row['name']);
        foreach (['terms_days', 'credit_limit'] as $n) if (array_key_exists($n, $row)) $row[$n] = $n === 'terms_days' ? (int) num($row[$n]) : r2(num($row[$n]));
        if (($row['terms_days'] ?? 0) < 0 || ($row['credit_limit'] ?? 0) < 0) throw HttpException::bad('Terms and credit limit cannot be negative');
        $id = (int) ($d['id'] ?? 0);
        if ($id) {
            if (!DB::value("SELECT id FROM $kind WHERE id = ?", [$id])) throw HttpException::notFound(ucfirst(rtrim($kind, 's')));
            DB::update($kind, $id, $row);
            Audit::log('update', rtrim($kind, 's'), $id, $row);
        } else {
            $id = DB::insert($kind, $row + ['active' => 1]);
            Audit::log('create', rtrim($kind, 's'), $id, $row);
        }
        return $id;
    }

    /** Deactivate (hide from selections, keep history) or reactivate. */
    public static function setActive(string $kind, int $id, bool $active): void
    {
        self::kind($kind);
        DB::run("UPDATE $kind SET active = ? WHERE id = ?", [$active ? 1 : 0, $id]);
        Audit::log($active ? 'reactivate' : 'deactivate', rtrim($kind, 's'), $id);
    }

    private static function kind(string $kind): array
    {
        if (!isset(self::KINDS[$kind])) throw new \InvalidArgumentException("Unknown partner kind $kind");
        return self::KINDS[$kind];
    }
}
