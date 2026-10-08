<?php
namespace App\Controllers;

use App\Core\Request;

/** Placeholder — replaced by the dashboard module. */
class DashboardController
{
    public function index(Request $req): string
    {
        return view('error', ['status' => 200, 'message' => 'Dashboard coming soon'], 'app');
    }
}
