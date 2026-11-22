<?php
// app/Views/partials/_pos_tab.php
// The Checkout tab of Point of Sale, the same for the Owner (admin/sales.php) and Staff
// (staff/sales.php): category chips, search (by name or item ID) and count, the item grid in
// its own scroll area, the cart, and the size picker. Styles: assets/css/pos.css. Behaviour:
// assets/js/pos.js. Category icons: App\Libraries\PosIcon.
//
// Expects $base ('admin' or 'staff'), $groups (ProductModel::groupedActive() + min_price,
// max_price, is_multi_size), $categories, and the checkout PIN state (PosPinActions::posPinData()):
// $posPinReady, $posHasPin, $posPinReset, $posPinError.
//
// Checkout PIN (App\Libraries\PosPin): pos-pin.js asks for it in #pinModal when Checkout is clicked
// and sends it in the hidden pos_pin field; the server checks it. Without a PIN, checkout stays
// disabled: staff set one right here (or in My Account), the owner in My Profile. Every PIN box is
// empty when this page is drawn. "Lock POS" (#posLockForm) locks this page until the PIN is entered.
use App\Libraries\PosIcon;
use App\Libraries\PosPin;

$groups = $groups ?? [];
$count  = count($groups);

$posPinReady = $posPinReady ?? true;
$posHasPin   = (bool) ($posHasPin ?? false);
$posPinReset = (bool) ($posPinReset ?? false);
$posPinError = $posPinError ?? null;
// The staff setup card's username hint and phone rule (PosPinActions::posPinData()).
$posUsername      = $posUsername ?? null;
$posUserPhone     = $posUserPhone ?? null;
$posPinErrorField = $posPinErrorField ?? null;
?>
<div class="pos-layout">
    <div class="pos-main">
        <div class="pos-toolbar">
            <?php
            // The category chips: always one line. The row scrolls sideways (no visible scrollbar) and a
            // soft fade shows on the side that has more chips (pos-chips.js). Filtering: pos.js.
            ?>
            <div class="pos-chips-wrap" id="posChipsWrap">
                <div class="pos-chips" role="group" aria-label="Category">
                    <button type="button" class="active" data-cat="" aria-pressed="true">All Items</button>
                    <?php foreach (($categories ?? []) as $c): ?>
                        <button type="button" data-cat="<?= esc($c, 'attr') ?>" aria-pressed="false"><?= PosIcon::svg($c, 14) ?><?= esc($c) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inv-search pos-search-box">
                <i class="bi bi-search" aria-hidden="true"></i>
                <?php // Not a credential box: a name and autocomplete="off" keep password managers from filling a username in here. ?>
                <input type="search" id="posSearch" name="pos_item_search" placeholder="Search by item name or ID&hellip;" aria-label="Search by item name or ID" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore data-form-type="other">
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
        <?php if ($posHasPin): ?>
            <?php // Lock POS: pos-pin.js adds the cart on screen (item IDs and quantities) so it comes back after unlocking. ?>
            <form method="post" action="<?= site_url($base . '/sales/lock') ?>" id="posLockForm" class="pos-lock-bar" data-submit-once data-busy-label="Locking…">
                <?= csrf_field() ?>
                <button type="submit" class="pos-lock" id="posLockBtn" title="Lock the Point of Sale while you step away. Your checkout PIN unlocks it."><?= view('partials/_lock_icon', ['lockIconSize' => 16], ['saveData' => false]) ?>Lock POS</button>
            </form>
        <?php elseif (! $posPinReady): ?>
            <?php // The same white card as the staff setup card (assets/css/pin-setup.css). ?>
            <link href="/assets/css/pin-setup.css" rel="stylesheet">
            <div class="pin-card" id="posPinNotReady" role="status">
                <div class="pin-card-head">
                    <span class="pin-card-icon" aria-hidden="true"><?= view('partials/_lock_icon', ['lockIconSize' => 18], ['saveData' => false]) ?></span>
                    <div>
                        <h3 class="pin-card-title">Checkout PINs aren't set up yet</h3>
                        <p class="pin-card-sub">The database needs updating (php spark migrate) before anyone can check out.</p>
                    </div>
                </div>
            </div>
        <?php elseif ($base === 'admin'): ?>
            <?php
            // The owner's first PIN: the same card as staff, saved through My Profile's own route
            // (SettingsController::updateSignIn: the same current-password check and attempt counter).
            // return=pos brings the owner back here, with any error in this card.
            ?>
            <?= view('partials/_pin_setup', [
                'pinAction'     => site_url('admin/settings/sign-in'),
                'pinPrefix'     => 'posSetup', // not 'pos': #posPin is the hidden checkout PIN field below
                'pinHidden'     => ['return' => 'pos'],
                'pinError'      => $posPinError,
                'pinErrorField' => $posPinErrorField ?? null,
                'pinUsername'   => $posUsername ?? null,
                'pinPhone'      => $posUserPhone ?? null,
            ], ['saveData' => false]) ?>
        <?php else: ?>
            <?php // The first PIN (or a new one after the owner reset it), confirmed with the account password (Staff\AccountController::setPinFromPos). ?>
            <?= view('partials/_pin_setup', [
                'pinAction'     => site_url('staff/sales/pin'),
                'pinPrefix'     => 'posSetup', // not 'pos': #posPin is the hidden checkout PIN field below
                'pinSub'        => $posPinReset ? 'The owner reset your PIN. Set a new one before your next sale.' : null,
                'pinError'      => $posPinError,
                'pinErrorField' => $posPinErrorField ?? null,
                'pinUsername'   => $posUsername ?? null,
                'pinPhone'      => $posUserPhone ?? null,
            ], ['saveData' => false]) ?>
        <?php endif; ?>
        <?php
        // Sent once (submit-once.js); the one-time key stops a repeat on the server too (PosCheckout).
        // After "Not enough stock" or "This page needed a refresh" the cart comes back: only the item
        // IDs and quantities that were posted (old input), never a price. pos.js rebuilds the cart
        // from this page's own item data (prices and stock from the server) and caps to stock.
        $oldIds  = (array) (old('item_id', [], false) ?? []);
        $oldQty  = (array) (old('quantity', [], false) ?? []);
        $restore = [];
        foreach (array_values($oldIds) as $i => $oldId) {
            $oldId = (string) $oldId;
            $qty   = (int) (array_values($oldQty)[$i] ?? 0);
            if ($oldId !== '' && strlen($oldId) <= 15 && $qty > 0) {
                $restore[] = ['id' => $oldId, 'qty' => min($qty, 9999)];
            }
        }
        $restorePay = $restore === [] ? [] : array_filter([
            'method'    => in_array(old('payment_method', '', false), ['Cash', 'GCash', 'Split'], true) ? old('payment_method', '', false) : null,
            'cash'      => is_numeric(old('cash_received', '', false)) ? (string) old('cash_received', '', false) : null,
            'gcash'     => is_numeric(old('gcash_amount', '', false)) ? (string) old('gcash_amount', '', false) : null,
            'gcash_ref' => mb_substr((string) old('gcash_reference_no', '', false), 0, 50) ?: null,
            'or_number' => mb_substr((string) old('or_number', '', false), 0, 30) ?: null,
        ], static fn ($v) => $v !== null);
        // The cart this browser saved (pos.js; item IDs and quantities only) comes back only on the
        // first POS page after signing back in from an idle sign-out (App\Libraries\SavedCart), and
        // never over the cart above. Any other load clears what was saved.
        $posSavedCart = \App\Libraries\SavedCart::take() && $restore === [];
        ?>
        <?php
        // Why Checkout is disabled, under the button (pos.js keeps it current): no PIN yet, or an empty cart.
        $noPinReason = $posPinReady ? 'Set your PIN above to check out.' : "Checkout PINs aren't set up yet.";
        ?>
        <form method="post" action="<?= site_url($base . '/sales/checkout') ?>" id="cartForm" data-submit-once data-busy-label="Processing sale…" class="cart-card"
              data-restore="<?= esc(json_encode($restore), 'attr') ?>" data-restore-payment="<?= esc(json_encode((object) $restorePay), 'attr') ?>"
              data-restore-saved="<?= $posSavedCart ? '1' : '0' ?>" data-no-pin="<?= $posHasPin ? '0' : '1' ?>"
              data-reason-no-pin="<?= esc($noPinReason, 'attr') ?>" data-reason-empty="Add an item to start a sale."
              data-reason-cash="Enter the cash received to check out." data-reason-field="Fix the box marked in red to check out.">
            <?= csrf_field() ?>
            <?= \App\Libraries\OnceToken::field(\App\Libraries\PosCheckout::ACTION) ?>
            <?php // Filled by pos-pin.js just before the form is sent; always empty when the page is drawn. ?>
            <input type="hidden" name="<?= PosPin::FIELD ?>" id="posPin" value="">
            <?php
            // A payment or OR box the server refused (SalesController, 'pos_field_error'): its message
            // shows under that box (the cart and what was typed came back as old input, never the PIN).
            $fe       = is_array($posFieldError ?? null) ? $posFieldError : null;
            $feMethod = (string) old('payment_method', '', false);
            $feBox    = $fe === null ? null : match ($fe['field'] ?? '') {
                'cash_received'      => $feMethod === 'Split' ? 'splitCashReceived' : 'cashReceived',
                'gcash_amount'       => 'splitGcashAmount',
                'gcash_reference_no' => $feMethod === 'Split' ? 'gcashRefSplit' : 'gcashRefGcash',
                'or_number'          => 'orNumber',
                default              => null,
            };
            $fieldErr = static fn (string $id): string => '<div class="cart-field-error" id="' . $id . 'Err" role="alert">'
                . ($feBox === $id ? esc((string) $fe['message']) : '') . '</div>';
            $invalid  = static fn (string $id): string => $feBox === $id ? ' aria-invalid="true"' : '';
            ?>
            <div class="cart-head">
                <h3 class="cart-title">Current order <span class="cart-count" id="cartCount">0 items</span></h3>
                <button type="button" class="pos-clear" id="clearCart" disabled>Clear</button>
            </div>
            <div id="cartLines"><div class="cart-empty" id="cartEmptyMsg">Cart is empty.<br>Tap an item to add it.</div></div>

            <?php // Subtotal − Discount = Total, as PosPricing computes it (pos.js mirrors it; the server recomputes at checkout). ?>
            <div class="cart-totals">
                <div class="row-line"><span>Subtotal</span><span id="cartSubtotal">₱0</span></div>
                <div class="row-line discount-row" id="discountRow" style="display:none;"><span id="cartDiscountLabel">Discount</span><span id="cartDiscountOut">−₱0</span></div>
                <div class="row-line total"><span>Total</span><span id="cartTotal">₱0</span></div>
            </div>

            <?php // Payment: hidden while the cart is empty (pos.js shows it with the first item; the chosen method is kept). ?>
            <div class="cart-pay" id="cartPay" hidden>
            <p class="cart-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Discounts: 10+ of one item 5% off · matching set 2% off</p>

            <?php // Amounts: digits and one point, up to 2 decimals (pos.js drops anything else as it is typed); the server checks again (PosCheckout::money()). ?>
            <div class="cart-label" id="payMethodLabel">Payment method</div>
            <input type="hidden" name="payment_method" id="paymentMethod" value="Cash">
            <div class="pay-method-toggle" role="group" aria-labelledby="payMethodLabel">
                <button type="button" class="pay-btn active" data-method="Cash" aria-pressed="true">Cash</button>
                <button type="button" class="pay-btn" data-method="GCash" aria-pressed="false">GCash</button>
                <button type="button" class="pay-btn" data-method="Split" aria-pressed="false">Cash+GCash</button>
            </div>

            <div id="cashPanel" class="pay-panel">
                <label class="cart-label" for="cashReceived">Cash received</label>
                <input type="text" name="cash_received" id="cashReceived" class="form-control cart-input" inputmode="decimal" maxlength="13" autocomplete="off" placeholder="₱0" data-money aria-describedby="cashReceivedErr"<?= $invalid('cashReceived') ?>>
                <?= $fieldErr('cashReceived') ?>
                <div class="quick-chips" id="cashChips"></div>
                <div class="cart-change" id="cashChangeBox"><span id="cartChangeLabel">Change</span><strong id="cartChange">₱0</strong></div>
            </div>

            <div id="gcashPanel" class="pay-panel" style="display:none;">
                <label class="cart-label" for="gcashAmountDisplay">GCash amount</label>
                <input type="text" id="gcashAmountDisplay" class="form-control cart-input" autocomplete="off" readonly>
                <input type="hidden" name="gcash_amount" id="gcashAmountHidden" disabled>
                <label class="cart-label" for="gcashRefGcash">GCash reference no.</label>
                <input type="text" name="gcash_reference_no" id="gcashRefGcash" class="form-control cart-input" inputmode="numeric" maxlength="13" pattern="\d{13}" title="13 digits" autocomplete="off" placeholder="13 digits" data-digits data-exact="13" aria-describedby="gcashRefGcashErr" disabled<?= $invalid('gcashRefGcash') ?>>
                <?= $fieldErr('gcashRefGcash') ?>
            </div>

            <div id="splitPanel" class="pay-panel" style="display:none;">
                <label class="cart-label" for="splitGcashAmount">GCash amount</label>
                <input type="text" name="gcash_amount" id="splitGcashAmount" class="form-control cart-input" inputmode="decimal" maxlength="13" autocomplete="off" placeholder="₱0" data-money aria-describedby="splitGcashAmountErr" disabled<?= $invalid('splitGcashAmount') ?>>
                <?= $fieldErr('splitGcashAmount') ?>
                <label class="cart-label" for="gcashRefSplit">GCash reference no.</label>
                <input type="text" name="gcash_reference_no" id="gcashRefSplit" class="form-control cart-input" inputmode="numeric" maxlength="13" pattern="\d{13}" title="13 digits" autocomplete="off" placeholder="13 digits" data-digits data-exact="13" aria-describedby="gcashRefSplitErr" disabled<?= $invalid('gcashRefSplit') ?>>
                <?= $fieldErr('gcashRefSplit') ?>
                <div class="row-line cart-due"><span>Cash due</span><span id="splitCashDue">₱0</span></div>
                <label class="cart-label" for="splitCashReceived">Cash received</label>
                <input type="text" name="cash_received" id="splitCashReceived" class="form-control cart-input" inputmode="decimal" maxlength="13" autocomplete="off" placeholder="₱0" data-money aria-describedby="splitCashReceivedErr" disabled<?= $invalid('splitCashReceived') ?>>
                <?= $fieldErr('splitCashReceived') ?>
                <div class="quick-chips" id="splitChips"></div>
                <div class="cart-change" id="splitChangeBox"><span id="splitChangeLabel">Change</span><strong id="splitChange">₱0</strong></div>
            </div>

            <label class="cart-label" for="orNumber">Official receipt no. <span class="cart-optional">(optional)</span></label>
            <input type="text" name="or_number" id="orNumber" class="form-control cart-input" data-check-url="<?= site_url($base . '/sales/check-or') ?>" inputmode="numeric" maxlength="20" autocomplete="off" placeholder="e.g. 1001" pattern="\d{1,20}" title="Numbers only, up to 20 digits" data-digits aria-describedby="orNumberHint orNumberErr"<?= $invalid('orNumber') ?>>
            <div class="cart-hint" id="orNumberHint">From the store's BIR booklet.</div>
            <?= $fieldErr('orNumber') ?>
            </div>

            <?php // Text only, full width; size and spacing in pos.css (.cart-card #checkoutBtn). ?>
            <button type="submit" class="btn btn-success" id="checkoutBtn" disabled aria-describedby="checkoutReason">Checkout &amp; Print Receipt</button>
            <p class="cart-reason" id="checkoutReason" aria-live="polite"><?= esc($posHasPin ? 'Add an item to start a sale.' : $noPinReason) ?></p>
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
<?php
// The checkout PIN dialog, "Confirm this sale" (pos-pin.js; styles: pos.css, #pinModal only, the shared
// .inv-modal look is untouched). The box has no name: only the hidden pos_pin field above is sent. It is
// its own <form> (never sent: pos-pin.js stops it) with the username hint first, so browsers pair this
// password box with that hint and not with the item search, which is in no form.
// A refused PIN (PosPinActions::checkoutGate) comes back in data-pin-error; pos-pin.js reopens the dialog
// with it. data-pin-locked: the 3 tries / 60 s lock, so that opening offers only Cancel.
$pinError  = $posCheckoutPinError ?? null;
$pinLocked = ! empty($posCheckoutPinLocked);
?>
<div class="inv-modal" id="pinModal" role="dialog" aria-modal="true" aria-labelledby="pinTitle" aria-describedby="pinSub"
     data-pin-error="<?= esc((string) $pinError, 'attr') ?>" data-pin-locked="<?= $pinLocked ? '1' : '0' ?>">
    <form class="inv-modal-card pos-pin-card" id="pinPromptForm" action="#" novalidate>
        <?= view('partials/_username_hint', ['username' => $posUsername ?? ''], ['saveData' => false]) ?>
        <div class="pin-prompt-head">
            <span class="pin-prompt-icon" aria-hidden="true"><?= view('partials/_lock_icon', ['lockIconSize' => 18], ['saveData' => false]) ?></span>
            <div>
                <h3 class="pin-prompt-title" id="pinTitle">Confirm this sale</h3>
                <p class="pin-prompt-sub" id="pinSub">Your own PIN records who made this sale.</p>
            </div>
        </div>
        <?php // What is being confirmed, read from the cart on screen when the dialog opens (display only). ?>
        <div class="pin-prompt-summary">
            <span class="pin-prompt-method">Total · <span id="pinSummaryMethod">Cash</span></span>
            <strong class="pin-prompt-total" id="pinSummaryTotal">₱0</strong>
        </div>
        <label class="pin-prompt-label" for="pinInput">Checkout PIN</label>
        <?php // one-time-code: browsers neither fill a saved password here nor offer to save the PIN as one. No eye. ?>
        <input type="password" id="pinInput" class="pos-pin-input" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="••••" autocomplete="one-time-code" aria-describedby="pinMsg" data-no-pw-toggle>
        <div class="pin-prompt-msg" id="pinMsg" data-hint="Enter your 4-digit PIN.">Enter your 4-digit PIN.</div>
        <div class="pin-prompt-actions">
            <button type="button" class="btn pin-prompt-btn pin-prompt-cancel" id="pinCancel">Cancel</button>
            <button type="button" class="btn btn-success pin-prompt-btn" id="pinConfirm" disabled>Confirm sale</button>
        </div>
    </form>
</div>
<script src="/assets/js/pos.js" defer></script>
<script src="/assets/js/pos-chips.js" defer></script>
<script src="/assets/js/pos-or-check.js" defer></script>
<script src="/assets/js/pos-pin.js" defer></script>
