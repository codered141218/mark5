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
        'tax' => ['label' => 'Tax & charges', 'keys' => ['vat_registered', 'prices_include_vat', 'vat_rate', 'sc_discount_rate', 'service_charge_rate', 'service_charge_dine_in_only', 'require_payment_ref']],
        'pos' => ['label' => 'POS', 'keys' => ['require_table_dine_in', 'pos_menu_sort', 'blind_count']],
        'backup' => ['label' => 'Backups', 'keys' => ['auto_backup', 'backup_retention']],
    ];
    public const BOOLEANS = ['vat_registered', 'service_charge_dine_in_only', 'require_payment_ref', 'auto_backup', 'require_table_dine_in', 'blind_count'];
    public const MENU_SORTS = ['custom' => 'My arrangement (Inventory → Arrange menu)', 'name' => 'Name A → Z', 'name_desc' => 'Name Z → A',
        'price' => 'Price low → high', 'price_desc' => 'Price high → low'];
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
        if (isset($values['prices_include_vat']) && !in_array($values['prices_include_vat'], ['0', '1'], true)) $values['prices_include_vat'] = '1';
        if (isset($values['pos_menu_sort']) && !isset(self::MENU_SORTS[$values['pos_menu_sort']])) throw HttpException::bad('Choose how the POS menu is sorted');
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
