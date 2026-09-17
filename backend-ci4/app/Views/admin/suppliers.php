
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php $suppliers = $suppliers ?? []; ?>

<style>
.sup-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
@media (max-width: 900px) { .sup-stats { grid-template-columns: repeat(2, 1fr); } }
.sup-stat { background: #fff; border-radius: 14px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.sup-stat span { display: block; color: #555; font-size: 14px; margin-bottom: 4px; }
.sup-stat strong { font-size: 26px; }
.sup-stat small { display: block; color: #888; font-size: 12px; margin-top: 2px; }

.sup-section-label { text-transform: uppercase; letter-spacing: .06em; color: #666; font-size: 13px; font-weight: 700; }

.sup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 14px; }
@media (max-width: 900px) { .sup-grid { grid-template-columns: 1fr; } }

.sup-card { background: #fff; border-radius: 14px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.sup-card-top { display: flex; align-items: center; gap: 14px; }
.sup-thumb { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; background: #e2e2e2; flex-shrink: 0; }
.sup-thumb.placeholder {
    display: flex; align-items: center; justify-content: center; color: #999; font-size: 20px;
    background: repeating-linear-gradient(45deg, #eee, #eee 6px, #e2e2e2 6px, #e2e2e2 12px);
}
.sup-card-top .name { font-weight: 800; font-size: 16px; }
.sup-card-top .cat { color: #666; font-size: 13px; }
.sup-badge { margin-left: auto; background: #e9e7e1; border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700; }
.sup-badge.portal { background: var(--green-bg); color: var(--green-text); }
.sup-card-foot { display: flex; gap: 32px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #eee; font-size: 14px; }
.sup-card-foot span { display: block; color: #888; font-size: 12px; }
.sup-card-actions { display: flex; gap: 8px; margin-top: 14px; }
.sup-card-actions a, .sup-card-actions button {
    flex: 1; text-align: center; border-radius: 8px; padding: 8px; font-weight: 700; text-decoration: none; cursor: pointer;
}
.sup-card-actions .edit-link { border: 1px solid #ccc; color: #333; background: #fff; }
.sup-card-actions .archive-btn { border: 1px solid #f0bcbc; color: var(--accent-maroon); background: #fff; }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="sup-stats">
        <div class="sup-stat"><span>Total Suppliers</span><strong><?= esc($totalSuppliers ?? 0) ?></strong></div>
        <div class="sup-stat"><span>Avg. lead time</span><strong><?= esc($avgLeadTime ?? 0) ?> days</strong><small>Across all suppliers</small></div>
        <div class="sup-stat"><span>Active suppliers</span><strong><?= esc(count(array_filter($suppliers, fn($s) => $s['is_active']))) ?></strong></div>
        <div class="sup-stat"><span>Pending Orders</span><strong><?= esc($pendingOrders ?? 0) ?></strong><small>Across all suppliers</small></div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="sup-section-label">Supplier Directory</span>
        <a href="<?= site_url('admin/suppliers/create') ?>" class="btn btn-success"><i class="bi bi-plus-lg"></i> Add Supplier</a>
    </div>

    <div class="sup-grid">
        <?php if (!empty($suppliers)): ?>
            <?php foreach ($suppliers as $s): ?>
                <div class="sup-card">
                    <div class="sup-card-top">
                        <div class="sup-thumb placeholder"><i class="bi bi-truck"></i></div>
                        <div>
                            <div class="name"><?= esc($s['company_name']) ?></div>
                            <div class="cat"><?= esc($s['category'] ?? 'General supplier') ?></div>
                        </div>
                        <span class="sup-badge <?= $s['has_portal'] ? 'portal' : '' ?>"><?= $s['has_portal'] ? 'Portal Active' : 'No Portal' ?></span>
                    </div>
                    <div class="sup-card-foot">
                        <div><span><i class="bi bi-clock"></i> Lead Time</span><?= esc($s['lead_time_days']) ?> days</div>
                        <div><span><i class="bi bi-telephone"></i> Contact</span><?= esc($s['contact_number'] ?? '—') ?></div>
                        <div><span><i class="bi bi-box-seam"></i> Products</span><?= esc($s['product_count']) ?></div>
                    </div>
                    <div class="sup-card-actions">
                        <a href="<?= site_url('admin/suppliers/edit/' . $s['supplier_id']) ?>" class="edit-link">Edit</a>
                        <form method="post" action="<?= site_url('admin/suppliers/delete/' . $s['supplier_id']) ?>" style="flex:1;" onsubmit="return confirm('Archive this supplier?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="archive-btn w-100">Archive</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">No suppliers yet. Add your first supplier to link it to inventory items and stock orders.</div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
