<?php
/**
 * Receiving / issuance / wastage document page.
 * Variables: $cfg, $slug, $type, $doc (null for new), $head (header values), $lines, $editable, $itemOptions, $itemData, $bill,
 *            $suppliers, $banks, $expenseAccounts, $accountKeys, $issueTo, $vatRate, $inputVat (posted deliveries), $canPost
 */
use App\Core\Table;
use App\Services\InventoryDocs;
use App\Services\Items;

$isNew = !$doc;
$isRec = $type === 'RECEIVE';
$base = "/inventory/$slug";
$h = fn ($k) => $head[$k] ?? '';
$confirmPost = [
    'RECEIVE' => 'Posting adds these quantities to stock, updates the average cost and records the journal entry. On credit, a payable bill is created for the supplier.',
    'ISSUE' => 'Posting deducts these items from stock at average cost (menu items are broken down into their recipe ingredients) and charges the expense account.',
    'WASTE' => 'Posting deducts these items from stock at average cost (menu items are broken down into their recipe ingredients) and books the cost to Spoilage & Wastage.',
][$type] . ' Posted documents can no longer be edited, only voided.';
?>
<div class="page-header">
  <div>
    <h1><?= $isNew ? e($cfg['new']) : e($doc['doc_no']) . ' ' . badge($doc['status']) ?></h1>
    <p class="muted"><?= $isNew ? e($cfg['subtitle'])
        : e($cfg['title'] . ' · ' . fmt_date($doc['doc_date']) . ($doc['created_by_name'] ? ' · prepared by ' . $doc['created_by_name'] : '')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url($base) ?>">← Back</a>
    <?php if (!$isNew && $doc['status'] === 'draft'): ?>
      <?php if ($canPost): ?>
        <form method="post" action="<?= url("$base/{$doc['id']}/post") ?>" class="inline" data-ok="Post"
              data-confirm="Post <?= e($doc['doc_no']) ?> as last saved? <?= e($confirmPost) ?>">
          <?= csrf_field() ?><button class="btn btn-success" type="submit">Post</button>
        </form>
      <?php endif; ?>
      <form method="post" action="<?= url("$base/{$doc['id']}/delete") ?>" class="inline" data-danger data-ok="Delete"
            data-confirm="Delete draft <?= e($doc['doc_no']) ?>? This cannot be undone.">
        <?= csrf_field() ?><button class="btn btn-ghost" type="submit">Delete draft</button>
      </form>
    <?php endif; ?>
    <?php if (!$isNew && $doc['status'] === 'posted' && $canPost): ?>
      <form method="post" action="<?= url("$base/{$doc['id']}/void") ?>" class="inline" data-danger data-ok="Void" data-prompt="Reason"
            data-confirm="Void <?= e($doc['doc_no']) ?>? This reverses the stock movements and the journal entry<?= $doc['ap_bill_id'] ? ' and cancels the supplier payable' : '' ?>.">
        <?= csrf_field() ?><button class="btn btn-danger" type="submit">Void</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$isNew && $doc['status'] === 'posted'): ?>
  <div class="alert alert-success row wrap gap">
    <span><b>Posted</b><?= $doc['posted_by_name'] ? ' by ' . e($doc['posted_by_name']) : '' ?><?= $doc['posted_at'] ? ' on ' . e(fmt_datetime($doc['posted_at'])) : '' ?></span>
    <?php if ($doc['entry_no']): ?><span>Journal entry <?= e($doc['entry_no']) ?></span><?php endif; ?>
    <?php if ($bill): ?>
      <span>Payable <b><?= e($bill['bill_no']) ?></b> — <?= peso($bill['amount']) ?>, due <?= e(fmt_date($bill['due_date'])) ?> <?= badge($bill['status']) ?></span>
    <?php endif; ?>
  </div>
<?php elseif (!$isNew && $doc['status'] === 'cancelled'): ?>
  <div class="alert alert-warn"><b>Voided.</b> Stock and journal entries were reversed<?= $bill ? ' and payable ' . e($bill['bill_no']) . ' was voided' : '' ?>.</div>
<?php endif; ?>

