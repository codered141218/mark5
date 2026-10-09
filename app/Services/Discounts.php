<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Discount presets the cashier picks from at the POS (managed under Administration → Discounts).
 *
 *  kind   sc / pwd   statutory Senior Citizen / PWD: VAT-exempt + the rate in Settings (default 20%)
 *         percent    e.g. Employee 10%, Complimentary 100%
 *         amount     fixed peso amount off
 *  value  the % or ₱ amount; NULL = "open" discount, the cashier types the value
 *  scope  both | order (whole receipt only) | item (single items only)
 *  requires_approval  1 = needs pos.discount permission or a manager PIN
 */
class Discounts
{
    public const KINDS = ['sc' => 'Senior Citizen (VAT-exempt)', 'pwd' => 'PWD (VAT-exempt)', 'percent' => 'Percent (%)', 'amount' => 'Fixed amount (₱)'];
    public const SCOPES = ['both' => 'Whole receipt or single items', 'order' => 'Whole receipt only', 'item' => 'Single items only'];

    public static function seedDefaults(): void
    {
        if (DB::value('SELECT COUNT(*) FROM discounts') > 0) return;
        $defaults = [
            ['Senior Citizen', 'sc', null, 'both', 1],
            ['PWD', 'pwd', null, 'both', 1],
            ['Employee 10%', 'percent', 10, 'both', 1],
            ['Promo 5%', 'percent', 5, 'both', 1],
            ['Complimentary (free)', 'percent', 100, 'item', 1],
            ['Open discount %', 'percent', null, 'both', 1],
            ['Open discount ₱', 'amount', null, 'both', 1],
        ];
        foreach ($defaults as $i => [$name, $kind, $value, $scope, $approval]) {
            DB::insert('discounts', ['name' => $name, 'kind' => $kind, 'value' => $value, 'scope' => $scope,
                'requires_approval' => $approval, 'active' => 1, 'sort_order' => $i + 1]);
        }
    }

    public static function all(bool $activeOnly = false): array
    {
        return array_map([self::class, 'cast'], DB::all(
            'SELECT * FROM discounts' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name'
        ));
    }

    public static function find(int $id): array
    {
        $d = DB::one('SELECT * FROM discounts WHERE id = ?', [$id]);
        if (!$d) throw HttpException::notFound('Discount');
        return self::cast($d);
    }

    private static function cast(array $d): array
    {
        $d['id'] = (int) $d['id'];
        $d['value'] = $d['value'] === null ? null : (float) $d['value'];
        $d['requires_approval'] = (int) $d['requires_approval'];
        $d['active'] = (int) $d['active'];
        return $d;
    }

    public static function save(array $data): int
    {
        required($data, 'name', 'kind');
        if (!isset(self::KINDS[$data['kind']])) throw HttpException::bad('Choose the discount type');
        $scope = isset(self::SCOPES[$data['scope'] ?? '']) ? $data['scope'] : 'both';
        $value = ($data['value'] ?? '') === '' || in_array($data['kind'], ['sc', 'pwd'], true) ? null : (float) $data['value'];
        if ($value !== null && $value <= 0) throw HttpException::bad('Value must be greater than zero (leave it blank for an open discount)');
        if ($data['kind'] === 'percent' && $value !== null && $value > 100) throw HttpException::bad('A percent discount cannot exceed 100%');
        $row = [
            'name' => trim($data['name']), 'kind' => $data['kind'], 'value' => $value, 'scope' => $scope,
            'requires_approval' => !empty($data['requires_approval']) ? 1 : 0, 'active' => !empty($data['active']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
        $id = (int) ($data['id'] ?? 0);
        $isNew = $id === 0;
        if ($isNew) $id = DB::insert('discounts', $row);
        else DB::update('discounts', $id, $row);
        Audit::log($isNew ? 'create' : 'update', 'discount', $id, $row);
        return $id;
    }

    public static function delete(int $id): void
    {
        $used = DB::value('SELECT COUNT(*) FROM tickets WHERE discount_id = ?', [$id]) + DB::value('SELECT COUNT(*) FROM ticket_items WHERE discount_id = ?', [$id]);
        if ($used) {
            DB::update('discounts', $id, ['active' => 0]);
        } else {
            DB::run('DELETE FROM discounts WHERE id = ?', [$id]);
        }
        Audit::log($used ? 'deactivate' : 'delete', 'discount', $id);
    }

    /**
     * Resolve what the cashier picked: a preset id (+ value for open presets), or a plain kind/value.
     * Returns ['id', 'name', 'kind', 'value', 'requires_approval'] or null for "no discount".
     */
    public static function resolve(array $input, string $scope): ?array
    {
        if (!empty($input['discount_id'])) {
            $d = self::find((int) $input['discount_id']);
            if (!$d['active']) throw HttpException::bad("The discount \"{$d['name']}\" is no longer active");
            if ($d['scope'] !== 'both' && $d['scope'] !== $scope) {
                throw HttpException::bad("\"{$d['name']}\" can only be applied to " . ($d['scope'] === 'item' ? 'single items' : 'the whole receipt'));
            }
            $value = $d['value'] ?? (float) ($input['value'] ?? $input['discount_rate'] ?? 0);
            return ['id' => $d['id'], 'name' => $d['name'], 'kind' => $d['kind'], 'value' => $value, 'requires_approval' => $d['requires_approval']];
        }
        $kind = $input['discount_type'] ?? $input['kind'] ?? 'none';
        if ($kind === 'none' || $kind === '' || $kind === null) return null;
        if (!isset(self::KINDS[$kind])) throw HttpException::bad('Invalid discount type');
        $value = (float) ($input['discount_rate'] ?? $input['value'] ?? 0);
        $names = ['sc' => 'Senior Citizen', 'pwd' => 'PWD', 'percent' => rtrim(rtrim(number_format($value, 2), '0'), '.') . '% discount', 'amount' => 'Discount ₱' . number_format($value, 2)];
        return ['id' => null, 'name' => $names[$kind], 'kind' => $kind, 'value' => $value, 'requires_approval' => 1];
    }
}
