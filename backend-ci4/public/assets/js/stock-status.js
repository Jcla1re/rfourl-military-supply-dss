/* public/assets/js/stock-status.js
 *
 * The stock status rule for the browser (live badges in the Add/Edit item
 * modal, size switching in the Inventory table, the sales product cards).
 * It mirrors ProductModel::getStatus() and takes its settings from the
 * server (window.STOCK_RULE, written by the admin/staff layouts from
 * ProductModel::statusRule()), so the browser and the server can't
 * disagree. Never hardcode the thresholds anywhere else.
 *
 *   Out of Stock (gray)  on hand 0 and ROP 0 (no sales in the demand window)
 *   Reorder Now  (red)   ROP > 0 and on hand <= ROP
 *   Low Stock    (amber) ROP > 0 and on hand <= ROP + max(min units, ceil(ROP x pct))
 *   In Stock     (green) anything else (including ROP 0 with stock on hand)
 */
function stockStatus(stock, rop) {
    const rule = window.STOCK_RULE || {};
    stock = Number(stock) || 0;
    rop = Math.max(0, Number(rop) || 0);
    if (stock <= 0 && rop === 0) return ['Out of Stock', 'gray'];
    if (rop === 0) return ['In Stock', 'green'];     // no reorder point to be near
    if (stock <= rop) return ['Reorder Now', 'red'];
    if (stock <= rop + Math.max(Number(rule.lowStockMin), Math.ceil(rop * Number(rule.lowStockPct)))) return ['Low Stock', 'amber'];
    return ['In Stock', 'green'];
}

/** Badge / bar color for a status label (same as ProductModel::statusColor). */
function stockStatusColor(label) {
    return { 'Reorder Now': 'red', 'Low Stock': 'amber', 'Out of Stock': 'gray', 'Archived': 'archived' }[label] || 'green';
}

/** Tooltip for a status badge (same as ProductModel::statusTitle). */
function stockStatusTitle(label) {
    return label === 'Out of Stock' ? ((window.STOCK_RULE || {}).outOfStockTip || '') : '';
}
