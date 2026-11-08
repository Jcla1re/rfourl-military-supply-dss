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

    // ---- Ordering: the shared purchase order dialog (assets/js/purchase-order-dialog.js).
    // Each Order button carries its size's line (data-line). Orders come back to this page.
    const lineOf = button => JSON.parse(button.dataset.line);
    const orderOptions = (opener, alertId = '') => ({ opener, alertId, returnTo: '/admin/reorder-alerts', returnQuery: location.search });
    board.querySelectorAll('.open-order-modal').forEach(button => button.addEventListener('click', () => {
        window.PurchaseOrderDialog.open([lineOf(button)], orderOptions(button, button.dataset.alertId));
    }));
    // "Order all sizes": the same sizes the button counts (see updateCard): shown, and from the
    // most urgent size's supplier; a size is never ordered from a supplier that isn't its own.
    board.querySelectorAll('[data-order-all]').forEach(button => button.addEventListener('click', () => {
        const card = button.closest('[data-ra-card]');
        const shownRows = [...card.querySelectorAll('[data-ra-row]')].filter(row => !row.hidden);
        const supplier = shownRows[0]?.dataset.supplier;
        const lines = shownRows.filter(row => row.dataset.supplier === supplier).map(row => lineOf(row.querySelector('.open-order-modal')));
        window.PurchaseOrderDialog.open(lines, orderOptions(button));
    }));

    // Dismiss / Restore post the current tab and filters, so the page comes back to the same view.
    board.querySelectorAll('form[data-keep-view]').forEach(form => form.addEventListener('submit', () => {
        form.querySelector('input[name="return_query"]').value = location.search;
    }));

    apply();
})();
