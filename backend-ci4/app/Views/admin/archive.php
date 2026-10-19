<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>
<?php $summary = $summary ?? null; $years = (int) ($years ?? 10); $savedCopy = $savedCopy ?? null; ?>

<style>
/* Old Sales Records (same type rules as Settings > Data & Records). Poppins throughout. */
.ar-page { font-family: 'Poppins', sans-serif; padding: 24px 32px; display: flex; flex-direction: column; gap: 16px; align-items: stretch; }
.ar-page h5, .ar-page label, .ar-page button, .ar-page input { font-family: inherit; }
.ar-back {
    align-self: flex-start; display: inline-flex; align-items: center; gap: 4px;
    padding: 6px 8px; margin-left: -8px; border-radius: 8px;
    font-size: 14px; font-weight: 600; color: #3B5434; text-decoration: none;
}
.ar-back i { font-size: 16px; line-height: 1; }
.ar-back:hover { background: #EAF1E3; color: #3B5434; }
.ar-back:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }

.ar-card { background: #FFFFFF; border: 1px solid #E8E5DC; border-radius: 14px; padding: 24px 28px; }
.ar-title { font-size: 19px; font-weight: 600; color: #1F2617; margin: 0; line-height: 1.3; }
.ar-title small { font-size: 14px; font-weight: 500; color: #5C6152; }
.ar-desc { font-size: 14px; color: #5C6152; line-height: 1.55; margin: 4px 0 0; }
.ar-desc strong { color: #1F2617; font-weight: 600; }

.ar-stats { display: flex; flex-wrap: wrap; gap: 48px; margin-top: 18px; }
.ar-stats span { display: block; font-size: 13px; color: #5C6152; }
.ar-stats strong { display: block; font-size: 24px; font-weight: 700; color: #1F2617; font-variant-numeric: tabular-nums; }

.ar-table { width: 620px; max-width: 100%; margin-top: 16px; font-size: 14px; color: #1F2617; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.ar-table th { font-size: 12px; font-weight: 600; color: #5C6152; padding: 8px 0; border-bottom: 1px solid #E8E5DC; text-align: left; }
.ar-table td { padding: 10px 0; border-bottom: 1px solid #F0EEE7; }
.ar-table .num { text-align: right; }

.ar-step { display: flex; gap: 16px; align-items: flex-start; }
.ar-step-body { flex: 1; min-width: 0; }
.ar-badge { width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; }
.ar-badge.is-1 { background: #EAF1E3; color: #2C3A1E; }
.ar-badge.is-1.is-done { background: #71AA60; color: #fff; }
.ar-badge.is-2 { background: #FDECEA; color: #B42318; }

.ar-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 14px; }
.ar-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 18px;
    border-radius: 10px; font-size: 15px; font-weight: 600; text-decoration: none; cursor: pointer;
    border: 1px solid transparent; transition: background-color .15s ease, border-color .15s ease;
}
.ar-btn:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
.ar-btn-primary { background: #71AA60; color: #fff; }
.ar-btn-primary:hover { background: #5F9850; color: #fff; }
.ar-btn-outline { background: #fff; border-color: #D6D2C6; color: #2C3A1E; }
.ar-btn-outline:hover { background: #F5F3EE; color: #2C3A1E; }
.ar-btn-danger { background: #B42318; color: #fff; }
.ar-btn-danger:hover:not(:disabled) { background: #912018; }
.ar-btn:disabled { background: #E8E5DC; border-color: #E8E5DC; color: #8A8D7E; cursor: not-allowed; }
.ar-saved { font-size: 14px; font-weight: 500; color: #3B5434; }
.ar-spin { display: inline-block; animation: arSpin 1s linear infinite; }
@keyframes arSpin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .ar-spin { animation: none; } }

.ar-unsaved { display: flex; align-items: center; gap: 8px; width: fit-content; margin-top: 12px; padding: 10px 14px; border-radius: 10px; background: #FFF5DA; color: #7A5300; font-size: 13px; }
.ar-unsaved[hidden] { display: none; }

/* Password row: label above the input; the button sits level with the input. */
.ar-confirm { display: grid; grid-template-columns: 380px auto; column-gap: 16px; justify-content: start; align-items: center; margin-top: 16px; }
.ar-confirm label { grid-column: 1; grid-row: 1; font-size: 14px; font-weight: 600; color: #1F2617; margin-bottom: 6px; }
.ar-confirm input { grid-column: 1; grid-row: 2; width: 380px; max-width: 100%; height: 46px; padding: 0 14px; border: 1px solid #D6D2C6; border-radius: 10px; font-size: 15px; color: #1F2617; background: #fff; }
.ar-confirm input:focus { outline: none; border-color: #71AA60; box-shadow: 0 0 0 3px rgba(113,170,96,.25); }
.ar-confirm button { grid-column: 2; grid-row: 2; }
.ar-confirm small { grid-column: 1; grid-row: 3; font-size: 12px; color: #5C6152; margin-top: 6px; }
@media (max-width: 640px) {
    .ar-page { padding: 20px 16px; }
    .ar-confirm { grid-template-columns: 1fr; }
    .ar-confirm button { grid-column: 1; grid-row: 4; margin-top: 12px; justify-self: start; }
}
</style>

<div class="ar-page">
    <?php if (! empty($success)): ?><div class="alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (! empty($error)): ?><div class="alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <a href="<?= site_url('admin/settings?tab=records') ?>" class="ar-back"><i class="bi bi-chevron-left" aria-hidden="true"></i> Back to Data &amp; Records</a>

    <?php if (! $summary): ?>
        <div class="ar-card">
            <h5 class="ar-title">Nothing to review</h5>
            <p class="ar-desc">No sales records are older than <?= $years ?> years. Records stay in Sales History and Trend Analysis until they pass that age.</p>
        </div>
    <?php else: ?>
        <div class="ar-card">
            <h5 class="ar-title">Sales records older than <?= $years ?> years</h5>
            <p class="ar-desc">
                These sales are dated before <strong><?= esc(date('F j, Y', strtotime($summary['cutoff']))) ?></strong>.
                Until you remove them, they stay in Sales History and Trend Analysis.
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

        <div class="ar-card ar-step">
            <span class="ar-badge is-1<?= $savedCopy ? ' is-done' : '' ?>" id="arStep1Badge" aria-hidden="true"><?= $savedCopy ? '&#10003;' : '1' ?></span>
            <div class="ar-step-body">
                <h5 class="ar-title">Save a copy first <small>(recommended)</small></h5>
                <p class="ar-desc">The PDF has the yearly trend, the best sellers of each year, and every receipt.</p>
                <div class="ar-actions">
                    <a class="ar-btn ar-btn-primary" id="arPdfBtn" href="<?= site_url('admin/archive/pdf') ?>"><i class="bi bi-download" aria-hidden="true"></i> <span class="ar-pdf-label">Save as PDF</span></a>
                    <a class="ar-btn ar-btn-outline" href="<?= site_url('admin/archive/print') ?>" target="_blank"><i class="bi bi-printer" aria-hidden="true"></i> Print</a>
                    <span class="ar-saved" id="arSaved" role="status"<?= $savedCopy ? '' : ' hidden' ?>>&#10003; Saved <span id="arSavedFile"><?= esc($savedCopy['file'] ?? '') ?></span></span>
                </div>
            </div>
        </div>

        <div class="ar-card ar-step">
            <span class="ar-badge is-2" aria-hidden="true">2</span>
            <div class="ar-step-body">
                <h5 class="ar-title">Remove them from the system</h5>
                <p class="ar-desc">This permanently deletes the <?= number_format($summary['receipts']) ?> receipts above. Trend Analysis and Top Sellers will no longer include them. This can't be undone.</p>
                <p class="ar-unsaved" id="arUnsaved"<?= $savedCopy ? ' hidden' : '' ?>><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> You haven't saved a copy yet.</p>
                <form method="post" action="<?= site_url('admin/archive/purge') ?>" id="arPurgeForm" autocomplete="off"
                      data-confirm="This permanently deletes <?= number_format($summary['receipts']) ?> sales records. It can't be undone."
                      data-confirm-title="Permanently remove sales records?"
                      data-confirm-ok="Remove records" data-confirm-variant="danger">
                    <?= csrf_field() ?>
                    <div class="ar-confirm">
                        <label for="arPassword">Enter your password to confirm</label>
                        <input type="password" id="arPassword" name="current_password" value="" placeholder="Password" autocomplete="new-password" aria-describedby="arPasswordHelp" required>
                        <button type="submit" class="ar-btn ar-btn-danger" id="arRemoveBtn" disabled>Remove <?= number_format($summary['receipts']) ?> records</button>
                        <small id="arPasswordHelp">The button turns on after you enter your password.</small>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function () {
            // Password starts empty (also after Back / restore from cache); the button follows it.
            const pw = document.getElementById('arPassword');
            const btn = document.getElementById('arRemoveBtn');
            const sync = () => { btn.disabled = pw.value.trim() === ''; };
            const reset = () => { pw.value = ''; sync(); };
            pw.addEventListener('input', sync);
            window.addEventListener('pageshow', reset);
            reset();

            // Save as PDF: download in place, then tick step 1 and show the file name.
            const pdf = document.getElementById('arPdfBtn');
            const label = pdf.querySelector('.ar-pdf-label');
            const icon = pdf.querySelector('.bi');
            pdf.addEventListener('click', event => {
                event.preventDefault();
                if (pdf.getAttribute('aria-busy') === 'true') return;
                pdf.setAttribute('aria-busy', 'true');
                label.textContent = 'Preparing PDF…';
                icon.className = 'bi bi-arrow-repeat ar-spin';
                fetch(pdf.href, { credentials: 'same-origin' })
                    .then(r => {
                        if (!r.ok || !(r.headers.get('Content-Type') || '').includes('pdf')) throw new Error('not a pdf');
                        const name = ((r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'sales-records.pdf';
                        return r.blob().then(blob => ({ blob, name }));
                    })
                    .then(({ blob, name }) => {
                        const url = URL.createObjectURL(blob);
                        const a = Object.assign(document.createElement('a'), { href: url, download: name });
                        document.body.appendChild(a); a.click(); a.remove();
                        setTimeout(() => URL.revokeObjectURL(url), 10000);
                        document.getElementById('arSavedFile').textContent = name;
                        document.getElementById('arSaved').hidden = false;
                        const badge = document.getElementById('arStep1Badge');
                        badge.classList.add('is-done');
                        badge.textContent = '✓';
                        document.getElementById('arUnsaved').hidden = true;
                    })
                    .catch(() => showToast({ type: 'error', title: "Couldn't save the PDF", message: 'Please try again.' }))
                    .finally(() => {
                        pdf.removeAttribute('aria-busy');
                        label.textContent = 'Save as PDF';
                        icon.className = 'bi bi-download';
                    });
            });
        })();
        </script>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
