<?php
/** Payroll & biometric attendance (People menu), and employees' own payslips. */
use App\Controllers\People\AttendanceController as Att;
use App\Controllers\People\PayrollController as Pay;

$view = ['payroll.view', 'payroll.manage', 'payroll.post'];

$router->get('/payroll', [Pay::class, 'index'], $view);
$router->get('/payroll/new', [Pay::class, 'create'], 'payroll.manage');
$router->post('/payroll', [Pay::class, 'store'], 'payroll.manage');
$router->get('/payroll/settings', [Pay::class, 'settings'], $view);
$router->post('/payroll/settings', [Pay::class, 'saveSettings'], 'payroll.manage');

$router->get('/payroll/attendance', [Att::class, 'index'], ['payroll.view', 'payroll.manage']);
$router->post('/payroll/attendance/upload', [Att::class, 'upload'], 'payroll.manage');
$router->get('/payroll/attendance/preview', [Att::class, 'preview'], 'payroll.manage');
$router->post('/payroll/attendance/import', [Att::class, 'import'], 'payroll.manage');
$router->post('/payroll/attendance/day', [Att::class, 'day'], 'payroll.manage');

$router->get('/payroll/payslips/{id}', [Pay::class, 'payslip'], array_merge($view, ['payroll.self']));
$router->post('/payroll/payslips/{id}/lines', [Pay::class, 'addLine'], 'payroll.manage');
$router->post('/payroll/payslips/{id}/remove', [Pay::class, 'removeSlip'], 'payroll.manage');
$router->post('/payroll/payslips/{id}/undo-payout', [Pay::class, 'undoPayout'], 'payroll.post');
$router->post('/payroll/lines/{id}', [Pay::class, 'updateLine'], 'payroll.manage');
$router->post('/payroll/lines/{id}/delete', [Pay::class, 'removeLine'], 'payroll.manage');

$router->get('/payroll/{id}', [Pay::class, 'show'], $view);
$router->get('/payroll/{id}/payslips', [Pay::class, 'printAll'], $view);
$router->post('/payroll/{id}/recompute', [Pay::class, 'recompute'], 'payroll.manage');
$router->post('/payroll/{id}/post', [Pay::class, 'post'], 'payroll.post');
$router->post('/payroll/{id}/payout', [Pay::class, 'payout'], 'payroll.post');
$router->post('/payroll/{id}/void', [Pay::class, 'void'], 'payroll.post');
$router->post('/payroll/{id}/delete', [Pay::class, 'delete'], 'payroll.manage');

$router->get('/my/payslips', [Pay::class, 'mine'], 'payroll.self');
