<?php
namespace App\Services;

use App\Core\DB;

/** Business settings stored as key/value pairs in the `settings` table. */
class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'business_name' => 'My Restaurant',
        'business_address' => 'Manila, Philippines',
        'business_tin' => '000-000-000-00000',
        'business_phone' => '',
        'receipt_title' => 'ORDER RECEIPT',
        'receipt_footer' => 'Thank you, please come again!',
        'receipt_prefix' => 'OR',
        'vat_registered' => '1',
        'vat_rate' => '12',
        'sc_discount_rate' => '20',
        'service_charge_rate' => '0',
        'service_charge_dine_in_only' => '1',
        'require_payment_ref' => '0',
        'auto_backup' => '1',
        'backup_retention' => '30',
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (DB::all('SELECT `key`, `value` FROM settings') as $r) self::$cache[$r['key']] = $r['value'];
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        DB::run('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value === null ? null : (string) $value]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** VAT / discount configuration used by the POS and inventory costing. */
    public static function tax(): array
    {
        $vatRegistered = self::get('vat_registered', '1') === '1';
        return [
            'vatRegistered' => $vatRegistered,
            'vatRate' => $vatRegistered ? (float) self::get('vat_rate', 12) / 100 : 0.0,
            'scRate' => (float) self::get('sc_discount_rate', 20) / 100,
            'svcRate' => (float) self::get('service_charge_rate', 0) / 100,
            'svcDineInOnly' => self::get('service_charge_dine_in_only', '1') === '1',
        ];
    }
}
