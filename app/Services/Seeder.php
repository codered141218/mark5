<?php
namespace App\Services;

use App\Core\DB;

/** Creates the default data for a new installation. Safe to run more than once. */
class Seeder
{
    public static function base(string $adminPassword = 'admin123', string $adminPin = '1234'): void
    {
        DB::transaction(function () use ($adminPassword, $adminPin) {
            foreach (Settings::DEFAULTS as $k => $v) {
                if (DB::value('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$k]) == 0) Settings::set($k, $v);
            }
            Ledger::seedAccounts();

            foreach (Permissions::defaultRoles() as $r) {
                if (!DB::value('SELECT id FROM roles WHERE name = ?', [$r['name']])) {
                    DB::insert('roles', ['name' => $r['name'], 'description' => $r['description'],
                        'permissions' => json_encode($r['permissions']), 'is_system' => $r['is_system'] ?? 0]);
                }
            }
            if (!DB::value('SELECT id FROM users LIMIT 1')) {
                DB::insert('users', [
                    'username' => 'admin', 'full_name' => 'System Administrator',
                    'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT), 'pin_hash' => password_hash($adminPin, PASSWORD_DEFAULT),
                    'role_id' => (int) DB::value("SELECT id FROM roles WHERE name = 'Administrator'"), 'active' => 1, 'created_at' => now(),
                ]);
            }

            $uoms = [['Piece', 'pc'], ['Kilogram', 'kg'], ['Gram', 'g'], ['Liter', 'L'], ['Milliliter', 'ml'], ['Pack', 'pack'],
                ['Bottle', 'btl'], ['Can', 'can'], ['Case', 'case'], ['Sack', 'sack'], ['Tray', 'tray'], ['Box', 'box'],
                ['Gallon', 'gal'], ['Serving', 'srv'], ['Dozen', 'doz']];
            foreach ($uoms as [$name, $abbr]) {
                if (!DB::value('SELECT id FROM uoms WHERE abbr = ?', [$abbr])) DB::insert('uoms', ['name' => $name, 'abbr' => $abbr]);
            }
            foreach ([['kg', 'g', 1000], ['L', 'ml', 1000], ['gal', 'L', 3.785], ['doz', 'pc', 12]] as [$f, $t, $factor]) {
                $from = self::uom($f); $to = self::uom($t);
                if (!DB::value('SELECT id FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', [$from, $to])) {
                    DB::insert('uom_conversions', ['from_uom_id' => $from, 'to_uom_id' => $to, 'factor' => $factor]);
                }
            }
        });
    }

    private static function uom(string $abbr): int
    {
        return (int) DB::value('SELECT id FROM uoms WHERE abbr = ?', [$abbr]);
    }

    /** Sample Filipino menu with recipes so a new install has something to explore. */
    public static function sample(): void
    {
        if (DB::value('SELECT id FROM items LIMIT 1')) return;
        DB::transaction(function () {
            $u = fn ($a) => self::uom($a);
            $cat = fn ($name, $kind, $color, $sort) => DB::insert('categories', ['name' => $name, 'kind' => $kind, 'color' => $color, 'sort_order' => $sort, 'active' => 1]);
            $cats = [
                'rice' => $cat('Rice Meals', 'menu', '#e8590c', 1),
                'ulam' => $cat('Ulam / Viands', 'menu', '#c2255c', 2),
                'noodles' => $cat('Noodles', 'menu', '#f59f00', 3),
                'drinks' => $cat('Beverages', 'menu', '#1c7ed6', 4),
                'dessert' => $cat('Desserts', 'menu', '#7048e8', 5),
                'meat' => $cat('Meat & Poultry', 'inventory', '#868e96', 10),
                'dry' => $cat('Dry Goods & Condiments', 'inventory', '#868e96', 11),
                'produce' => $cat('Produce', 'inventory', '#868e96', 12),
            ];
            $n = 1;
            $item = function (array $o) use (&$n, $u) {
                return DB::insert('items', [
                    'sku' => sprintf('IT%04d', $n++), 'name' => $o['name'], 'category_id' => $o['cat'], 'item_type' => $o['type'],
                    'base_uom_id' => $u($o['uom']), 'price' => $o['price'] ?? 0, 'avg_cost' => $o['cost'] ?? 0, 'last_cost' => $o['cost'] ?? 0,
                    'reorder_point' => $o['rop'] ?? 0, 'reorder_qty' => $o['roq'] ?? 0, 'sellable' => !empty($o['sellable']) ? 1 : 0,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            };
            $raw = fn ($name, $c, $uom, $cost, $rop, $roq) => $item(['name' => $name, 'cat' => $c, 'type' => 'raw', 'uom' => $uom, 'cost' => $cost, 'rop' => $rop, 'roq' => $roq]);
            $R = [
                'chicken' => $raw('Chicken (whole cut)', $cats['meat'], 'kg', 190, 5, 10),
                'pork' => $raw('Pork Liempo', $cats['meat'], 'kg', 320, 5, 10),
                'porkface' => $raw('Pork Maskara (for Sisig)', $cats['meat'], 'kg', 180, 3, 5),
                'rice' => $raw('Rice (Dinorado)', $cats['dry'], 'kg', 55, 25, 50),
                'soy' => $raw('Soy Sauce', $cats['dry'], 'L', 60, 2, 4),
                'vinegar' => $raw('Cane Vinegar', $cats['dry'], 'L', 45, 2, 4),
                'garlic' => $raw('Garlic', $cats['produce'], 'kg', 140, 1, 2),
                'onion' => $raw('Red Onion', $cats['produce'], 'kg', 160, 1, 2),
                'oil' => $raw('Cooking Oil', $cats['dry'], 'L', 95, 3, 6),
                'sinigangmix' => $raw('Sinigang Mix', $cats['dry'], 'pack', 22, 10, 24),
                'kangkong' => $raw('Kangkong', $cats['produce'], 'kg', 60, 1, 3),
                'canton' => $raw('Pancit Canton Noodles', $cats['dry'], 'pack', 48, 5, 10),
                'egg' => $raw('Egg', $cats['produce'], 'pc', 8, 30, 60),
                'calamansi' => $raw('Calamansi', $cats['produce'], 'kg', 80, 1, 2),
                'sugar' => $raw('Sugar', $cats['dry'], 'kg', 75, 2, 5),
                'icedteamix' => $raw('Iced Tea Powder', $cats['dry'], 'pack', 35, 3, 6),
                'halo' => $raw('Halo-halo Mix-ins', $cats['dry'], 'kg', 250, 1, 2),
                'milk' => $raw('Evaporated Milk', $cats['dry'], 'can', 32, 6, 12),
            ];
            // Purchase units: 1 sack of rice = 50 kg, 1 tray of eggs = 30 pcs ...
            foreach ([['rice', 'sack', 50], ['egg', 'tray', 30], ['oil', 'gal', 3.785], ['milk', 'case', 48]] as [$k, $uom, $f]) {
                DB::insert('item_uoms', ['item_id' => $R[$k], 'uom_id' => $u($uom), 'factor' => $f]);
            }
            foreach ([['Coke in Can', 'can', 65, 38], ['San Miguel Pale Pilsen', 'btl', 85, 52], ['Bottled Water', 'btl', 30, 12]] as [$name, $uom, $price, $cost]) {
                $id = $item(['name' => $name, 'cat' => $cats['drinks'], 'type' => 'retail', 'uom' => $uom, 'price' => $price, 'cost' => $cost, 'rop' => 24, 'roq' => 48, 'sellable' => true]);
                DB::insert('item_uoms', ['item_id' => $id, 'uom_id' => $u('case'), 'factor' => 24]);
            }
            $menu = fn ($name, $c, $price) => $item(['name' => $name, 'cat' => $c, 'type' => 'composite', 'uom' => 'srv', 'price' => $price, 'sellable' => true]);
            $comp = fn ($parent, $child, $qty, $uom) => DB::insert('item_components', ['parent_id' => $parent, 'component_id' => $child, 'qty' => $qty, 'uom_id' => $u($uom)]);

            $plainRice = $menu('Plain Rice', $cats['rice'], 25);
            $comp($plainRice, $R['rice'], 120, 'g');
            $garlicRice = $menu('Garlic Rice', $cats['rice'], 40);
            $comp($garlicRice, $R['rice'], 120, 'g'); $comp($garlicRice, $R['garlic'], 10, 'g'); $comp($garlicRice, $R['oil'], 10, 'ml');
            $adobo = $menu('Chicken Adobo', $cats['ulam'], 185);
            $comp($adobo, $R['chicken'], 250, 'g'); $comp($adobo, $R['soy'], 40, 'ml'); $comp($adobo, $R['vinegar'], 30, 'ml'); $comp($adobo, $R['garlic'], 15, 'g'); $comp($adobo, $R['oil'], 15, 'ml');
            $sinigang = $menu('Sinigang na Baboy', $cats['ulam'], 265);
            $comp($sinigang, $R['pork'], 250, 'g'); $comp($sinigang, $R['sinigangmix'], 1, 'pack'); $comp($sinigang, $R['kangkong'], 100, 'g'); $comp($sinigang, $R['onion'], 30, 'g');
            $sisig = $menu('Sizzling Pork Sisig', $cats['ulam'], 225);
            $comp($sisig, $R['porkface'], 200, 'g'); $comp($sisig, $R['onion'], 40, 'g'); $comp($sisig, $R['calamansi'], 20, 'g'); $comp($sisig, $R['egg'], 1, 'pc'); $comp($sisig, $R['oil'], 10, 'ml');
            $adoboMeal = $menu('Adobo Rice Meal', $cats['rice'], 199);
            $comp($adoboMeal, $adobo, 1, 'srv'); $comp($adoboMeal, $plainRice, 1, 'srv');
            $sisigMeal = $menu('Sisig Rice Meal', $cats['rice'], 219);
            $comp($sisigMeal, $sisig, 1, 'srv'); $comp($sisigMeal, $garlicRice, 1, 'srv');
            $canton = $menu('Pancit Canton (Good for 3)', $cats['noodles'], 280);
            $comp($canton, $R['canton'], 1, 'pack'); $comp($canton, $R['chicken'], 150, 'g'); $comp($canton, $R['soy'], 30, 'ml'); $comp($canton, $R['onion'], 30, 'g'); $comp($canton, $R['garlic'], 10, 'g');
            $icedtea = $menu('House Iced Tea', $cats['drinks'], 55);
            $comp($icedtea, $R['icedteamix'], 0.1, 'pack'); $comp($icedtea, $R['sugar'], 15, 'g'); $comp($icedtea, $R['calamansi'], 10, 'g');
            $halohalo = $menu('Halo-halo Special', $cats['dessert'], 140);
            $comp($halohalo, $R['halo'], 120, 'g'); $comp($halohalo, $R['milk'], 0.25, 'can'); $comp($halohalo, $R['sugar'], 15, 'g');
            $extraEgg = $menu('Extra Egg', $cats['ulam'], 20);
            $comp($extraEgg, $R['egg'], 1, 'pc');
            $item(['name' => 'Corkage Fee', 'cat' => $cats['drinks'], 'type' => 'non_inventory', 'uom' => 'pc', 'price' => 150, 'sellable' => true]);
            // keep the SKU sequence ahead of the sample SKUs
            DB::run("INSERT INTO sequences (name, prefix, next_no, pad) VALUES ('SKU','SKU',1,5) ON DUPLICATE KEY UPDATE name = name");

            DB::insert('suppliers', ['name' => 'Metro Meat Supply', 'contact_person' => 'Mang Jun', 'phone' => '0917-000-0000', 'terms_days' => 15, 'active' => 1]);
            DB::insert('suppliers', ['name' => 'Divisoria Dry Goods Trading', 'phone' => '0918-000-0000', 'terms_days' => 0, 'active' => 1]);
            DB::insert('customers', ['name' => 'ABC Corporation (Charge Account)', 'terms_days' => 30, 'credit_limit' => 50000, 'active' => 1]);
            DB::insert('employees', ['emp_no' => Sequence::next('EMP', 'EMP', 3), 'full_name' => 'Juan Dela Cruz', 'position' => 'Cook', 'department' => 'Kitchen', 'active' => 1, 'date_hired' => '2025-01-15']);
            DB::insert('employees', ['emp_no' => Sequence::next('EMP', 'EMP', 3), 'full_name' => 'Maria Santos', 'position' => 'Cashier', 'department' => 'Front of House', 'active' => 1, 'date_hired' => '2025-03-01']);
        });
    }
}
