/* public/assets/js/account-menu.js
 *
 * Account menu of the collapsed sidebar (partials/_account_menu.php, all three layouts).
 *
 * - The avatar button (shown only while the sidebar is collapsed) opens a card to the right
 *   of the sidebar, its bottom lined up with the avatar's bottom, kept inside the window.
 * - Enter / Space / click open it with focus on the first item; ↑ / ↓ move between items
 *   (wrapping), Home / End jump, Enter chooses, Tab closes.
 * - Closes on a click outside, Esc (focus back on the avatar), choosing an item, or the
 *   sidebar being expanded.
 */
(function () {
    const btn = document.getElementById('accountMenuBtn');
    const menu = document.getElementById('accountMenu');
    if (!btn || !menu) return;
    const sidebar = btn.closest('.sidebar');
    // Out of the sidebar, so the card's items get the normal (green) focus ring, not the
    // sidebar's white one.
    document.body.appendChild(menu);
    const items = () => [...menu.querySelectorAll('[role="menuitem"]')];
    const GAP = 8;      // between the sidebar and the card
    const MARGIN = 8;   // minimum distance from the window edges

    function place() {
        const b = btn.getBoundingClientRect();
        const s = sidebar.getBoundingClientRect();
        const h = menu.offsetHeight;
        let top = b.bottom - h;
        top = Math.min(top, window.innerHeight - MARGIN - h);
        top = Math.max(top, MARGIN);
        menu.style.left = (s.right + GAP) + 'px';
        menu.style.top = top + 'px';
    }

    function open(focusIndex = 0) {
        menu.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        place();
        const list = items();
        (list[focusIndex] || list[0]).focus();
    }

    function close(returnFocus) {
        if (menu.hidden) return;
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        if (returnFocus) btn.focus();
    }

    btn.addEventListener('click', () => (menu.hidden ? open() : close(false)));
    btn.addEventListener('keydown', e => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            open(e.key === 'ArrowUp' ? items().length - 1 : 0);
        }
    });

    menu.addEventListener('keydown', e => {
        const list = items();
        const i = list.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const next = (i + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length;
            list[next].focus();
        } else if (e.key === 'Home' || e.key === 'End') {
            e.preventDefault();
            list[e.key === 'Home' ? 0 : list.length - 1].focus();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            close(true);
        } else if (e.key === 'Tab') {
            close(false);
        } else if (e.key === ' ' && document.activeElement.tagName === 'A') {
            e.preventDefault();
            document.activeElement.click();
        }
    });

    // Choosing an item closes the menu (the link / logout form then does its job).
    menu.addEventListener('click', e => { if (e.target.closest('[role="menuitem"]')) close(false); });

    document.addEventListener('mousedown', e => {
        if (!menu.hidden && !menu.contains(e.target) && !btn.contains(e.target)) close(false);
    });
    window.addEventListener('resize', () => { if (!menu.hidden) place(); });

    // Expanding the sidebar closes it (the button is hidden then).
    new MutationObserver(() => { if (!sidebar.classList.contains('collapsed')) close(false); })
        .observe(sidebar, { attributes: true, attributeFilter: ['class'] });
})();
