<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\SettingsForm;
use App\Services\Settings;

/** /admin/settings — business & receipt details, tax rules and backup options, one tab per form. */
class SettingsController
{
    public function index(Request $req)
    {
        $tab = array_key_exists($req->query('tab', ''), SettingsForm::TABS) ? $req->query('tab') : 'business';
        return view('admin/settings/index', ['title' => 'Settings', 'tab' => $tab, 'tabs' => SettingsForm::TABS, 's' => Settings::all()]);
    }

    public function save(Request $req): Response
    {
        $tab = (string) $req->input('tab');
        $changed = SettingsForm::save($tab, $req->all());
        flash('success', $changed ? 'Settings saved.' : 'Nothing changed.');
        return redirect('/admin/settings?tab=' . urlencode($tab));
    }
}
