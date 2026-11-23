<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<a href="<?= esc($backUrl, 'attr') ?>" class="btn btn-topbar"><i class="bi bi-arrow-left"></i> Back to supplier</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
// Procurement Report > Suppliers > "Create PO for low items": the review. Variables from SupplierPoController::render():
// $supplier, $now (Reorder Now lines), $low (Low Stock lines), $orderingCost, $defaultExpected, $errors, $posted, $backUrl.
// One purchase order with several lines is created from the ticked lines (App\Libraries\SupplierPo). The unit cost is the
// item's own (products.unit_cost) and cannot be edited here; only the quantity can.
$peso    = static fn (float $n): string => '₱' . number_format($n, 2);
$posted  = $posted ?? null;
$errors  = $errors ?? [];
$lead    = (int) ($supplier['lead_time_days'] ?? 0);
$expected = $posted !== null ? ($posted['expected'] ?? '') : ($defaultExpected ?? '');
$selectable = 0;
foreach (array_merge($now, $low) as $l) {
    $selectable += $l['on_order'] === null ? 1 : 0;
}

/** One table row; $section is 'now' or 'low' (low lines start unticked). */
$row = static function (array $l) use ($posted, $peso): string {
    $id      = (string) $l['item_id'];
    $blocked = $l['on_order'] !== null;
    $checked = ! $blocked && ($posted !== null ? in_array($id, $posted['pick'] ?? [], true) : $l['section'] === 'now');
    $qty     = $posted !== null && isset($posted['qty'][$id]) && is_scalar($posted['qty'][$id]) ? (string) $posted['qty'][$id] : (string) $l['qty'];
    $min     = (int) ($l['supplier_min'] ?? 0);
    $below   = $min > 0 && ctype_digit($qty) && (int) $qty < $min;
    ob_start(); ?>
    <tr class="spo-row<?= $blocked ? ' is-blocked' : '' ?>" data-item-id="<?= esc($id, 'attr') ?>" data-cost="<?= esc((string) $l['unit_cost'], 'attr') ?>" data-min="<?= $min ?>">
        <td class="spo-check"><input type="checkbox" name="pick[]" value="<?= esc($id, 'attr') ?>" class="spo-pick" aria-label="Order <?= esc($l['name'], 'attr') ?>"<?= $checked ? ' checked' : '' ?><?= $blocked ? ' disabled' : '' ?>></td>
        <td class="spo-name"><strong><?= esc($l['item_name']) ?></strong><?php if ($l['size'] !== null): ?> <span class="spo-size">Size <?= esc($l['size']) ?></span><?php endif; ?></td>
        <td class="col-center"><?php if ($l['class'] !== null): ?><span class="abc-badge cls-<?= esc(strtolower($l['class']), 'attr') ?>"><?= esc($l['class']) ?></span><?php else: ?>&mdash;<?php endif; ?></td>
        <td class="col-num"><?= (int) $l['stock'] ?></td>
        <td class="col-num"><?= (int) $l['rop'] ?></td>
        <?php if ($blocked): ?>
            <td colspan="3" class="spo-onorder"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Already on order (<?= esc($l['on_order']) ?>)</td>
        <?php else: ?>
            <td class="col-num spo-qty-cell">
                <input type="number" name="qty[<?= esc($id, 'attr') ?>]" class="spo-qty" value="<?= esc($qty, 'attr') ?>" min="1" step="1" inputmode="numeric" aria-label="Quantity for <?= esc($l['name'], 'attr') ?>"<?= $checked ? ' required' : ' disabled' ?>>
                <span class="spo-warn" role="note"<?= $below ? '' : ' hidden' ?>>Below the supplier's minimum of <?= $min ?>.</span>
                <?php if (! empty($l['note'])): ?><span class="spo-note <?= $l['note']['level'] === 'warning' ? 'is-warning' : '' ?>"><?= esc($l['note']['text']) ?></span><?php endif; ?>
            </td>
            <td class="col-num"><?= $peso((float) $l['unit_cost']) ?></td>
            <td class="col-num spo-line-total"><?= $peso((float) $l['unit_cost'] * (int) ($checked && ctype_digit($qty) ? $qty : 0)) ?></td>
        <?php endif; ?>
    </tr>
    <?php

    return (string) ob_get_clean();
};
$head = '<thead><tr><th class="spo-check"></th><th>Item</th><th class="col-center">Class</th><th class="col-num">On hand</th><th class="col-num">Reorder at</th><th class="col-num">Quantity</th><th class="col-num">Unit cost</th><th class="col-num">Line total</th></tr></thead>';
?>

