<?php
namespace App\Services\Admin;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Discounts;
use App\Services\GlSetup;
use App\Services\Ledger;
use App\Services\Positions;
use App\Services\Seeder;
use App\Services\Settings;
use App\Services\Stations;

/**
 * First-time setup wizard (/setup): business details, taxes, chart of accounts, GL accounts, units, prep stations,
 * categories and POS options, one screen each. Shown to administrators until finished or skipped
 * (settings.setup_done = '0' until then); it can be run again any time from Settings.
 */
class SetupWizard
{
    public const STEPS = [
        'welcome' => 'Welcome',
        'business' => 'Business & receipt',
        'taxes' => 'Taxes & charges',
        'accounts' => 'Chart of accounts',
        'gl' => 'GL accounts',
        'units' => 'Units of measure',
        'stations' => 'Prep stations',
        'categories' => 'Categories',
        'pos' => 'POS, discounts & roles',
        'done' => 'Ready',
    ];

    public static function pending(): bool
    {
        return Settings::get('setup_done') === '0';
    }

    public static function next(string $step): string
    {
        $keys = array_keys(self::STEPS);
        return $keys[array_search($step, $keys, true) + 1] ?? 'done';
    }

    public static function prev(string $step): ?string
    {
        $keys = array_keys(self::STEPS);
        $i = array_search($step, $keys, true);
        return $i > 0 ? $keys[$i - 1] : null;
    }

    /** What is in the database now (shown on the welcome screen and the summary). */
    public static function counts(): array
    {
        $c = fn ($t, $w = '') => (int) DB::value("SELECT COUNT(*) FROM `$t`" . ($w ? " WHERE $w" : ''));
        return [
            'items' => $c('items'), 'categories' => $c('categories'), 'stations' => $c('prep_stations'), 'uoms' => $c('uoms'),
            'accounts' => $c('accounts'), 'sales' => $c('tickets', "status = 'paid'"), 'discounts' => $c('discounts'),
            'roles' => $c('roles'), 'users' => $c('users'), 'banks' => $c('bank_accounts'),
            'gl_missing' => count(array_filter(GlSetup::rows(), fn ($r) => !$r['account_id'])),
        ];
    }

    /** Save one step. */
    public static function save(string $step, array $in): void
    {
        switch ($step) {
            case 'business':
            case 'taxes':
                $tab = $step === 'business' ? 'business' : 'tax';
                SettingsForm::save($tab, $in);
                break;

            case 'accounts':
                $choice = $in['chart'] ?? 'keep';
                if ($choice === 'standard') { Ledger::seedAccounts(); Ledger::clearCache(); }
                break;

            case 'gl':
                GlSetup::save((array) ($in['gl'] ?? []));
                break;

            case 'units':
                if (!empty($in['standard_units'])) Seeder::units();
                foreach (self::lines($in['extra_units'] ?? '') as $line) {
                    [$name, $abbr] = array_map('trim', array_pad(preg_split('/\s*[=,–-]\s*|\s*\(\s*|\s*\)\s*/', $line, 3), 2, ''));
                    $abbr = $abbr !== '' ? $abbr : $name;
                    if (!DB::value('SELECT id FROM uoms WHERE abbr = ?', [$abbr])) DB::insert('uoms', ['name' => mb_substr($name, 0, 40), 'abbr' => mb_substr($abbr, 0, 12)]);
                }
                break;

            case 'stations':
                foreach (self::lines($in['stations'] ?? '') as $name) {
                    if (!DB::value('SELECT id FROM prep_stations WHERE LOWER(name) = LOWER(?)', [$name])) Stations::save(['name' => $name, 'active' => 1]);
                }
                break;

            case 'categories':
                foreach ((array) ($in['cat'] ?? []) as $c) {
                    $name = trim((string) ($c['name'] ?? ''));
                    if ($name === '' || DB::value('SELECT id FROM categories WHERE LOWER(name) = LOWER(?)', [$name])) continue;
                    $kind = in_array($c['kind'] ?? '', ['menu', 'inventory', 'both'], true) ? $c['kind'] : 'menu';
                    $station = (int) ($c['station_id'] ?? 0);
                    DB::insert('categories', ['name' => mb_substr($name, 0, 80), 'kind' => $kind, 'active' => 1,
                        'color' => preg_match('/^#[0-9a-f]{6}$/i', $c['color'] ?? '') ? $c['color'] : null,
                        'station_id' => $station && DB::value('SELECT id FROM prep_stations WHERE id = ?', [$station]) ? $station : null,
                        'sort_order' => Positions::next('categories')]);
                }
                break;

            case 'pos':
                SettingsForm::save('pos', $in);
                if (!empty($in['standard_discounts'])) Discounts::seedDefaults();
                if (!empty($in['standard_roles'])) Seeder::roles();
                break;

            case 'welcome':
            case 'done':
                break;

            default:
                throw HttpException::notFound('Setup step');
        }
        Audit::log('setup_wizard', 'settings', null, ['step' => $step]);
    }

    public static function finish(): void
    {
        Settings::set('setup_done', '1');
        Audit::log('setup_wizard', 'settings', null, ['finished' => true]);
    }

    public static function restart(): void
    {
        Settings::set('setup_done', '0');
    }

    /** Non-empty trimmed lines of a textarea. */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text)), fn ($l) => $l !== ''));
    }
}
