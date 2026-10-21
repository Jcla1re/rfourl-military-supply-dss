/* public/assets/js/info-tip.js
 *
 * Small info icons with a tooltip (<button class="info-tip" data-tip="...">,
 * made by App\Libraries\Glossary::tip()). The tooltip shows on hover and on
 * keyboard focus, hides on leave / blur / Esc, and is positioned on the page
 * body, so a scrolling table or a sticky header can't clip it.
 */
(function () {
    // Also the staff Inventory "✓ Admin notified" button (who notified, and when) and
    // item names cut off after two lines (clamp-tip.js: Inventory table, POS cards).
    const TIP = '.info-tip, .notify-done, .name-clamped';
    let bubble = null;
    let owner = null;

    const hide = () => {
        if (!bubble) return;
        bubble.remove();
        if (owner) owner.removeAttribute('aria-describedby');
        bubble = null;
        owner = null;
    };

    const show = btn => {
        if (owner === btn) return;
        hide();
        owner = btn;
        bubble = document.createElement('div');
        bubble.className = 'info-tip-bubble';
        bubble.id = 'infoTipBubble';
        bubble.setAttribute('role', 'tooltip');
        bubble.textContent = btn.dataset.tip || '';
        document.body.appendChild(bubble);
        btn.setAttribute('aria-describedby', bubble.id);

        // Below the icon, centred; above it when there's no room; kept inside the window.
        const r = btn.getBoundingClientRect();
        const b = bubble.getBoundingClientRect();
        const gap = 8;
        let top = r.bottom + gap;
        if (top + b.height > window.innerHeight - 8) top = r.top - gap - b.height;
        let left = r.left + r.width / 2 - b.width / 2;
        left = Math.max(8, Math.min(left, window.innerWidth - b.width - 8));
        bubble.style.top = Math.round(top) + 'px';
        bubble.style.left = Math.round(left) + 'px';
    };

    document.addEventListener('mouseover', e => { const btn = e.target.closest(TIP); if (btn) show(btn); });
    document.addEventListener('mouseout', e => {
        const btn = e.target.closest(TIP);
        if (btn && !btn.contains(e.relatedTarget) && document.activeElement !== btn) hide();
    });
    document.addEventListener('focusin', e => { const btn = e.target.closest(TIP); if (btn) show(btn); });
    document.addEventListener('focusout', e => { if (e.target.closest(TIP)) hide(); });
    document.addEventListener('click', e => { if (e.target.closest(TIP)) e.preventDefault(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && bubble) { e.stopPropagation(); hide(); } }, true);
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
