/* public/assets/js/custom-select.js
 *
 * One custom dropdown menu for every <select> in the app (filters, rows per
 * page, forms, modals, the size pickers in table rows).
 *
 * The real <select> stays where it is and keeps doing the work: it's what the
 * user focuses, what labels point at, what forms submit and validate, and what
 * page scripts read and set. This script only replaces the browser's own list
 * with a styled menu: picking an option sets the select's value and fires the
 * usual "input" and "change" events, so every existing handler runs as before.
 *
 * Mouse: click opens / closes; click outside closes.
 * Keyboard (on the select): Enter, Space, Alt+Down or ↑/↓ open; ↑/↓ (and Home /
 * End) move; Enter picks; Esc or Tab closes; typing letters jumps to a match.
 * ARIA: the select is the combobox (aria-haspopup, aria-expanded,
 * aria-controls, aria-activedescendant); the menu is a listbox of options.
 * Disabled selects and options can't be opened or picked.
 */
(function () {
    const SELECTOR = 'select:not([multiple]):not([size]):not(.cs-skip)';
    let uid = 0;
    let open = null; // { select, menu, items, active }

    function enhance(select) {
        if (select.dataset.cs) return;
        select.dataset.cs = '1';
        select.id || (select.dataset.csId = 'cs' + (++uid));
        const menuId = 'cs-menu-' + (select.id || select.dataset.csId);
        select.classList.add('cs-native');
        if (getComputedStyle(select).appearance === 'auto') select.classList.add('cs-plain'); // had the browser's own arrow
        select.setAttribute('aria-haspopup', 'listbox');
        select.setAttribute('aria-expanded', 'false');
        select.setAttribute('aria-controls', menuId);
        select.dataset.csMenu = menuId;

        // Mouse / touch: our menu instead of the browser's list.
        const onPress = event => {
            if (event.button !== undefined && event.button !== 0) return;
            event.preventDefault();
            if (select.disabled) return;
            select.focus();
            if (open && open.select === select) close();
            else show(select);
        };
        select.addEventListener('mousedown', onPress);
        select.addEventListener('pointerdown', event => { if (event.pointerType !== 'mouse') onPress(event); });
        select.addEventListener('click', event => event.preventDefault());
        select.addEventListener('keydown', event => onKey(select, event));
        select.addEventListener('blur', () => {
            // Keep the menu while the mouse is pressed inside it.
            setTimeout(() => { if (open && open.select === select && !open.menu.matches(':hover') && document.activeElement !== select) close(); }, 0);
        });
    }

    function optionRows(select) {
        const rows = [];
        for (const child of select.children) {
            if (child.tagName === 'OPTGROUP') {
                rows.push({ group: child.label });
                for (const o of child.children) if (!o.hidden) rows.push({ option: o, disabled: o.disabled || child.disabled });
            } else if (child.tagName === 'OPTION' && !child.hidden) {
                rows.push({ option: child, disabled: child.disabled });
            }
        }
        return rows;
    }

    function show(select) {
        close();
        const menu = document.createElement('ul');
        menu.className = 'cs-menu';
        menu.id = select.dataset.csMenu;
        menu.setAttribute('role', 'listbox');
        const label = select.getAttribute('aria-label') || (select.labels && select.labels[0] ? select.labels[0].textContent.trim() : '');
        if (label) menu.setAttribute('aria-label', label);
        const items = [];
        optionRows(select).forEach((row, i) => {
            const li = document.createElement('li');
            if (row.group !== undefined) {
                li.className = 'cs-group';
                li.setAttribute('role', 'presentation');
                li.textContent = row.group;
                menu.appendChild(li);
                return;
            }
            const selected = row.option.selected;
            li.className = 'cs-option' + (selected ? ' is-selected' : '') + (row.disabled ? ' is-disabled' : '');
            li.id = menu.id + '-o' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', selected ? 'true' : 'false');
            if (row.disabled) li.setAttribute('aria-disabled', 'true');
            const text = document.createElement('span');
            text.className = 'cs-text';
            text.textContent = row.option.textContent.trim().replace(/\s+/g, ' ');
            li.appendChild(text);
            if (selected) {
                const tick = document.createElement('i');
                tick.className = 'bi bi-check2 cs-check';
                tick.setAttribute('aria-hidden', 'true');
                li.appendChild(tick);
            }
            li.addEventListener('mousedown', event => event.preventDefault()); // keep focus on the select
            li.addEventListener('click', () => { if (!row.disabled) pick(row.option); });
            li.addEventListener('mousemove', () => setActive(items.indexOf(item)));
            const item = { li, option: row.option, disabled: row.disabled };
            items.push(item);
            menu.appendChild(li);
        });
        document.body.appendChild(menu);
        open = { select, menu, items, active: -1 };
        select.setAttribute('aria-expanded', 'true');
        select.classList.add('cs-open');
        select.closest('.size-picker')?.classList.add('cs-open');
        place();
        setActive(items.findIndex(it => it.option.selected && !it.disabled));
    }

    // 6px below the select, or above it when there isn't room below; at least as wide as the select.
    function place() {
        if (!open) return;
        const { select, menu } = open;
        const anchor = select.closest('.size-picker') || select;
        const r = anchor.getBoundingClientRect();
        const gap = 6, margin = 8;
        menu.style.minWidth = Math.round(r.width) + 'px';
        menu.style.maxHeight = '';
        const below = window.innerHeight - r.bottom - gap - margin;
        const above = r.top - gap - margin;
        const natural = menu.scrollHeight + 2;
        const up = natural > below && above > below;
        const room = Math.max(120, up ? above : below);
        menu.style.maxHeight = Math.min(natural, room, 340) + 'px';
        const h = menu.getBoundingClientRect().height;
        menu.style.top = (up ? r.top - gap - h : r.bottom + gap) + 'px';
        let left = r.left;
        const w = menu.getBoundingClientRect().width;
        if (left + w > window.innerWidth - margin) left = Math.max(margin, window.innerWidth - margin - w);
        menu.style.left = Math.round(left) + 'px';
        menu.classList.toggle('is-up', up);
    }

    function setActive(index) {
        if (!open) return;
        open.items.forEach((it, i) => it.li.classList.toggle('is-active', i === index));
        open.active = index;
        const item = open.items[index];
        if (item) {
            open.select.setAttribute('aria-activedescendant', item.li.id);
            const m = open.menu, li = item.li;
            if (li.offsetTop < m.scrollTop) m.scrollTop = li.offsetTop - 6;
            else if (li.offsetTop + li.offsetHeight > m.scrollTop + m.clientHeight) m.scrollTop = li.offsetTop + li.offsetHeight - m.clientHeight + 6;
        } else {
            open.select.removeAttribute('aria-activedescendant');
        }
    }

    function move(step) {
        if (!open || !open.items.length) return;
        let i = open.active;
        for (let n = 0; n < open.items.length; n++) {
            i = i < 0 ? (step > 0 ? 0 : open.items.length - 1) : i + step;
            if (i < 0 || i >= open.items.length) return; // stop at the ends
            if (!open.items[i].disabled) return setActive(i);
        }
    }

    function pick(option) {
        const select = open.select;
        close();
        select.focus();
        if (option.selected) return;
        select.value = option.value;
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function close() {
        if (!open) return;
        const { select, menu } = open;
        menu.remove();
        select.setAttribute('aria-expanded', 'false');
        select.removeAttribute('aria-activedescendant');
        select.classList.remove('cs-open');
        select.closest('.size-picker')?.classList.remove('cs-open');
        open = null;
    }

    // Typing letters jumps to the next option starting with them.
    let typed = '', typedAt = 0;
    function typeahead(select, ch) {
        const now = Date.now();
        typed = now - typedAt > 700 ? ch : typed + ch;
        typedAt = now;
        if (!open || open.select !== select) show(select);
        const items = open.items;
        const start = typed.length === 1 ? open.active + 1 : Math.max(open.active, 0);
        for (let n = 0; n < items.length; n++) {
            const i = (start + n) % items.length;
            if (!items[i].disabled && items[i].option.textContent.trim().toLowerCase().startsWith(typed.toLowerCase())) return setActive(i);
        }
    }

    function onKey(select, event) {
        if (select.disabled) return;
        const isOpen = open && open.select === select;
        const k = event.key;
        if (!isOpen) {
            if (k === 'Enter' || k === ' ' || k === 'ArrowDown' || k === 'ArrowUp' || (k === 'ArrowDown' && event.altKey) || k === 'F4') {
                event.preventDefault();
                show(select);
            } else if (k.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                typeahead(select, k);
            }
            return;
        }
        if (k === 'ArrowDown') { event.preventDefault(); move(1); }
        else if (k === 'ArrowUp') { event.preventDefault(); move(-1); }
        else if (k === 'Home') { event.preventDefault(); setActive(-1); move(1); }
        else if (k === 'End') { event.preventDefault(); setActive(-1); move(-1); }
        else if (k === 'Enter' || (k === ' ' && !typed)) {
            event.preventDefault();
            const item = open.items[open.active];
            if (item && !item.disabled) pick(item.option); else close();
        } else if (k === 'Escape') {
            event.preventDefault();
            event.stopPropagation(); // close only the menu, not a modal it sits in
            close();
        } else if (k === 'Tab') {
            close();
        } else if (k.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            typeahead(select, k);
        }
    }

    // Click outside closes; scrolling elsewhere or resizing closes too (the menu is fixed in place).
    document.addEventListener('mousedown', event => {
        if (open && !open.menu.contains(event.target) && event.target !== open.select && !open.select.closest('.size-picker')?.contains(event.target)) close();
    }, true);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', event => { if (open && !open.menu.contains(event.target)) close(); }, true);
    // Esc while the menu is open: handled before page-level Esc handlers (modals).
    document.addEventListener('keydown', event => {
        if (open && event.key === 'Escape' && document.activeElement === open.select) { event.preventDefault(); event.stopImmediatePropagation(); close(); }
    }, true);

    const enhanceAll = root => {
        if (root.matches && root.matches(SELECTOR)) enhance(root);
        root.querySelectorAll && root.querySelectorAll(SELECTOR).forEach(enhance);
    };
    enhanceAll(document);
    // Selects added later (live search swaps the Inventory table, modals built by scripts).
    new MutationObserver(records => {
        for (const r of records) r.addedNodes.forEach(n => { if (n.nodeType === 1) enhanceAll(n); });
        if (open && !document.contains(open.select)) close();
    }).observe(document.body, { childList: true, subtree: true });
})();
