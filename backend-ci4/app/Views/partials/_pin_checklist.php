<?php
// app/Views/partials/_pin_checklist.php
// The live checklist under a new checkout PIN and its repeat (assets/js/pin-checklist.js, styles in
// assets/css/pin-setup.css). It mirrors App\Libraries\PosPin::weakReason() so the cashier sees the
// rules as they type; the server still makes the final check.
//
// Expects $pinId and $repeatId (the two inputs' ids). Optional: $passwordId and $saveId (Save stays
// disabled until every item is met and the password is typed), $phone (the user's own contact number:
// its last 4 digits are refused too).
use App\Libraries\PosPin;

$phoneDigits = preg_replace('/\D+/', '', (string) ($phone ?? ''));
$phoneLast4  = strlen($phoneDigits) >= 4 ? substr($phoneDigits, -4) : '';
?>
<ul class="pin-checklist" aria-live="polite"
    data-pin-checklist
    data-pin="<?= esc($pinId, 'attr') ?>"
    data-repeat="<?= esc($repeatId, 'attr') ?>"
    <?php if (! empty($passwordId)): ?>data-password="<?= esc($passwordId, 'attr') ?>"<?php endif; ?>
    <?php if (! empty($saveId)): ?>data-save="<?= esc($saveId, 'attr') ?>"<?php endif; ?>
    data-common="<?= esc(json_encode(PosPin::COMMON), 'attr') ?>"
    data-phone-last4="<?= esc($phoneLast4, 'attr') ?>">
    <li data-check="digits"><span class="pin-check-mark" aria-hidden="true"></span><span class="pin-check-text">4 digits</span><span class="visually-hidden pin-check-state"></span></li>
    <li data-check="strong"><span class="pin-check-mark" aria-hidden="true"></span><span class="pin-check-text">Not easy to guess (no 1234, 1111, 1212 or a year)</span><span class="visually-hidden pin-check-state"></span></li>
    <li data-check="match"><span class="pin-check-mark" aria-hidden="true"></span><span class="pin-check-text">Both PINs match</span><span class="visually-hidden pin-check-state"></span></li>
</ul>
