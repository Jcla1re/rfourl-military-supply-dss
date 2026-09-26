
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('header_actions') ?>
<span class="text-white-75 small"><i class="bi bi-calendar3"></i> <?= esc(date('l, F j Y')) ?></span>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $tab = $tab ?? 'pos'; $receipt = $receipt ?? null; ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');

.pos-tabs {
    display: flex;
    gap: 2px;
    background: #4B6B42;
    padding: 0;
    margin: -24px -24px 20px -24px;
}
.pos-tabs a {
    flex: 1;
    height: 44px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 400;
    color: #fff;
    background: #4B6B42;
    border: 1px solid rgba(255, 255, 255, 0.45);
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    transition: background-color 0.15s ease;
}
.pos-tabs a:hover:not(.active) {
    background: rgba(255, 255, 255, 0.08);
}
.pos-tabs a.active {
    background: #F3F1EB;
    color: #1E2616;
    font-weight: 500;
    border: none;
}

.pos-layout { display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .pos-layout { grid-template-columns: 1fr; } }

.pos-search { padding: 14px 18px; border: 1px solid #ddd; border-radius: 12px; width: 100%; margin-bottom: 16px; }
.pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
.pos-card {
    background: #fff; border: 2px solid transparent; border-radius: 14px; padding: 18px; text-align: center;
    cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.pos-card.selected { border-color: #3d5230; }
.pos-card.disabled { opacity: .5; cursor: not-allowed; background: #eee; }
.pos-card .icon { font-size: 34px; margin-bottom: 8px; }
.pos-card .name { font-weight: 700; }
.pos-card .size { color: #777; font-size: 12px; }
.pos-card .price { font-weight: 800; font-size: 20px; margin: 6px 0; }
.pos-card .stock { font-size: 12px; }
.pos-card .stock.green { color: var(--green-text); }
.pos-card .stock.amber { color: var(--amber-text); }
.pos-card .stock.red { color: var(--red-text); }

.cart-panel { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); position: sticky; top: 16px; }
.cart-head { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 12px; margin-bottom: 12px; }
.cart-head .count { background: var(--sidebar-bg); color: #fff; border-radius: 999px; padding: 2px 10px; font-size: 12px; margin-left: 6px; }
.cart-line { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.cart-line .info { flex: 1; }
.cart-line .name { font-weight: 700; font-size: 14px; }
.cart-line .price { color: #777; font-size: 12px; }
.cart-line .badge-disc { display: inline-block; background: #fff3cd; color: #8a6300; border-radius: 999px; padding: 1px 8px; font-size: 11px; font-weight: 700; margin-left: 6px; }
.cart-line .qty-ctrl { display: flex; align-items: center; gap: 6px; }
.cart-line .qty-ctrl button { width: 26px; height: 26px; border: 1px solid #ccc; border-radius: 6px; background: #fff; }
.cart-line .line-total { font-weight: 700; text-align: right; }
.cart-line .line-total .line-disc { display: block; color: #b3261e; font-size: 11px; font-weight: 600; }
.cart-empty { text-align: center; color: #999; padding: 30px 0; }
.cart-totals { border-top: 1px solid #333; padding-top: 10px; margin-top: 10px; }
.cart-totals .row-line { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 4px; }
.cart-totals .row-line.total { font-weight: 800; font-size: 17px; }
.cart-totals .row-line.muted { color: #777; font-size: 12.5px; }
.cart-totals .row-line.discount-row { color: #b3261e; }
.cart-note { font-size: 11.5px; color: #888; margin: 8px 0 4px; line-height: 1.4; }
.cart-change { background: var(--green-bg); border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; margin: 12px 0; }

.pay-method-toggle { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 12px; }
.pay-btn { padding: 10px 4px; border: 1px solid #ccc; border-radius: 8px; background: #fff; font-weight: 600; font-size: 13px; cursor: pointer; }
.pay-btn.active { background: #3d5230; color: #fff; border-color: #3d5230; }
.quick-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.quick-chip { border: 1px solid #ccc; background: #f7f6f2; border-radius: 999px; padding: 4px 12px; font-size: 12.5px; cursor: pointer; }
.quick-chip:hover { background: #eef0ea; }

.rec-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
@media (max-width: 800px) { .rec-stats { grid-template-columns: 1fr; } }
.rec-stat { background: #fff; border-radius: 14px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); display: flex; align-items: center; gap: 14px; }
.rec-stat .rec-stat-icon { width: 42px; height: 42px; border-radius: 10px; background: #eef0ea; color: #3d5230; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.rec-stat .value { font-size: 22px; font-weight: 800; }
.rec-stat .trend { color: #3d5230; font-size: 12px; font-weight: 600; }
.line-item-chip { display:inline-block; background:#eef0ea; border-radius: 999px; padding: 3px 10px; font-size: 12px; margin: 2px 4px 2px 0; }
.item-line { padding: 2px 0; }
.item-line:not(:last-child) { border-bottom: 1px dashed #eee; }

/* Receipt modal */
.receipt-modal { position: fixed; inset: 0; z-index: 1100; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.45); }
.receipt-modal.show { display: flex; }
.receipt-card {
    width: min(600px, 94vw); max-height: 92vh; overflow-y: auto; background: #fff; border-radius: 10px;
    padding: 32px 36px; font-family: Georgia, 'Times New Roman', serif; color: #1c1c1c;
}
.receipt-card .rc-close { float: right; background: none; border: none; font-size: 20px; cursor: pointer; font-family: Arial, sans-serif; }
.inv-title { text-align: center; font-weight: 800; font-size: 25px; letter-spacing: .01em; margin: 0 0 8px; }
.inv-sub { text-align: center; font-size: 12px; color: #333; line-height: 1.5; }
.inv-heading-row { display: flex; justify-content: space-between; align-items: baseline; border-top: 2px solid #1c1c1c; border-bottom: 2px solid #1c1c1c; margin: 18px 0 14px; padding: 8px 2px; }
.inv-heading { font-weight: 800; font-size: 15px; letter-spacing: .05em; }
.inv-no { font-weight: 800; font-size: 19px; color: var(--accent-maroon); }
.inv-no .inv-no-label { font-size: 13px; margin-right: 8px; }
.inv-field-row { display: flex; gap: 24px; font-size: 12.5px; margin-bottom: 9px; }
.inv-field { flex: 1; }
.inv-field .inv-line { display: inline-block; border-bottom: 1px solid #999; min-width: 55%; padding-bottom: 1px; margin-left: 4px; }
.inv-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin: 16px 0 6px; }
.inv-table th, .inv-table td { border: 1px solid #999; padding: 5px 8px; text-align: left; }
.inv-table th { text-align: center; font-weight: 700; background: #f2f0e9; }
.inv-table td.num, .inv-table th.num { text-align: right; }
.inv-table .blank-row td { height: 20px; }
.inv-totals { margin-left: auto; width: 62%; margin-top: 4px; }
.inv-totals div { display: flex; justify-content: space-between; font-size: 12.5px; padding: 3px 6px; }
.inv-totals div.grand { font-weight: 800; font-size: 14px; border-top: 2px solid #1c1c1c; border-bottom: 2px solid #1c1c1c; margin-top: 2px; padding: 6px; }
.inv-payment { margin-top: 14px; font-size: 12.5px; border-top: 1px dashed #ccc; padding-top: 10px; }
.inv-payment div { display: flex; justify-content: space-between; padding: 2px 0; }
.inv-signature { margin-top: 46px; text-align: center; font-size: 12px; border-top: 1px solid #1c1c1c; padding-top: 4px; width: 62%; margin-left: auto; margin-right: 0; font-weight: 700; }
.rc-footer { text-align: center; font-size: 10.5px; color: #666; margin-top: 18px; font-family: Arial, sans-serif; }
.rc-actions { display: flex; gap: 10px; margin-top: 16px; }
.rc-actions button, .rc-actions a { flex: 1; text-align: center; padding: 10px; border-radius: 8px; font-weight: 700; border: 1px solid #ccc; background: #fff; cursor: pointer; text-decoration: none; color: #1c1c1c; font-family: Arial, sans-serif; }

.view-receipt-link { text-decoration: none; }
.view-receipt-link:hover strong { text-decoration: underline; }

@media print {
    body * { visibility: hidden; }
    .receipt-modal.show, .receipt-modal.show * { visibility: visible; }
    .receipt-modal.show { position: absolute; inset: 0; background: #fff; }
    .rc-close, .rc-actions { display: none; }
}

.toast-success { display: flex; align-items: center; gap: 10px; background: #fff; border-top: 4px solid var(--green-text); border-radius: 8px; padding: 14px 20px; margin-bottom: 18px; font-weight: 700; color: var(--green-text); }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="toast-success"><i class="bi bi-check-circle-fill"></i> Success!</div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="pos-tabs">
        <a href="?tab=pos" class="<?= $tab === 'pos' ? 'active' : '' ?>">Point of Sale</a>
        <a href="?tab=receipts" class="<?= $tab === 'receipts' ? 'active' : '' ?>">Sales &amp; Receipts</a>
    </div>

    <?php if ($tab === 'pos'): ?>
        <?php $products = $products ?? []; ?>
        <div class="pos-layout">
            <div>
                <div class="pill-tabs mb-3">
                    <button type="button" class="active" data-cat="">All Items</button>
                    <?php foreach (($categories ?? []) as $c): ?>
                        <button type="button" data-cat="<?= esc($c) ?>"><?= esc($c) ?></button>
                    <?php endforeach; ?>
                </div>
                <input type="search" class="pos-search" id="posSearch" placeholder="Search items...">

                <?php if (empty($products)): ?>
                    <div class="empty-state">No items in inventory yet.</div>
                <?php else: ?>
                    <div class="pos-grid" id="posGrid">
                        <?php foreach ($products as $p): ?>
                            <div class="pos-card <?= $p['current_stock'] <= 0 ? 'disabled' : '' ?>"
                                 data-cat="<?= esc($p['category']) ?>"
                                 data-name="<?= esc(strtolower($p['item_name'])) ?>"
                                 data-id="<?= esc($p['item_id']) ?>"
                                 data-item-name="<?= esc($p['item_name']) ?>"
                                 data-size="<?= esc($p['size'] ?? '') ?>"
                                 data-price="<?= esc($p['selling_price']) ?>"
                                 data-stock="<?= esc($p['current_stock']) ?>"
                                 data-rop="<?= esc($p['manual_rop_warning'] ?? 0) ?>">
                                <div class="icon"><i class="bi bi-box-seam"></i></div>
                                <div class="name"><?= esc($p['item_name']) ?></div>
                                <div class="size">Size <?= esc($p['size'] ?? '—') ?></div>
                                <div class="price">₱<?= number_format($p['selling_price'], 0) ?></div>
                                <div class="stock <?= $p['pos_status']['class'] ?>"><?= esc($p['pos_status']['label']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cart-panel">
                <form method="post" action="<?= site_url('staff/sales/checkout') ?>" id="cartForm">
                    <?= csrf_field() ?>
                    <div class="cart-head">
                        <div>Current order <span class="count" id="cartCount">0</span></div>
                        <button type="button" class="btn btn-sm btn-success" id="clearCart">Clear</button>
                    </div>
                    <div id="cartLines"><div class="cart-empty" id="cartEmptyMsg">Cart is empty.<br>Tap an item to add it.</div></div>

                    <div class="cart-totals">
                        <div class="row-line"><span>Subtotal</span><span id="cartSubtotal">₱0</span></div>
                        <div class="row-line discount-row" id="discountRow" style="display:none;"><span>Discount</span><span id="cartDiscountOut">- ₱0</span></div>
                        <div class="row-line total"><span>TOTAL</span><span id="cartTotal">₱0</span></div>
                        <div class="row-line muted"><span>VATable Sales</span><span id="cartVatable">₱0</span></div>
                        <div class="row-line muted"><span>VAT (12%)</span><span id="cartVat">₱0</span></div>
                    </div>
                    <div class="cart-note">Prices include 12% VAT. Buy 10 or more of the same item for 5% off, or a matching set (e.g. Upper + Lower) for 2% off.</div>

                    <label class="fw-bold mt-2 mb-1 d-block">Payment Method</label>
                    <input type="hidden" name="payment_method" id="paymentMethod" value="Cash">
                    <div class="pay-method-toggle">
                        <button type="button" class="pay-btn active" data-method="Cash">Cash</button>
                        <button type="button" class="pay-btn" data-method="GCash">GCash</button>
                        <button type="button" class="pay-btn" data-method="Split">Cash+GCash</button>
                    </div>

                    <div id="cashPanel" class="pay-panel">
                        <label class="fw-bold mb-1 d-block">Cash received</label>
                        <input type="number" name="cash_received" id="cashReceived" class="form-control mb-2" min="0" step="0.01" placeholder="₱0">
                        <div class="quick-chips" id="cashChips"></div>
                        <div class="cart-change"><span>Change</span><strong id="cartChange">₱0</strong></div>
                    </div>

                    <div id="gcashPanel" class="pay-panel" style="display:none;">
                        <label class="fw-bold mb-1 d-block">GCash amount</label>
                        <input type="text" id="gcashAmountDisplay" class="form-control mb-2" readonly>
                        <input type="hidden" name="gcash_amount" id="gcashAmountHidden" disabled>
                        <label class="fw-bold mb-1 d-block">Reference no.</label>
                        <input type="text" name="gcash_reference_no" id="gcashRefGcash" class="form-control mb-2" placeholder="e.g. 1234567890" disabled>
                    </div>

                    <div id="splitPanel" class="pay-panel" style="display:none;">
                        <label class="fw-bold mb-1 d-block">GCash amount</label>
                        <input type="number" name="gcash_amount" id="splitGcashAmount" class="form-control mb-2" min="0" step="0.01" placeholder="₱0" disabled>
                        <label class="fw-bold mb-1 d-block">Reference no.</label>
                        <input type="text" name="gcash_reference_no" id="gcashRefSplit" class="form-control mb-2" placeholder="e.g. 1234567890" disabled>
                        <div class="row-line"><span>Cash due</span><strong id="splitCashDue">₱0</strong></div>
                        <label class="fw-bold mb-1 d-block mt-2">Cash received</label>
                        <input type="number" name="cash_received" id="splitCashReceived" class="form-control mb-2" min="0" step="0.01" placeholder="₱0" disabled>
                        <div class="quick-chips" id="splitChips"></div>
                        <div class="cart-change"><span>Change</span><strong id="splitChange">₱0</strong></div>
                    </div>

                    <button type="submit" class="btn btn-success w-100 mt-2" id="checkoutBtn" disabled>
                        <i class="bi bi-check-lg"></i> Checkout &amp; Print Receipt
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <?php $transactions = $transactions ?? []; ?>
        <div class="rec-stats">
            <div class="rec-stat">
                <div class="rec-stat-icon"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="text-muted small">Total Sales Today</div>
                    <div class="value">₱<?= number_format($salesToday ?? 0, 0) ?></div>
                    <div class="trend"><i class="bi bi-arrow-up-short"></i> <?= (int) ($txnToday ?? 0) ?> transactions</div>
                </div>
            </div>
            <div class="rec-stat">
                <div class="rec-stat-icon"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="text-muted small">This week</div>
                    <div class="value">₱<?= number_format($salesWeek ?? 0, 0) ?></div>
                    <div class="trend"><i class="bi bi-arrow-up-short"></i> <?= (int) ($txnWeek ?? 0) ?> transactions</div>
                </div>
            </div>
            <div class="rec-stat">
                <div class="rec-stat-icon"><i class="bi bi-graph-up"></i></div>
                <div>
                    <div class="text-muted small">This Month</div>
                    <div class="value">₱<?= number_format($salesMonth ?? 0, 0) ?></div>
                    <div class="trend"><i class="bi bi-arrow-up-short"></i> <?= (int) ($txnMonth ?? 0) ?> transactions</div>
                </div>
            </div>
        </div>

        <div class="page-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Transaction History</h5>
                <div class="d-flex gap-2 align-items-center">
                    <form method="get" class="d-flex gap-2">
                        <input type="hidden" name="tab" value="receipts">
                        <input type="date" name="date" value="<?= esc($date ?? date('Y-m-d')) ?>" class="form-control" onchange="this.form.submit()">
                    </form>
                    <a href="<?= site_url('staff/sales/export-pdf') . '?date=' . esc($date ?? date('Y-m-d')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
                </div>
            </div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th>Receipt #</th><th>Date &amp; Time</th><th>Items</th><th>Quantity</th><th>Price</th><th>Discount</th><th>Cash</th><th>GCash</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $t): ?>
                                <?php
                                    $receiptPayload = [
                                        'receipt_no'         => $t['receipt_no'],
                                        'sale_date'          => $t['sale_date'],
                                        'items'              => array_map(fn ($l) => [
                                            'item_name'  => $l['item_name'] ?? $l['item_id'],
                                            'qty'        => (int) $l['quantity_sold'],
                                            'unit_price' => (float) $l['selling_price'],
                                            'total'      => round((float) $l['quantity_sold'] * (float) $l['selling_price'], 2),
                                        ], $t['items'] ?? []),
                                        'subtotal'           => (float) $t['subtotal'],
                                        'discount'           => (float) $t['discount'],
                                        'total'              => (float) $t['total_amount'],
                                        'payment_method'     => $t['payment_method'],
                                        'cash_amount'        => (float) $t['cash_amount'],
                                        'gcash_amount'       => (float) $t['gcash_amount'],
                                        'gcash_reference_no' => $t['gcash_reference_no'] ?? null,
                                    ];
                                ?>
                                <tr>
                                    <td><a href="javascript:void(0)" class="view-receipt-link" data-receipt="<?= esc(json_encode($receiptPayload), 'attr') ?>"><strong>#<?= esc($t['receipt_no']) ?></strong></a></td>
                                    <td><?= esc(date('M j, Y g:i A', strtotime($t['sale_date']))) ?></td>
                                    <td>
                                        <?php foreach (($t['items'] ?? []) as $line): ?>
                                            <div class="item-line"><?= esc($line['item_name'] ?? $line['item_id']) ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <?php foreach (($t['items'] ?? []) as $line): ?>
                                            <div class="item-line"><?= esc($line['quantity_sold']) ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <?php foreach (($t['items'] ?? []) as $line): ?>
                                            <div class="item-line">₱<?= number_format((float) $line['selling_price'], 2) ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td><?= (float) ($t['discount'] ?? 0) > 0 ? '- ₱' . number_format((float) $t['discount'], 2) : '—' ?></td>
                                    <td><?= (float) ($t['cash_amount'] ?? 0) > 0 ? '₱' . number_format((float) $t['cash_amount'], 2) : '—' ?></td>
                                    <td>
                                        <?php if ((float) ($t['gcash_amount'] ?? 0) > 0): ?>
                                            ₱<?= number_format((float) $t['gcash_amount'], 2) ?>
                                            <?php if (!empty($t['gcash_reference_no'])): ?>
                                                <div class="text-muted small">Ref: <?= esc($t['gcash_reference_no']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>₱<?= number_format((float) $t['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center py-4">No sales transactions recorded this month.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($receipt)): ?>
<div class="receipt-modal show" id="receiptModal">
    <div class="receipt-card">
        <button type="button" class="rc-close" id="closeReceipt">&times;</button>
        <div class="inv-title">RFOURL MILITARY SUPPLY</div>
        <div class="inv-sub">#5 3rd Ave., Bagong Lipunan ng Crame,<br>1111 Quezon City NCR, 2nd Dist., Phils.</div>
        <div class="inv-sub">RICHELLE D. P. PARAS - Prop.</div>
        <div class="inv-sub">Non-VAT Reg. TIN: 335-802-288-00000</div>

        <div class="inv-heading-row">
            <div class="inv-heading">SALES INVOICE</div>
            <div class="inv-no"><span class="inv-no-label">Nº</span><?= esc($receipt['receipt_no']) ?></div>
        </div>

        <div class="inv-field-row">
            <div class="inv-field">Sold to<span class="inv-line">&nbsp;</span></div>
            <div class="inv-field">Date:<span class="inv-line"><?= esc(date('M j, Y', strtotime($receipt['sale_date']))) ?></span></div>
        </div>
        <div class="inv-field-row">
            <div class="inv-field">TIN:<span class="inv-line">&nbsp;</span></div>
            <div class="inv-field">Terms:<span class="inv-line">&nbsp;</span></div>
        </div>
        <div class="inv-field-row">
            <div class="inv-field">Address<span class="inv-line">&nbsp;</span></div>
            <div class="inv-field">Business Style:<span class="inv-line">&nbsp;</span></div>
        </div>

        <table class="inv-table">
            <thead><tr><th>Qty.</th><th>Unit</th><th>ARTICLES</th><th class="num">Unit Price</th><th class="num">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($receipt['lines'] as $l): ?>
                    <tr>
                        <td style="text-align:center;"><?= esc($l['qty']) ?></td>
                        <td style="text-align:center;">pc</td>
                        <td><?= esc($l['item_name']) ?><?php if (!empty($l['badge'])): ?> <span style="font-size:10px;color:#8a6300;">(<?= esc($l['badge']) ?>)</span><?php endif; ?></td>
                        <td class="num">₱<?= number_format($l['unit_price'], 2) ?></td>
                        <td class="num">₱<?= number_format($l['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = count($receipt['lines']); $i < 5; $i++): ?>
                    <tr class="blank-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div class="inv-totals">
            <div><span>Subtotal</span><span>₱<?= number_format($receipt['subtotal'], 2) ?></span></div>
            <div><span>Less: Discount</span><span>₱<?= number_format($receipt['discount'], 2) ?></span></div>
            <div><span>Add: VAT (12%)</span><span>₱<?= number_format($receipt['vat'], 2) ?></span></div>
            <div class="grand"><span>TOTAL AMOUNT DUE</span><span>₱<?= number_format($receipt['total'], 2) ?></span></div>
        </div>

        <div class="inv-payment">
            <?php if ($receipt['payment_method'] === 'Split'): ?>
                <div><span>Paid via GCash<?= !empty($receipt['gcash_reference_no']) ? ' (Ref: ' . esc($receipt['gcash_reference_no']) . ')' : '' ?></span><span>₱<?= number_format($receipt['gcash_amount'], 2) ?></span></div>
                <div><span>Paid via Cash</span><span>₱<?= number_format($receipt['cash_amount'], 2) ?></span></div>
                <div><span>Cash Tendered</span><span>₱<?= number_format($receipt['cash_received'], 2) ?></span></div>
                <div><span>Change</span><span>₱<?= number_format($receipt['change'], 2) ?></span></div>
            <?php elseif ($receipt['payment_method'] === 'GCash'): ?>
                <div><span>Paid via GCash<?= !empty($receipt['gcash_reference_no']) ? ' (Ref: ' . esc($receipt['gcash_reference_no']) . ')' : '' ?></span><span>₱<?= number_format($receipt['gcash_amount'], 2) ?></span></div>
            <?php else: ?>
                <div><span>Cash Tendered</span><span>₱<?= number_format($receipt['cash_received'], 2) ?></span></div>
                <div><span>Change</span><span>₱<?= number_format($receipt['change'], 2) ?></span></div>
            <?php endif; ?>
            <div><span>Payment Method</span><span><?= esc(strtoupper($receipt['payment_method'] === 'Split' ? 'CASH + GCASH' : $receipt['payment_method'])) ?></span></div>
        </div>

        <div class="inv-signature">Cashier/Authorized Representative</div>

        <div class="rc-footer">This receipt is system-generated. Not valid for claim of input taxes.<br>Concerns: rfourlmilitary@gmail.com</div>

        <div class="rc-actions">
            <button type="button" onclick="window.print()">Print</button>
            <a href="<?= site_url('staff/sales') ?>">+ New Sale</a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="receipt-modal" id="historyReceiptModal">
    <div class="receipt-card">
        <button type="button" class="rc-close" id="closeHistoryReceipt">&times;</button>
        <div class="inv-title">RFOURL MILITARY SUPPLY</div>
        <div class="inv-sub">#5 3rd Ave., Bagong Lipunan ng Crame,<br>1111 Quezon City NCR, 2nd Dist., Phils.</div>
        <div class="inv-sub">RICHELLE D. P. PARAS - Prop.</div>
        <div class="inv-sub">Non-VAT Reg. TIN: 335-802-288-00000</div>

        <div class="inv-heading-row">
            <div class="inv-heading">SALES INVOICE</div>
            <div class="inv-no"><span class="inv-no-label">Nº</span><span id="hrReceiptNo"></span></div>
        </div>

        <div class="inv-field-row">
            <div class="inv-field">Sold to<span class="inv-line">&nbsp;</span></div>
            <div class="inv-field">Date:<span class="inv-line" id="hrDate"></span></div>
        </div>
        <div class="inv-field-row">
            <div class="inv-field">TIN:<span class="inv-line">&nbsp;</span></div>
            <div class="inv-field">Terms:<span class="inv-line">&nbsp;</span></div>
        </div>
        <div class="inv-field-row">
            <div class="inv-field">Address<span class="inv-line">&nbsp;</span></div>
            <div class="inv-field">Business Style:<span class="inv-line">&nbsp;</span></div>
        </div>

        <table class="inv-table">
            <thead><tr><th>Qty.</th><th>Unit</th><th>ARTICLES</th><th class="num">Unit Price</th><th class="num">Amount</th></tr></thead>
            <tbody id="hrItemsBody"></tbody>
        </table>

        <div class="inv-totals" id="hrTotals"></div>
        <div class="inv-payment" id="hrPayment"></div>
        <div class="inv-signature">Cashier/Authorized Representative</div>
        <div class="rc-footer">This receipt is system-generated. Not valid for claim of input taxes.<br>Concerns: rfourlmilitary@gmail.com</div>
        <div class="rc-actions">
            <button type="button" onclick="window.print()">Print</button>
            <button type="button" id="closeHistoryReceipt2">Close</button>
        </div>
    </div>
</div>
<script>
(function () {
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    function showHistoryReceipt(r) {
        document.getElementById('hrReceiptNo').textContent = r.receipt_no;
        const saleDate = new Date(r.sale_date.replace(' ', 'T'));
        document.getElementById('hrDate').textContent = saleDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

        const rowsHtml = r.items.map(l =>
            `<tr><td style="text-align:center;">${l.qty}</td><td style="text-align:center;">pc</td><td>${escapeHtml(l.item_name)}</td><td class="num">₱${l.unit_price.toFixed(2)}</td><td class="num">₱${l.total.toFixed(2)}</td></tr>`
        );
        for (let i = r.items.length; i < 5; i++) {
            rowsHtml.push('<tr class="blank-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>');
        }
        document.getElementById('hrItemsBody').innerHTML = rowsHtml.join('');

        const vat = Math.round((r.total - r.total / 1.12) * 100) / 100;

        let totalsHtml = `<div><span>Subtotal</span><span>₱${r.subtotal.toFixed(2)}</span></div>`;
        totalsHtml += `<div><span>Less: Discount</span><span>₱${r.discount.toFixed(2)}</span></div>`;
        totalsHtml += `<div><span>Add: VAT (12%)</span><span>₱${vat.toFixed(2)}</span></div>`;
        totalsHtml += `<div class="grand"><span>TOTAL AMOUNT DUE</span><span>₱${r.total.toFixed(2)}</span></div>`;
        document.getElementById('hrTotals').innerHTML = totalsHtml;

        let paymentHtml = '';
        if (r.cash_amount > 0) {
            paymentHtml += `<div><span>Paid via Cash</span><span>₱${r.cash_amount.toFixed(2)}</span></div>`;
        }
        if (r.gcash_amount > 0) {
            const refSuffix = r.gcash_reference_no ? ` (Ref: ${escapeHtml(r.gcash_reference_no)})` : '';
            paymentHtml += `<div><span>Paid via GCash${refSuffix}</span><span>₱${r.gcash_amount.toFixed(2)}</span></div>`;
        }
        const methodLabel = r.payment_method === 'Split' ? 'CASH + GCASH' : r.payment_method.toUpperCase();
        paymentHtml += `<div><span>Payment Method</span><span>${escapeHtml(methodLabel)}</span></div>`;
        document.getElementById('hrPayment').innerHTML = paymentHtml;

        document.getElementById('historyReceiptModal').classList.add('show');
    }

    document.querySelectorAll('.view-receipt-link').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            showHistoryReceipt(JSON.parse(link.dataset.receipt));
        });
    });

    const closeHistory = () => document.getElementById('historyReceiptModal').classList.remove('show');
    document.getElementById('closeHistoryReceipt')?.addEventListener('click', closeHistory);
    document.getElementById('closeHistoryReceipt2')?.addEventListener('click', closeHistory);
})();
</script>

<script>
document.getElementById('closeReceipt')?.addEventListener('click', () => {
    document.getElementById('receiptModal').classList.remove('show');
});

if (document.getElementById('posGrid')) {
    const cart = {};

    document.querySelectorAll('.pill-tabs button').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.pill-tabs button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterCards();
        });
    });
    document.getElementById('posSearch').addEventListener('input', filterCards);

    function filterCards() {
        const cat = document.querySelector('.pill-tabs button.active').dataset.cat;
        const term = document.getElementById('posSearch').value.toLowerCase();
        document.querySelectorAll('.pos-card').forEach(card => {
            const matchCat = !cat || card.dataset.cat === cat;
            const matchTerm = !term || card.dataset.name.includes(term);
            card.style.display = (matchCat && matchTerm) ? '' : 'none';
        });
    }

    document.querySelectorAll('.pos-card:not(.disabled)').forEach(card => {
        card.addEventListener('click', () => {
            const id = card.dataset.id;
            if (!cart[id]) {
                cart[id] = { name: card.dataset.itemName, size: card.dataset.size, price: parseFloat(card.dataset.price), stock: parseInt(card.dataset.stock), qty: 0 };
            }
            if (cart[id].qty < cart[id].stock) cart[id].qty++;
            card.classList.add('selected');
            renderCart();
        });
    });

    // Mirrors App\Libraries\PosPricing::priceCart() for instant on-screen
    // feedback; the server recomputes this authoritatively at checkout.
    const BULK_QTY_THRESHOLD = 10;
    const BULK_DISCOUNT_RATE = 0.05;
    const SET_DISCOUNT_RATE = 0.02;
    const VAT_RATE = 0.12;
    const UPPER_WORDS = ['upper', 'top', 'shirt', 'blouse', 'jacket', 'polo'];
    const LOWER_WORDS = ['lower', 'bottom', 'pants', 'trousers', 'shorts', 'skirt'];

    function splitSetName(name) {
        const words = name.trim().split(/\s+/);
        let marker = null;
        const baseWords = [];
        for (const w of words) {
            const lw = w.toLowerCase();
            if (marker === null && UPPER_WORDS.includes(lw)) { marker = 'upper'; continue; }
            if (marker === null && LOWER_WORDS.includes(lw)) { marker = 'lower'; continue; }
            baseWords.push(lw);
        }
        return [baseWords.join(' '), marker];
    }

    function priceCart(ids) {
        const groups = {};
        ids.forEach((id, i) => {
            const [base, marker] = splitSetName(cart[id].name);
            if (marker && base) {
                groups[base] = groups[base] || { upper: [], lower: [] };
                groups[base][marker].push(i);
            }
        });
        const setIndexes = {};
        Object.values(groups).forEach(g => {
            if (g.upper.length && g.lower.length) {
                [...g.upper, ...g.lower].forEach(i => setIndexes[i] = true);
            }
        });

        let subtotal = 0, discount = 0;
        const lines = ids.map((id, i) => {
            const item = cart[id];
            const lineSubtotal = Math.round(item.price * item.qty * 100) / 100;
            subtotal += lineSubtotal;
            let lineDiscount = 0, badge = null;
            if (item.qty >= BULK_QTY_THRESHOLD) {
                lineDiscount = Math.round(lineSubtotal * BULK_DISCOUNT_RATE * 100) / 100;
                badge = 'Bundle -5%';
            } else if (setIndexes[i]) {
                lineDiscount = Math.round(lineSubtotal * SET_DISCOUNT_RATE * 100) / 100;
                badge = 'Set -2%';
            }
            discount += lineDiscount;
            // Set discount is applied to the combined total of the matched
            // pair, not to either line individually, so the line still
            // shows its full price; bulk discount belongs to one line and
            // is shown subtracted from it directly.
            const lineTotal = badge === 'Set -2%' ? lineSubtotal : lineSubtotal - lineDiscount;
            return { id, item, lineSubtotal, lineDiscount, badge, lineTotal };
        });

        subtotal = Math.round(subtotal * 100) / 100;
        discount = Math.round(discount * 100) / 100;
        const total = Math.max(0, Math.round((subtotal - discount) * 100) / 100);
        const vat = Math.round((total - total / (1 + VAT_RATE)) * 100) / 100;
        const vatable = Math.round((total - vat) * 100) / 100;

        return { lines, subtotal, discount, total, vatable, vat };
    }

    function renderCart() {
        const linesEl = document.getElementById('cartLines');
        const ids = Object.keys(cart).filter(id => cart[id].qty > 0);
        document.getElementById('cartCount').textContent = ids.length;

        const pricing = priceCart(ids);

        if (ids.length === 0) {
            linesEl.innerHTML = '<div class="cart-empty">Cart is empty.<br>Tap an item to add it.</div>';
        } else {
            linesEl.innerHTML = pricing.lines.map(l => {
                const item = l.item;
                return `<div class="cart-line">
                    <div class="info">
                        <div class="name">${item.name}${item.size ? ' &middot; ' + item.size : ''}${l.badge ? '<span class="badge-disc">' + l.badge + '</span>' : ''}</div>
                        <div class="price">₱${item.price}</div>
                    </div>
                    <div class="qty-ctrl">
                        <button type="button" onclick="posDec('${l.id}')">-</button>
                        <span>${item.qty}</span>
                        <button type="button" onclick="posInc('${l.id}')">+</button>
                    </div>
                    <div class="line-total">₱${l.lineTotal.toLocaleString()}${(l.lineDiscount > 0 && l.badge !== 'Set -2%') ? '<span class="line-disc">-₱' + l.lineDiscount.toLocaleString() + '</span>' : ''}</div>
                    <input type="hidden" name="item_id[]" value="${l.id}">
                    <input type="hidden" name="quantity[]" value="${item.qty}">
                </div>`;
            }).join('');
        }

        document.getElementById('cartSubtotal').textContent = '₱' + pricing.subtotal.toLocaleString();
        document.getElementById('discountRow').style.display = pricing.discount > 0 ? '' : 'none';
        document.getElementById('cartDiscountOut').textContent = '- ₱' + pricing.discount.toLocaleString();
        document.getElementById('cartTotal').textContent = '₱' + pricing.total.toLocaleString();
        document.getElementById('cartVatable').textContent = '₱' + pricing.vatable.toLocaleString();
        document.getElementById('cartVat').textContent = '₱' + pricing.vat.toLocaleString();
        document.getElementById('checkoutBtn').disabled = ids.length === 0;

        renderChips('cashChips', pricing.total, 'cashReceived');
        renderChips('splitChips', null, 'splitCashReceived');
        updateGcashPanel(pricing.total);
        updateSplitPanel(pricing.total);
        updateChange();
        updateCardAvailability();
    }

    // Grays out a product card, matching the "Out of Stock" look, once the
    // quantity already in the cart uses up all remaining stock — so the
    // customer can't add an 11th unit of an item that only had 10 left.
    function updateCardAvailability() {
        document.querySelectorAll('.pos-card').forEach(card => {
            const totalStock = parseInt(card.dataset.stock, 10);
            if (totalStock <= 0) return; // already permanently out of stock

            const id = card.dataset.id;
            const rop = parseInt(card.dataset.rop, 10) || 0;
            const remaining = totalStock - (cart[id] ? cart[id].qty : 0);
            const stockEl = card.querySelector('.stock');

            if (remaining <= 0) {
                card.classList.add('disabled');
                if (stockEl) { stockEl.textContent = 'Out of Stock'; stockEl.className = 'stock red'; }
            } else {
                card.classList.remove('disabled');
                if (stockEl) {
                    const status = rop > 0 && remaining <= rop
                        ? { label: `${remaining} Left (Low)`, cls: 'amber' }
                        : { label: `${remaining} in Stock`, cls: 'green' };
                    stockEl.textContent = status.label;
                    stockEl.className = 'stock ' + status.cls;
                }
            }
        });
    }

    function roundUpTo(amount, step) {
        return Math.ceil(amount / step) * step;
    }

    function renderChips(containerId, dueAmount, targetInputId) {
        const el = document.getElementById(containerId);
        if (!el) return;
        if (dueAmount === null) { el.innerHTML = ''; return; }
        if (dueAmount <= 0) { el.innerHTML = ''; return; }
        const amounts = [dueAmount, roundUpTo(dueAmount, 100), roundUpTo(dueAmount, 500), roundUpTo(dueAmount, 1000)];
        const seen = new Set();
        const chips = amounts.filter(a => { if (seen.has(a)) return false; seen.add(a); return true; });
        el.innerHTML = chips.map(a => `<button type="button" class="quick-chip" data-amt="${a}">₱${a.toLocaleString()}</button>`).join('');
        el.querySelectorAll('.quick-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.getElementById(targetInputId).value = chip.dataset.amt;
                updateChange();
            });
        });
    }

    function updateGcashPanel(total) {
        document.getElementById('gcashAmountDisplay').value = '₱' + total.toLocaleString();
        document.getElementById('gcashAmountHidden').value = total.toFixed(2);
    }

    function updateSplitPanel(total) {
        const gcashAmt = parseFloat(document.getElementById('splitGcashAmount').value) || 0;
        const cashDue = Math.max(0, total - gcashAmt);
        document.getElementById('splitCashDue').textContent = '₱' + cashDue.toLocaleString();
        renderChips('splitChips', cashDue, 'splitCashReceived');
    }

    function updateChange() {
        const total = parseFloat(document.getElementById('cartTotal').textContent.replace(/[^0-9.]/g, '')) || 0;
        const method = document.getElementById('paymentMethod').value;
        if (method === 'Split') {
            const gcashAmt = parseFloat(document.getElementById('splitGcashAmount').value) || 0;
            const cashDue = Math.max(0, total - gcashAmt);
            const received = parseFloat(document.getElementById('splitCashReceived').value) || 0;
            document.getElementById('splitChange').textContent = '₱' + Math.max(0, received - cashDue).toLocaleString();
        } else {
            const received = parseFloat(document.getElementById('cashReceived').value) || 0;
            document.getElementById('cartChange').textContent = '₱' + Math.max(0, received - total).toLocaleString();
        }
    }

    window.posInc = id => { if (cart[id].qty < cart[id].stock) cart[id].qty++; renderCart(); };
    window.posDec = id => { cart[id].qty = Math.max(0, cart[id].qty - 1); renderCart(); };

    document.getElementById('cashReceived').addEventListener('input', updateChange);
    document.getElementById('splitCashReceived').addEventListener('input', updateChange);
    document.getElementById('splitGcashAmount').addEventListener('input', () => {
        const total = parseFloat(document.getElementById('cartTotal').textContent.replace(/[^0-9.]/g, '')) || 0;
        updateSplitPanel(total);
        updateChange();
    });

    document.getElementById('clearCart').addEventListener('click', () => {
        Object.keys(cart).forEach(id => cart[id].qty = 0);
        document.querySelectorAll('.pos-card').forEach(c => c.classList.remove('selected'));
        renderCart();
    });

    const payPanels = { Cash: 'cashPanel', GCash: 'gcashPanel', Split: 'splitPanel' };
    document.querySelectorAll('.pay-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const method = btn.dataset.method;
            document.getElementById('paymentMethod').value = method;
            document.querySelectorAll('.pay-btn').forEach(b => b.classList.toggle('active', b === btn));
            Object.entries(payPanels).forEach(([m, panelId]) => {
                const panel = document.getElementById(panelId);
                panel.style.display = m === method ? '' : 'none';
                panel.querySelectorAll('input').forEach(inp => inp.disabled = (m !== method));
            });
            updateChange();
        });
    });

    renderCart();
}
</script>

<?= $this->endSection() ?>
