<?php
/** Owner notice shown on Trend Analysis and Sales when records are 3+ years old. $archiveNotice from the controller. */
if (empty($archiveNotice)) {
    return;
}
?>
<div class="d-flex flex-wrap align-items-center gap-3 mb-3" style="background:#fff8e1;border:1px solid #f1d98a;border-radius:12px;padding:14px 18px;">
    <i class="bi bi-archive" style="font-size:22px;color:#8a6d00;"></i>
    <div class="flex-grow-1">
        <strong><?= number_format($archiveNotice['receipts']) ?> sales records are older than 3 years</strong>
        <span class="text-muted"> (before <?= esc(date('M j, Y', strtotime($archiveNotice['cutoff']))) ?>). Do you want to save them as a PDF or print them before they are removed?</span>
    </div>
    <a href="<?= site_url('admin/archive') ?>" class="btn btn-sm btn-success">Review</a>
    <form method="post" action="<?= site_url('admin/archive/later') ?>" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline-secondary">Remind me later</button>
    </form>
</div>
