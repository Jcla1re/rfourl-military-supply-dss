<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('admin/trend-analysis') ?>" class="btn btn-success">Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php $summary = $summary ?? null; ?>

<style>
.ar-card { background: #fff; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 16px; }
.ar-card h5 { font-weight: 800; margin-bottom: 6px; }
.ar-stats { display: flex; flex-wrap: wrap; gap: 36px; margin: 14px 0 4px; }
.ar-stats span { display: block; color: #777; font-size: 13px; }
.ar-stats strong { font-size: 22px; }
.ar-table { width: 100%; font-size: 14px; margin-top: 14px; }
.ar-table th { text-align: left; color: #777; font-weight: 600; padding: 8px 0; border-bottom: 1px solid #eee; }
.ar-table td { padding: 8px 0; border-bottom: 1px solid #f4f4f4; }
.ar-table .num { text-align: right; }
.ar-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
</style>

<div class="page-wrap">
    <?php if (! empty($success)): ?><div class="alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (! empty($error)): ?><div class="alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <?php if (! $summary): ?>
        <div class="ar-card">
            <h5>Nothing to review</h5>
            <p class="text-muted mb-0">There are no sales records older than 3 years. Records stay in Sales History and Trend Analysis until they pass that age.</p>
        </div>
    <?php else: ?>
        <div class="ar-card">
            <h5>Sales records older than 3 years</h5>
            <p class="text-muted mb-0">
                These sales are dated before <strong><?= esc(date('F j, Y', strtotime($summary['cutoff']))) ?></strong>.
                Until you remove them they stay searchable in Sales History and Trend Analysis. To keep the system fast, you can save a copy and then remove them.
            </p>
            <div class="ar-stats">
                <div><span>Receipts</span><strong><?= number_format($summary['receipts']) ?></strong></div>
                <div><span>Units sold</span><strong><?= number_format($summary['units']) ?></strong></div>
                <div><span>Sales value</span><strong>&#8369;<?= number_format($summary['revenue'], 2) ?></strong></div>
            </div>
            <table class="ar-table">
                <thead><tr><th>Year</th><th class="num">Receipts</th><th class="num">Units</th><th class="num">Sales value</th></tr></thead>
                <tbody>
                <?php foreach ($summary['years'] as $y): ?>
                    <tr><td><?= esc($y['yr']) ?></td><td class="num"><?= number_format($y['receipts']) ?></td><td class="num"><?= number_format($y['units']) ?></td><td class="num">&#8369;<?= number_format($y['revenue'], 2) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ar-card">
            <h5>1. Save a copy first (recommended)</h5>
            <p class="text-muted mb-0">The copy has the yearly trend (units and sales by month), the best sellers of each year, and every receipt.</p>
            <div class="ar-actions">
                <a class="btn btn-success" href="<?= site_url('admin/archive/pdf') ?>"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</a>
                <a class="btn btn-outline-dark" href="<?= site_url('admin/archive/print') ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
            </div>
        </div>

        <div class="ar-card">
            <h5>2. Remove them from the system</h5>
            <p class="text-muted">This permanently deletes the <?= number_format($summary['receipts']) ?> receipts above. It cannot be undone, so make sure you have saved a copy if you need one.</p>
            <form method="post" action="<?= site_url('admin/archive/purge') ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-4"><label class="fw-bold mb-1 d-block">Type REMOVE</label><input class="form-control" name="confirm_text" autocomplete="off" required></div>
                <div class="col-md-4"><label class="fw-bold mb-1 d-block">Your password</label><input class="form-control" type="password" name="current_password" autocomplete="current-password" required></div>
                <div class="col-md-4"><button type="submit" class="btn btn-danger w-100" onclick="return confirm('Permanently remove these sales records?')">Remove old records</button></div>
            </form>
        </div>

        <div class="ar-card">
            <h5>Not now</h5>
            <p class="text-muted mb-0">Keep everything for now. You'll be reminded again next time.</p>
            <form method="post" action="<?= site_url('admin/archive/later') ?>" class="ar-actions">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary">Keep them for now</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
