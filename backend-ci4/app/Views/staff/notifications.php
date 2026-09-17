
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('header_actions') ?>
<form method="post" action="<?= site_url('staff/notifications/mark-all-read') ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-light btn-sm">Mark all as read</button>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $notifications = $notifications ?? []; ?>

<style>
.sn-card { background: #fff; border-radius: 12px; padding: 16px 20px; display: flex; gap: 14px; margin-bottom: 14px; }
.sn-card.unread { border-left: 4px solid var(--green-text); }
.sn-card .icon { width: 38px; height: 38px; border-radius: 8px; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sn-card .title { font-weight: 800; }
.sn-card .msg { color: #555; font-size: 14px; margin-top: 2px; }
.sn-card .meta { color: #999; font-size: 12px; margin-top: 6px; }
.sn-card .new-pill { background: var(--green-bg); color: var(--green-text); border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 700; margin-left: 8px; }
</style>

<div class="page-wrap">
    <?php if (empty($notifications)): ?>
        <div class="empty-state">No notifications yet.</div>
    <?php else: ?>
        <?php foreach ($notifications as $n): ?>
            <div class="sn-card <?= empty($n['is_read']) ? 'unread' : '' ?>">
                <span class="icon"><i class="bi bi-bell"></i></span>
                <div class="flex-grow-1">
                    <div class="title"><?= esc($n['title']) ?><?php if (empty($n['is_read'])): ?><span class="new-pill">NEW</span><?php endif; ?></div>
                    <?php if (!empty($n['message'])): ?><div class="msg"><?= esc($n['message']) ?></div><?php endif; ?>
                    <div class="meta"><?= esc(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
                </div>
                <?php if (empty($n['is_read'])): ?>
                    <form method="post" action="<?= site_url('staff/notifications/mark-read/' . $n['notification_id']) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-success">Mark read</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
