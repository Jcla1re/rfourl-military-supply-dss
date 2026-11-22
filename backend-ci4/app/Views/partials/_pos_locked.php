<?php
// app/Views/partials/_pos_locked.php
// "Lock POS" (App\Libraries\PosLock): shown instead of the Point of Sale page (both tabs) until the
// signed-in user types their own checkout PIN. Wrong tries count toward the 3 tries / 60 s lock
// (App\Libraries\PosPin). The PIN box is always empty when drawn. The cart kept with the lock comes
// back after unlocking. Other pages of the app are not locked. Styles: assets/css/pos.css
// (.pos-locked...). Unlock stays disabled until 4 digits are typed (assets/js/pos-unlock.js).
//
// Expects $base ('admin' or 'staff'), $posLock (['at' => time, 'cart' => ...]), $posHasPin, $posUserName,
// $posUsername (the username hint for password managers), $posUnlockError (a refused unlock: shown
// under the box in place of the hint).
use App\Libraries\PosPin;

$lockedAt    = date('g:i A', (int) ($posLock['at'] ?? time()));
$unlockError = $posUnlockError ?? null;
?>
<div class="pos-locked">
    <section class="pos-locked-card" aria-labelledby="posLockedTitle">
        <div class="pos-locked-icon" aria-hidden="true"><?= view('partials/_lock_icon', ['lockIconSize' => 20], ['saveData' => false]) ?></div>
        <h2 class="pos-locked-title" id="posLockedTitle">Point of Sale is locked</h2>
        <p class="pos-locked-by">Locked by <span class="pos-locked-name"><?= esc($posUserName) ?></span> at <?= esc($lockedAt) ?>.</p>

        <?php if (! empty($posHasPin)): ?>
            <form method="post" action="<?= site_url($base . '/sales/unlock') ?>" id="posUnlockForm" class="pos-unlock-form" data-submit-once data-busy-label="Unlocking…" autocomplete="off">
                <?= csrf_field() ?>
                <?= view('partials/_username_hint', ['username' => $posUsername ?? ''], ['saveData' => false]) ?>
                <label for="unlockPin" class="pos-unlock-label">Checkout PIN</label>
                <?php // No show/hide eye (data-no-pw-toggle): a PIN typed at the counter is never shown. ?>
                <input type="password" id="unlockPin" name="<?= PosPin::FIELD ?>" class="pos-unlock-input" inputmode="numeric" pattern="\d{4}" minlength="4" maxlength="4" placeholder="••••" required autocomplete="one-time-code" data-no-pw-toggle autofocus aria-describedby="unlockPinMsg"<?= $unlockError !== null ? ' aria-invalid="true"' : '' ?>>
                <div class="pos-unlock-msg<?= $unlockError !== null ? ' is-error' : '' ?>" id="unlockPinMsg" data-hint="Enter your 4-digit PIN to continue."<?= $unlockError !== null ? ' role="alert"' : '' ?>><?= esc($unlockError ?? 'Enter your 4-digit PIN to continue.') ?></div>
                <button type="submit" class="btn btn-success pos-unlock-btn" id="unlockBtn" disabled>Unlock</button>
            </form>
            <script src="/assets/js/pos-unlock.js" defer></script>
        <?php else: ?>
            <p class="pos-locked-note">Your checkout PIN was reset. Sign out and sign in again to use the Point of Sale.</p>
        <?php endif; ?>
        <p class="pos-locked-note">Not you? Sign out from the menu.</p>
    </section>
</div>