<?php if ($editable): ?>
<form id="doc-form" method="post" action="<?= url($isNew ? $base : "$base/{$doc['id']}") ?>" class="stack" data-doc-form
      data-doc-type="<?= e($type) ?>" data-vat-rate="<?= e($vatRate) ?>"
      data-account-supplies="<?= e($accountKeys['supplies'] ?? '') ?>" data-account-staff="<?= e($accountKeys['staff_meals'] ?? '') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="_form" value="doc">

  <div class="card">
    <div class="card-head"><h3>Details</h3></div>
    <div class="card-body form-grid">
      <label class="field"><span class="field-label">Date *</span><input class="input" type="date" name="doc_date" value="<?= e($h('doc_date')) ?>" required></label>
      <?php if ($isRec): ?>
        <label class="field" style="grid-column:span 2"><span class="field-label">Supplier <span data-supplier-required>*</span></span>
          <select class="input" name="supplier_id" data-combo data-placeholder="Search supplier…">
            <?= options(array_column(array_map(fn ($s) => ['id' => $s['id'], 'label' => $s['name'] . ' · ' . ($s['terms_days'] ? "{$s['terms_days']} days" : 'COD')], $suppliers), 'label', 'id'), $h('supplier_id'), '') ?>
          </select>
          <?php if (can('partners.manage', 'finance.ap')): ?><span class="field-hint">New supplier? Add it under <a href="<?= url('/finance/suppliers') ?>">Suppliers</a>.</span><?php endif; ?>
        </label>
        <label class="field"><span class="field-label">Supplier invoice / DR no.</span><input class="input" name="invoice_no" value="<?= e($h('invoice_no')) ?>" maxlength="60"></label>
        <label class="field" style="grid-column:span 2"><span class="field-label">Payment</span>
          <select class="input" name="payment_mode" data-payment-mode><?= options(InventoryDocs::PAYMENT_MODES, $h('payment_mode') ?: 'credit') ?></select></label>
        <label class="field" data-bank-field><span class="field-label">Bank account *</span>
          <select class="input" name="bank_account_id">
            <?= options(array_column(array_map(fn ($b) => ['id' => $b['id'], 'label' => $b['bank_name'] . ($b['account_no'] ? ' ' . substr($b['account_no'], -4) : '')], $banks), 'label', 'id'), $h('bank_account_id'), '— Choose bank —') ?>
          </select></label>
        <div class="field" style="grid-column:span 2;justify-content:flex-end">
          <label class="checkbox"><input type="checkbox" name="vat_inclusive" value="1" data-vat-inclusive <?= !empty($head['vat_inclusive']) ? 'checked' : '' ?>> Supplier price is VAT-inclusive (claim input VAT)</label>
          <span class="field-hint">Tick only for VAT-registered suppliers issuing a VAT invoice.</span>
        </div>
      <?php elseif ($type === 'ISSUE'): ?>
        <label class="field"><span class="field-label">Issued to</span>
          <input class="input" name="issued_to" list="inv-issue-to" value="<?= e($h('issued_to')) ?>" placeholder="e.g. Kitchen" maxlength="120" data-issued-to>
          <datalist id="inv-issue-to"><?php foreach ($issueTo as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist></label>
        <label class="field"><span class="field-label">Reason / purpose</span>
          <input class="input" name="reason" value="<?= e($h('reason')) ?>" placeholder="e.g. Daily kitchen requisition" maxlength="120"></label>
        <label class="field" style="grid-column:span 2"><span class="field-label">Charge to expense account</span>
          <select class="input" name="expense_account_id" data-expense-account <?= $isNew ? '' : 'data-touched' ?>><?= options($expenseAccounts, $h('expense_account_id')) ?></select></label>
      <?php else: ?>
        <label class="field"><span class="field-label">Reason</span>
          <select class="input" name="reason"><?= options(array_combine(InventoryDocs::WASTE_REASONS, array_map('ucfirst', InventoryDocs::WASTE_REASONS)), $h('reason') ?: 'spoilage') ?></select></label>
      <?php endif; ?>
      <label class="field" style="grid-column:span 2"><span class="field-label">Notes</span>
        <textarea class="input" style="height:auto;padding:8px 10px" rows="1" name="notes"><?= e($h('notes')) ?></textarea></label>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Items</h3><span class="muted small">Type to search an item · a new line is added automatically</span></div>
    <div class="table-wrap">
      <table class="table dense">
        <thead><tr>
          <th>Item</th><th class="right">Qty</th><th>Unit</th>
          <?php if ($isRec): ?><th class="right">Unit cost</th><th class="right">Line total</th><?php else: ?><th class="right">Est. cost</th><?php endif; ?>
          <th>Notes</th><th></th>
        </tr></thead>
        <tbody data-rows="lines">
          <?php foreach ($lines as $i => $l): ?>
            <?= view('inventory/docs/_line', ['i' => $i, 'l' => $l, 'type' => $type, 'itemOptions' => $itemOptions], null) ?>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td colspan="<?= $isRec ? 4 : 3 ?>">
            <span data-line-count>0 items</span>
            <span class="muted small" style="font-weight:400;margin-left:12px" data-vat-note></span>
            <?php if (!$isRec): ?><span class="muted small" style="font-weight:400;margin-left:12px">Estimated at current average cost; the final cost is set when posted.</span><?php endif; ?>
          </td>
          <td class="right" data-doc-total>0.00</td><td colspan="2"></td>
        </tr></tfoot>
      </table>
    </div>
    <template id="lines-template"><?= view('inventory/docs/_line', ['i' => '__i__', 'l' => [], 'type' => $type, 'itemOptions' => $itemOptions], null) ?></template>
  </div>

  <div class="row gap-sm wrap">
    <button class="btn" type="submit">Save draft</button>
    <?php if ($canPost): ?>
      <button class="btn btn-success" type="submit" name="post" value="1" data-ask="Post this document?" data-ask-message="<?= e($confirmPost) ?>" data-ok="Post">Save &amp; post</button>
    <?php else: ?>
      <span class="muted small">A manager with posting rights must post this document.</span>
    <?php endif; ?>
  </div>
</form>
<script type="application/json" id="inv-items"><?= json_encode($itemData, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('js/inventory.js') ?>"></script>

<?php else: ?>
<div class="stack">
  <div class="card">
    <div class="card-head"><h3>Details</h3></div>
    <div class="card-body">
      <dl class="kv" style="margin:0">
        <dt>Date</dt><dd><?= e(fmt_date($doc['doc_date'])) ?></dd>
        <?php if ($isRec): ?>
          <dt>Supplier</dt><dd><?= e($doc['supplier_name'] ?: '—') ?></dd>
          <dt>Invoice / DR no.</dt><dd><?= e($doc['invoice_no'] ?: '—') ?></dd>
          <dt>Payment</dt><dd><?= e((InventoryDocs::PAYMENT_MODES[$doc['payment_mode']] ?? $doc['payment_mode']) . ($doc['payment_mode'] === 'bank' && $doc['bank_name'] ? ' — ' . $doc['bank_name'] : '')) ?></dd>
          <dt>VAT</dt><dd><?= $doc['vat_inclusive'] ? 'VAT-inclusive (input VAT claimed)' : 'No input VAT' ?></dd>
        <?php elseif ($type === 'ISSUE'): ?>
          <dt>Issued to</dt><dd><?= e($doc['issued_to'] ?: '—') ?></dd>
          <dt>Reason</dt><dd><?= e($doc['reason'] ?: '—') ?></dd>
          <dt>Expense account</dt><dd><?= e($doc['expense_account_code'] . ' · ' . $doc['expense_account_name']) ?></dd>
        <?php else: ?>
          <dt>Reason</dt><dd><?= e(ucfirst((string) $doc['reason'])) ?></dd>
        <?php endif; ?>
        <?php if ($doc['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($doc['notes'])) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Items</h3></div>
    <?php
    $columns = [
        ['key' => 'item_name', 'label' => 'Item', 'html' => fn ($l) => '<b>' . e($l['item_name']) . '</b> <span class="muted small">' . e($l['sku']) . ($l['item_type'] === 'composite' ? ' · recipe' : '') . '</span>'],
        ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
        ['key' => 'uom', 'label' => 'Unit', 'html' => fn ($l) => e($l['uom']) . ($l['uom'] !== $l['base_uom'] ? ' <span class="muted small">(' . qty($l['base_qty']) . ' ' . e($l['base_uom']) . ')</span>' : '')],
    ];
    if ($isRec) $columns[] = ['key' => 'unit_cost', 'label' => 'Unit cost', 'type' => 'money', 'html' => fn ($l) => Items::cost4($l['unit_cost'])];
    $columns[] = ['key' => 'line_total', 'label' => $isRec ? 'Line total' : 'Cost at save', 'type' => 'money', 'total' => true];
    $columns[] = ['key' => 'notes', 'label' => 'Notes'];
    ?>
    <?= Table::html($columns, $doc['lines'], ['search' => false]) ?>
    <div class="card-body right">
      <?php if ($inputVat): ?><span class="muted small">incl. input VAT <?= peso($inputVat) ?> · </span><?php endif; ?>
      <b>Document total <?= peso($doc['total_cost']) ?></b>
      <?php if (!$isRec): ?><div class="muted small">Posted at average cost; menu items were broken down into their ingredients.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
