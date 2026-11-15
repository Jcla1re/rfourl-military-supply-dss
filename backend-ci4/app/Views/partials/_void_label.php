<?php
/**
 * "Voided duplicate of #1737" next to a voided receipt, on every list that shows receipts (POS
 * receipts tab, day PDF, Reports page and PDF, Old Sales Records). Inline style so it looks the
 * same in the Dompdf PDFs. Always pass it: view('partials/_void_label', ['voidLabel' => ...]),
 * the text from SalesTransactionModel::voidLabel() (null for a normal sale shows nothing).
 *
 * @var string|null $voidLabel
 */
if (($voidLabel ?? null) === null || $voidLabel === '') {
    return;
}
?>
<span class="void-label" style="display:inline-block;margin-left:4px;padding:1px 6px;border-radius:999px;background:#ECEAE4;color:#5A4A1C;font-size:9px;font-weight:700;letter-spacing:.02em;white-space:nowrap;vertical-align:middle;"><?= esc($voidLabel) ?></span>
