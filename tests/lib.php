<?php
/**
 * Tiny test helpers.
 *   test('sells an item', function () { ...; eq(2, $x); ok($cond, 'message'); throws(fn () => ..., 'not balanced'); });
 * Tests share one database and run in file order, so later tests can rely on earlier data.
 */
use App\Core\Auth;
use App\Core\DB;

class T
{
    public static int $pass = 0;
    public static int $fail = 0;

    public static function summary(): int
    {
        echo "\n" . self::$pass . ' passed, ' . self::$fail . " failed\n";
        return self::$fail ? 1 : 0;
    }
}

function test(string $name, callable $fn): void
{
    try {
        $fn();
        T::$pass++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        T::$fail++;
        echo "  ✗ $name\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        // Leave no half-finished transaction behind for the next test
        if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
        DB::reconnect();
    }
}

function eq($expected, $actual, string $msg = ''): void
{
    if (is_float($expected) || is_float($actual) || (is_numeric($expected) && is_numeric($actual))) {
        if (abs((float) $expected - (float) $actual) > 0.0001) throw new RuntimeException("$msg expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        return;
    }
    if ($expected !== $actual) throw new RuntimeException("$msg expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function ok($cond, string $msg = 'assertion failed'): void
{
    if (!$cond) throw new RuntimeException($msg);
}

/** Assert that $fn throws and (optionally) that the message contains $contains. */
function throws(callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($contains && stripos($e->getMessage(), $contains) === false) throw new RuntimeException("Expected error containing '$contains', got: " . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected an exception' . ($contains ? " containing '$contains'" : ''));
}

/** Act as a user by username for the next calls (permissions are checked against this user). */
function as_user(string $username): void
{
    Auth::actAs((int) DB::value('SELECT id FROM users WHERE username = ?', [$username]));
}

/** Total debits and credits of the whole ledger must always match. */
function assert_books_balance(): void
{
    $r = DB::one('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines');
    eq(round((float) $r['d'], 2), round((float) $r['c'], 2), 'Ledger out of balance:');
}

function item_id(string $namePrefix): int
{
    return (int) DB::value('SELECT id FROM items WHERE name LIKE ? ORDER BY id LIMIT 1', [$namePrefix . '%']);
}

function stock(string $namePrefix): float
{
    return (float) DB::value('SELECT stock_qty FROM items WHERE name LIKE ? ORDER BY id LIMIT 1', [$namePrefix . '%']);
}

/** Balance (debit - credit) of an account by system_key. */
function acct_balance(string $key): float
{
    return App\Services\Ledger::balance(App\Services\Ledger::account($key));
}
