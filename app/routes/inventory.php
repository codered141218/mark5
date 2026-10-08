<?php
/** Inventory module routes. Third argument = required permission(s). */
use App\Controllers\Inventory\CategoryController;

$router->get('/inventory/categories', [CategoryController::class, 'index'], 'inventory.manage');
$router->post('/inventory/categories', [CategoryController::class, 'save'], 'inventory.manage');
$router->post('/inventory/categories/{id}/delete', [CategoryController::class, 'delete'], 'inventory.manage');
