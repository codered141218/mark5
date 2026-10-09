<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Services\GlSetup;

/** Finance → GL Account Setup: the default account of every automatic posting. */
class GlSetupController
{
    public function index(Request $req)
    {
        $groups = [];
        foreach (GlSetup::rows() as $r) $groups[$r['group']][] = $r;
        $categories = \App\Core\DB::all(
            'SELECT c.name, s.code AS s_code, s.name AS s_name, g.code AS g_code, g.name AS g_name, v.code AS v_code, v.name AS v_name
             FROM categories c LEFT JOIN accounts s ON s.id = c.sales_account_id LEFT JOIN accounts g ON g.id = c.cogs_account_id
             LEFT JOIN accounts v ON v.id = c.inventory_account_id
             WHERE c.sales_account_id IS NOT NULL OR c.cogs_account_id IS NOT NULL OR c.inventory_account_id IS NOT NULL ORDER BY c.sort_order, c.name'
        );
        return view('finance/gl_setup/index', ['title' => 'GL Account Setup', 'groups' => $groups, 'categories' => $categories]);
    }

    public function save(Request $req): Response
    {
        GlSetup::save((array) $req->input('gl', []));
        flash('success', 'GL accounts saved. New transactions will post to these accounts.');
        return redirect('/finance/gl-setup');
    }
}
