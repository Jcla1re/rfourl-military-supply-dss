// Inventory: the chevron on a row shows / hides its detail row (Item ID, Category, Order qty, stock bar, Last updated).
// Delegated on the document because live search replaces the table.
document.addEventListener('click', function (event) {
    var button = event.target.closest('.inv-expand');
    if (!button) return;
    var detail = document.getElementById(button.getAttribute('aria-controls'));
    if (!detail) return;
    var open = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    detail.hidden = !open;
});
