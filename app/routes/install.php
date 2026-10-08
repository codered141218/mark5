<?php
/** Only loaded until the app is installed: every page shows the installer. */
use App\Controllers\InstallController;
use App\Core\Router;

$router->get('/install', [InstallController::class, 'form'], Router::PUBLIC);
$router->post('/install', [InstallController::class, 'install'], Router::PUBLIC);
// Anything else redirects to the installer
$router->get('/{any}', [InstallController::class, 'redirect'], Router::PUBLIC);
$router->get('/', [InstallController::class, 'redirect'], Router::PUBLIC);
