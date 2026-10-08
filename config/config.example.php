<?php
// Copy this file to config/config.php and adjust the values for your server.
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'mark5',
        'username' => 'root',
        'password' => '',
    ],
    'app' => [
        'timezone' => 'Asia/Manila',
        // Set to true only while developing: shows full error details in the browser.
        'debug'    => false,
        // Base URL path if the app is not at the web root, e.g. '/mark5' for http://localhost/mark5
        'base_path' => '',
    ],
];
