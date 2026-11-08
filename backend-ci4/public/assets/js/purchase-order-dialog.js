/* public/assets/js/purchase-order-dialog.js
 *
 * The shared "New purchase order" dialog (app/Views/partials/_purchase_order_dialog.php).
 *
 *   PurchaseOrderDialog.open(lines, { alertId, returnTo, returnQuery, opener })
 *     Inventory's "Order" and Reorder Alerts' "Order {EOQ}" / "Order all sizes": pre-filled.
 *     lines: one per size, from App\Libraries\PurchaseOrderLine (all from one supplier).
 *     No Priority field: the server decides it (StockOrderController::dialogPriority()).
 *
 *   PurchaseOrderDialog.open(lines, { mode: 'empty', showPriority: true, returnTo: '/admin/orders',
 *                                     catalog, supplierId, priority, warning })
 *     The Orders page's "Add New Order": empty start. The owner chooses a supplier, then adds
 *     that supplier's items from catalog (supplier_id => lines, built the same way). lines may
 *     carry a cancelled order's items to reorder (with qty and price); priority is then taken
 *     as the owner's choice. Priority starts on the automatic rule (Urgent when an added item
 *     has 0 on hand) and follows it until the owner picks one.
 *
 * Each line: quantity (− / + or typed, min 1, starts at the EOQ) and unit cost (starts at
 * the item's saved cost; what's typed here only goes on the order line). Errors show under
 * the field and in the footer; Create order stays disabled until the order is ready and
 * can't be clicked twice. Esc or Cancel closes and returns focus to the button that opened it.
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
    // Empty start (Orders page)
    const chooseSection = $('poChooseSection'), choose = $('poSupplierChoose'), chooseWarn = $('poChooseWarn');
    const emptyBox = $('poEmpty'), addBox = $('poAdd'), addBtn = $('poAddBtn');
    const picker = $('poPicker'), pickerSearch = $('poPickerSearch'), pickerList = $('poPickerList');
    const prioField = $('poPriorityField'), prioInput = $('poPriority'), prioHelp = $('poPriorityHelp');
    const prioButtons = [...$('poPrioritySeg').querySelectorAll('[data-priority]')];
    const prioLabel = v => prioButtons.find(b => b.dataset.priority === v)?.textContent || v;

    const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const localDate = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    let lines = [], opener = null, submitting = false, uid = 0;
    let empty = false, showPriority = false, catalog = {}, priorityChoice = null;

    // Expected delivery: today + the supplier's lead time.
    function setLead(days) {
        const today = new Date();
        expected.min = localDate(today);
        expected.value = days ? localDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() + days)) : '';
        expectedHelp.textContent = days ? `Based on this supplier's ${days}-day lead time.`
            : (empty && !choose.value ? 'Choose a supplier to get a suggested date.' : '');
    }
    const chosenLead = () => Number(choose.selectedOptions[0]?.dataset.lead) || 0;

    const qtyOf = el => (/^\d+$/.test(el.value.trim()) ? parseInt(el.value, 10) : NaN);
    const costOf = el => (el.value.trim() === '' ? NaN : Number(el.value));

    // The supplier's items not yet on the order.
    const available = () => (catalog[choose.value] || []).filter(c => !lines.some(l => l.item_id === c.item_id));

    // "Urgent" when an added item has nothing on hand, otherwise "Order" (shown as Normal).
    function outOfStockText() {
        const out = lines.filter(l => l.on_hand <= 0).map(l => l.name);
        if (!out.length) return '';
        if (out.length === 1) return `${out[0]} is`;
        if (out.length === 2) return `${out[0]} and ${out[1]} are`;
        return `${out[0]} and ${out.length - 1} more are`;
    }

    function refreshPriority() {
        const suggested = lines.some(l => l.on_hand <= 0) ? 'Urgent' : 'Order';
        const value = priorityChoice || suggested;
        prioButtons.forEach(b => {
            const on = b.dataset.priority === value;
            b.setAttribute('aria-checked', on);
            b.tabIndex = on ? 0 : -1;
        });
        prioInput.value = value;
        if (priorityChoice === null) {
            prioHelp.textContent = suggested === 'Urgent'
                ? `Urgent because ${outOfStockText()} out of stock. You can change it.`
                : 'Normal by default. Urgent is suggested when an item is out of stock.';
        } else {
            prioHelp.textContent = priorityChoice === suggested ? 'Matches the automatic suggestion.' : `Set by you. Suggested: ${prioLabel(suggested)}.`;
        }
    }

    // Empty start: the items title, the empty box and the "+ Add item" button.
    function refreshItemsArea() {
        $('poItemsTitle').textContent = lines.length ? `Items · ${lines.length}` : 'Items';
        const hasSupplier = choose.value !== '';
        const all = catalog[choose.value] || [];
        emptyBox.hidden = lines.length > 0;
        emptyBox.textContent = !hasSupplier ? 'Choose a supplier first. Then you can add their items.'
            : (all.length ? 'No items yet. Click "+ Add item" to choose from this supplier\'s items.' : 'This supplier has no active items yet.');
        const left = available().length;
        addBtn.disabled = !hasSupplier || left === 0;
        addBtn.textContent = !hasSupplier ? 'Add item (choose a supplier first)'
            : (all.length && !left ? "All of this supplier's items are added" : '+ Add item');
        if (addBtn.disabled) closePicker(false);
        else if (!picker.hidden) renderPicker();
    }

    // Re-checks every line: errors, line totals, "After delivery", the totals box and the footer.
    // Returns how many things stop the order from being created.
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
            // Pre-filled lines are sizes of one item; an empty-start order can mix items.
            const label = empty ? l.name : (l.size ? 'Size ' + l.size : l.name);
            rows.push([`${label} · ${qBad ? '—' : q} × ${cBad ? '—' : peso(c)}`, qBad || cBad ? '—' : peso(sub)]);
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

        let blocked = errors, message;
        if (empty && !choose.value) { blocked += 1; message = 'Choose a supplier to start.'; }
        else if (empty && !lines.length) { blocked += 1; message = 'Add at least 1 item.'; }
        else message = errors ? `Fix ${errors} error${errors === 1 ? '' : 's'} to create.` : 'Ready to create.';
        status.textContent = message;
        status.classList.toggle('is-error', errors > 0);
        submit.disabled = blocked > 0 || submitting;

        if (empty) {
            refreshItemsArea();
            if (showPriority) refreshPriority();
        }
        return blocked;
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
        // A reorder keeps the cancelled order's quantity and unit price.
        q.value = l.qty ?? (l.eoq || 1);
        const cost = l.price ?? l.cost;
        c.value = cost > 0 ? Number(cost).toFixed(2) : '';
        q.setAttribute('aria-describedby', (el.querySelector('.po-qty-error').id = `poQtyErr${n}`));
        c.setAttribute('aria-describedby', (el.querySelector('.po-cost-error').id = `poCostErr${n}`));
        el.querySelector('[data-step="-1"]').setAttribute('aria-label', `One less: ${l.name}`);
        el.querySelector('[data-step="1"]').setAttribute('aria-label', `One more: ${l.name}`);
        const remove = el.querySelector('[data-po-remove]');
        remove.hidden = !empty;
        remove.setAttribute('aria-label', `Remove from order: ${l.name}`);
        return el;
    }

    // ---- The item picker (empty start) ----

    function pickOption(l) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'po-pick';
        b.dataset.itemId = l.item_id;
        const text = Object.assign(document.createElement('span'), { className: 'po-pick-text' });
        text.append(
            Object.assign(document.createElement('strong'), { className: 'po-pick-name', textContent: l.name }),
            Object.assign(document.createElement('span'), { className: 'po-pick-meta', textContent: [`ID ${l.item_id}`, l.category, `${l.on_hand} on hand`].filter(Boolean).join(' · ') }),
        );
        const pill = Object.assign(document.createElement('span'), { className: `status-pill ${l.status_class}`, textContent: l.status });
        b.append(text, pill);
        return b;
    }

    function renderPicker() {
        const term = pickerSearch.value.trim().toLowerCase();
        const items = available().filter(l => !term || l.name.toLowerCase().includes(term) || l.item_id.toLowerCase().includes(term));
        const needs = items.filter(l => l.status === 'Out of Stock' || l.status === 'Reorder Now').sort((a, b) => a.on_hand - b.on_hand);
        const rest = items.filter(l => !needs.includes(l));
        const nodes = [];
        [['Needs ordering', needs], ['In stock', rest]].forEach(([title, group]) => {
            if (!group.length) return;
            nodes.push(Object.assign(document.createElement('div'), { className: 'po-pick-group', textContent: title }));
            group.forEach(l => nodes.push(pickOption(l)));
        });
        if (!nodes.length) nodes.push(Object.assign(document.createElement('p'), { className: 'po-pick-none', textContent: `No items match "${pickerSearch.value.trim()}".` }));
        pickerList.replaceChildren(...nodes);
    }

    function openPicker() {
        picker.hidden = false;
        addBtn.setAttribute('aria-expanded', 'true');
        pickerSearch.value = '';
        renderPicker();
        pickerSearch.focus();
    }

    function closePicker(focusButton = true) {
        if (picker.hidden) return;
        picker.hidden = true;
        addBtn.setAttribute('aria-expanded', 'false');
        if (focusButton && !addBtn.disabled) addBtn.focus();
    }

    function addLine(l) {
        lines.push(l);
        const el = lineElement(l);
        linesBox.append(el);
        closePicker(false);
        refresh();
        el.querySelector('input[name="quantity[]"]').focus();
    }

    // ---- Open / close ----

    function open(newLines, options = {}) {
        empty = options.mode === 'empty';
        showPriority = empty && !!options.showPriority;
        catalog = empty ? (options.catalog || {}) : {};
        lines = (newLines || []).slice();
        opener = options.opener || document.activeElement;
        submitting = false;

        chooseSection.hidden = !empty;
        choose.disabled = !empty;
        addBox.hidden = !empty;
        $('poSupplierField').hidden = empty;
        prioField.hidden = !showPriority;
        prioInput.disabled = !showPriority;
        if (showPriority) prioInput.name = 'priority'; else prioInput.removeAttribute('name');
        closePicker(false);

        if (empty) {
            $('poSubtitle').textContent = 'Choose a supplier, then add their items.';
            choose.value = options.supplierId || '';
            if (choose.selectedIndex < 0) choose.value = '';
            chooseWarn.textContent = options.warning || '';
            chooseWarn.hidden = !options.warning;
            supId.disabled = true;
            pick.hidden = true;
            pick.disabled = true;
            priorityChoice = options.priority || null; // a reorder keeps the cancelled order's priority
            setLead(chosenLead());
            $('poUrgentNote').hidden = true; // the Priority control says it instead
        } else {
            const multi = lines.length > 1;
            $('poSubtitle').textContent = multi ? `Restock ${lines.length} sizes from the same supplier in one order.` : 'Restock an item from its supplier.';
            $('poItemsTitle').textContent = multi ? `Items · ${lines.length} sizes` : 'Item';

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
        }
        linesBox.replaceChildren(...lines.map(lineElement));

        $('poAlertId').value = options.alertId || '';
        $('poReturnTo').value = options.returnTo || '';
        $('poReturnQuery').value = options.returnQuery || '';
        refresh();
        modal.classList.add('show');
        (linesBox.querySelector('input[name="quantity[]"]') || choose).focus();
    }

    function close() {
        if (!modal.classList.contains('show')) return;
        modal.classList.remove('show');
        if (opener && document.contains(opener)) opener.focus();
    }

    // ---- Events ----

    linesBox.addEventListener('click', e => {
        const remove = e.target.closest('[data-po-remove]');
        if (remove) {
            const el = remove.closest('.po-line');
            lines.splice([...linesBox.children].indexOf(el), 1);
            el.remove();
            refresh();
            (addBtn.disabled ? choose : addBtn).focus();
            return;
        }
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

    // Another supplier: its items replace the old supplier's.
    choose.addEventListener('change', () => {
        const n = lines.length;
        lines = [];
        linesBox.replaceChildren();
        chooseWarn.textContent = n ? `Supplier changed, so the ${n} item${n === 1 ? ' was' : 's were'} removed. Add this supplier's items below.` : '';
        chooseWarn.hidden = !n;
        closePicker(false);
        setLead(chosenLead());
        refresh();
    });
    addBtn.addEventListener('click', () => (picker.hidden ? openPicker() : closePicker()));
    pickerSearch.addEventListener('input', renderPicker);
    pickerSearch.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault(); // Enter here adds the first match instead of submitting the order
        pickerList.querySelector('.po-pick')?.click();
    });
    pickerList.addEventListener('click', e => {
        const b = e.target.closest('.po-pick');
        const l = b && available().find(c => c.item_id === b.dataset.itemId);
        if (l) addLine({ ...l });
    });

    prioButtons.forEach((b, i) => {
        b.addEventListener('click', () => { priorityChoice = b.dataset.priority; refresh(); });
        b.addEventListener('keydown', e => {
            const dir = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
            if (!dir) return;
            e.preventDefault();
            const next = prioButtons[(i + dir + prioButtons.length) % prioButtons.length];
            priorityChoice = next.dataset.priority;
            refresh();
            next.focus();
        });
    });

    modal.querySelectorAll('[data-po-close]').forEach(b => b.addEventListener('click', close));
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape' || !modal.classList.contains('show')) return;
        e.stopPropagation();
        if (!picker.hidden) closePicker(); else close(); // Esc first closes the item picker
    });
    form.addEventListener('submit', e => {
        if (submitting || refresh() > 0) { e.preventDefault(); return; }
        submitting = true; // one order per click
        submit.disabled = true;
    });

    window.PurchaseOrderDialog = { open, close };
})();
