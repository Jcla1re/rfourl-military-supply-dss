
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>

<?php
$groups = $groups ?? [];
$filter = $filter ?? 'all';
$allCount = $allCount ?? 0;
$unreadCount = $unreadCount ?? 0;
// Styles: assets/css/notifications.css (shared with Staff and Supplier). Icons: App\Libraries\NotificationIcon.
$typeLabel = fn (array $n) => ['Reorder Point Notice' => 'Reorder At Notice'][$n['type']] ?? $n['type'];
$when = fn (array $n) => date('M j, Y g:i A', strtotime($n['created_at']));
?>

<div class="page-wrap">
    <div class="nt-wrap">
        <?= view('partials/_notifications_toolbar', ['base' => 'admin', 'filter' => $filter, 'allCount' => $allCount, 'unreadCount' => $unreadCount]) ?>

        <?php if (empty($groups)): ?>
            <?= view('partials/_notifications_empty') ?>
        <?php else: ?>
            <div class="nt-list">
            <?php foreach ($groups as $label => $items): ?>
                <div class="nt-date"><?= esc($label) ?></div>
                <?php foreach ($items as $n): ?>
                    <?php
                    $look   = \App\Libraries\NotificationIcon::for($n);
                    $icon   = $look['icon'];
                    $isRead = !empty($n['is_read']);
                    $state  = $look['color'] . ' ' . ($isRead ? 'is-read' : 'is-unread');
                    ?>

                    <?php if (in_array($n['category'], ['order_status', 'sales_records'], true)): // opens its link ?>
                        <form method="post" action="<?= site_url('admin/notifications/open/' . $n['notification_id']) ?>" class="nt-openform">
                            <?= csrf_field() ?>
                            <button type="submit" class="nt-card <?= $state ?>">
                                <span class="nt-icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
                                <span class="nt-body">
                                    <span class="nt-title"><?= esc($n['title']) ?><?php if (!$isRead): ?><span class="new-pill">NEW</span><?php endif; ?></span>
                                    <?php if (!empty($n['message'])): ?><span class="nt-msg"><?= esc($n['message']) ?></span><?php endif; ?>
                                    <span class="nt-meta"><span><?= esc($when($n)) ?></span><span class="type-pill"><?= esc($typeLabel($n)) ?></span></span>
                                </span>
                                <span class="nt-go" aria-hidden="true"><i class="bi bi-arrow-right-circle"></i></span>
                            </button>
                        </form>

                    <?php elseif ($n['category'] === 'security'): ?>
                        <div class="nt-card <?= $state ?>">
                            <span class="nt-icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
                            <div class="nt-body">
                                <div class="nt-title"><?= esc($n['title']) ?><?php if (!$isRead): ?><span class="new-pill">NEW</span><?php endif; ?></div>
                                <?php if (!empty($n['message'])): ?><div class="nt-msg"><?= esc($n['message']) ?></div><?php endif; ?>
                                <div class="nt-meta"><span><?= esc($when($n)) ?></span><span class="type-pill"><?= esc($typeLabel($n)) ?></span></div>
                                <div class="nt-actions">
                                    <?php if (empty($n['status']) && !empty($n['action_user_id']) && $n['type'] === \App\Libraries\StaffPasswordAlert::TYPE_FORGOT): ?>
                                        <form method="post" action="<?= site_url('admin/notifications/accept/' . $n['notification_id']) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="nt-btn is-green">Accept</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (empty($n['status']) && !empty($n['action_user_id'])): ?>
                                        <form method="post" action="<?= site_url('admin/notifications/lock/' . $n['notification_id']) ?>"
                                              data-confirm="They won't be able to sign in until you reset their password from Manage Accounts."
                                              data-confirm-title="Lock this account?"
                                              data-confirm-ok="Lock account"
                                              data-confirm-variant="danger">
                                            <?= csrf_field() ?>
                                            <?php $__lockTarget = demo_mode() ? model('UserModel')->find((int) $n['action_user_id']) : null; ?>
                                            <button type="submit" class="nt-btn is-red"<?= demo_lock_attr($__lockTarget) ?>>Lock account</button>
                                        </form>
                                    <?php elseif (($n['status'] ?? '') === 'locked'): ?>
                                        <div class="nt-msg"><em>Account locked</em></div>
                                    <?php elseif (($n['status'] ?? '') === 'confirmed'): ?>
                                        <div class="nt-msg"><em>Confirmed</em></div>
                                    <?php endif; ?>
                                    <?php if (!$isRead && empty($n['status'])): ?>
                                        <form method="post" action="<?= site_url('admin/notifications/mark-read/' . $n['notification_id']) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="nt-btn">Mark as Done</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($n['category'] === 'access_request'): // old approval requests: history only ?>
                        <div class="nt-card <?= $state ?>">
                            <span class="nt-icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
                            <div class="nt-body">
                                <div class="nt-title"><?= esc($n['title']) ?></div>
                                <?php if (!empty($n['message'])): ?><div class="nt-msg"><?= esc($n['message']) ?></div><?php endif; ?>
                                <div class="nt-meta"><span><?= esc($when($n)) ?></span><span class="type-pill"><?= esc($n['type']) ?></span></div>
                                <div class="nt-note"><em>Closed: staff now reset their own password.</em></div>
                            </div>
                        </div>

                    <?php else: ?>
                        <details class="nt-card nt-details <?= $state ?>">
                            <summary>
                                <span class="nt-icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></span>
                                <span class="nt-body">
                                    <span class="nt-title"><?= esc($n['title']) ?><?php if (!$isRead): ?><span class="new-pill">NEW</span><?php endif; ?></span>
                                    <span class="nt-meta"><span><?= esc($when($n)) ?></span><span class="type-pill"><?= esc($typeLabel($n)) ?></span></span>
                                </span>
                                <span class="nt-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span>
                            </summary>
                            <div class="nt-expand">
                                <?php if (!empty($n['message'])): ?><div class="nt-msg"><?= esc($n['message']) ?></div><?php endif; ?>
                                <?php if (empty($n['is_read'])): ?>
                                    <div class="nt-actions">
                                        <form method="post" action="<?= site_url('admin/notifications/mark-read/' . $n['notification_id']) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="nt-btn">Mark as Done</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
