
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<form method="post" action="<?= site_url('admin/notifications/mark-all-read') ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-light">Mark all as read</button>
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

.notif-card.green    { border-color: var(--green-text); }
.notif-card.green .icn { color: var(--green-text); border-color: var(--green-text); }
.notif-card.green .btn-view { background: var(--green-text); color: #fff; border: none; }
.notif-card.green .type-pill { background: var(--green-bg); color: var(--green-text); }

.notif-card.amber  { border-color: var(--amber-text); }
.notif-card.amber .icn { color: var(--amber-text); border-color: var(--amber-text); }
.notif-card.amber .btn-view { background: var(--amber-text); color: #fff; border: none; }
.notif-card.amber .type-pill { background: var(--amber-bg); color: var(--amber-text); }

/* Order-status cards: the whole card is a submit button that marks it done
   and forwards straight to the order — reset button chrome so it still
   looks/lays out exactly like a plain notif-card. */
.notif-card-openform { display: block; width: 100%; padding: 0; margin-bottom: 14px; }
.notif-card-open {
    all: unset; display: flex; align-items: flex-start; gap: 16px; width: 100%; box-sizing: border-box;
    background: #fff; border-radius: 12px; padding: 18px 20px; border-left: 4px solid var(--green-text); cursor: pointer;
}
.notif-card-open:hover { background: #f7f9fc; }
.notif-card-open .icn { width: 40px; height: 40px; border-radius: 50%; border: 2px solid var(--green-text); color: var(--green-text); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.notif-card-open .body { flex: 1; text-align: left; }
.notif-card-open .title { font-weight: 800; font-size: 17px; color: #1c1c1c; }
.notif-card-open .msg { color: #555; font-size: 14px; margin-top: 2px; }
.notif-card-open .type-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; margin-top: 8px; background: var(--green-bg); color: var(--green-text); }
.notif-card-open .go { align-self: center; color: var(--green-text); font-size: 20px; }

/* Staff-activity cards: <details> disclosure — collapsed shows just the
   title, expanding reveals the message + a "Mark as Done" action. */
.notif-details { margin-bottom: 14px; }
.notif-details summary { list-style: none; cursor: pointer; }
.notif-details summary::-webkit-details-marker { display: none; }
.notif-details .notif-card { margin-bottom: 0; }
.notif-details[open] .notif-card { border-radius: 12px 12px 0 0; }
.notif-details .chevron { margin-left: auto; align-self: center; transition: transform .15s ease; color: #999; }
.notif-details[open] .chevron { transform: rotate(180deg); }
.notif-details .notif-expand {
    background: #fff; border-radius: 0 0 12px 12px; padding: 4px 20px 18px 76px; border-left: 4px solid #ccc; border-top: 1px dashed #eee;
}
.notif-details.red .notif-expand, .notif-details.red .notif-card { border-left-color: var(--accent-maroon); }
.notif-details.amber .notif-expand, .notif-details.amber .notif-card { border-left-color: var(--amber-text); }
.notif-details.green .notif-expand, .notif-details.green .notif-card { border-left-color: var(--green-text); }

.notif-actions { display: flex; gap: 10px; margin-top: 12px; }
.notif-actions button { padding: 9px 18px; border-radius: 8px; font-weight: 700; border: 1px solid #ccc; background: #fff; cursor: pointer; }
.notif-actions .approve { background: var(--green-text); color: #fff; border: none; }
.notif-actions .decline { background: var(--accent-maroon); color: #fff; border: none; }
.notif-actions .done { background: var(--sidebar-bg); color: #fff; border: none; }
</style>

<div class="page-wrap">
    <div class="notif-filters">
        <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All <span class="count"><?= esc((string) $allCount) ?></span></a>
        <a href="?filter=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread <span class="count"><?= esc((string) $unreadCount) ?></span></a>
    </div>

    <?php if (empty($groups)): ?>
        <div class="empty-state">No notifications yet. You'll see reorder alerts, order status changes, and supplier updates here.</div>
    <?php else: ?>
        <?php foreach ($groups as $label => $items): ?>
            <div class="notif-date-label"><?= esc($label) ?></div>
            <?php foreach ($items as $n): ?>
                <?php
                $typeLower = strtolower($n['type']);
                $isDamagedLost = str_contains($typeLower, 'damaged') || str_contains($typeLower, 'lost');
                $color = $isDamagedLost ? 'red' : ($n['category'] === 'access_request' ? 'amber' : 'green');
                $icon = match (true) {
                    $isDamagedLost => 'bi-exclamation',
                    $n['category'] === 'access_request' => 'bi-exclamation',
                    $n['category'] === 'order_status' => 'bi-clipboard-check',
                    default => 'bi-bell',
                };
                ?>

                <?php if ($n['category'] === 'order_status'): ?>
                    <form method="post" action="<?= site_url('admin/notifications/open/' . $n['notification_id']) ?>" class="notif-card-openform">
                        <?= csrf_field() ?>
                        <button type="submit" class="notif-card-open">
                            <span class="icn"><i class="bi <?= $icon ?>"></i></span>
                            <span class="body">
                                <span class="title" style="display:block;"><?= esc($n['title']) ?></span>
                                <?php if (!empty($n['message'])): ?><span class="msg" style="display:block;"><?= esc($n['message']) ?></span><?php endif; ?>
                                <span class="type-pill"><?= esc($n['type']) ?></span>
                            </span>
                            <span class="go"><i class="bi bi-arrow-right-circle"></i></span>
                        </button>
                    </form>

                <?php elseif ($n['category'] === 'access_request'): ?>
                    <div class="notif-card <?= $color ?>">
                        <div class="icn"><i class="bi <?= $icon ?>"></i></div>
                        <div class="flex-grow-1">
                            <div class="title"><?= esc($n['title']) ?></div>
                            <?php if (!empty($n['message'])): ?><div class="msg"><?= esc($n['message']) ?></div><?php endif; ?>
                            <span class="type-pill"><?= esc($n['type']) ?></span>
                            <?php if (empty($n['status'])): ?>
                                <div class="notif-actions">
                                    <form method="post" action="<?= site_url('admin/notifications/approve/' . $n['notification_id']) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="approve">Approve</button>
                                    </form>
                                    <form method="post" action="<?= site_url('admin/notifications/decline/' . $n['notification_id']) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="decline">Decline</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="msg" style="margin-top:8px;"><em>Resolved: <?= esc(ucfirst($n['status'])) ?></em></div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <details class="notif-details <?= $color ?>">
                        <summary>
                            <div class="notif-card <?= $color ?>">
                                <div class="icn"><i class="bi <?= $icon ?>"></i></div>
                                <div class="flex-grow-1">
                                    <div class="title"><?= esc($n['title']) ?></div>
                                    <span class="type-pill"><?= esc($n['type']) ?></span>
                                </div>
                                <span class="chevron"><i class="bi bi-chevron-down"></i></span>
                            </div>
                        </summary>
                        <div class="notif-expand">
                            <?php if (!empty($n['message'])): ?><div class="msg"><?= esc($n['message']) ?></div><?php endif; ?>
                            <?php if (empty($n['is_read'])): ?>
                                <div class="notif-actions">
                                    <form method="post" action="<?= site_url('admin/notifications/mark-read/' . $n['notification_id']) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="done">Mark as Done</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
