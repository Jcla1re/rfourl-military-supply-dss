/* public/assets/js/inventory-table.js
 *
 * Inventory table (admin and staff), shared behaviour that goes with
 * assets/css/inventory-table.css.
 *
 * Sticky header state: the table scrolls inside the layout's .main-scroll and its
 * header row sticks under the green header. While it's stuck, .is-stuck squares its
 * corners and adds a 1px line under it. Called again by a page after it swaps the
 * table (live search), so it's a global function.
 */
function updateStickyHeader() {
    const scroller = document.querySelector('.main-scroll');
    const table = document.querySelector('.inv-table');
    if (!table || !scroller) return;
    const offset = parseFloat(getComputedStyle(scroller).getPropertyValue('--sticky-top')) || 0;
    const stuck = table.getBoundingClientRect().top < scroller.getBoundingClientRect().top + offset - 0.5;
    table.classList.toggle('is-stuck', stuck);
}

(function () {
    const scroller = document.querySelector('.main-scroll');
    if (!scroller) return;
    let frame = 0;
    scroller.addEventListener('scroll', () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(updateStickyHeader);
    }, { passive: true });
    updateStickyHeader();
})();
