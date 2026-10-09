<?php
/**
 * Global helper functions available everywhere (controllers, services and views).
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\View;

// ------------------------------------------------------------------ config & urls
function config(string $key, $default = null)
{
    $value = $GLOBALS['__config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

/** Absolute path inside the app, respecting base_path (e.g. when installed in /mark5). */
function url(string $path = '/', array $query = []): string
{
    if (preg_match('#^https?://#', $path)) return $path;
    $base = rtrim(config('app.base_path', ''), '/');
    $q = $query ? '?' . http_build_query($query) : '';
    return $base . '/' . ltrim($path, '/') . $q;
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return url('/assets/' . ltrim($path, '/')) . '?v=' . $v;
}

/** Current URL with some query parameters replaced (used by export links and filters). */
function current_url(array $merge = []): string
{
    $req = $GLOBALS['__request'] ?? null;
    $query = array_merge($_GET, $merge);
    $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
    return url($req ? $req->path : '/', $query);
}

// ------------------------------------------------------------------ responses
function view(string $template, array $data = [], ?string $layout = 'app'): string
{
    return View::render($template, $data, $layout);
}

function redirect(string $to): Response
{
    return Response::redirect($to);
}

function back(): Response
{
    return new Response('', 302, ['Location' => $_SERVER['HTTP_REFERER'] ?? url('/')]);
}

/**
 * Run a bulk action from a table's checkboxes (ids[] in the request): $fn(int $id) for each id, where $fn returns
 * what happened ('deleted', 'deactivated' ...). One row failing does not stop the others. Flashes a summary like
 * "3 items deleted, 1 deactivated. Could not delete “Rice”: ...". Returns the per-outcome counts.
 */
function bulk_apply(array $ids, string $noun, callable $fn): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw App\Core\HttpException::bad('Select at least one row');
    $counts = [];
    $errors = [];
    foreach ($ids as $id) {
        try {
            $what = App\Core\DB::transaction(fn () => $fn($id)) ?: 'done';
            $counts[$what] = ($counts[$what] ?? 0) + 1;
        } catch (App\Core\HttpException | \RuntimeException | \PDOException $e) {
            $errors[] = $e instanceof \PDOException ? "#$id is still in use" : $e->getMessage();
        }
    }
    $parts = [];
    foreach ($counts as $what => $n) $parts[] = "$n " . ($n === 1 ? $noun : $noun . 's') . " $what";
    if ($parts) flash('success', implode(', ', $parts) . '.');
    if ($errors) flash('error', count($errors) . ' could not be changed: ' . implode(' · ', array_slice(array_unique($errors), 0, 5)));
    return $counts;
}

function json($data, int $status = 200): Response
{
    return Response::json($data, $status);
}

function flash(string $type, ?string $message = null)
{
    if ($message === null) {
        $m = $_SESSION['flash'][$type] ?? null;
        unset($_SESSION['flash'][$type]);
        return $m;
    }
    $_SESSION['flash'][$type] = $message;
    return null;
}

/** Previously submitted value (after a validation error) or the default. */
function old(string $key, $default = '')
{
    return $GLOBALS['__old'][$key] ?? $default;
}

// ------------------------------------------------------------------ html
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function csrf_token(): string
{
    return Csrf::token();
}

function can(string ...$perms): bool
{
    return Auth::can(...$perms);
}

function user(): ?array
{
    return Auth::user();
}

/** <option> list. $options = [value => label] */
function options(array $options, $selected = null, ?string $placeholder = null): string
{
    $html = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($options as $value => $label) {
        $sel = (string) $value === (string) $selected ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

const BADGE_COLORS = [
    'posted' => 'green', 'paid' => 'green', 'settled' => 'green', 'OK' => 'green', 'active' => 'green',
    'approved' => 'blue', 'open' => 'amber', 'partial' => 'amber', 'pending' => 'amber', 'REORDER' => 'amber',
    'void' => 'red', 'cancelled' => 'red', 'rejected' => 'red', 'NEGATIVE' => 'red', 'inactive' => 'red',
    'draft' => 'gray', 'closed' => 'gray',
];

function badge(?string $text, ?string $color = null): string
{
    if ($text === null || $text === '') return '';
    $color = $color ?? (BADGE_COLORS[$text] ?? 'gray');
    return '<span class="badge badge-' . e($color) . '">' . e($text) . '</span>';
}

// ------------------------------------------------------------------ numbers & dates
function r2($n): float
{
    return round((float) $n + 0.0, 2);
}

function r4($n): float
{
    return round((float) $n, 4);
}

function num($n, float $default = 0): float
{
    return is_numeric($n) ? (float) $n : $default;
}

function money($n): string
{
    return $n === null || $n === '' ? '' : number_format((float) $n, 2);
}

function peso($n): string
{
    return $n === null || $n === '' ? '' : '₱' . number_format((float) $n, 2);
}

function qty($n): string
{
    if ($n === null || $n === '') return '';
    $s = number_format((float) $n, 4);
    return rtrim(rtrim($s, '0'), '.');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function is_date($s): bool
{
    return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
}

function add_days(string $date, int $days): string
{
    return date('Y-m-d', strtotime("$date $days day"));
}

function fmt_date(?string $s): string
{
    return $s ? date('M j, Y', strtotime($s)) : '';
}

function fmt_datetime(?string $s): string
{
    return $s ? date('M j, Y g:i A', strtotime($s)) : '';
}

/** Date range from ?from=&to= with "this month" as the default. */
function date_range(?\App\Core\Request $req = null, string $default = 'month'): array
{
    $from = $req ? $req->query('from') : ($_GET['from'] ?? null);
    $to = $req ? $req->query('to') : ($_GET['to'] ?? null);
    $to = is_date($to) ? $to : today();
    $from = is_date($from) ? $from : ($default === 'today' ? today() : date('Y-m-01'));
    if ($from > $to) [$from, $to] = [$to, $from];
    return [$from, $to];
}

function range_label(string $from, string $to): string
{
    return $from === $to ? fmt_date($from) : fmt_date($from) . ' – ' . fmt_date($to);
}

// ------------------------------------------------------------------ validation
/** Throw a 400 error unless every listed field has a value. */
function required(array $data, string ...$fields): void
{
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '' || $data[$f] === null) {
            throw HttpException::bad(ucfirst(str_replace('_', ' ', $f)) . ' is required');
        }
    }
}

/** Keep only the listed keys; empty strings become null. */
function pick(array $data, array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $data)) $out[$k] = $data[$k] === '' ? null : $data[$k];
    }
    return $out;
}
