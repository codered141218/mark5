<?php
namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\Reset;
use App\Services\Admin\SetupWizard;
use App\Services\GlSetup;
use App\Services\Settings;
use App\Services\Stations;

/** First-time setup wizard: GET /setup?step=..., POST /setup/{step} saves and goes to the next step. */
class SetupController
{
    public function show(Request $req): string
    {
        $step = array_key_exists((string) $req->query('step'), SetupWizard::STEPS) ? (string) $req->query('step') : 'welcome';
        $groups = [];
        if ($step === 'gl') foreach (GlSetup::rows() as $r) $groups[$r['group']][] = $r;
        return view('setup/wizard', [
            'title' => 'Setup · ' . SetupWizard::STEPS[$step], 'step' => $step, 'steps' => SetupWizard::STEPS,
            'counts' => SetupWizard::counts(), 's' => Settings::all(), 'glGroups' => $groups, 'stations' => Stations::options(),
            'categories' => \App\Core\DB::all('SELECT c.name, c.kind, s.name AS station FROM categories c LEFT JOIN prep_stations s ON s.id = c.station_id ORDER BY c.sort_order, c.name'),
            'units' => \App\Core\DB::all('SELECT name, abbr FROM uoms ORDER BY name'),
            'prev' => SetupWizard::prev($step), 'next' => SetupWizard::next($step),
        ], 'blank');
    }

    public function save(Request $req, string $step): Response
    {
        if ($req->input('skip') === '1') return redirect('/setup?step=' . SetupWizard::next($step));
        SetupWizard::save($step, $req->all());
        if ($step === 'done') { SetupWizard::finish(); flash('success', 'Setup finished — welcome aboard!'); return redirect('/'); }
        return redirect('/setup?step=' . SetupWizard::next($step));
    }

    /** "Not now": hide the wizard (it can be started again from Settings). */
    public function later(Request $req): Response
    {
        SetupWizard::finish();
        flash('success', 'Setup wizard closed. You can run it again any time from Administration → Settings.');
        return redirect('/');
    }

    public function restart(Request $req): Response
    {
        SetupWizard::restart();
        return redirect('/setup');
    }

    /** Welcome step: delete the sample / old data first, then continue with the wizard. */
    public function fresh(Request $req): Response
    {
        $backup = Reset::startFresh((int) Auth::id(), (string) $req->input('password'), (string) $req->input('confirm'));
        SetupWizard::restart();
        flash('success', "All old data was deleted (backup saved as $backup). Let's set up your restaurant.");
        return redirect('/setup?step=business');
    }
}
