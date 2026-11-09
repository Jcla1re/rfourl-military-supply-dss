<?php
// app/Views/partials/_notifications_toolbar.php
// The Notifications toolbar of the Owner, Staff and Supplier (assets/css/notifications.css):
// All / Unread on the left, Mark all as read / Clear all on the right. Mark all as read is
// disabled with nothing unread, Clear all with nothing to clear; the forms are unchanged.
//
// Expects $base ('admin', 'staff' or 'supplier'), $filter, $allCount, $unreadCount.
?>
<link href="/assets/css/notifications.css" rel="stylesheet">
<div class="nt-bar">
    <div class="nt-chips">
        <a href="?filter=all" class="nt-chip <?= $filter === 'all' ? 'active' : '' ?>">All <span class="nt-count"><?= esc((string) $allCount) ?></span></a>
        <a href="?filter=unread" class="nt-chip <?= $filter === 'unread' ? 'active' : '' ?>">Unread <span class="nt-count"><?= esc((string) $unreadCount) ?></span></a>
    </div>
    <div class="nt-bar-actions">
        <form method="post" action="<?= site_url($base . '/notifications/mark-all-read') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="nt-bar-btn is-read"<?= (int) $unreadCount === 0 ? ' disabled' : '' ?>><i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read</button>
        </form>
        <form method="post" action="<?= site_url($base . '/notifications/clear-all') ?>"
              data-confirm="This permanently deletes all your notifications. This cannot be undone."
              data-confirm-title="Clear all notifications?" data-confirm-ok="Clear all" data-confirm-variant="danger">
            <?= csrf_field() ?>
            <button type="submit" class="nt-bar-btn is-clear"<?= (int) $allCount === 0 ? ' disabled' : '' ?>><i class="bi bi-trash3" aria-hidden="true"></i> Clear all</button>
        </form>
    </div>
</div>
