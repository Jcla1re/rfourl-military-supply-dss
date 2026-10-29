<?php
// Demo mode only: the slim banner on every page, plus the on-screen box for a code or
// temporary password that would otherwise only arrive by email (see DemoGuard::reveal()).
if (! demo_mode()) {
    return;
}
$__demoSecret = session()->getFlashdata('demo_secret');
$__demoReset  = demo_setting('RESET_TIME', '12:00 AM');
?>
<div class="demo-banner" role="note">Demo version: data resets every night at <?= esc($__demoReset) ?>.</div>
<?php if (! empty($__demoSecret['code'])): ?>
    <div class="demo-secret" role="status">
        <div class="demo-secret-label">Demo only: this is normally sent by email</div>
        <div class="demo-secret-code"><?= esc((string) $__demoSecret['label']) ?>: <span><?= esc((string) $__demoSecret['code']) ?></span></div>
    </div>
<?php endif; ?>
