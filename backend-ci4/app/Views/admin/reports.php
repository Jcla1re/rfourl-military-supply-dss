<?php
/**
 * Owner > Reports. Variables from ReportsController::index():
 * @var string $type    sales | purchases | summary
 * @var string $from    Y-m-d
 * @var string $to      Y-m-d
 * @var array  $report  the report's data (App\Libraries\AccountingReports)
 */
use App\Libraries\AccountingReports;

$peso    = static fn (float $n): string => '₱' . number_format($n, 2);
$params  = static fn (string $t): string => http_build_query(['type' => $t, 'from' => $from, 'to' => $to]);
$current = http_build_query(['type' => $type, 'from' => $from, 'to' => $to]);
$periodText = date('M j, Y', strtotime($from)) . ($from === $to ? '' : ' – ' . date('M j, Y', strtotime($to)));
?>
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>
<style>
.rp-tabs { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid #dcdad2; flex-wrap: wrap; }
.rp-tab { padding: 10px 16px; font-size: 14px; font-weight: 600; color: #5C6152; text-decoration: none; border-bottom: 3px solid transparent; margin-bottom: -1px; border-radius: 8px 8px 0 0; }
.rp-tab:hover { color: #1F2617; background: #F2F0EA; }
.rp-tab:focus-visible { outline: 2px solid #4b6a3f; outline-offset: 2px; }
.rp-tab.active { color: #2F5A27; border-bottom-color: #4b6a3f; }
.rp-filter { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-bottom: 16px; }
.rp-filter label { display: block; font-size: 12px; font-weight: 600; color: #5C6152; margin-bottom: 4px; }
.rp-filter input[type=date] { padding: 8px 10px; border: 1px solid #ccc; border-radius: var(--radius-control); }
/* Every button in the filter row is 40px tall and never shrinks; the Export pair sits at the
   right end and wraps to its own line when the row runs out of room. */
.rp-filter .btn { display: inline-flex; align-items: center; gap: 7px; height: 40px; white-space: nowrap; flex-shrink: 0; }
.rp-exports { display: flex; gap: 10px; flex-wrap: wrap; margin-left: auto; }
/* Secondary button (This month, Export PDF/CSV): same resting look as the Procurement Report's
   Export buttons. Reports-only; .btn-outline-secondary still supplies the shared hover and focus. */
.btn.rp-btn-secondary { padding: 0 16px; border: 1px solid #D6D2C6; border-radius: 10px; background: #fff; color: #1F2617; font-size: 14px; font-weight: 500; }
.rp-period { color: #5C6152; font-size: 14px; margin: 0 0 12px; }
.rp-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.rp-table tr.rp-sub td { background: #F7F6F2; font-weight: 600; }
.rp-table tr.rp-total td { background: #E4EFDF; font-weight: 800; }
.rp-muted { color: #8a8d7e; }
/* Period Summary (screen only; the PDF and CSV have their own layout). */
.rp-period.rp-ps-period { margin-bottom: 0; }
.rp-ps-sub { font-size: 13px; font-weight: 400; color: #5C6152; margin: 3px 0 0; }
.rp-ps-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; margin-top: 16px; }
.rp-ps-col { display: flex; flex-direction: column; gap: 20px; }
.rp-ps-section { border: 1px solid #E3E0D7; border-radius: 12px; padding: 14px 18px 6px; background: #fff; }
.rp-ps-heading { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #5C6152; margin: 0; }
.rp-ps-heading.rp-ps-subhead { margin-top: 16px; }
.rp-ps-row { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 10px 0; border-bottom: 1px solid #F0EDE6; font-size: inherit; }
.rp-ps-row.rp-ps-last { border-bottom: 0; }
.rp-ps-row.rp-ps-indent { padding-left: 14px; }
.rp-ps-label { font-weight: 400; color: #3A4030; }
.rp-ps-value { font-weight: 500; color: #1F2617; font-variant-numeric: tabular-nums; white-space: nowrap; }
.rp-ps-row.rp-ps-total { border-top: 1px solid #D6D2C6; border-bottom: 0; }
.rp-ps-row.rp-ps-total .rp-ps-label,
.rp-ps-row.rp-ps-total .rp-ps-value,
.rp-ps-row.rp-ps-total-value .rp-ps-value { font-weight: 600; color: #1F2617; }
@media (max-width: 899.98px) { .rp-ps-grid { grid-template-columns: 1fr; } }
.rp-dup { display: inline-block; margin-left: 4px; padding: 1px 6px; border-radius: 999px; background: #FDE8E8; color: #8A1C1C; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; vertical-align: middle; }
</style>

<div class="page-wrap">
    <nav class="rp-tabs" aria-label="Report type">
        <?php foreach (AccountingReports::TYPES as $key => $label): ?>
            <a class="rp-tab<?= $type === $key ? ' active' : '' ?>" href="<?= site_url('admin/reports') . '?' . $params($key) ?>"<?= $type === $key ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <form method="get" action="<?= site_url('admin/reports') ?>" class="rp-filter" id="rpFilter">
        <input type="hidden" name="type" value="<?= esc($type, 'attr') ?>">
        <div><label for="rpFrom">From</label><input type="date" id="rpFrom" name="from" value="<?= esc($from, 'attr') ?>" required></div>
        <div><label for="rpTo">To</label><input type="date" id="rpTo" name="to" value="<?= esc($to, 'attr') ?>" required></div>
        <button type="submit" class="btn btn-success">Show</button>
        <a class="btn btn-outline-secondary rp-btn-secondary" href="<?= site_url('admin/reports') . '?type=' . esc($type, 'attr') ?>">This month</a>
        <div class="rp-exports">
            <a class="btn btn-outline-secondary rp-btn-secondary" href="<?= site_url('admin/reports/pdf') . '?' . $current ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Export PDF</a>
            <a class="btn btn-outline-secondary rp-btn-secondary" href="<?= site_url('admin/reports/csv') . '?' . $current ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i> Export CSV</a>
        </div>
    </form>

    <div class="page-panel">
        <p class="rp-period<?= $type === 'summary' ? ' rp-ps-period' : '' ?>"><strong><?= esc(AccountingReports::TYPES[$type]) ?></strong> &middot; <?= esc($periodText) ?></p>

        <?php if ($type === 'sales'): ?>
            <?php $days = array_column($report['days'], null, 'date'); $rows = $report['rows']; ?>
            <div class="data-table-wrap">
                <table class="data-table rp-table" id="salesJournal">
                    <thead>
                        <tr><th>Receipt No.</th><th>OR No.</th><th>Date &amp; Time</th><th>Cashier</th><th class="num">Gross</th><th class="num">Discount</th><th class="num">Net</th><th class="num">Cash</th><th class="num">GCash</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="9" class="text-center py-4 rp-muted">No sales in this period.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $i => $r): ?>
                            <?php $strike = $r['voided'] !== null ? ' style="text-decoration:line-through;color:#8a8a8a"' : ''; ?>
                            <tr class="rp-row<?= $r['voided'] !== null ? ' rp-voided' : '' ?>">
                                <td>#<?= esc($r['receipt_no']) ?><?= view('partials/_void_label', ['voidLabel' => $r['voided']]) ?></td>
                                <td><?= $r['or_number'] !== null ? esc($r['or_number']) : '—' ?><?php if (! empty($r['duplicate'])): ?> <span class="rp-dup" title="This OR number is on more than one receipt">duplicate</span><?php endif; ?></td>
                                <td><?= esc(date('M j, Y', strtotime($r['date']))) ?> <?= esc($r['time']) ?></td>
                                <td><?= esc($r['cashier'] !== '' ? $r['cashier'] : '—') ?></td>
                                <td class="num"<?= $strike ?>><?= $peso($r['gross']) ?></td>
                                <td class="num"<?= $strike ?>><?= $peso($r['discount']) ?></td>
                                <td class="num"<?= $strike ?>><?= $peso($r['net']) ?></td>
                                <td class="num"<?= $strike ?>><?= $peso($r['cash']) ?></td>
                                <td class="num"<?= $strike ?>><?= $peso($r['gcash']) ?></td>
                            </tr>
                            <?php if (! isset($rows[$i + 1]) || $rows[$i + 1]['date'] !== $r['date']): ?>
                                <?php $d = $days[$r['date']]; ?>
                                <tr class="rp-sub" data-day="<?= esc($r['date'], 'attr') ?>">
                                    <td colspan="4">Subtotal &middot; <?= esc(date('M j, Y', strtotime($r['date']))) ?> (<?= (int) $d['count'] ?> receipt<?= $d['count'] === 1 ? '' : 's' ?>)</td>
                                    <td class="num"><?= $peso($d['gross']) ?></td><td class="num"><?= $peso($d['discount']) ?></td><td class="num"><?= $peso($d['net']) ?></td><td class="num"><?= $peso($d['cash']) ?></td><td class="num"><?= $peso($d['gcash']) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php $t = $report['total']; ?>
                        <tr class="rp-total" id="salesTotal">
                            <td colspan="4">Period total (<?= (int) $t['count'] ?> receipt<?= $t['count'] === 1 ? '' : 's' ?>)</td>
                            <td class="num"><?= $peso($t['gross']) ?></td><td class="num"><?= $peso($t['discount']) ?></td><td class="num"><?= $peso($t['net']) ?></td><td class="num"><?= $peso($t['cash']) ?></td><td class="num"><?= $peso($t['gcash']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        <?php elseif ($type === 'purchases'): ?>
            <div class="data-table-wrap">
                <table class="data-table rp-table" id="purchasesJournal">
                    <thead>
                        <tr><th>PO No.</th><th>DR No.</th><th>Supplier</th><th>Date Received</th><th class="num">Items</th><th class="num">Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($report['rows'] === []): ?>
                            <tr><td colspan="6" class="text-center py-4 rp-muted">No orders were received in this period.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($report['rows'] as $r): ?>
                            <tr class="rp-row">
                                <td><?= esc($r['po_number'] ?? '—') ?></td>
                                <td><?= esc($r['dr_number'] ?? '—') ?></td>
                                <td><?= esc($r['supplier'] !== '' ? $r['supplier'] : '—') ?></td>
                                <td><?= esc(date('M j, Y', strtotime($r['received']))) ?></td>
                                <td class="num"><?= (int) $r['items'] ?></td>
                                <td class="num"><?= $peso($r['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="rp-total" id="purchasesTotal">
                            <td colspan="4">Period total (<?= (int) $report['total']['count'] ?> order<?= $report['total']['count'] === 1 ? '' : 's' ?>)</td>
                            <td class="num"></td>
                            <td class="num"><?= $peso($report['total']['amount']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <?php
            // One row: [label, value, extra classes]. 'rp-ps-last' drops the line under a group's last row.
            $psRow = static fn (string $label, string $value, string $cls = ''): string =>
                '<div class="rp-ps-row' . ($cls !== '' ? ' ' . $cls : '') . '"><span class="rp-ps-label">' . esc($label)
                . '</span><span class="rp-ps-value">' . esc($value) . '</span></div>';
            ?>
            <p class="rp-ps-sub"><?= esc(AccountingReports::SUMMARY_LABEL) ?></p>
            <div class="rp-ps-grid" id="periodSummary">
                <section class="rp-ps-section" aria-labelledby="rpPsSales">
                    <h2 class="rp-ps-heading" id="rpPsSales">Sales</h2>
                    <?= $psRow(AccountingReports::SUMMARY_LINES['sales'], $peso($report['sales'])) ?>
                    <?= $psRow(AccountingReports::SUMMARY_LINES['discounts'], $peso($report['discounts'])) ?>
                    <?= $psRow(AccountingReports::SUMMARY_LINES['net_sales'], $peso($report['net_sales']), 'rp-ps-total') ?>
                    <h3 class="rp-ps-heading rp-ps-subhead">Net sales received as</h3>
                    <?= $psRow(AccountingReports::SUMMARY_LINES['cash'], $peso($report['cash']), 'rp-ps-indent') ?>
                    <?= $psRow(AccountingReports::SUMMARY_LINES['gcash'], $peso($report['gcash']), 'rp-ps-indent rp-ps-last') ?>
                </section>
                <div class="rp-ps-col">
                    <section class="rp-ps-section" aria-labelledby="rpPsPurchases">
                        <h2 class="rp-ps-heading" id="rpPsPurchases">Purchases</h2>
                        <?= $psRow(AccountingReports::SUMMARY_LINES['purchases'], $peso($report['purchases']), 'rp-ps-last rp-ps-total-value') ?>
                    </section>
                    <section class="rp-ps-section" aria-labelledby="rpPsCounts">
                        <h2 class="rp-ps-heading" id="rpPsCounts">Counts</h2>
                        <?= $psRow(AccountingReports::SUMMARY_LINES['receipts'], number_format($report['receipts'])) ?>
                        <?= $psRow(AccountingReports::SUMMARY_LINES['orders'], number_format($report['orders']), 'rp-ps-last') ?>
                    </section>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
