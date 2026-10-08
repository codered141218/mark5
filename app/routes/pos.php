<?php
/** POS screen + its JSON API. */
use App\Controllers\Pos\PosApiController as Api;
use App\Controllers\Pos\PosController;

$router->get('/pos', [PosController::class, 'index'], 'pos.access');
$router->get('/printer', [PosController::class, 'printer'], ['pos.access', 'admin.settings']);

$router->get('/api/pos/state', [Api::class, 'state'], 'pos.access');
$router->get('/api/pos/menu', [Api::class, 'menu'], 'pos.access');
$router->get('/api/pos/orders', [Api::class, 'orders'], 'pos.access');
$router->post('/api/pos/orders', [Api::class, 'create'], 'pos.access');
$router->get('/api/pos/orders/{id}', [Api::class, 'show'], 'pos.access');
$router->post('/api/pos/orders/{id}', [Api::class, 'update'], 'pos.access');
$router->post('/api/pos/orders/{id}/table', [Api::class, 'table'], 'pos.access');
$router->post('/api/pos/orders/{id}/items', [Api::class, 'addItem'], 'pos.access');
$router->post('/api/pos/orders/{id}/items/{line}', [Api::class, 'updateLine'], 'pos.access');
$router->post('/api/pos/orders/{id}/items/{line}/void', [Api::class, 'voidLine'], 'pos.access');
$router->post('/api/pos/orders/{id}/send', [Api::class, 'send'], 'pos.access');
$router->post('/api/pos/orders/{id}/discount', [Api::class, 'discount'], 'pos.access');
$router->post('/api/pos/orders/{id}/split', [Api::class, 'split'], 'pos.split_move');
$router->post('/api/pos/orders/{id}/merge', [Api::class, 'merge'], 'pos.split_move');
$router->post('/api/pos/orders/{id}/pay', [Api::class, 'pay'], 'pos.settle');
$router->post('/api/pos/orders/{id}/void', [Api::class, 'void'], 'pos.access');
$router->post('/api/pos/orders/{id}/reprint', [Api::class, 'reprint'], 'pos.reprint');
$router->get('/api/pos/receipts', [Api::class, 'receipts'], 'pos.access');
$router->get('/api/pos/customers', [Api::class, 'customers'], 'pos.settle');

$router->post('/api/pos/day/open', [Api::class, 'openDay'], 'pos.open_day');
$router->get('/api/pos/day/xreading', [Api::class, 'xreading'], ['pos.xreading', 'pos.close_day']);
$router->post('/api/pos/day/close', [Api::class, 'closeDay'], 'pos.close_day');

$router->get('/api/pos/payouts', [Api::class, 'payouts'], 'pos.petty_cash');
$router->post('/api/pos/payouts', [Api::class, 'addPayout'], 'pos.petty_cash');
$router->post('/api/pos/payouts/{id}/void', [Api::class, 'voidPayout'], 'pos.petty_cash');
