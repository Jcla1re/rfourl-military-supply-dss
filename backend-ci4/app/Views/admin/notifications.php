
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<form method="post" action="<?= site_url('admin/notifications/mark-all-read') ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-light">Mark all as read</button>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $groups = $groups ?? []; $filter = $filter ?? 'all'; ?>

<style>
.notif-filters { display: flex; gap: 12px; margin-bottom: 22px; }
.notif-filters a {
    display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 999px;
    text-decoration: none; font-weight: 700; background: #fff; color: #1c1c1c; border: 1px solid #ddd;
}
.notif-filters a.active { background: var(--sidebar-bg); color: #fff; border-color: var(--sidebar-bg); }
.notif-filters a .count { background: rgba(0,0,0,.08); border-radius: 999px; padding: 2px 9px; font-size: 12px; }
.notif-filters a.active .count { background: rgba(255,255,255,.2); }

.notif-date-label { color: #888; font-weight: 800; font-size: 22px; margin: 20px 0 12px; }

.notif-card {
    background: #fff; border-radius: 12px; padding: 18px 20px; display: flex; align-items: flex-start; gap: 16px;
    margin-bottom: 14px; border-left: 4px solid #ccc;
}
.notif-card .icn { width: 40px; height: 40px; border-radius: 50%; border: 2px solid; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.notif-card .title { font-weight: 800; font-size: 17px; }
.notif-card .msg { color: #555; font-size: 14px; margin-top: 2px; }
.notif-card .btn-view { margin-left: auto; align-self: center; white-space: nowrap; }
.notif-card .type-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; margin-top: 8px; }

.notif-card.red     { border-color: var(--accent-maroon); }
.notif-card.red .icn { color: var(--accent-maroon); border-color: var(--accent-maroon); }
.notif-card.red .btn-view { background: var(--accent-maroon); color: #fff; border: none; }

.notif-card.blue    { border-color: #2159a8; }
.notif-card.blue .icn { color: #2159a8; border-color: #2159a8; }
.notif-card.blue .btn-view { background: #2159a8; color: #fff; border: none; }
.notif-card.blue .type-pill { background: #dbe6f5; color: #2159a8; }

.notif-card.purple  { border-color: #5a3aa8; }
.notif-card.purple .icn { color: #5a3aa8; border-color: #5a3aa8; }
.notif-card.purple .btn-view { background: #5a3aa8; color: #fff; border: none; }
.notif-card.purple .type-pill { background: #e6ddf5; color: #5a3aa8; }
</style>

<div class="page-wrap">
    <div class="notif-filters">
        <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All <span class="count"><?= esc($allCount ?? 0) ?></span></a>
        <a href="?filter=staff" class="<?= $filter === 'staff' ? 'active' : '' ?>">Staff Activity <span class="count"><?= esc($staffCount ?? 0) ?></span></a>
        <a href="?filter=orders" class="<?= $filter === 'orders' ? 'active' : '' ?>">Order Status <span class="count"><?= esc($orderCount ?? 0) ?></span></a>
    </div>

    <?php if (empty($groups)): ?>
        <div class="empty-state">No notifications yet. You'll see reorder alerts, order status changes, and supplier updates here.</div>
    <?php else: ?>
        <?php foreach ($groups as $label => $items): ?>
            <div class="notif-date-label"><?= esc($label) ?></div>
            <?php foreach ($items as $n): ?>
                <?php
                $typeLower = strtolower($n['type']);
                $color = str_contains($typeLower, 'damaged') || str_contains($typeLower, 'lost') ? 'red'
                    : (str_contains($typeLower, 'password') ? 'purple' : 'blue');
                $icon = match (true) {
                    str_contains($typeLower, 'damaged'), str_contains($typeLower, 'lost') => 'bi-exclamation',
                    str_contains($typeLower, 'password') => 'bi-exclamation',
                    str_contains($typeLower, 'order') => 'bi-clipboard-check',
                    default => 'bi-bell',
                };
                ?>
                <div class="notif-card <?= $color ?>">
                    <div class="icn"><i class="bi <?= $icon ?>"></i></div>
                    <div class="flex-grow-1">
                        <div class="title"><?= esc($n['title']) ?></div>
                        <?php if (!empty($n['message'])): ?><div class="msg"><?= esc($n['message']) ?></div><?php endif; ?>
                        <span class="type-pill"><?= esc($n['type']) ?></span>
                    </div>
                    <?php if (empty($n['is_read'])): ?>
                        <form method="post" action="<?= site_url('admin/notifications/mark-read/' . $n['notification_id']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-view">View Details</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
