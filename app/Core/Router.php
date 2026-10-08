<?php
namespace App\Core;

/**
 * Minimal router.
 *
 *   $router->get('/inventory/items', [ItemController::class, 'index'], 'inventory.view');
 *   $router->post('/inventory/items/{id}', [ItemController::class, 'update'], ['inventory.manage']);
 *   $router->get('/login', [AuthController::class, 'loginForm'], Router::PUBLIC);
 *
 * Third argument = required permission(s): the user needs ANY of them.
 * null means "any logged-in user"; Router::PUBLIC means no login needed.
 * Controller methods receive (Request $req, ...$routeParams) and return a string (HTML) or a Response.
 */
class Router
{
    public const PUBLIC = '__public__';
    private array $routes = [];

    public function get(string $path, $handler, $perms = null): void
    {
        $this->add('GET', $path, $handler, $perms);
    }

    public function post(string $path, $handler, $perms = null): void
    {
        $this->add('POST', $path, $handler, $perms);
    }

    private function add(string $method, string $path, $handler, $perms): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($path, '/') ?: '/') . '$#';
        $this->routes[] = compact('method', 'path', 'regex', 'handler', 'perms');
    }

    public function dispatch(Request $req): Response
    {
        try {
            foreach ($this->routes as $r) {
                if ($r['method'] !== $req->method || !preg_match($r['regex'], $req->path, $m)) continue;
                if ($r['perms'] !== self::PUBLIC) {
                    if (!Auth::check()) {
                        if ($req->wantsJson()) throw new HttpException(401, 'Your session has expired. Please log in again.');
                        $_SESSION['intended'] = $req->path;
                        return Response::redirect('/login');
                    }
                    if ($r['perms'] !== null && !Auth::can(...(array) $r['perms'])) throw HttpException::forbidden();
                }
                if ($req->method !== 'GET') Csrf::verify($req);
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                [$class, $method] = $r['handler'];
                $out = (new $class())->$method($req, ...array_values($params));
                return $out instanceof Response ? $out : new Response((string) $out);
            }
            throw HttpException::notFound('Page');
        } catch (HttpException $e) {
            return $this->handleError($req, $e->status, $e->getMessage());
        } catch (\PDOException $e) {
            error_log((string) $e);
            $msg = config('app.debug') ? $e->getMessage() : 'Database error. Please try again or contact the administrator.';
            if (($e->errorInfo[1] ?? 0) === 1062) $msg = 'That record already exists (duplicate value).';
            if (($e->errorInfo[1] ?? 0) === 1451) $msg = 'This record is still used by other records and cannot be deleted.';
            return $this->handleError($req, 500, $msg);
        }
    }

    private function handleError(Request $req, int $status, string $message): Response
    {
        if ($req->wantsJson()) return Response::json(['error' => $message], $status);
        if ($req->method === 'POST') {
            // Send the user back to the form with the message and what they typed.
            flash('error', $message);
            $_SESSION['old'] = $req->all();
            $back = $_SERVER['HTTP_REFERER'] ?? url('/');
            return new Response('', 302, ['Location' => $back]);
        }
        $html = View::render('error', ['status' => $status, 'message' => $message], Auth::check() ? 'app' : 'blank');
        return new Response($html, $status);
    }
}
