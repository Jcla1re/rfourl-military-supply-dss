<?php
// app/Views/partials/_purchase_order_dialog.php
// The one "New purchase order" dialog, used by Inventory ("Order") and Reorder Alerts
// ("Order {EOQ}" and "Order all sizes"). Each size is a line; all lines share one supplier
// (a size is only ordered from its own supplier) and become one stock order. Filled and
// checked by assets/js/purchase-order-dialog.js (PurchaseOrderDialog.open(lines, options));
// each page's Order button carries its size's line from App\Libraries\PurchaseOrderLine.
// Styles: assets/css/item-modal.css (same look as Add item) + purchase-order-dialog.css.
//
// Expects $suppliers: active suppliers (only offered for a size that has no supplier yet).
// Priority isn't asked: StockOrderController::store() decides it for these orders.
//
// The Orders page opens it in "empty start" mode instead (options.mode = 'empty'): a
// Supplier section first, then "+ Add item" with a picker of that supplier's items, a
// ✕ on each line, and (options.showPriority) a Priority control. Those parts stay hidden
// otherwise, so Inventory and Reorder Alerts look and post exactly as before.
?>
<link href="/assets/css/purchase-order-dialog.css" rel="stylesheet">
<div class="inv-modal po-modal" id="poModal" data-po-dialog>
    <div class="inv-modal-card item-modal po-dialog" role="dialog" aria-modal="true" aria-labelledby="poTitle" aria-describedby="poSubtitle">
        <div class="im-head">
            <div class="im-head-text">
                <h2 id="poTitle">New purchase order</h2>
                <div class="im-subtitle" id="poSubtitle">Restock an item from its supplier.</div>
            </div>
            <button type="button" class="im-close" data-po-close aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>

        <form id="poForm" class="im-form" method="post" action="<?= site_url('admin/orders/store') ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="alert_id" id="poAlertId">
            <input type="hidden" name="return_to" id="poReturnTo">
            <input type="hidden" name="return_query" id="poReturnQuery">

            <div class="im-body">
                <?php // Empty start only: the supplier comes first and decides which items can be added. ?>
                <section class="im-section" id="poChooseSection" hidden>
                    <h3 class="im-section-title"><i class="bi bi-truck" aria-hidden="true"></i> Supplier</h3>
                    <div class="im-field">
                        <label for="poSupplierChoose">Supplier</label>
                        <select name="supplier_id" id="poSupplierChoose" aria-describedby="poChooseHelp poChooseWarn" disabled>
                            <option value="">Choose a supplier&hellip;</option>
                            <?php foreach ($suppliers ?? [] as $s): ?>
                                <?php $lead = (int) ($s['lead_time_days'] ?? 0); ?>
                                <option value="<?= esc($s['supplier_id'], 'attr') ?>" data-lead="<?= $lead ?>"><?= esc($s['company_name']) ?><?= $lead ? ' &middot; ' . $lead . '-day lead time' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="im-help" id="poChooseHelp">One order = one supplier. The item list below shows only this supplier's items.</span>
                        <span class="im-help is-warning po-choose-warn" id="poChooseWarn" hidden></span>
                    </div>
                </section>

                <section class="im-section">
                    <h3 class="im-section-title"><i class="bi bi-box" aria-hidden="true"></i> <span id="poItemsTitle">Item</span></h3>
                    <div class="po-lines" id="poLines"></div>
                    <div class="po-empty" id="poEmpty" hidden></div>
                    <div class="po-add" id="poAdd" hidden>
                        <button type="button" class="po-add-btn" id="poAddBtn" aria-expanded="false" aria-controls="poPicker">+ Add item</button>
                        <div class="po-picker" id="poPicker" hidden>
                            <div class="po-picker-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="search" id="poPickerSearch" placeholder="Search this supplier's items&hellip;" aria-label="Search this supplier's items" autocomplete="off">
                            </div>
                            <div class="po-picker-list" id="poPickerList"></div>
                        </div>
                    </div>
                </section>

                <section class="im-section">
                    <h3 class="im-section-title"><i class="bi bi-truck" aria-hidden="true"></i> Order details</h3>
                    <div class="im-grid">
                        <?php // Empty start, with options.showPriority: posted as name="priority" only then. ?>
                        <div class="im-field po-priority-field" id="poPriorityField" hidden>
                            <span class="im-label" id="poPriorityLabel">Priority</span>
                            <div class="po-seg" id="poPrioritySeg" role="radiogroup" aria-labelledby="poPriorityLabel" aria-describedby="poPriorityHelp">
                                <?php foreach (\App\Models\StockOrderModel::PRIORITIES as $value): ?>
                                    <button type="button" role="radio" aria-checked="false" tabindex="-1" data-priority="<?= esc($value, 'attr') ?>"><?= esc(\App\Models\StockOrderModel::priorityLabel($value)) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="poPriority" disabled>
                            <span class="im-help" id="poPriorityHelp"></span>
                        </div>
                        <div class="im-field" id="poSupplierField">
                            <span class="im-label" id="poSupplierLabel">Supplier</span>
                            <div class="im-static im-auto po-supplier-fixed" id="poSupplierFixed"></div>
                            <input type="hidden" name="supplier_id" id="poSupplierId">
                            <select name="supplier_id" id="poSupplierPick" aria-labelledby="poSupplierLabel" aria-describedby="poSupplierHelp poSupplierError" hidden disabled>
                                <option value="">Choose a supplier&hellip;</option>
                                <?php foreach ($suppliers ?? [] as $s): ?>
                                    <option value="<?= esc($s['supplier_id'], 'attr') ?>" data-lead="<?= (int) ($s['lead_time_days'] ?? 0) ?>"><?= esc($s['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="im-help" id="poSupplierHelp">The item's own supplier.</span>
                            <span class="im-field-error" id="poSupplierError"></span>
                        </div>
                        <div class="im-field">
                            <label for="poExpected">Expected delivery</label>
                            <input type="date" name="expected_delivery_date" id="poExpected" aria-describedby="poExpectedHelp">
                            <span class="im-help" id="poExpectedHelp"></span>
                        </div>
                    </div>

                    <div class="po-totals" aria-live="polite">
                        <div id="poTotalRows"></div>
                        <div class="po-total-row is-grand"><span>Order total</span><strong id="poTotal">₱0.00</strong></div>
                    </div>
                    <?php // Shown when a size has nothing on hand; store() decides it from the stock at that moment. ?>
                    <p class="im-help po-urgent-note" id="poUrgentNote" hidden>This order will be marked Urgent for the supplier because an item is out of stock.</p>
                </section>
            </div>

            <div class="im-foot">
                <span class="im-changes" id="poStatus" aria-live="polite">Ready to create.</span>
                <div class="im-foot-actions">
                    <button type="button" class="im-btn-secondary" data-po-close>Cancel</button>
                    <button type="submit" class="btn btn-success im-btn-save" id="poSubmit">Create order</button>
                </div>
            </div>
        </form>
    </div>
</div>

<template id="poLineTemplate">
    <div class="po-line">
        <input type="hidden" name="item_id[]">
        <div class="po-line-head">
            <span class="po-line-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
            <div class="po-line-text">
                <strong class="po-line-name"></strong>
                <span class="po-line-meta"></span>
            </div>
            <span class="status-pill po-line-status"></span>
            <button type="button" class="po-line-remove" data-po-remove data-tooltip="Remove from order" data-tooltip-pos="above" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <p class="po-line-numbers"></p>
        <div class="po-line-row">
            <div class="im-field po-qty-field">
                <label class="po-qty-label">Quantity</label>
                <div class="po-qty">
                    <button type="button" class="po-step" data-step="-1">&minus;</button>
                    <input type="number" name="quantity[]" min="1" step="1" inputmode="numeric">
                    <button type="button" class="po-step" data-step="1">+</button>
                </div>
                <span class="im-field-error po-qty-error"></span>
            </div>
            <div class="im-field po-cost-field">
                <label class="po-cost-label">Unit cost</label>
                <div class="im-prefix"><span>₱</span><input type="number" name="unit_price[]" min="0.01" step="0.01" inputmode="decimal"></div>
                <span class="im-field-error po-cost-error"></span>
            </div>
            <div class="im-field po-line-total-box">
                <span class="im-label">Line total</span>
                <strong class="po-line-total"></strong>
            </div>
        </div>
        <span class="im-help po-after"></span>
    </div>
</template>
<script src="/assets/js/purchase-order-dialog.js" defer></script>
