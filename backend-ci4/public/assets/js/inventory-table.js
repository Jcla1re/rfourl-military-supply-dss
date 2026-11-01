/* public/assets/js/inventory-table.js
 *
 * Inventory table (admin and staff), shared behaviour that goes with
 * assets/css/inventory-table.css.
 *
 * Sticky header state: the table scrolls inside the layout's .main-scroll and its
 * header row sticks under the green header. While it's stuck, .is-stuck squares its
 * corners and adds the shared soft shadow. The state itself is kept by
 * assets/js/sticky-offset.js for every table; a page calls this again after it swaps
 * the table (live search), so it's a global function.
 */
function updateStickyHeader() {
    if (window.updateStickyHeaders) window.updateStickyHeaders();
}

/* Item names cut off after two lines: tooltip + accessible name in assets/js/clamp-tip.js. */
