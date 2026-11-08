/* public/assets/js/purchase-order-dialog.js
 *
 * The shared "New purchase order" dialog (app/Views/partials/_purchase_order_dialog.php),
 * opened by Inventory's "Order" and Reorder Alerts' "Order {EOQ}" / "Order all sizes":
 *
 *   PurchaseOrderDialog.open(lines, { alertId, returnTo, returnQuery, opener })
 *
 * lines: one per size, from App\Libraries\PurchaseOrderLine (all from one supplier).
 * Each line: quantity (− / + or typed, min 1, starts at the EOQ) and unit cost (starts at
 * the item's saved cost; what's typed here only goes on the order line). Errors show under
 * the field and in the footer; Create order stays disabled until there are none and can't
 * be clicked twice. Esc or Cancel closes and returns focus to the button that opened it.
 */
(function () {
    const modal = document.getElementById('poModal');
    if (!modal) return;

    const $ = id => document.getElementById(id);
    const form = $('poForm'), linesBox = $('poLines'), tpl = $('poLineTemplate');
    const fixed = $('poSupplierFixed'), supId = $('poSupplierId'), pick = $('poSupplierPick');
    const supHelp = $('poSupplierHelp'), supError = $('poSupplierError');
    const expected = $('poExpected'), expectedHelp = $('poExpectedHelp');
    const submit = $('poSubmit'), status = $('poStatus');
    const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const localDate = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    let lines = [], opener = null, submitting = false, uid = 0;

    // Expected delivery: today + the supplier's lead time (the longest among the lines).
    function setLead(days) {
        const today = new Date();
        expected.min = localDate(today);
        expected.value = days ? localDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() + days)) : '';
        expectedHelp.textContent = days ? `Based on this supplier's ${days}-day lead time.` : '';
    }

    const qtyOf = el => (/^\d+$/.test(el.value.trim()) ? parseInt(el.value, 10) : NaN);
    const costOf = el => (el.value.trim() === '' ? NaN : Number(el.value));

    // Re-checks every line: errors, line totals, "After delivery", the totals box and the footer.
    function refresh() {
        let errors = 0, total = 0;
        const rows = [];
        linesBox.querySelectorAll('.po-line').forEach((el, i) => {
            const l = lines[i];
            const qIn = el.querySelector('input[name="quantity[]"]'), cIn = el.querySelector('input[name="unit_price[]"]');
            const q = qtyOf(qIn), c = costOf(cIn);
            const qBad = !(q >= 1), cBad = !(c > 0);
            errors += qBad + cBad;
            qIn.classList.toggle('is-invalid', qBad); qIn.setAttribute('aria-invalid', qBad);
            cIn.classList.toggle('is-invalid', cBad); cIn.setAttribute('aria-invalid', cBad);
            el.querySelector('.po-qty-error').textContent = qBad ? 'Enter a quantity of 1 or more.' : '';
            el.querySelector('.po-cost-error').textContent = cBad ? 'Enter a unit cost above ₱0.' : '';
            el.querySelector('[data-step="-1"]').disabled = !(q > 1);
            const sub = qBad || cBad ? 0 : q * c;
            total += sub;
            el.querySelector('.po-line-total').textContent = qBad || cBad ? '—' : peso(sub);
            el.querySelector('.po-after').textContent = `After delivery: ${l.on_hand + (qBad ? 0 : q)} on hand`;
            rows.push([`${l.size ? 'Size ' + l.size : l.name} · ${qBad ? '—' : q} × ${cBad ? '—' : peso(c)}`, qBad || cBad ? '—' : peso(sub)]);
        });
        const needsSupplier = !pick.disabled && pick.value === '';
        errors += needsSupplier;
        supError.textContent = needsSupplier ? 'Choose a supplier for this order.' : '';
        pick.setAttribute('aria-invalid', needsSupplier);

        const totalRows = $('poTotalRows');
        totalRows.replaceChildren(...rows.map(([label, amount]) => {
            const row = document.createElement('div');
            row.className = 'po-total-row';
            row.append(Object.assign(document.createElement('span'), { textContent: label }), Object.assign(document.createElement('span'), { textContent: amount }));
            return row;
        }));
        $('poTotal').textContent = peso(total);
        status.textContent = errors ? `Fix ${errors} error${errors === 1 ? '' : 's'} to create.` : 'Ready to create.';
        status.classList.toggle('is-error', errors > 0);
        submit.disabled = errors > 0 || submitting;
        return errors;
    }

    function lineElement(l) {
        const el = tpl.content.firstElementChild.cloneNode(true);
        const n = ++uid;
        el.querySelector('input[name="item_id[]"]').value = l.item_id;
        el.querySelector('.po-line-name').textContent = l.name;
        el.querySelector('.po-line-meta').textContent = [`ID ${l.item_id}`, l.category, l.size ? null : 'No size'].filter(Boolean).join(' · ');
        const pill = el.querySelector('.po-line-status');
        pill.textContent = l.status;
        pill.classList.add(l.status_class);
        el.querySelector('.po-line-numbers').textContent = [
            `${l.on_hand} on hand`, `Reorder at ${l.rop}`, `Safety stock ${l.safety ?? '—'}`, `Suggested ${l.eoq ?? '—'} (EOQ)`,
        ].join(' · ');
        const q = el.querySelector('input[name="quantity[]"]'), c = el.querySelector('input[name="unit_price[]"]');
        q.id = `poQty${n}`; c.id = `poCost${n}`;
        el.querySelector('.po-qty-label').htmlFor = q.id;
        el.querySelector('.po-cost-label').htmlFor = c.id;
        q.value = l.eoq || 1;
        c.value = l.cost > 0 ? l.cost.toFixed(2) : '';
        q.setAttribute('aria-describedby', (el.querySelector('.po-qty-error').id = `poQtyErr${n}`));
        c.setAttribute('aria-describedby', (el.querySelector('.po-cost-error').id = `poCostErr${n}`));
        el.querySelector('[data-step="-1"]').setAttribute('aria-label', `One less: ${l.name}`);
        el.querySelector('[data-step="1"]').setAttribute('aria-label', `One more: ${l.name}`);
        return el;
    }

    function open(newLines, options = {}) {
        lines = newLines;
        opener = options.opener || document.activeElement;
        submitting = false;
        const multi = lines.length > 1;
        $('poSubtitle').textContent = multi ? `Restock ${lines.length} sizes from the same supplier in one order.` : 'Restock an item from its supplier.';
        $('poItemsTitle').textContent = multi ? `Items · ${lines.length} sizes` : 'Item';
        linesBox.replaceChildren(...lines.map(lineElement));

        // The item's own supplier (read-only); a dropdown only when the item has none yet.
        const first = lines[0];
        const own = first.supplier_id !== '';
        fixed.hidden = !own;
        fixed.textContent = own ? first.supplier : '';
        supId.disabled = !own;
        supId.value = first.supplier_id;
        pick.hidden = own;
        pick.disabled = own;
        pick.value = '';
        supHelp.textContent = own ? "The item's own supplier." : 'This item has no supplier yet. Choose one for this order.';
        supHelp.classList.toggle('is-warning', !own);
        setLead(Math.max(0, ...lines.map(l => l.lead || 0)));
        // The server marks the order Urgent when a size has nothing on hand; say so up front.
        $('poUrgentNote').hidden = !lines.some(l => l.on_hand <= 0);

        $('poAlertId').value = options.alertId || '';
        $('poReturnTo').value = options.returnTo || '';
        $('poReturnQuery').value = options.returnQuery || '';
        refresh();
        modal.classList.add('show');
        linesBox.querySelector('input[name="quantity[]"]').focus();
    }

    function close() {
        if (!modal.classList.contains('show')) return;
        modal.classList.remove('show');
        if (opener && document.contains(opener)) opener.focus();
    }

    linesBox.addEventListener('click', e => {
        const step = e.target.closest('.po-step');
        if (!step) return;
        const input = step.closest('.po-qty').querySelector('input');
        input.value = Math.max(1, (qtyOf(input) || 0) + Number(step.dataset.step));
        refresh();
    });
    linesBox.addEventListener('input', refresh);
    pick.addEventListener('change', () => {
        setLead(Number(pick.selectedOptions[0]?.dataset.lead) || 0);
        refresh();
    });
    modal.querySelectorAll('[data-po-close]').forEach(b => b.addEventListener('click', close));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.classList.contains('show')) { e.stopPropagation(); close(); }
    });
    form.addEventListener('submit', e => {
        if (submitting || refresh() > 0) { e.preventDefault(); return; }
        submitting = true; // one order per click
        submit.disabled = true;
    });

    window.PurchaseOrderDialog = { open, close };
})();
