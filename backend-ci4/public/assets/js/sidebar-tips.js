/* public/assets/js/sidebar-tips.js
 *
 * Collapsed sidebar: each nav link's tooltip (its data-tooltip, drawn by admin-theme.css as a
 * fixed ::after so the scrolling nav can't clip it) is placed 12px to the right of the link,
 * vertically centred on it. Runs on hover and keyboard focus (all three layouts).
 */
(function () {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    function place(e) {
        const link = e.target.closest && e.target.closest('.nav-link[data-tooltip]');
        if (!link || !sidebar.contains(link)) return;
        const r = link.getBoundingClientRect();
        link.style.setProperty('--tip-x', (sidebar.getBoundingClientRect().right + 12) + 'px');
        link.style.setProperty('--tip-y', (r.top + r.height / 2) + 'px');
    }
    sidebar.addEventListener('mouseover', place);
    sidebar.addEventListener('focusin', place);
})();
