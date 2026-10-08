<?php
/** Report routes. The dashboard is GET / (AuthController::home). */
use App\Controllers\Reports\ReportController;

$router->get('/reports/sales', [ReportController::class, 'sales'], 'reports.sales');
$router->get('/reports/inventory', [ReportController::class, 'inventory'], 'reports.inventory');
$router->get('/reports/finance', [ReportController::class, 'finance'], ['reports.finance', 'pettycash.view']);
$router->get('/reports/eod/{id}', [ReportController::class, 'eod'], ['reports.sales', 'pos.close_day']);
