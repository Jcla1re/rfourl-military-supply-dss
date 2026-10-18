
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php
$suppliers = $suppliers ?? [];
$status    = $status ?? 'active';
$archived  = $status === 'archived';
?>

<style>
.sup-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
@media (max-width: 900px) { .sup-stats { grid-template-columns: repeat(2, 1fr); } }
.sup-stat { background: #fff; border-radius: var(--radius-card); padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.sup-stat span { display: block; color: #555; font-size: 14px; margin-bottom: 4px; }
.sup-stat strong { font-size: 26px; }
.sup-stat small { display: block; color: #888; font-size: 12px; margin-top: 2px; }

.sup-section-label { text-transform: uppercase; letter-spacing: .06em; color: #666; font-size: 13px; font-weight: 700; }

.sup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 14px; }
@media (max-width: 900px) { .sup-grid { grid-template-columns: 1fr; } }

.sup-card { background: #fff; border-radius: var(--radius-card); padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.sup-card-top { display: flex; align-items: center; gap: 14px; }
.sup-thumb { width: 52px; height: 52px; border-radius: var(--radius-icon); object-fit: cover; background: #e2e2e2; flex-shrink: 0; }
.sup-thumb.placeholder {
    display: flex; align-items: center; justify-content: center; color: #999; font-size: 20px;
    background: repeating-linear-gradient(45deg, #eee, #eee 6px, #e2e2e2 6px, #e2e2e2 12px);
}
.sup-card-top .name { font-weight: 800; font-size: 16px; }
.sup-card-top .cat { color: #666; font-size: 13px; }
.sup-badge { margin-left: auto; background: #e9e7e1; border-radius: var(--radius-pill); padding: 5px 14px; font-size: 12px; font-weight: 700; }
.sup-badge.portal { background: var(--green-bg); color: var(--green-text); }
.sup-card-foot { display: flex; gap: 32px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #eee; font-size: 14px; }
.sup-card-foot span { display: block; color: #888; font-size: 12px; }
.sup-card-actions { display: flex; gap: 8px; margin-top: 14px; }
.sup-card-actions a, .sup-card-actions button {
    flex: 1; text-align: center; border-radius: var(--radius-control); padding: 8px; font-weight: 700; text-decoration: none; cursor: pointer;
}
.sup-card-actions .edit-link { border: 1px solid #ccc; color: #333; background: #fff; }
.sup-card-actions .archive-btn { border: 1px solid #f0bcbc; color: var(--accent-maroon); background: #fff; }
.sup-card-actions .restore-btn { border: 1px solid var(--green-text); color: var(--green-text); background: #fff; }

.sup-tabs { display: flex; gap: 8px; }
.sup-tabs a {
    padding: 7px 16px; border-radius: var(--radius-pill); background: #e9e7e1; color: #444;
    font-size: 14px; font-weight: 700; text-decoration: none;
}
.sup-tabs a.active { background: #fff; color: #1c1c1c; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
.sup-card.archived { background: #f6f5f1; }
.sup-card.archived .name, .sup-card.archived .cat { color: #666; }
.sup-badge.locked { background: #f7e2e2; color: var(--accent-maroon); }
.sup-archive-note { background: #fff; border-radius: var(--radius-card); padding: 12px 16px; margin-bottom: 14px; font-size: 14px; color: #555; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <?php if (! $archived): ?>
    <div class="sup-stats">
        <div class="sup-stat"><span>Total Suppliers</span><strong><?= esc($totalSuppliers ?? 0) ?></strong></div>
        <div class="sup-stat"><span>Avg. lead time</span><strong><?= esc($avgLeadTime ?? 0) ?> days</strong><small>Across all suppliers</small></div>
        <div class="sup-stat"><span>Active suppliers</span><strong><?= esc(count(array_filter($suppliers, fn($s) => $s['is_active']))) ?></strong></div>
        <div class="sup-stat"><span>Pending Orders</span><strong><?= esc($pendingOrders ?? 0) ?></strong><small>Across all suppliers</small></div>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="sup-tabs">
            <a href="<?= site_url('admin/suppliers') ?>" class="<?= $archived ? '' : 'active' ?>">Active (<?= esc($activeCount ?? 0) ?>)</a>
            <a href="<?= site_url('admin/suppliers?status=archived') ?>" class="<?= $archived ? 'active' : '' ?>">Archived (<?= esc($archivedCount ?? 0) ?>)</a>
        </div>
        <?php if (! $archived): ?>
            <a href="<?= site_url('admin/suppliers/create') ?>" class="btn btn-success"><i class="bi bi-plus-lg"></i> Add Supplier</a>
        <?php endif; ?>
    </div>

    <?php if ($archived): ?>
        <div class="sup-archive-note">
            <i class="bi bi-info-circle"></i> Archived suppliers are hidden from orders and alerts, and their portal account cannot sign in.
            Restore a supplier to unlock the account again.
        </div>
    <?php endif; ?>

    <div class="sup-grid">
        <?php if (!empty($suppliers)): ?>
            <?php foreach ($suppliers as $s): ?>
                <div class="sup-card <?= $archived ? 'archived' : '' ?>">
                    <div class="sup-card-top">
                        <div class="sup-thumb placeholder"><i class="bi bi-truck"></i></div>
                        <div>
                            <div class="name"><?= esc($s['company_name']) ?></div>
                            <div class="cat"><?= esc($s['category'] ?? 'General supplier') ?></div>
                        </div>
                        <?php if ($archived): ?>
                            <span class="sup-badge locked"><i class="bi bi-lock-fill"></i> <?= $s['has_portal'] ? 'Portal locked' : 'Archived' ?></span>
                        <?php else: ?>
                            <span class="sup-badge <?= $s['has_portal'] ? 'portal' : '' ?>"><?= $s['has_portal'] ? 'Portal Active' : 'No Portal' ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="sup-card-foot">
                        <div><span><i class="bi bi-clock"></i> Lead Time</span><?= esc($s['lead_time_days']) ?> days</div>
                        <div><span><i class="bi bi-telephone"></i> Contact</span><?= esc($s['contact_number'] ?? '—') ?></div>
                        <div><span><i class="bi bi-box-seam"></i> Products</span><?= esc($s['product_count']) ?></div>
                        <?php if ($archived): ?>
                            <div><span><i class="bi bi-archive"></i> Archived on</span><?= ! empty($s['updated_at']) ? esc(date('M j, Y', strtotime($s['updated_at']))) : '—' ?></div>
                            <div><span><i class="bi bi-person"></i> Portal user</span><?= esc($s['portal_username'] ?? '—') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="sup-card-actions">
                        <?php if ($archived): ?>
                            <form method="post" action="<?= site_url('admin/suppliers/restore/' . $s['supplier_id']) ?>" style="flex:1;">
                                <?= csrf_field() ?>
                                <button type="submit" class="restore-btn w-100"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                            </form>
                        <?php else: ?>
                            <a href="<?= site_url('admin/suppliers/edit/' . $s['supplier_id']) ?>" class="edit-link">Edit</a>
                            <?php $archiveWarning = 'Archive this supplier?' . ($s['has_portal'] ? ' Their portal account will be locked until you restore it.' : '')
                                . ($s['open_orders'] > 0 ? ' They still have ' . $s['open_orders'] . ' open order(s).' : ''); ?>
                            <form method="post" action="<?= site_url('admin/suppliers/delete/' . $s['supplier_id']) ?>" style="flex:1;" onsubmit="return confirm(<?= esc(json_encode($archiveWarning), 'attr') ?>);">
                                <?= csrf_field() ?>
                                <button type="submit" class="archive-btn w-100">Archive</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state"><?= $archived ? 'No archived suppliers.' : 'No suppliers yet. Add your first supplier to link it to inventory items and stock orders.' ?></div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
