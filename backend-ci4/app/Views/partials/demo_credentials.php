<?php
// Demo mode + DEMO_SHOW_CREDENTIALS only: the demo login for this role. Expects $role = admin|staff|supplier.
if (! demo_show_credentials()) {
    return;
}
$__demoLogins = [
    'admin'    => ['Owner',    'demo_owner',    demo_setting('OWNER_PASSWORD')],
    'staff'    => ['Staff',    'demo_staff',    demo_setting('STAFF_PASSWORD')],
    'supplier' => ['Supplier', 'demo_supplier', demo_setting('SUPPLIER_PASSWORD')],
];
[$__label, $__user, $__pass] = $__demoLogins[$role ?? 'admin'] ?? $__demoLogins['admin'];
?>
<div class="demo-credentials">
    <strong>Demo account (<?= esc($__label) ?>)</strong>
    <?php if ($role !== 'staff'): ?>
        <div>Username: <code><?= esc($__user) ?></code></div>
    <?php endif; ?>
    <div>Password: <code><?= esc((string) $__pass) ?></code></div>
</div>
