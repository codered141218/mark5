<?php /** AP / AR aging. Variables: $cfg, $rows, $columns, $asOf */ use App\Core\Table; ?>
<?= view('finance/docs/_tabs', ['cfg' => $cfg, 'tab' => 'aging'], null) ?>
<?= view('partials/daterange', ['to' => $asOf, 'from' => $asOf, 'single' => true, 'extra' => '<span class="muted small">Unpaid balances grouped by days past the due date.</span>'], null) ?>
<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'empty' => "No unpaid {$cfg['docs']} as of this date."]) ?></div>
