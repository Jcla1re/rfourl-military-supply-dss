// Owner: Reorder Alerts tabs and filters, all in the page (every Reorder Now size is
// already rendered in its tab). Tab counts follow search / category / supplier; the
// "Show only" chips narrow To order only and can be combined. State stays in the URL
// (?tab=&q=&cat=&sup=&only=) so a reload or a link opens the same view.
(() => {
    const board = document.getElementById('raBoard');
    if (!board) return;

    const rows     = [...board.querySelectorAll('[data-ra-row]')];
    const search   = document.getElementById('raSearch');
    const clearX   = document.getElementById('raSearchClear');
    const category = document.getElementById('raCategory');
    const supplier = document.getElementById('raSupplier');
    const clearAll = document.getElementById('raClearAll');
    const chips    = [...board.querySelectorAll('[data-only]')];
    const tabLinks = [...board.querySelectorAll('[data-tab-link]')];
    const TABS     = ['order', 'ordered', 'dismissed'];

    const state = {
        tab: board.dataset.activeTab || 'order',
        only: new Set(chips.filter(c => c.classList.contains('active')).map(c => c.dataset.only)),
    };

    const matchesFilters = row => {
        const q = search.value.trim().toLowerCase();
        return (q === '' || row.dataset.search.includes(q))
            && (category.value === '' || row.dataset.category === category.value)
            && (supplier.value === '' || row.dataset.supplier === supplier.value);
    };
    const matchesChips = row => [...state.only].every(key => row.dataset[key] === '1');

    // Item cards (To order, Dismissed): hidden when none of its sizes is shown; a multi-size
    // card says "2 sizes" or "1 of 2 sizes" (the item's other sizes are on another tab or
    // filtered out). "Order all sizes" needs 2+ shown sizes from the same supplier as the
    // most urgent one: a size is never ordered from a supplier that isn't its own.
    const cards = [...board.querySelectorAll('[data-ra-card]')];
    function updateCard(card) {
        const shownRows = [...card.querySelectorAll('[data-ra-row]')].filter(row => !row.hidden);
        card.hidden = shownRows.length === 0;
        if (!('multi' in card.dataset)) return;
        const totalSizes = Number(card.dataset.total);
        const text = shownRows.length === totalSizes ? `${totalSizes} sizes` : `${shownRows.length} of ${totalSizes} sizes`;
        card.querySelectorAll('[data-size-count]').forEach(el => { el.textContent = text; });
        const orderAll = card.querySelector('[data-order-all]');
        if (orderAll) {
            const supplier = shownRows[0]?.dataset.supplier;
            orderAll.hidden = shownRows.filter(row => row.dataset.supplier === supplier).length < 2;
        }
    }

    function apply() {
        const counts = { order: 0, ordered: 0, dismissed: 0 };
        const chipCounts = { out: 0, new: 0 };
        let late = 0;
        const shown = { order: 0, ordered: 0, dismissed: 0 };
        const total = { order: 0, ordered: 0, dismissed: 0 };

        rows.forEach(row => {
            const tab = row.dataset.tab;
            total[tab]++;
            const inFilters = matchesFilters(row);
            if (inFilters) {
                counts[tab]++;
                if (tab === 'ordered' && row.dataset.late === '1') late++;
                if (tab === 'order') {
                    if (row.dataset.out === '1') chipCounts.out++;
                    if (row.dataset.new === '1') chipCounts.new++;
                }
            }
            const visible = inFilters && (tab !== 'order' || matchesChips(row));
            row.hidden = !visible;
            if (visible) shown[tab]++;
        });

        cards.forEach(updateCard);

        TABS.forEach(tab => {
            board.querySelector(`[data-tab-count="${tab}"]`).textContent = counts[tab];
            const panel = board.querySelector(`[data-panel="${tab}"]`);
            panel.hidden = tab !== state.tab;
            panel.querySelector('[data-tab-empty]').hidden = total[tab] !== 0;
            panel.querySelector('[data-no-results]').hidden = total[tab] === 0 || shown[tab] !== 0;
            const items = panel.querySelector('[data-items]'); // column header + cards
            if (items) items.hidden = shown[tab] === 0;
        });
        tabLinks.forEach(link => {
            const on = link.dataset.tabLink === state.tab;
            link.classList.toggle('active', on);
            link.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        const lateBox = board.querySelector('[data-late-count]');
        lateBox.querySelector('[data-late-number]').textContent = late;
        lateBox.hidden = late === 0;

        chips.forEach(chip => {
            const on = state.only.has(chip.dataset.only);
            chip.classList.toggle('active', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            chip.querySelector('[data-chip-count]').textContent = chipCounts[chip.dataset.only];
        });

        // "Clear all (n)": each filter that is on counts once.
        const active = (search.value.trim() !== '') + (category.value !== '') + (supplier.value !== '') + state.only.size;
        clearAll.hidden = active === 0;
        document.getElementById('raClearCount').textContent = active;
        clearAll.setAttribute('aria-label', `Clear all filters (${active} active)`);
        clearX.hidden = search.value === '';
        category.classList.toggle('has-value', category.value !== '');
        supplier.classList.toggle('has-value', supplier.value !== '');

        const params = new URLSearchParams();
        if (state.tab !== 'order') params.set('tab', state.tab);
        if (search.value.trim() !== '') params.set('q', search.value.trim());
        if (category.value !== '') params.set('cat', category.value);
        if (supplier.value !== '') params.set('sup', supplier.value);
        if (state.only.size) params.set('only', [...state.only].join(','));
        const query = params.toString();
        history.replaceState(null, '', location.pathname + (query ? '?' + query : ''));
    }

    tabLinks.forEach(link => link.addEventListener('click', e => {
        e.preventDefault();
        state.tab = link.dataset.tabLink;
        apply();
    }));
    chips.forEach(chip => chip.addEventListener('click', () => {
        const key = chip.dataset.only;
        state.only.has(key) ? state.only.delete(key) : state.only.add(key);
        apply();
    }));
    search.addEventListener('input', apply);
    category.addEventListener('change', apply);
    supplier.addEventListener('change', apply);
    clearX.addEventListener('click', () => { search.value = ''; apply(); search.focus(); });
    board.querySelectorAll('[data-clear-all]').forEach(button => button.addEventListener('click', () => {
        search.value = '';
        category.value = '';
        supplier.value = '';
        state.only.clear();
        apply();
    }));

    // ---- Order dialog: one size ("Order 12") or "Order all sizes" (one stock order, a line
    // per size). Each Order button carries its size's data (data-line). Sizes are only ever
    // ordered from their own supplier: the supplier is fixed, and the dropdown only appears
    // for sizes that have none.
    const modal     = document.getElementById('orderModal');
    const form      = document.getElementById('orderForm');
    const linesBox  = document.getElementById('orderLines');
    const lineTpl   = document.getElementById('orderLineTemplate');
    const supName   = document.getElementById('orderSupplierName');
    const supId     = document.getElementById('orderSupplierId');
    const supPick   = document.getElementById('orderSupplierPick');
    const expected  = document.getElementById('orderExpected');
    const totalBox  = document.getElementById('orderTotal');
    const peso      = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const localDate = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    let opener = null;

    function updateTotal() {
        let total = 0;
        linesBox.querySelectorAll('.od-line').forEach(line => {
            const input = line.querySelector('input[name="quantity[]"]');
            const qty = Math.max(1, parseInt(input.value, 10) || 0);
            const sub = qty * Number(line.dataset.cost);
            line.querySelector('.od-line-total').textContent = peso(sub);
            line.querySelector('[data-step="-1"]').disabled = qty <= 1;
            total += sub;
        });
        totalBox.textContent = peso(total);
    }

    function openOrder(lines, alertId, title, button) {
        opener = button;
        linesBox.replaceChildren();
        lines.forEach(l => {
            const line = lineTpl.content.firstElementChild.cloneNode(true);
            line.dataset.cost = l.cost;
            line.querySelector('input[name="item_id[]"]').value = l.item_id;
            line.querySelector('input[name="unit_price[]"]').value = l.cost;
            line.querySelector('.od-line-name').textContent = l.name;
            line.querySelector('.od-line-meta').textContent = [
                `Reorder at ${l.rop}`,
                `Safety stock ${l.safety ?? '—'}`,
                `Suggested ${l.eoq ?? '—'} (EOQ)`,
                `${peso(l.cost)} each`,
            ].join(' · ');
            const qty = line.querySelector('input[name="quantity[]"]');
            qty.value = l.eoq || 1;
            qty.setAttribute('aria-label', `Quantity: ${l.name}`);
            line.querySelector('[data-step="-1"]').setAttribute('aria-label', `One less: ${l.name}`);
            line.querySelector('[data-step="1"]').setAttribute('aria-label', `One more: ${l.name}`);
            linesBox.appendChild(line);
        });

        // All lines share one supplier (Order all sizes only groups a supplier's sizes).
        const first = lines[0];
        const fixed = first.supplier_id !== '';
        supName.hidden = !fixed;
        supName.textContent = fixed ? first.supplier : '';
        supId.disabled = !fixed;
        supId.value = first.supplier_id;
        supPick.hidden = fixed;
        supPick.disabled = fixed;
        supPick.value = '';

        // Expected delivery: today + the longest delivery time among the lines.
        const lead = Math.max(0, ...lines.map(l => l.lead || 0));
        const today = new Date();
        expected.min = localDate(today);
        expected.value = lead ? localDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() + lead)) : '';

        document.getElementById('orderAlertId').value = alertId || '';
        document.getElementById('orderSubtitle').textContent = title;
        document.getElementById('orderSubmit').disabled = false;
        updateTotal();
        modal.classList.add('show');
        linesBox.querySelector('input[name="quantity[]"]').focus();
    }

    function closeOrder() {
        modal.classList.remove('show');
        opener?.focus();
    }

    const lineOf = button => JSON.parse(button.dataset.line);
    board.querySelectorAll('.open-order-modal').forEach(button => button.addEventListener('click', () => {
        const line = lineOf(button);
        openOrder([line], button.dataset.alertId, line.name, button);
    }));
    // The same sizes the button counts (see updateCard): shown, same supplier as the most urgent.
    board.querySelectorAll('[data-order-all]').forEach(button => button.addEventListener('click', () => {
        const card = button.closest('[data-ra-card]');
        const shownRows = [...card.querySelectorAll('[data-ra-row]')].filter(row => !row.hidden);
        const supplier = shownRows[0]?.dataset.supplier;
        const lines = shownRows.filter(row => row.dataset.supplier === supplier).map(row => lineOf(row.querySelector('.open-order-modal')));
        const name = card.querySelector('.ra-item-head .ra-name').textContent.trim();
        openOrder(lines, '', `${name} · ${lines.length} sizes`, button);
    }));

    linesBox.addEventListener('click', e => {
        const step = e.target.closest('.od-step');
        if (!step) return;
        const input = step.closest('.od-qty').querySelector('input');
        input.value = Math.max(1, (parseInt(input.value, 10) || 0) + Number(step.dataset.step));
        updateTotal();
    });
    linesBox.addEventListener('input', updateTotal);
    linesBox.addEventListener('change', e => {
        if (e.target.name === 'quantity[]') e.target.value = Math.max(1, parseInt(e.target.value, 10) || 1);
        updateTotal();
    });
    modal.querySelectorAll('.close-order-modal').forEach(b => b.addEventListener('click', closeOrder));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.classList.contains('show')) closeOrder();
    });
    // One order per click: the button stays disabled while the page posts.
    form.addEventListener('submit', () => { document.getElementById('orderSubmit').disabled = true; });

    // Dismiss / Restore post the current tab and filters, so the page comes back to the same view.
    board.querySelectorAll('form[data-keep-view]').forEach(form => form.addEventListener('submit', () => {
        form.querySelector('input[name="return_query"]').value = location.search;
    }));

    apply();
})();
