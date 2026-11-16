<?php
// Demo mode + DEMO_SHOW_CREDENTIALS only: the demo logins, under the one sign-in form (all three roles).
if (! demo_show_credentials()) {
    return;
}
$__demoLogins = [
    ['Owner',    'demo_owner',    demo_setting('OWNER_PASSWORD')],
    ['Staff',    'demo_staff',    demo_setting('STAFF_PASSWORD')],
    ['Supplier', 'demo_supplier', demo_setting('SUPPLIER_PASSWORD')],
];
?>
<div class="demo-credentials">
    <strong>Demo accounts</strong>
    <?php foreach ($__demoLogins as [$__label, $__user, $__pass]): ?>
        <div><?= esc($__label) ?>: <code><?= esc($__user) ?></code> / <code><?= esc((string) $__pass) ?></code></div>
    <?php endforeach; ?>
</div>
