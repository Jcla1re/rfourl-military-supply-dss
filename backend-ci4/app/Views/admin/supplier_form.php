
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php
$supplier = $supplier ?? null;
$isEdit = $supplier !== null;
$action = $isEdit ? site_url('admin/suppliers/update/' . $supplier['supplier_id']) : site_url('admin/suppliers/store');
?>

<style>
.sf-page { padding: 24px; background: var(--content-bg); min-height: calc(100vh - 88px); }
.sf-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.sf-back { background: #fff; border: 1px solid #ccc; border-radius: 10px; padding: 10px 18px; text-decoration: none; color: #1c1c1c; font-weight: 600; }
.sf-actions { display: flex; gap: 10px; }
.sf-label { text-transform: uppercase; letter-spacing: .06em; color: #666; font-size: 13px; font-weight: 700; margin-bottom: 10px; }

.sf-section { background: #fbfbfa; border: 1px solid #e5e3dc; border-radius: 14px; padding: 24px; margin-bottom: 20px; }
.sf-section-title { display: inline-block; background: #f0e6c8; color: #1c1c1c; font-weight: 700; padding: 6px 16px; border-radius: 999px; margin-bottom: 20px; }
.sf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.sf-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
.sf-field label { font-weight: 600; margin-bottom: 6px; display: block; }
.sf-field input, .sf-field select {
    width: 100%; padding: 12px 14px; border: 1px solid #ccc; border-radius: 8px; background: #fff;
}
.sf-radios { display: flex; gap: 28px; margin-top: 6px; }
.sf-radios label { display: flex; align-items: center; gap: 8px; font-weight: 500; }
@media (max-width: 800px) { .sf-grid, .sf-grid-3 { grid-template-columns: 1fr; } }
</style>

<div class="sf-page">
    <div class="sf-topbar">
        <a href="<?= site_url('admin/suppliers') ?>" class="sf-back">&larr; Back</a>
        <div class="sf-actions">
            <a href="<?= site_url('admin/suppliers') ?>" class="btn btn-success">Cancel</a>
        </div>
    </div>

    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <span class="sf-label">Fill in details</span>

    <form method="post" action="<?= $action ?>">
        <?= csrf_field() ?>

        <div class="sf-section">
            <span class="sf-section-title">Business Information</span>
            <div class="sf-grid mb-3">
                <div class="sf-field">
                    <label>Company name</label>
                    <input name="company_name" value="<?= esc($supplier['company_name'] ?? '') ?>" placeholder="e.g. Military accessories" required>
                </div>
                <div class="sf-field">
                    <label>Supplier ID</label>
                    <input value="<?= $isEdit ? esc($supplier['supplier_id']) : 'Auto-generate' ?>" readonly style="color:#888;">
                </div>
            </div>
            <div class="sf-grid mb-3">
                <div class="sf-field">
                    <label>Product category</label>
                    <input name="category" value="<?= esc($supplier['category'] ?? '') ?>" placeholder="e.g. Fatigue Cloth, Footwear">
                </div>
                <div class="sf-field">
                    <label>Lead time (days)</label>
                    <input name="lead_time_days" type="number" min="0" value="<?= esc($supplier['lead_time_days'] ?? '') ?>" placeholder="e.g. 7" required>
                </div>
            </div>
            <div class="sf-grid-3 mb-3">
                <div class="sf-field">
                    <label>Contact person</label>
                    <input name="contact_person" value="<?= esc($supplier['contact_person'] ?? '') ?>">
                </div>
                <div class="sf-field">
                    <label>Contact email</label>
                    <input name="contact_email" type="email" value="<?= esc($supplier['contact_email'] ?? '') ?>">
                </div>
                <div class="sf-field">
                    <label>Contact number</label>
                    <input name="contact_number" value="<?= esc($supplier['contact_number'] ?? '') ?>">
                </div>
            </div>

            <label class="fw-bold">Supplier status</label>
            <?php $currentStatus = $isEdit ? (($supplier['is_active'] ?? 1) ? 'Active' : 'Inactive') : 'Active'; ?>
            <div class="sf-radios">
                <label><input type="radio" name="supplier_status" value="Active" <?= $currentStatus === 'Active' ? 'checked' : '' ?>> Active (Primary)</label>
                <label><input type="radio" name="supplier_status" value="Secondary" <?= $currentStatus === 'Secondary' ? 'checked' : '' ?>> Secondary</label>
                <label><input type="radio" name="supplier_status" value="Inactive" <?= $currentStatus === 'Inactive' ? 'checked' : '' ?>> Inactive</label>
            </div>
        </div>

        <div class="sf-section">
            <span class="sf-section-title">Delivery Preferences</span>
            <div class="sf-grid">
                <div class="sf-field">
                    <label>Preferred Courier</label>
                    <input name="preferred_courier" value="<?= esc($supplier['preferred_courier'] ?? '') ?>" placeholder="e.g. JNT Express">
                </div>
                <div class="sf-field">
                    <label>Demand Lookback</label>
                    <input value="<?= esc($dss['demand_lookback_days'] ?? 90) ?> days (system-wide)" readonly style="color:#888;">
                </div>
            </div>
        </div>

        <?php if (!$isEdit): ?>
        <div class="sf-section">
            <span class="sf-section-title">Portal Account creation</span>
            <p class="text-muted small">Optional — create a login so this supplier can access the Supplier Portal to view orders and update delivery status.</p>
            <div class="sf-grid mb-3">
                <div class="sf-field">
                    <label>Login email</label>
                    <input name="login_email" type="email" placeholder="supplier@email.com">
                </div>
                <div class="sf-field">
                    <label>Username</label>
                    <input name="username" placeholder="e.g. supplier_a">
                </div>
            </div>
            <div class="sf-grid">
                <div class="sf-field">
                    <label>Password</label>
                    <input name="password" type="password" placeholder="Minimum 8 characters">
                </div>
                <div class="sf-field">
                    <label>Confirm Password</label>
                    <input name="confirm_password" type="password" placeholder="Re-enter password">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-end gap-2">
            <a href="<?= site_url('admin/suppliers') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-success"><?= $isEdit ? 'Save Changes' : 'Save & create account' ?></button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
