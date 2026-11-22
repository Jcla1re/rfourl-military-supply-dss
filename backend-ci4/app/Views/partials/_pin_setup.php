<?php
// app/Views/partials/_pin_setup.php
// The staff "Set up your checkout PIN" card: on the Point of Sale page (while they have no PIN) and in
// My Account. Posts the new PIN, its repeat and the account password (Staff\AccountController::savePin,
// which makes every check again). Every PIN and password box is empty when drawn, also after an error;
// the error shows under the field it is about ('form' = at the top of the card).
//
// Expects $pinAction (URL), $pinPrefix (unique id prefix, e.g. 'pos' or 'acct'). Optional: $pinTitle,
// $pinSub, $pinSubmit, $pinError, $pinErrorField ('new_pin' | 'new_pin_confirm' | 'current_password' |
// 'form'), $pinUsername (the signed-in username: tells password managers which account this is, so
// they don't fill it into another box on the page), $pinPhone, $pinLocked (demo: Save disabled),
// $pinHidden (extra hidden fields, name => value: the owner's card sends return=pos).
// Styles: assets/css/pin-setup.css. Behaviour: assets/js/pin-checklist.js.
use App\Libraries\PosPin;

$p          = $pinPrefix;
$pinTitle   = $pinTitle ?? 'Set up your checkout PIN';
$pinSub     = $pinSub ?? "You'll type it at every checkout. Only you should know it.";
$pinSubmit  = $pinSubmit ?? 'Save PIN';
$pinError   = $pinError ?? null;
$errField   = $pinError !== null ? ($pinErrorField ?? 'form') : null;
$errFor     = static fn (string $field): ?string => $errField === $field ? $pinError : null;
// One error line per field; aria-describedby ties it to its box.
$errLine    = static fn (string $field, string $id): string => ($m = $errFor($field)) !== null
    ? '<div class="pin-error" id="' . $id . 'Err" role="alert">' . esc($m) . '</div>' : '';
$invalid    = static fn (string $field, string $id, string $extra = ''): string => $errFor($field) !== null
    ? ' aria-invalid="true" aria-describedby="' . trim($extra . ' ' . $id . 'Err') . '"'
    : ($extra !== '' ? ' aria-describedby="' . $extra . '"' : '');
?>
<link href="/assets/css/pin-setup.css" rel="stylesheet">
<form method="post" action="<?= $pinAction /* a site_url() */ ?>" class="pin-card" id="<?= $p ?>PinSetup" data-submit-once data-busy-label="Saving…">
    <?= csrf_field() ?>
    <?php foreach ((array) ($pinHidden ?? []) as $hiddenName => $hiddenValue): ?>
        <input type="hidden" name="<?= esc((string) $hiddenName, 'attr') ?>" value="<?= esc((string) $hiddenValue, 'attr') ?>">
    <?php endforeach; ?>
    <?php // First in the form: the "username" for the password and PIN boxes below (see the partial). ?>
    <?= view('partials/_username_hint', ['username' => $pinUsername ?? '', 'name' => 'pin_account'], ['saveData' => false]) ?>
    <div class="pin-card-head">
        <span class="pin-card-icon" aria-hidden="true"><?= view('partials/_lock_icon', ['lockIconSize' => 18], ['saveData' => false]) ?></span>
        <div>
            <h3 class="pin-card-title" id="<?= $p ?>PinTitle"><?= esc($pinTitle) ?></h3>
            <p class="pin-card-sub"><?= esc($pinSub) ?></p>
        </div>
    </div>

    <?php if (($m = $errFor('form')) !== null): ?><div class="pin-error pin-error-form" role="alert"><?= esc($m) ?></div><?php endif; ?>

    <div class="pin-field">
        <label for="<?= $p ?>Pin">New PIN</label>
        <input type="password" id="<?= $p ?>Pin" name="<?= PosPin::NEW_FIELD ?>" class="pin-input" inputmode="numeric" pattern="\d{4}" minlength="4" maxlength="4" placeholder="••••" required autocomplete="new-password" data-pin-input<?= $invalid(PosPin::NEW_FIELD, $p . 'Pin') ?>>
        <?= $errLine(PosPin::NEW_FIELD, $p . 'Pin') ?>
    </div>
    <div class="pin-field">
        <label for="<?= $p ?>Pin2">Repeat PIN</label>
        <input type="password" id="<?= $p ?>Pin2" name="<?= PosPin::REPEAT_FIELD ?>" class="pin-input" inputmode="numeric" pattern="\d{4}" minlength="4" maxlength="4" placeholder="••••" required autocomplete="new-password" data-pin-input<?= $invalid(PosPin::REPEAT_FIELD, $p . 'Pin2') ?>>
        <?= $errLine(PosPin::REPEAT_FIELD, $p . 'Pin2') ?>
    </div>

    <?= view('partials/_pin_checklist', ['pinId' => $p . 'Pin', 'repeatId' => $p . 'Pin2', 'passwordId' => $p . 'PinPassword', 'saveId' => $p . 'PinSave', 'phone' => $pinPhone ?? null], ['saveData' => false]) ?>

    <div class="pin-field">
        <label for="<?= $p ?>PinPassword">Your account password</label>
        <input type="password" id="<?= $p ?>PinPassword" name="current_password" class="pin-password" required autocomplete="current-password"<?= $invalid('current_password', $p . 'PinPassword', $p . 'PinPasswordHint') ?>>
        <div class="pin-hint" id="<?= $p ?>PinPasswordHint">To confirm it's you.</div>
        <?= $errLine('current_password', $p . 'PinPassword') ?>
    </div>

    <button type="submit" class="btn btn-success pin-save" id="<?= $p ?>PinSave" disabled<?= ! empty($pinLocked) ? ' data-demo-locked="1" title="Disabled in the demo version"' : '' ?>><?= esc($pinSubmit) ?></button>
</form>
<script src="/assets/js/pin-checklist.js" defer></script>
