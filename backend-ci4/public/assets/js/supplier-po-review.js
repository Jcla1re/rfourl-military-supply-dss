// Procurement Report > Suppliers > Create PO for low items (app/Views/admin/supplier_po_review.php).
// Ticking a line enables its quantity; the totals and the "1 order instead of N" note follow what is ticked.
// Display only: the server checks every line again (App\Libraries\SupplierPo) and uses its own unit costs.
(function () {
    'use strict';

    var form = document.getElementById('spoForm');
    if (!form) { return; }

    var rows = Array.prototype.slice.call(form.querySelectorAll('.spo-row'));
    var summary = form.querySelector('.spo-summary');
    var orderingCost = parseFloat(summary ? summary.getAttribute('data-ordering-cost') : '0') || 0;
    var elLines = document.getElementById('spoLines');
    var elUnits = document.getElementById('spoUnits');
    var elTotal = document.getElementById('spoTotal');
    var elSaving = document.getElementById('spoSaving');
    var submit = document.getElementById('spoSubmit');

    function peso(n) {
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }

    function update() {
        var lines = 0, units = 0, total = 0, invalid = false;
        rows.forEach(function (row) {
            var pick = row.querySelector('.spo-pick');
            var qtyInput = row.querySelector('.spo-qty');
            if (!pick || !qtyInput) { return; }                       // an "Already on order" line
            var on = pick.checked;
            qtyInput.disabled = !on;
            qtyInput.required = on;
            row.classList.toggle('is-picked', on);

            var qty = /^\d+$/.test(qtyInput.value.trim()) ? parseInt(qtyInput.value, 10) : 0;
            var cost = parseFloat(row.getAttribute('data-cost')) || 0;
            var min = parseInt(row.getAttribute('data-min'), 10) || 0;
            var cell = row.querySelector('.spo-line-total');
            if (cell) { cell.textContent = peso(on ? qty * cost : 0); }

            // Warn only (never blocks): below the supplier's own minimum.
            var warn = row.querySelector('.spo-warn');
            if (warn) { warn.hidden = !(on && min > 0 && qty > 0 && qty < min); }

            if (on) {
                lines += 1;
                if (qty < 1) { invalid = true; }
                units += qty;
                total += qty * cost;
            }
        });

        elLines.textContent = plural(lines, 'line');
        elUnits.textContent = plural(units, 'piece');
        elTotal.textContent = peso(total);
        if (lines > 1) {
            var saved = (lines - 1) * orderingCost;
            elSaving.textContent = '1 order instead of ' + lines + ' separate orders' + (saved > 0 ? ' — saves about ' + peso(saved) + ' in ordering cost.' : '.');
        } else if (lines === 1) {
            elSaving.textContent = '1 order.';
        } else {
            elSaving.textContent = '';
        }
        if (submit) { submit.disabled = lines === 0 || invalid; }
    }

    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
})();
