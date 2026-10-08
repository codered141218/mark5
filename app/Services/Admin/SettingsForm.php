<?php
namespace App\Services\Admin;

use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Settings;

/**
 * The Settings page: which keys live on which tab, and how they are validated before Settings::set().
 * Checkbox settings are stored as '1' / '0'.
 */
class SettingsForm
{
    public const TABS = [
        'business' => ['label' => 'Business & receipt', 'keys' => ['business_name', 'business_address', 'business_tin', 'business_phone', 'receipt_title', 'receipt_footer', 'receipt_prefix']],
        'tax' => ['label' => 'Tax & charges', 'keys' => ['vat_registered', 'vat_rate', 'sc_discount_rate', 'service_charge_rate', 'service_charge_dine_in_only', 'require_payment_ref']],
        'backup' => ['label' => 'Backups', 'keys' => ['auto_backup', 'backup_retention']],
    ];
    public const BOOLEANS = ['vat_registered', 'service_charge_dine_in_only', 'require_payment_ref', 'auto_backup'];
    private const RATES = ['vat_rate' => 'VAT rate', 'sc_discount_rate' => 'Senior Citizen / PWD discount', 'service_charge_rate' => 'Service charge'];

    /** Validate and save the fields of one tab. Returns the values that changed. */
    public static function save(string $tab, array $input): array
    {
        if (!isset(self::TABS[$tab])) throw HttpException::bad('Unknown settings tab');
        $current = Settings::all();
        $values = [];
        foreach (self::TABS[$tab]['keys'] as $key) {
            if (in_array($key, self::BOOLEANS, true)) $values[$key] = empty($input[$key]) ? '0' : '1'; // unticked checkboxes are not sent
            else $values[$key] = trim((string) ($input[$key] ?? $current[$key] ?? ''));
        }
        foreach (self::RATES as $key => $label) {
            if (!isset($values[$key])) continue;
            $v = $values[$key];
            if (!is_numeric($v) || $v < 0 || $v > 100) throw HttpException::bad("$label must be a number from 0 to 100");
            $values[$key] = (string) (float) $v;
        }
        if (isset($values['backup_retention'])) {
            if (!ctype_digit($values['backup_retention']) || (int) $values['backup_retention'] < 1) throw HttpException::bad('Keep at least 1 automatic backup');
        }
        if (isset($values['receipt_prefix']) && mb_strlen($values['receipt_prefix']) > 10) throw HttpException::bad('Receipt prefix can be at most 10 characters');

        $changed = array_filter($values, fn ($v, $k) => (string) ($current[$k] ?? '') !== $v, ARRAY_FILTER_USE_BOTH);
        foreach ($changed as $k => $v) Settings::set($k, $v);
        if ($changed) Audit::log('update', 'settings', null, $changed);
        return $changed;
    }
}
