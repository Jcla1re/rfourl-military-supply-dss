
<?= $this->extend('layouts/supplier_layout') ?>

<?= $this->section('header_actions') ?>
<form method="post" action="<?= site_url('supplier/notifications/mark-all-read') ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-light btn-sm">Mark all as read</button>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$groups = $groups ?? [];
$filter = $filter ?? 'all';
$allCount = $allCount ?? 0;
$unreadCount = $unreadCount ?? 0;
?>

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
.notif-date-label:first-child { margin-top: 0; }

.sn-card { background: #fff; border-radius: 12px; padding: 16px 20px; display: flex; gap: 14px; margin-bottom: 14px; }
.sn-card.unread { border-left: 4px solid var(--green-text); }
.sn-card .icon { width: 38px; height: 38px; border-radius: 8px; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sn-card .title { font-weight: 800; }
.sn-card .msg { color: #555; font-size: 14px; margin-top: 2px; }
.sn-card .meta { color: #999; font-size: 12px; margin-top: 6px; }
.sn-card .new-pill { background: var(--green-bg); color: var(--green-text); border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 700; margin-left: 8px; }

/* Cards that have somewhere to go: the whole card is a submit button that
   marks it read and forwards straight there. */
.sn-card-openform { display: block; width: 100%; padding: 0; margin-bottom: 14px; }
.sn-card-open {
    all: unset; display: flex; gap: 14px; width: 100%; box-sizing: border-box;
    background: #fff; border-radius: 12px; padding: 16px 20px; cursor: pointer;
}
.sn-card-open:hover { background: #f7f9fc; }
.sn-card-open.unread { border-left: 4px solid var(--green-text); }
.sn-card-open .icon { width: 38px; height: 38px; border-radius: 8px; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sn-card-open .body { flex: 1; text-align: left; }
.sn-card-open .title { font-weight: 800; color: #1c1c1c; }
.sn-card-open .msg { color: #555; font-size: 14px; margin-top: 2px; }
.sn-card-open .meta { color: #999; font-size: 12px; margin-top: 6px; }
.sn-card-open .go { align-self: center; color: var(--green-text); font-size: 20px; }
</style>

<div class="page-wrap">
    <div class="notif-filters">
        <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All <span class="count"><?= esc((string) $allCount) ?></span></a>
        <a href="?filter=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread <span class="count"><?= esc((string) $unreadCount) ?></span></a>
    </div>

    <?php if (empty($groups)): ?>
        <div class="empty-state">No notifications yet.</div>
    <?php else: ?>
        <?php foreach ($groups as $label => $items): ?>
            <div class="notif-date-label"><?= esc($label) ?></div>
            <?php foreach ($items as $n): ?>
                <?php if (!empty($n['link_url'])): ?>
                    <form method="post" action="<?= site_url('supplier/notifications/open/' . $n['notification_id']) ?>" class="sn-card-openform">
                        <?= csrf_field() ?>
                        <button type="submit" class="sn-card-open <?= empty($n['is_read']) ? 'unread' : '' ?>">
                            <span class="icon"><i class="bi bi-bell"></i></span>
                            <span class="body">
                                <span class="title" style="display:block;"><?= esc($n['title']) ?><?php if (empty($n['is_read'])): ?><span class="new-pill">NEW</span><?php endif; ?></span>
                                <?php if (!empty($n['message'])): ?><span class="msg" style="display:block;"><?= esc($n['message']) ?></span><?php endif; ?>
                                <span class="meta" style="display:block;"><?= esc(date('M j, Y g:i A', strtotime($n['created_at']))) ?></span>
                            </span>
                            <span class="go"><i class="bi bi-arrow-right-circle"></i></span>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="sn-card <?= empty($n['is_read']) ? 'unread' : '' ?>">
                        <span class="icon"><i class="bi bi-bell"></i></span>
                        <div class="flex-grow-1">
                            <div class="title"><?= esc($n['title']) ?><?php if (empty($n['is_read'])): ?><span class="new-pill">NEW</span><?php endif; ?></div>
                            <?php if (!empty($n['message'])): ?><div class="msg"><?= esc($n['message']) ?></div><?php endif; ?>
                            <div class="meta"><?= esc(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
                        </div>
                        <?php if (empty($n['is_read'])): ?>
                            <form method="post" action="<?= site_url('supplier/notifications/mark-read/' . $n['notification_id']) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-success">Mark read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
