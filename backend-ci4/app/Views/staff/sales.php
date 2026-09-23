
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
.cart-line .price { color: #777; font-size: 12px; text-decoration: line-through; }
.cart-line .qty-ctrl { display: flex; align-items: center; gap: 6px; }
.cart-line .qty-ctrl button { width: 26px; height: 26px; border: 1px solid #ccc; border-radius: 6px; background: #fff; }
.cart-line .line-total { font-weight: 700; }
.cart-empty { text-align: center; color: #999; padding: 30px 0; }
.cart-totals { border-top: 1px solid #333; padding-top: 10px; margin-top: 10px; }
.cart-totals .row-line { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 4px; }
.cart-totals .row-line.total { font-weight: 800; font-size: 17px; }
.cart-change { background: var(--green-bg); border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; margin: 12px 0; }

.rec-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
@media (max-width: 800px) { .rec-stats { grid-template-columns: 1fr; } }
.rec-stat { background: #fff; border-radius: 14px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.rec-stat .value { font-size: 24px; font-weight: 800; }
.line-item-chip { display:inline-block; background:#eef0ea; border-radius: 999px; padding: 3px 10px; font-size: 12px; margin: 2px 4px 2px 0; }

/* Receipt modal */
.receipt-modal { position: fixed; inset: 0; z-index: 1100; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.45); }
.receipt-modal.show { display: flex; }
.receipt-card { width: min(420px, 92vw); max-height: 92vh; overflow-y: auto; background: #fff; border-radius: 14px; padding: 24px; font-family: 'Courier New', monospace; }
.receipt-card .rc-close { float: right; background: none; border: none; font-size: 20px; cursor: pointer; }
.receipt-card h5 { text-align: center; font-weight: 800; margin-bottom: 4px; }
.receipt-card .rc-sub { text-align: center; font-size: 11px; color: #444; margin-bottom: 4px; }
.receipt-card hr { border-top: 1px dashed #999; }
.receipt-card table { width: 100%; font-size: 13px; margin: 10px 0; }
.receipt-card table th { text-align: left; border-bottom: 1px solid #333; padding-bottom: 4px; }
.receipt-card table td { padding: 3px 0; }
.receipt-card .rc-totals div { display: flex; justify-content: space-between; font-size: 13px; }
.receipt-card .rc-totals .grand { font-weight: 800; font-size: 15px; }
.rc-barcode { text-align: center; font-size: 22px; letter-spacing: 2px; margin: 14px 0 4px; }
.rc-barcode-no { text-align: center; font-size: 11px; letter-spacing: 3px; margin-bottom: 10px; }
.rc-footer { text-align: center; font-size: 11px; color: #444; margin-top: 10px; }
.rc-actions { display: flex; gap: 10px; margin-top: 16px; }
.rc-actions button, .rc-actions a { flex: 1; text-align: center; padding: 10px; border-radius: 8px; font-weight: 700; border: 1px solid #ccc; background: #fff; cursor: pointer; text-decoration: none; color: #1c1c1c; }

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
                                 data-stock="<?= esc($p['current_stock']) ?>">
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
                        <div class="row-line"><span>Discount</span><span>- ₱<input type="number" name="discount" id="cartDiscount" value="0" min="0" style="width:60px; border:none; text-align:right;"></span></div>
                        <div class="row-line total"><span>TOTAL</span><span id="cartTotal">₱0</span></div>
                    </div>

                    <label class="fw-bold mt-2 mb-1 d-block">Payment Method</label>
                    <select name="payment_method" class="form-select mb-2">
                        <option value="GCash">GCash</option>
                        <option value="Cash">Cash</option>
                    </select>

                    <label class="fw-bold mb-1 d-block">Amount Paid</label>
                    <input type="number" name="amount_paid" id="amountPaid" class="form-control mb-2" min="0" placeholder="₱0">

                    <div class="cart-change"><span>Change</span><strong id="cartChange">₱0</strong></div>

                    <button type="submit" class="btn btn-success w-100" id="checkoutBtn" disabled>
                        <i class="bi bi-check-lg"></i> Checkout &amp; Print Receipt
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <?php $transactions = $transactions ?? []; ?>
        <div class="rec-stats">
            <div class="rec-stat"><div class="text-muted small">Today's Sales</div><div class="value">₱<?= number_format($salesToday ?? 0, 0) ?></div></div>
            <div class="rec-stat"><div class="text-muted small">This week</div><div class="value">₱<?= number_format($salesWeek ?? 0, 0) ?></div></div>
            <div class="rec-stat"><div class="text-muted small">This Month</div><div class="value">₱<?= number_format($salesMonth ?? 0, 0) ?></div></div>
        </div>

        <div class="page-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Transaction History</h5>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="receipts">
                    <input type="month" name="month" value="<?= esc($month ?? date('Y-m')) ?>" class="form-control" onchange="this.form.submit()">
                </form>
            </div>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th>Receipt #</th><th>Time</th><th>Items</th><th>Payment</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><strong>#<?= esc($t['receipt_no']) ?></strong></td>
                                    <td><?= esc(date('g:i A', strtotime($t['sale_date']))) ?></td>
                                    <td><?php foreach (($t['items'] ?? []) as $line): ?><span class="line-item-chip"><?= esc($line['item_name'] ?? $line['item_id']) ?> ×<?= esc($line['quantity_sold']) ?></span><?php endforeach; ?></td>
                                    <td><span class="status-pill <?= $t['payment_method'] === 'GCash' ? 'blue' : 'green' ?>"><?= esc($t['payment_method']) ?></span></td>
                                    <td>₱<?= number_format((float) $t['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-4">No sales transactions recorded this month.</td></tr>
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
        <h5>RFOURL MILITARY SUPPLY</h5>
        <div class="rc-sub">#5 3rd Ave., Bagong Lipunan ng Crame,<br>1111 Quezon City NCR, 2nd Dist., Phils.</div>
        <div class="rc-sub">Receipt No: #<?= esc($receipt['receipt_no']) ?><br>Non-VAT Reg.</div>
        <hr>
        <table>
            <thead><tr><th>ITEM</th><th>QTY</th><th style="text-align:right;">Total</th></tr></thead>
            <tbody>
                <?php foreach ($receipt['lines'] as $l): ?>
                    <tr>
                        <td><?= esc($l['item_name']) ?><?= !empty($l['size']) ? ' ' . esc($l['size']) : '' ?></td>
                        <td>x<?= esc($l['qty']) ?></td>
                        <td style="text-align:right;">₱<?= number_format($l['total'], 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <hr>
        <div class="rc-totals">
            <div><span>SUBTOTAL</span><span>₱<?= number_format($receipt['subtotal_ex_vat'], 2) ?></span></div>
            <div><span>VAT (3%)</span><span>₱<?= number_format($receipt['vat'], 2) ?></span></div>
            <div class="grand"><span>TOTAL</span><span>₱<?= number_format($receipt['total'], 2) ?></span></div>
            <div><span>Cash Tendered</span><span>₱<?= number_format($receipt['amount_paid'], 2) ?></span></div>
            <div><span>CHANGE</span><span>₱<?= number_format($receipt['change'], 2) ?></span></div>
            <div><span>Payment</span><span><?= esc(strtoupper($receipt['payment_method'])) ?></span></div>
        </div>
        <div class="rc-barcode">| | | | | | | | | | | | |</div>
        <div class="rc-barcode-no">RFL-<?= esc($receipt['receipt_no']) ?>-<?= esc(date('His', strtotime($receipt['sale_date']))) ?></div>
        <div class="rc-footer">Thank you for your purchase!<br>All sales are final.<br>Concerns: rfourlmilitary@gmail.com</div>
        <div class="rc-actions">
            <button type="button" onclick="window.print()">Print</button>
            <a href="<?= site_url('staff/sales') ?>">+ New Sale</a>
        </div>
    </div>
</div>
<?php endif; ?>

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

    function renderCart() {
        const linesEl = document.getElementById('cartLines');
        const ids = Object.keys(cart).filter(id => cart[id].qty > 0);
        document.getElementById('cartCount').textContent = ids.length;

        if (ids.length === 0) {
            linesEl.innerHTML = '<div class="cart-empty">Cart is empty.<br>Tap an item to add it.</div>';
        } else {
            linesEl.innerHTML = ids.map(id => {
                const item = cart[id];
                return `<div class="cart-line">
                    <div class="info">
                        <div class="name">${item.name}${item.size ? ' &middot; ' + item.size : ''}</div>
                        <div class="price">₱${item.price}</div>
                    </div>
                    <div class="qty-ctrl">
                        <button type="button" onclick="posDec('${id}')">-</button>
                        <span>${item.qty}</span>
                        <button type="button" onclick="posInc('${id}')">+</button>
                    </div>
                    <div class="line-total">₱${(item.price * item.qty).toLocaleString()}</div>
                    <input type="hidden" name="item_id[]" value="${id}">
                    <input type="hidden" name="quantity[]" value="${item.qty}">
                </div>`;
            }).join('');
        }

        const subtotal = ids.reduce((sum, id) => sum + cart[id].price * cart[id].qty, 0);
        const discount = parseFloat(document.getElementById('cartDiscount').value) || 0;
        const total = Math.max(0, subtotal - discount);
        document.getElementById('cartSubtotal').textContent = '₱' + subtotal.toLocaleString();
        document.getElementById('cartTotal').textContent = '₱' + total.toLocaleString();
        document.getElementById('checkoutBtn').disabled = ids.length === 0;
        updateChange();
    }

    function updateChange() {
        const total = parseFloat(document.getElementById('cartTotal').textContent.replace(/[^0-9.]/g, '')) || 0;
        const paid = parseFloat(document.getElementById('amountPaid').value) || 0;
        document.getElementById('cartChange').textContent = '₱' + Math.max(0, paid - total).toLocaleString();
    }

    window.posInc = id => { if (cart[id].qty < cart[id].stock) cart[id].qty++; renderCart(); };
    window.posDec = id => { cart[id].qty = Math.max(0, cart[id].qty - 1); renderCart(); };

    document.getElementById('cartDiscount').addEventListener('input', renderCart);
    document.getElementById('amountPaid').addEventListener('input', updateChange);
    document.getElementById('clearCart').addEventListener('click', () => {
        Object.keys(cart).forEach(id => cart[id].qty = 0);
        document.querySelectorAll('.pos-card').forEach(c => c.classList.remove('selected'));
        renderCart();
    });
}
</script>

<?= $this->endSection() ?>
