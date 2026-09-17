
<?= $this->extend('layouts/supplier_layout') ?>
<?= $this->section('content') ?>

<?php $supplier = $supplier ?? []; $stats = $stats ?? []; ?>

<style>
.pf-head { background: #fff; border-radius: 14px; padding: 22px; display: flex; align-items: center; gap: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
.pf-avatar { width: 60px; height: 60px; border-radius: 50%; background: #eee; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 20px; }
.pf-badge { background: #e9e7e1; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; margin-right: 6px; }
.pf-card { background: #fff; border-radius: 14px; padding: 20px 22px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; height: calc(100% - 20px); }
.pf-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f2f2f2; font-size: 14px; }
.pf-row:last-child { border-bottom: none; }
.pf-row span { color: #777; }
.pf-perf-track { height: 8px; background: #e4e2dc; border-radius: 4px; overflow: hidden; margin: 4px 0 12px; }
.pf-perf-track > span { display: block; height: 100%; background: var(--green-text); }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

    <div class="pf-head">
        <div class="pf-avatar"><?= esc(strtoupper(substr($supplier['company_name'] ?? '?', 0, 2))) ?></div>
        <div>
            <h4 class="mb-1"><?= esc($supplier['company_name'] ?? '') ?></h4>
            <div class="text-muted mb-2">Primary Supplier &middot; <?= esc($supplier['category'] ?? 'General supplier') ?></div>
            <span class="pf-badge">&bull; <?= !empty($supplier['is_active']) ? 'Active' : 'Inactive' ?></span>
            <span class="pf-badge">Verified Supplier</span>
            <span class="pf-badge">RfourL Military</span>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="pf-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0">Business Information</h6>
                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#editProfileModal">Edit</button>
                </div>
                <div class="pf-row"><span>Company Name</span><strong><?= esc($supplier['company_name'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Supplier ID</span><strong><?= esc($supplier['supplier_id'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Product Category</span><strong><?= esc($supplier['category'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Account Status</span><strong><?= !empty($supplier['is_active']) ? 'Active' : 'Inactive' ?></strong></div>
                <div class="pf-row"><span>Joined</span><strong><?= !empty($supplier['created_at']) ? esc(date('F Y', strtotime($supplier['created_at']))) : '—' ?></strong></div>
                <div class="pf-row"><span>Primary Contact</span><strong><?= esc($supplier['contact_email'] ?? '—') ?></strong></div>
            </div>

            <div class="pf-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0">Delivery Preferences</h6>
                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#editProfileModal">Edit</button>
                </div>
                <div class="pf-row"><span>Preferred Courier</span><strong><?= esc($supplier['preferred_courier'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Default Lead Time</span><strong><?= esc($supplier['lead_time_days'] ?? '—') ?> business days</strong></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="pf-card">
                <h6 class="fw-bold mb-3">Performance Summary</h6>
                <div class="d-flex justify-content-between small"><span>On-Time Delivery Rate</span><strong><?= esc($stats['on_time_rate'] ?? 0) ?>%</strong></div>
                <div class="pf-perf-track"><span style="width: <?= esc($stats['on_time_rate'] ?? 0) ?>%"></span></div>

                <div class="d-flex justify-content-between small"><span>Orders Completed (Month)</span><strong><?= esc($stats['orders_completed'] ?? '0 / 0') ?></strong></div>
                <div class="pf-perf-track"><span style="width: 100%"></span></div>

                <div class="pf-row"><span>Items Supplied (Month)</span><strong><?= number_format($stats['items_month'] ?? 0) ?> items</strong></div>
                <div class="pf-row"><span>Avg. Lead Time</span><strong><?= esc($supplier['lead_time_days'] ?? '—') ?> days</strong></div>
                <div class="pf-row"><span>Total Orders Fulfilled</span><strong><?= esc($stats['total_fulfilled'] ?? '0 / 0') ?></strong></div>
                <div class="pf-row"><span>Items Supplied</span><strong><?= number_format($stats['items_total'] ?? 0) ?> items</strong></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= site_url('supplier/profile/update') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Edit Contact &amp; Delivery Info</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">Contact Person</label><input class="form-control" name="contact_person" value="<?= esc($supplier['contact_person'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">Contact Email</label><input class="form-control" type="email" name="contact_email" value="<?= esc($supplier['contact_email'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">Contact Number</label><input class="form-control" name="contact_number" value="<?= esc($supplier['contact_number'] ?? '') ?>"></div>
                    <div><label class="fw-bold mb-1 d-block">Preferred Courier</label><input class="form-control" name="preferred_courier" value="<?= esc($supplier['preferred_courier'] ?? '') ?>"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
