<?php
// app/Views/partials/_pos_tab.php
// The Point of Sale tab of POS & Sales, the same for the Owner (admin/sales.php) and Staff
// (staff/sales.php): category chips, search (by name or item ID) and count, the item grid in
// its own scroll area, the cart, and the size picker. Styles: assets/css/pos.css. Behaviour:
// assets/js/pos.js. Category icons: App\Libraries\PosIcon.
//
// Expects $base ('admin' or 'staff'), $groups (ProductModel::groupedActive() + min_price,
// max_price, is_multi_size), $categories.
use App\Libraries\PosIcon;

$groups = $groups ?? [];
$count  = count($groups);
?>
<div class="pos-layout">
    <div class="pos-main">
        <div class="pos-toolbar">
            <div class="pos-chips" role="group" aria-label="Category">
                <button type="button" class="active" data-cat="" aria-pressed="true">All Items</button>
                <?php foreach (($categories ?? []) as $c): ?>
                    <button type="button" data-cat="<?= esc($c, 'attr') ?>" aria-pressed="false"><?= PosIcon::svg($c, 16) ?><?= esc($c) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="inv-search pos-search-box">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="posSearch" placeholder="Search by item name or ID&hellip;" aria-label="Search by item name or ID" autocomplete="off">
            </div>
            <div class="pos-count" id="posCount" aria-live="polite"><?= $count ?> item<?= $count === 1 ? '' : 's' ?></div>
        </div>

        <div class="pos-grid-scroll">
            <?php if (empty($groups)): ?>
                <div class="empty-state"><i class="bi bi-box-seam" aria-hidden="true"></i> No items to sell yet.
                    <?php if ($base === 'admin'): ?><a href="<?= site_url('admin/inventory') ?>" class="btn btn-success btn-sm ms-2">Add items</a><?php else: ?>Ask the owner.<?php endif; ?>
                </div>
            <?php else: ?>
                <div class="pos-grid" id="posGrid">
                    <?php foreach ($groups as $g): ?>
                        <?php
                        $variantsPayload = array_map(fn ($v) => [
                            'item_id'            => $v['item_id'],
                            'item_name'          => $v['item_name'],
                            'size'               => $v['size'] ?? '',
                            'selling_price'      => (float) $v['selling_price'],
                            'current_stock'      => (int) $v['current_stock'],
                            'manual_rop_warning' => (int) ($v['manual_rop_warning'] ?? 0),
                        ], $g['variants']);
                        $first = $g['variants'][0];
                        $sub   = $g['is_multi_size']
                            ? count($g['variants']) . ' sizes'
                            : $first['item_id'] . (trim((string) ($first['size'] ?? '')) !== '' ? ' · Size ' . $first['size'] : '');
                        $out   = $g['total_stock'] <= 0;
                        ?>
                        <div class="pos-card <?= $out ? 'disabled' : '' ?>"
                             data-cat="<?= esc($g['category']) ?>"
                             data-name="<?= esc(strtolower($g['item_name'])) ?>"
                             data-ids="<?= esc(implode(' ', array_column($g['variants'], 'item_id')), 'attr') ?>"
                             data-item-name="<?= esc($g['item_name']) ?>"
                             data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
                            <div class="pos-card-top">
                                <span class="pos-card-icon"><?= PosIcon::svg($g['category']) ?></span>
                                <span class="stock <?= $out ? 'out' : 'green' ?>"><?= $out ? 'Out of stock' : esc((string) $g['total_stock']) . ' in stock' ?></span>
                            </div>
                            <div class="name"><?= esc($g['item_name']) ?></div>
                            <div class="sub"><?= esc($sub) ?></div>
                            <div class="price">
                                <?= $g['min_price'] === $g['max_price']
                                    ? '₱' . number_format($g['min_price'], 0)
                                    : '₱' . number_format($g['min_price'], 0) . '–' . number_format($g['max_price'], 0) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="pos-none" id="posNone" hidden></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="cart-panel">
        <form method="post" action="<?= site_url($base . '/sales/checkout') ?>" id="cartForm">
            <?= csrf_field() ?>
            <div class="cart-head">
                <div>Current order <span class="count" id="cartCount">0</span></div>
                <button type="button" class="pos-clear" id="clearCart" disabled>Clear</button>
            </div>
            <div id="cartLines"><div class="cart-empty" id="cartEmptyMsg">Cart is empty.<br>Tap an item to add it.</div></div>

            <div class="cart-totals">
                <div class="row-line"><span>Subtotal</span><span id="cartSubtotal">₱0</span></div>
                <div class="row-line discount-row" id="discountRow" style="display:none;"><span>Discount</span><span id="cartDiscountOut">- ₱0</span></div>
                <div class="row-line total"><span>TOTAL</span><span id="cartTotal">₱0</span></div>
            </div>
            <div class="cart-note">10+ of one item: 5% off. Matching set: 2% off.</div>

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

            <label class="fw-bold mt-2 mb-1 d-block" for="orNumber">Official receipt / invoice no. <span class="text-muted fw-normal">(from the store's BIR booklet, optional)</span></label>
            <input type="text" name="or_number" id="orNumber" class="form-control mb-2" maxlength="30" autocomplete="off" placeholder="e.g. 1001" pattern="[A-Za-z0-9][A-Za-z0-9 /\-]{0,29}" title="Letters, numbers, dashes and slashes, up to 30 characters">

            <button type="submit" class="btn btn-success w-100 mt-2" id="checkoutBtn" disabled>
                <i class="bi bi-check-lg"></i> Checkout &amp; Print Receipt
            </button>
        </form>
    </div>
</div>

<div class="inv-modal" id="sizePickerModal">
    <div class="inv-modal-card" style="max-width:420px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0" id="sizePickerTitle">Choose Size</h3>
            <button type="button" class="btn-close" id="closeSizePicker"></button>
        </div>
        <div class="size-pick-list" id="sizePickerList"></div>
    </div>
</div>
<script src="/assets/js/pos.js" defer></script>
