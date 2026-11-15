
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>

<?php $tab = $tab ?? 'pos'; ?>
<script src="/assets/js/clamp-tip.js" defer></script>

<link href="/assets/css/pos.css" rel="stylesheet">
<style>
/* Point of Sale tab and the tab bar: assets/css/pos.css. */

.rec-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
@media (max-width: 800px) { .rec-stats { grid-template-columns: 1fr; } }
.rec-stat { background: #fff; border-radius: var(--radius-card); padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); display: flex; align-items: center; gap: 14px; }
.rec-stat .rec-stat-icon { width: 42px; height: 42px; border-radius: var(--radius-icon); background: #eef0ea; color: #3d5230; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.rec-stat .value { font-size: 22px; font-weight: 800; }
.rec-stat .trend { color: #3d5230; font-size: 12px; font-weight: 600; }
.line-item-chip { display:inline-block; background:#eef0ea; border-radius: var(--radius-pill); padding: 3px 10px; font-size: 12px; margin: 2px 4px 2px 0; }
.item-line { padding: 2px 0; }
.item-line:not(:last-child) { border-bottom: 1px dashed #eee; }
.view-receipt-link { text-decoration: none; }
.view-receipt-link:hover strong { text-decoration: underline; }

.receipt-modal { position: fixed; inset: 0; z-index: 1100; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.45); }
.receipt-modal.show { display: flex; }
.receipt-card {
    width: min(600px, 94vw); max-height: 92vh; overflow-y: auto; background: #fff; border-radius: var(--radius-card);
    padding: 32px 36px; font-family: Georgia, 'Times New Roman', serif; color: #1c1c1c;
}
.receipt-card .rc-close { float: right; background: none; border: none; font-size: 20px; cursor: pointer; font-family: Arial, sans-serif; }
.inv-title { text-align: center; font-weight: 800; font-size: 25px; letter-spacing: .01em; margin: 0 0 8px; }
.inv-sub { text-align: center; font-size: 12px; color: #333; line-height: 1.5; }
.inv-heading-row { display: flex; justify-content: space-between; align-items: baseline; margin: 18px 0 14px; padding: 8px 2px; }
.inv-heading { font-weight: 800; font-size: 15px; letter-spacing: .05em; }
.inv-no { font-weight: 800; font-size: 19px; color: var(--accent-maroon); }
.inv-no .inv-no-label { font-size: 13px; margin-right: 8px; }
.inv-field-row { display: flex; gap: 24px; font-size: 12.5px; margin-bottom: 9px; }
.inv-field { flex: 1; }
.inv-field .inv-line { display: inline-block; border-bottom: 1px solid #999; min-width: 55%; padding-bottom: 1px; margin-left: 4px; }
.inv-table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin: 16px 0 6px; }
.inv-table th, .inv-table td { border: 1px solid #999; padding: 5px 8px; text-align: left; }
.inv-table th { text-align: center; font-weight: 700; }
.inv-table td.num, .inv-table th.num { text-align: right; }
.inv-table .blank-row td { height: 20px; }
.inv-totals { margin-left: auto; width: 62%; margin-top: 4px; }
.inv-totals div { display: flex; justify-content: space-between; font-size: 12.5px; padding: 3px 6px; }
.inv-totals div.grand { font-weight: 800; font-size: 14px; border-top: 2px solid #1c1c1c; border-bottom: 2px solid #1c1c1c; margin-top: 2px; padding: 6px; }
.inv-payment { margin-top: 14px; font-size: 12.5px; border-top: 1px dashed #ccc; padding-top: 10px; }
.inv-payment div { display: flex; justify-content: space-between; padding: 2px 0; }

/* Printing-house / BIR boilerplate that's pre-printed on the shop's real
   invoice booklets — static on every receipt, so it's plain markup rather
   than anything computed per-sale. */
.inv-print-footer { display: flex; justify-content: space-between; gap: 20px; margin-top: 28px; font-size: 9.5px; line-height: 1.5; color: #1c1c1c; font-family: Arial, sans-serif; }
.inv-print-footer .pf-left { max-width: 58%; }
.inv-print-footer .pf-bold { font-weight: 700; }
.inv-print-footer .pf-right { text-align: center; min-width: 190px; }
.inv-print-footer .pf-sig-line { border-top: 1px solid #1c1c1c; margin-bottom: 4px; margin-top: 16px; }
.inv-print-footer .pf-sig-name { font-family: Georgia, 'Times New Roman', serif; font-size: 12px; margin-bottom: 2px; }
.inv-print-footer .pf-sig-label { font-weight: 700; margin-bottom: 6px; font-family: Georgia, 'Times New Roman', serif; font-size: 12px; }
.inv-disclaimer { text-align: center; font-size: 10px; font-weight: 700; text-decoration: underline; margin-top: 18px; font-family: Arial, sans-serif; }
.rc-actions { display: flex; gap: 10px; margin-top: 16px; }
.rc-actions button, .rc-actions a { flex: 1; text-align: center; padding: 10px; border-radius: var(--radius-control); font-weight: 700; border: 1px solid #ccc; background: #fff; cursor: pointer; text-decoration: none; color: #1c1c1c; font-family: Arial, sans-serif; }

@media print {
    @page { margin: 12mm; }
    body * { visibility: hidden; }
    .receipt-modal.show, .receipt-modal.show * { visibility: visible; }
    .receipt-modal.show {
        position: absolute; inset: 0; background: #fff;
        display: block;
    }
    /* max-height/overflow-y are screen-only concerns (vh has no meaning on
       a printed page and clips/rescales content unpredictably); drop them
       here so the printed receipt matches the on-screen card exactly. */
    .receipt-card {
        width: 100%; max-width: 600px; max-height: none; overflow: visible;
        margin: 0 auto; box-shadow: none; border-radius: 0; padding: 0;
    }
    .receipt-modal.show * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .rc-close, .rc-actions { display: none; }
}
</style>

<div class="page-wrap<?= $tab === 'pos' ? ' pos-page' : '' ?>">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="pos-tabs sticky-tabs">
        <a href="?tab=pos" class="<?= $tab === 'pos' ? 'active' : '' ?>">Point of Sale</a>
        <a href="?tab=receipts" class="<?= $tab === 'receipts' ? 'active' : '' ?>">Sales &amp; Receipts</a>
    </div>


    <?php if ($tab === 'pos'): ?>
        <?= view('partials/_pos_tab', ['base' => 'admin', 'groups' => $groups ?? [], 'categories' => $categories ?? []]) ?>
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
                    <a href="<?= site_url('admin/sales/export-pdf') . '?date=' . esc($date ?? date('Y-m-d')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
                    <a href="<?= site_url('admin/sales/export-csv') . '?date=' . esc($date ?? date('Y-m-d')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-csv"></i> Export CSV</a>
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
                                        'or_number'          => $t['or_number'] ?? null,
                                        'staff_name'         => ($t['cashier_role'] ?? null) === 'Admin' ? 'Owner' : ($t['cashier_name'] ?? null),
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

<?php $receipt = $receipt ?? null; ?>
<?php if (!empty($receipt)): ?>
<div class="receipt-modal show" id="receiptModal">
    <div class="receipt-card">
        <button type="button" class="rc-close" id="closeReceipt">&times;</button>
        <div class="inv-title">RFOURL MILITARY SUPPLY</div>
        <div class="inv-sub">#5 3rd Ave., Bagong Lipunan ng Crame,<br>1111 Quezon City NCR, 2nd Dist., Phils.</div>
        <div class="inv-sub">RICHELLE D. P. PARAS - Prop.</div>
        <div class="inv-sub">Non-VAT Reg. TIN: 335-802-288-00000</div>

        <div class="inv-heading-row">
            <div class="inv-heading">SALES RECEIPT</div>
            <div class="inv-no"><span class="inv-no-label">Nº</span><?= esc($receipt['receipt_no']) ?></div>
        </div>

        <div class="inv-sub inv-not-official">This is not an official receipt.</div>
        <?php if (!empty($receipt['or_number'])): ?><div class="inv-sub inv-or-no">OR / Invoice no.: <strong><?= esc($receipt['or_number']) ?></strong></div><?php endif; ?>

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
                        <td><?= esc($l['item_name']) ?><?php if (!empty($l['badge'])): ?> <span style="font-size:10px;">(<?= esc($l['badge']) ?>)</span><?php endif; ?></td>
                        <td class="num">₱<?= number_format($l['unit_price'], 2) ?></td>
                        <td class="num">₱<?= number_format($l['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < 2; $i++): ?>
                    <tr class="blank-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div class="inv-totals">
            <div><span>Subtotal</span><span>₱<?= number_format($receipt['subtotal'], 2) ?></span></div>
            <div><span>Less: Discount</span><span>₱<?= number_format($receipt['discount'], 2) ?></span></div>
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

        <div class="inv-print-footer">
            <div class="pf-left">
                <div>50 Bklts. (50 &times; 2) 1001-3500</div>
                <div>BIR Authority to Print No.: 040AU20230000001797</div>
                <div>Date Issued: 03-24-2023</div>
                <div class="pf-bold">VICKIES PRINTING HOUSE (VICTOR S. OCAMPO)</div>
                <div>1124-A T. Mapua St., Brgy 335 Zone 33, Sta. Cruz, Manila</div>
                <div class="pf-bold">TIN: 255-258-227-00000 Non-VAT</div>
            </div>
            <div class="pf-right">
                <div class="pf-sig-line"></div>
                <div class="pf-sig-name"><?= esc($receipt['staff_name'] ?? '') ?></div>
                <div class="pf-sig-label">Cashier/Authorized Representative</div>
                <div>Printer's Accreditation No. 031MP20190000000028</div>
                <div>Date Issued: 06-19-2019 Expiry date: 06-18-2024</div>
            </div>
        </div>
        <div class="inv-disclaimer">&quot;THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAXES&quot;</div>

        <div class="rc-actions">
            <button type="button" onclick="window.print()">Print</button>
            <a href="<?= site_url('admin/sales') ?>">+ New Sale</a>
        </div>
    </div>
</div>
<script>
document.getElementById('closeReceipt')?.addEventListener('click', () => {
    document.getElementById('receiptModal').classList.remove('show');
});
</script>
<?php endif; ?>

<div class="receipt-modal" id="historyReceiptModal">
    <div class="receipt-card">
        <button type="button" class="rc-close" id="closeHistoryReceipt">&times;</button>
        <div class="inv-title">RFOURL MILITARY SUPPLY</div>
        <div class="inv-sub">#5 3rd Ave., Bagong Lipunan ng Crame,<br>1111 Quezon City NCR, 2nd Dist., Phils.</div>
        <div class="inv-sub">RICHELLE D. P. PARAS - Prop.</div>
        <div class="inv-sub">Non-VAT Reg. TIN: 335-802-288-00000</div>

        <div class="inv-heading-row">
            <div class="inv-heading">SALES RECEIPT</div>
            <div class="inv-no"><span class="inv-no-label">Nº</span><span id="hrReceiptNo"></span></div>
        </div>

        <div class="inv-sub inv-not-official">This is not an official receipt.</div>
        <div class="inv-sub inv-or-no" id="hrOrRow" style="display:none;">OR / Invoice no.: <strong id="hrOrNo"></strong></div>

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

        <div class="inv-print-footer">
            <div class="pf-left">
                <div>50 Bklts. (50 &times; 2) 1001-3500</div>
                <div>BIR Authority to Print No.: 040AU20230000001797</div>
                <div>Date Issued: 03-24-2023</div>
                <div class="pf-bold">VICKIES PRINTING HOUSE (VICTOR S. OCAMPO)</div>
                <div>1124-A T. Mapua St., Brgy 335 Zone 33, Sta. Cruz, Manila</div>
                <div class="pf-bold">TIN: 255-258-227-00000 Non-VAT</div>
            </div>
            <div class="pf-right">
                <div class="pf-sig-line"></div>
                <div class="pf-sig-name" id="hrSigName"></div>
                <div class="pf-sig-label">Cashier/Authorized Representative</div>
                <div>Printer's Accreditation No. 031MP20190000000028</div>
                <div>Date Issued: 06-19-2019 Expiry date: 06-18-2024</div>
            </div>
        </div>
        <div class="inv-disclaimer">&quot;THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAXES&quot;</div>

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
        document.getElementById('hrOrNo').textContent = r.or_number || '';
        document.getElementById('hrOrRow').style.display = r.or_number ? '' : 'none';
        const saleDate = new Date(r.sale_date.replace(' ', 'T'));
        document.getElementById('hrDate').textContent = saleDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

        const rowsHtml = r.items.map(l =>
            `<tr><td style="text-align:center;">${l.qty}</td><td style="text-align:center;">pc</td><td>${escapeHtml(l.item_name)}</td><td class="num">₱${l.unit_price.toFixed(2)}</td><td class="num">₱${l.total.toFixed(2)}</td></tr>`
        );
        for (let i = 0; i < 2; i++) {
            rowsHtml.push('<tr class="blank-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>');
        }
        document.getElementById('hrItemsBody').innerHTML = rowsHtml.join('');

        let totalsHtml = `<div><span>Subtotal</span><span>₱${r.subtotal.toFixed(2)}</span></div>`;
        totalsHtml += `<div><span>Less: Discount</span><span>₱${r.discount.toFixed(2)}</span></div>`;
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
        document.getElementById('hrSigName').textContent = r.staff_name || '';

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
function printReceipt() {
    try {
        if (typeof window.print !== 'function') {
            throw new Error('Printing is not supported in this browser.');
        }
        window.print();
    } catch (err) {
        console.error('Receipt print failed:', err);
        if (typeof showToast === 'function') {
            showToast({ type: 'error', title: "Couldn't print the receipt", message: "The print dialog didn't open. Try again, or press Ctrl+P to print manually." });
        } else {
            alert("Couldn't open the print dialog. Try again, or press Ctrl+P to print manually.");
        }
    }
}
</script>

<?= $this->endSection() ?>
