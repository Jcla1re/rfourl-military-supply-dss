/* public/assets/js/clamp-tip.js
 *
 * Item names that CSS cuts off after two lines ("…"): Inventory table (admin
 * and staff) and the POS product cards. A cut-off name gets the full name as a
 * tooltip (info-tip.js: hover and keyboard focus) and as its accessible name.
 * Re-checked when the window resizes and when the list changes (live search,
 * POS category tabs / search).
 */
(function () {
    const SEL = '.inv-table tbody td:nth-child(2) > strong, .pos-card .name';

    const mark = () => {
        document.querySelectorAll(SEL).forEach(el => {
            if (!el.offsetParent) return; // hidden (filtered out): checked again when shown
            const clamped = el.scrollHeight > el.clientHeight + 1;
            el.classList.toggle('name-clamped', clamped);
            if (clamped) {
                el.dataset.tip = el.textContent.trim();
                el.setAttribute('aria-label', el.dataset.tip);
                el.tabIndex = 0;
            } else if (el.hasAttribute('data-tip')) {
                el.removeAttribute('data-tip');
                el.removeAttribute('aria-label');
                el.removeAttribute('tabindex');
            }
        });
    };

    let frame = 0;
    const schedule = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(mark); };
    window.addEventListener('resize', schedule);
    document.querySelectorAll('.inv-table-card, .pos-grid').forEach(area => {
        new MutationObserver(schedule).observe(area.parentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
    });
    if (document.fonts) document.fonts.ready.then(schedule);
    schedule();
})();
