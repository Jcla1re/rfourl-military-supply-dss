<?php
// app/Views/partials/_notifications_list.php
// The Staff and Supplier Notifications page body (assets/css/notifications.css). A card with
// a link is the submit button of its "open" form (marks it read and goes there); any other
// card has a "Mark read" button while unread. Icons: App\Libraries\NotificationIcon.
//
// Expects $base ('staff' or 'supplier'), $groups, $filter, $allCount, $unreadCount.
use App\Libraries\NotificationIcon;

?>
<div class="nt-wrap">
    <?= view('partials/_notifications_toolbar', ['base' => $base, 'filter' => $filter, 'allCount' => $allCount, 'unreadCount' => $unreadCount]) ?>

    <?php if (empty($groups)): ?>
        <?= view('partials/_notifications_empty') ?>
    <?php else: ?>
        <div class="nt-list">
            <?php foreach ($groups as $label => $items): ?>
                <div class="nt-date"><?= esc($label) ?></div>
                <?php foreach ($items as $n): ?>
                    <?php
                    $look   = NotificationIcon::for($n);
                    $isRead = !empty($n['is_read']);
                    $state  = $look['color'] . ' ' . ($isRead ? 'is-read' : 'is-unread');
                    ?>
                    <?php if (!empty($n['link_url'])): ?>
                        <form method="post" action="<?= site_url($base . '/notifications/open/' . $n['notification_id']) ?>" class="nt-openform">
                            <?= csrf_field() ?>
                            <button type="submit" class="nt-card <?= $state ?>">
                                <span class="nt-icon" aria-hidden="true"><i class="bi <?= $look['icon'] ?>"></i></span>
                                <span class="nt-body">
                                    <span class="nt-title"><?= esc($n['title']) ?><?php if (!$isRead): ?><span class="new-pill">NEW</span><?php endif; ?></span>
                                    <?php if (!empty($n['message'])): ?><span class="nt-msg"><?= esc($n['message']) ?></span><?php endif; ?>
                                    <span class="nt-meta"><?= esc(date('M j, Y g:i A', strtotime($n['created_at']))) ?></span>
                                </span>
                                <span class="nt-go" aria-hidden="true"><i class="bi bi-arrow-right-circle"></i></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="nt-card <?= $state ?>">
                            <span class="nt-icon" aria-hidden="true"><i class="bi <?= $look['icon'] ?>"></i></span>
                            <div class="nt-body">
                                <div class="nt-title"><?= esc($n['title']) ?><?php if (!$isRead): ?><span class="new-pill">NEW</span><?php endif; ?></div>
                                <?php if (!empty($n['message'])): ?><div class="nt-msg"><?= esc($n['message']) ?></div><?php endif; ?>
                                <div class="nt-meta"><?= esc(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
                            </div>
                            <?php if (!$isRead): ?>
                                <form method="post" action="<?= site_url($base . '/notifications/mark-read/' . $n['notification_id']) ?>" class="nt-side">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="nt-btn is-green">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
