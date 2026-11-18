<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php
$suppliers = $suppliers ?? [];
$status    = $status ?? 'active';
$archived  = $status === 'archived';

// Display only: 11-digit numbers starting with 09 are grouped "0917 011 1111"; anything else as stored.
$formatPhone = static function (?string $phone): string {
    $phone = (string) $phone;
    if (preg_match('/^09\d{9}$/', $phone)) {
        return substr($phone, 0, 4) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7);
    }
    return $phone !== '' ? $phone : '—';
};
?>

<style>
.sup-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
@media (max-width: 900px) { .sup-stats { grid-template-columns: repeat(2, 1fr); } }
.sup-stat { background: #fff; border-radius: var(--radius-card); padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.sup-stat span { display: block; color: #5C6152; font-size: 13px; margin-bottom: 4px; }
.sup-stat strong { display: block; font-size: 26px; font-weight: 600; }
.sup-stat small { display: block; color: #7A7E70; font-size: 12px; margin-top: 2px; }

/* Tab row: the shared underline tabs (as they are) with Add Supplier on the right. */
.sup-tabrow { display: flex; align-items: flex-end; gap: 12px; margin-bottom: 14px; border-bottom: 1px solid #ddd; }
.sup-tabrow .underline-tabs { flex: 1; min-width: 0; margin-bottom: 0; border-bottom: 0; }
.sup-tabrow .underline-tabs a { display: inline-flex; align-items: center; gap: 6px; }
.sup-tabrow .sup-add { align-self: center; margin: 6px 0; }

.sup-archive-note { margin: 0 0 14px; font-size: 13px; color: #5C6152; }

.sup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 900px) { .sup-grid { grid-template-columns: 1fr; } }

.sup-card { background: #fff; border-radius: var(--radius-card); padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.sup-card-top { display: flex; align-items: center; gap: 14px; }
.sup-thumb { width: 52px; height: 52px; border-radius: var(--radius-icon); object-fit: cover; background: #e2e2e2; flex-shrink: 0; }
.sup-thumb.placeholder {
    display: flex; align-items: center; justify-content: center; color: #999; font-size: 20px;
    background: repeating-linear-gradient(45deg, #eee, #eee 6px, #e2e2e2 6px, #e2e2e2 12px);
}
.sup-card-top .name { font-weight: 600; font-size: 16px; }
.sup-card-top .cat { color: #666; font-size: 13px; }
.sup-badge { margin-left: auto; background: #e9e7e1; border-radius: var(--radius-pill); padding: 5px 14px; font-size: 12px; font-weight: 600; }
.sup-badge.portal { background: var(--green-bg); color: var(--green-text); }
.sup-card-foot { display: flex; gap: 32px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #eee; font-size: 14px; }
.sup-card-foot span { display: block; color: #888; font-size: 12px; }

/* Edit / Archive / Restore: white secondary buttons. Keyboard focus is the shared
   :focus-visible rule (admin-theme.css): no focus rules here, so it stays green. */
.sup-card-actions { display: flex; gap: 8px; margin-top: 14px; }
.sup-card-actions form { flex: 1; display: flex; }
.sup-btn {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    box-sizing: border-box; height: 38px; padding: 0 14px;
    border: 1px solid #D6D2C6; border-radius: var(--radius-control); background: #fff;
    color: #1F2617; font-size: 14px; font-weight: 500; line-height: 1;
    text-decoration: none; cursor: pointer; white-space: nowrap;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
}
.sup-btn:hover { background: #F7F5F0; border-color: #BFB9AA; color: #1F2617; }
.sup-btn:active { background: #EDEAE2; }
.sup-btn.is-archive { color: #B3261E; }
.sup-btn.is-archive:hover { background: #FDF1EF; border-color: #E3B5AF; color: #8C1D18; }
.sup-btn.is-archive:active { background: #F8E3E0; }

.sup-card.archived { background: #f6f5f1; }
.sup-card.archived .name, .sup-card.archived .cat { color: #666; }
.sup-badge.locked { background: #f7e2e2; color: var(--accent-maroon); }

/* Empty state: full content width. */
.sup-empty {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    padding: 48px 20px; text-align: center;
    background: #fff; border: 1px dashed #D6D2C6; border-radius: var(--radius-card);
}
.sup-empty > i.bi { font-size: 30px; color: #a9a79f; margin-bottom: 6px; }
.sup-empty .title { font-size: 15px; font-weight: 600; color: #1F2617; }
.sup-empty .hint { font-size: 13px; color: #5C6152; }
.sup-empty .btn { margin-top: 10px; }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="sup-stats">
        <div class="sup-stat"><span>Total suppliers</span><strong><?= esc($totalSuppliers ?? 0) ?></strong><small>Active and archived</small></div>
        <div class="sup-stat"><span>Avg. delivery time</span><strong><?= esc($avgLeadTime ?? 0) ?> days</strong><small>Across active suppliers</small></div>
        <div class="sup-stat"><span>Active suppliers</span><strong><?= esc($activeSuppliers ?? 0) ?></strong><small>Can receive orders</small></div>
        <div class="sup-stat"><span>Pending orders</span><strong><?= esc($pendingOrders ?? 0) ?></strong><small>Across all suppliers</small></div>
    </div>

    <div class="sup-tabrow sticky-tabs">
        <div class="underline-tabs">
            <a href="<?= site_url('admin/suppliers') ?>" class="<?= $archived ? '' : 'active' ?>"<?= $archived ? '' : ' aria-current="page"' ?>>Active (<?= esc($activeCount ?? 0) ?>)</a>
            <a href="<?= site_url('admin/suppliers?status=archived') ?>" class="<?= $archived ? 'active' : '' ?>"<?= $archived ? ' aria-current="page"' : '' ?>><i class="bi bi-archive" aria-hidden="true"></i> Archived (<?= esc($archivedCount ?? 0) ?>)</a>
        </div>
        <a href="<?= site_url('admin/suppliers/create') ?>" class="btn btn-success sup-add"><i class="bi bi-plus-lg"></i> Add Supplier</a>
    </div>

    <?php if ($archived): ?>
        <p class="sup-archive-note">Archived suppliers are hidden from orders and alerts and can't sign in. Restore one to unlock their account.</p>
    <?php endif; ?>

    <?php if (!empty($suppliers)): ?>
    <div class="sup-grid">
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
                            <span class="sup-badge <?= $s['has_portal'] ? 'portal' : '' ?>"><?= $s['has_portal'] ? 'Portal active' : 'No Portal' ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="sup-card-foot">
                        <div><span><i class="bi bi-clock"></i> Delivery time</span><?= esc($s['lead_time_days']) ?> days</div>
                        <div><span><i class="bi bi-telephone"></i> Contact</span><?= esc($formatPhone($s['contact_number'] ?? null)) ?></div>
                        <div class="sup-terms"><span><i class="bi bi-cash-coin"></i> Terms</span><?= ! empty($s['payment_terms']) ? esc($s['payment_terms']) : '—' ?></div>
                        <div><span><i class="bi bi-box-seam"></i> Products</span><?= esc($s['product_count']) ?></div>
                        <?php if ($archived): ?>
                            <div><span><i class="bi bi-archive"></i> Archived on</span><?= ! empty($s['updated_at']) ? esc(date('M j, Y', strtotime($s['updated_at']))) : '—' ?></div>
                            <div><span><i class="bi bi-person"></i> Portal user</span><?= esc($s['portal_username'] ?? '—') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="sup-card-actions">
                        <?php if ($archived): ?>
                            <form method="post" action="<?= site_url('admin/suppliers/restore/' . $s['supplier_id']) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="sup-btn"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                            </form>
                        <?php else: ?>
                            <a href="<?= site_url('admin/suppliers/edit/' . $s['supplier_id']) ?>" class="sup-btn">Edit</a>
                            <?php $archiveWarning = trim(($s['has_portal'] ? 'Their portal account will be locked until you restore it.' : '')
                                . ($s['open_orders'] > 0 ? ' They still have ' . $s['open_orders'] . ' open order(s).' : '')) ?: 'You can restore this supplier later.'; ?>
                            <form method="post" action="<?= site_url('admin/suppliers/delete/' . $s['supplier_id']) ?>"
                                  data-confirm="<?= esc($archiveWarning, 'attr') ?>"
                                  data-confirm-title="Archive <?= esc($s['company_name'], 'attr') ?>?"
                                  data-confirm-ok="Archive" data-confirm-variant="danger">
                                <?= csrf_field() ?>
                                <button type="submit" class="sup-btn is-archive">Archive</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
    </div>
    <?php elseif ($archived): ?>
        <div class="sup-empty">
            <i class="bi bi-truck" aria-hidden="true"></i>
            <div class="title">No archived suppliers</div>
            <div class="hint">Suppliers you archive will appear here.</div>
        </div>
    <?php else: ?>
        <div class="sup-empty">
            <i class="bi bi-truck" aria-hidden="true"></i>
            <div class="title">No suppliers yet</div>
            <a href="<?= site_url('admin/suppliers/create') ?>" class="btn btn-success btn-sm"><i class="bi bi-plus-lg"></i> Add Supplier</a>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
