<?php
/** Inventory module routes. Third argument = required permission(s). */
use App\Controllers\Inventory\CategoryController;
use App\Controllers\Inventory\CountController;
use App\Controllers\Inventory\DocController;
use App\Controllers\Inventory\ItemController;
use App\Controllers\Inventory\UomController;
use App\Services\InventoryDocs;

$router->get('/inventory/categories', [CategoryController::class, 'index'], 'inventory.manage');
$router->post('/inventory/categories', [CategoryController::class, 'save'], 'inventory.manage');
$router->post('/inventory/categories/{id}/delete', [CategoryController::class, 'delete'], 'inventory.manage');

// Items & recipes ('new' must come before '{id}')
$router->get('/inventory/items', [ItemController::class, 'index'], ['inventory.view', 'inventory.manage']);
$router->get('/inventory/items/new', [ItemController::class, 'create'], 'inventory.manage');
$router->post('/inventory/items', [ItemController::class, 'store'], 'inventory.manage');
$router->get('/inventory/items/{id}', [ItemController::class, 'show'], ['inventory.view', 'inventory.manage']);
$router->post('/inventory/items/{id}', [ItemController::class, 'update'], 'inventory.manage');
$router->post('/inventory/items/{id}/delete', [ItemController::class, 'delete'], 'inventory.manage');
$router->get('/api/inventory/items/{id}/units', [ItemController::class, 'units'],
    ['inventory.view', 'inventory.manage', 'inventory.receive', 'inventory.issue', 'inventory.waste', 'inventory.count']);

// Units of measure & global conversions
$router->get('/inventory/uom', [UomController::class, 'index'], 'inventory.manage');
$router->post('/inventory/uom', [UomController::class, 'save'], 'inventory.manage');
$router->post('/inventory/uom/{id}/delete', [UomController::class, 'delete'], 'inventory.manage');
$router->post('/inventory/uom/conversions', [UomController::class, 'saveConversion'], 'inventory.manage');
$router->post('/inventory/uom/conversions/{id}/delete', [UomController::class, 'deleteConversion'], 'inventory.manage');

// Receiving / issuance / wastage documents. Posting and voiding also need inventory.post (checked in the service).
foreach (DocController::SLUGS as $slug => $type) {
    $perm = InventoryDocs::PERMS[$type];
    $router->get("/inventory/$slug", [DocController::class, 'index'], $perm);
    $router->get("/inventory/$slug/new", [DocController::class, 'create'], $perm);
    $router->post("/inventory/$slug", [DocController::class, 'store'], $perm);
    $router->get("/inventory/$slug/{id}", [DocController::class, 'show'], $perm);
    $router->post("/inventory/$slug/{id}", [DocController::class, 'update'], $perm);
    $router->post("/inventory/$slug/{id}/post", [DocController::class, 'post'], $perm);
    $router->post("/inventory/$slug/{id}/void", [DocController::class, 'void'], $perm);
    $router->post("/inventory/$slug/{id}/delete", [DocController::class, 'delete'], $perm);
}

// Physical counts. Posting also needs inventory.post (checked in the service).
$router->get('/inventory/counts', [CountController::class, 'index'], 'inventory.count');
$router->post('/inventory/counts', [CountController::class, 'start'], 'inventory.count');
$router->get('/inventory/counts/{id}', [CountController::class, 'show'], 'inventory.count');
$router->post('/inventory/counts/{id}', [CountController::class, 'save'], 'inventory.count');
$router->post('/inventory/counts/{id}/post', [CountController::class, 'post'], 'inventory.count');
$router->post('/inventory/counts/{id}/cancel', [CountController::class, 'cancel'], 'inventory.count');
