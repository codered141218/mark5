<?php
use App\Controllers\AuthController;
use App\Core\Router;

$router->get('/login', [AuthController::class, 'loginForm'], Router::PUBLIC);
$router->post('/login', [AuthController::class, 'login'], Router::PUBLIC);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/', [AuthController::class, 'home']);
$router->get('/account', [AuthController::class, 'account']);
$router->post('/account', [AuthController::class, 'changePassword']);