<style>
.spo-head h2 { font-size: 20px; font-weight: 800; margin: 4px 0 4px; }
.spo-facts { display: flex; flex-wrap: wrap; gap: 6px 22px; color: #3A4030; font-size: 14px; margin-bottom: 14px; }
.spo-facts b { color: #6B6E60; font-weight: 600; margin-right: 4px; }
.spo-errors { border: 1px solid #E0B4B4; background: #FBEDED; color: #8A1C1C; border-radius: 12px; padding: 12px 16px; margin: 0 0 16px; }
.spo-errors strong { display: block; margin-bottom: 4px; }
.spo-errors ul { margin: 0; padding-left: 20px; }
.spo-section { margin: 0 0 18px; }
.spo-section h3 { font-size: 16px; font-weight: 800; margin: 0 0 4px; }
.spo-sub { color: #6B6E60; font-size: 13px; margin: 0 0 8px; }
.spo-table { width: 100%; }
.spo-table td, .spo-table th { vertical-align: middle; }
.spo-check { width: 42px; text-align: center; }
.spo-check input { width: 18px; height: 18px; cursor: pointer; }
.spo-size { margin-left: 6px; color: #6B6E60; font-size: 12.5px; }
.spo-qty { width: 92px; text-align: right; padding: 6px 8px; border: 1px solid #D6D2C6; border-radius: 8px; }
.spo-qty:disabled { background: #F3F1EA; color: #8A8F85; }
.spo-qty-cell { min-width: 130px; }
.spo-warn { display: block; margin-top: 3px; font-size: 11.5px; font-weight: 600; color: #8A1C1C; white-space: normal; }
.spo-note { display: block; margin-top: 2px; font-size: 11.5px; color: #6B6E60; white-space: normal; max-width: 260px; margin-left: auto; }
.spo-note.is-warning { color: #8A1C1C; font-weight: 600; }
.spo-row.is-blocked { color: #8A8F85; background: #FAF9F5; }
.spo-onorder { color: #6B4E0E; font-weight: 600; }
.spo-row:not(.is-picked) .spo-line-total { color: #8A8F85; }
.spo-empty { padding: 22px; text-align: center; color: #6B6E60; }
.spo-summary { border: 1px solid #D6D2C6; border-radius: 12px; background: #fff; padding: 14px 18px; margin: 4px 0 14px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 24px; }
.spo-summary .spo-total { font-size: 20px; font-weight: 800; }
.spo-summary .spo-saving { color: #3d5230; font-size: 13px; margin-top: 2px; }
.spo-details { display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: end; margin: 0 0 14px; }
.spo-details label { display: block; font-size: 12px; font-weight: 700; color: #3A4030; margin-bottom: 4px; }
.spo-details input[type=date] { padding: 7px 10px; border: 1px solid #D6D2C6; border-radius: 8px; }
.spo-actions { display: flex; gap: 10px; justify-content: flex-end; }
</style>

<?php // The same page container as the Procurement Report (padding from the sidebar); "Back to supplier" is in the green header only. ?>
<div class="page-wrap">
<div class="spo-head">
    <h2>Create PO for low items &middot; <?= esc($supplier['company_name']) ?></h2>
    <div class="spo-facts">
        <span><b>Delivery time</b> <?= $lead ?> days</span>
        <?php if (($supplier['payment_terms'] ?? '') !== ''): ?><span><b>Payment terms</b> <?= esc($supplier['payment_terms']) ?></span><?php endif; ?>
        <span><b>One order</b> with every ticked line below, from this supplier only.</span>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="spo-errors" role="alert" id="spoErrors">
        <strong>Nothing was ordered. Please check these lines and send it again:</strong>
        <ul>
            <?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?>
        </ul>
        The list below is up to date.
    </div>
<?php endif; ?>

<form method="post" action="<?= site_url('admin/procurement-report/po/' . rawurlencode((string) $supplier['supplier_id'])) ?>" id="spoForm" data-submit-once data-busy-label="Creating order…" novalidate>
    <?= csrf_field() ?>
    <?= \App\Libraries\OnceToken::field('supplier_po') ?>

    <section class="spo-section" id="spoNow">
        <h3>At or below the reorder point</h3>
        <p class="spo-sub">Quantities are the Order Qty from the Items tab. Untick a line to leave it out, or change its quantity.</p>
        <?php if ($now === []): ?>
            <div class="inv-table-card"><div class="spo-empty" id="spoNowEmpty">Nothing from this supplier is at or below its reorder point.</div></div>
        <?php else: ?>
            <div class="inv-table-card"><div class="data-table-wrap">
                <table class="data-table inv-table spo-table" id="spoNowTable"><?= $head ?><tbody>
                    <?php foreach ($now as $l): ?><?= $row($l) ?><?php endforeach; ?>
                </tbody></table>
            </div></div>
        <?php endif; ?>
    </section>

    <?php if ($low !== []): ?>
        <section class="spo-section" id="spoLow">
            <h3>Also running low (optional)</h3>
            <p class="spo-sub">Adding these now saves a separate order later (<?= $peso($orderingCost) ?> ordering cost per order). They are not ticked.</p>
            <div class="inv-table-card"><div class="data-table-wrap">
                <table class="data-table inv-table spo-table" id="spoLowTable"><?= $head ?><tbody>
                    <?php foreach ($low as $l): ?><?= $row($l) ?><?php endforeach; ?>
                </tbody></table>
            </div></div>
        </section>
    <?php endif; ?>

    <div class="spo-details">
        <div>
            <label for="spoExpected">Expected delivery</label>
            <input type="date" id="spoExpected" name="expected_delivery_date" value="<?= esc((string) $expected, 'attr') ?>" min="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <div class="spo-summary" aria-live="polite" data-ordering-cost="<?= esc((string) $orderingCost, 'attr') ?>">
        <div>
            <div><span id="spoLines">0 lines</span> &middot; <span id="spoUnits">0 pieces</span></div>
            <div class="spo-saving" id="spoSaving"></div>
        </div>
        <div>Order total <span class="spo-total" id="spoTotal">₱0.00</span></div>
    </div>

    <div class="spo-actions">
        <a href="<?= esc($backUrl, 'attr') ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-success" id="spoSubmit"<?= $selectable === 0 ? ' disabled' : '' ?>>Create purchase order</button>
    </div>
</form>
</div>

<script src="/assets/js/supplier-po-review.js" defer></script>
<?= $this->endSection() ?>
