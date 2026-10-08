<?php
namespace App\Core;

/**
 * Renders PHP templates from /views.
 *   View::render('inventory/items/index', ['items' => $items]);          // inside the main layout
 *   View::render('pos/index', $data, 'blank');                           // no sidebar
 * Inside a template, every array key becomes a variable ($items).
 * The layout receives the page HTML as $content plus $title.
 */
class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) return $content;
        return self::partial('layouts/' . $layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/views/' . $template . '.php';
        if (!is_file($file)) throw new \RuntimeException("View not found: $template");
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }
}
