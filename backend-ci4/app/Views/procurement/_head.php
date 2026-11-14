<?php
/**
 * Shared top of the three procurement PDFs (styles, company header, document title and number).
 * Expects: $title, $number, $meta (list of [label, value] shown under the number).
 */
?>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1c1c; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    .sub { color: #555; margin-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; }
    table.items th, table.items td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
    table.items th { background: #3d5230; color: #fff; }
    table.items tbody tr:nth-child(even) td { background: #f7f6f2; }
    .num { text-align: right; }
    .ctr { text-align: center; }
    .head td { vertical-align: top; border: 0; padding: 0; }
    .doc-title { text-align: right; }
    .doc-title .t { font-size: 18px; font-weight: bold; color: #3d5230; letter-spacing: 1px; }
    .doc-title .n { font-size: 13px; font-weight: bold; margin-top: 2px; }
    .rule { border-top: 2px solid #3d5230; margin: 10px 0 12px; }
    .box { border: 1px solid #ccc; padding: 8px 10px; }
    .box .lbl { color: #666; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; }
    .gap td { padding: 0 6px 0 0; border: 0; }
    .kv { table-layout: fixed; }
    .kv td { border: 0; padding: 2px 0; }
    /* One label/value pair per row: a narrow label column and a wide value column, so numbers and names stay on one line. */
    .kv td.k { color: #555; width: 30%; white-space: nowrap; }
    .kv td.v { width: 70%; white-space: nowrap; }
    .totals { margin-top: 12px; text-align: right; font-size: 13px; font-weight: bold; }
    .sign { margin-top: 38px; }
    .sign td { border: 0; padding: 0 12px 0 0; vertical-align: bottom; }
    .sign .line { border-top: 1px solid #1c1c1c; padding-top: 3px; color: #555; font-size: 10px; }
    .foot { margin-top: 26px; color: #777; font-size: 9px; }
</style>

<table class="head">
    <tr>
        <td>
            <h1>RFOURL MILITARY SUPPLY</h1>
            <div class="sub">#5 3rd Ave., Bagong Lipunan ng Crame, 1111 Quezon City NCR, 2nd Dist., Phils.</div>
            <div class="sub">RICHELLE D. P. PARAS - Prop. &middot; Non-VAT Reg. TIN: 335-802-288-00000</div>
        </td>
        <td class="doc-title" style="width: 42%;">
            <div class="t"><?= esc(strtoupper($title)) ?></div>
            <div class="n"><?= esc($number) ?></div>
            <?php foreach ($meta ?? [] as [$label, $value]): ?>
                <div class="sub"><?= esc($label) ?>: <?= esc($value ?: '—') ?></div>
            <?php endforeach; ?>
        </td>
    </tr>
</table>
<div class="rule"></div>
