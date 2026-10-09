<?php
namespace App\Controllers\Pos;

use App\Core\Request;

/** POS screen and printer setup page (the POS itself is driven by public/assets/js/pos.js). */
class PosController
{
    public function index(Request $req): string
    {
        return view('pos/index', ['title' => 'POS', 'boot' => PosApiController::bootstrap(), 'bodyClass' => 'pos-body-page',
            'scripts' => ['js/printer.js', 'js/pos.js'], 'styles' => ['css/pos.css']], 'blank');
    }

    public function printer(Request $req): string
    {
        return view('pos/printer', ['title' => 'Printer setup', 'scripts' => ['js/printer.js', 'js/printer-setup.js'],
            'stations' => \App\Services\Stations::all(true)]);
    }
}
