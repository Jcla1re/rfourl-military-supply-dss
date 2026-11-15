// POS checkout: warn when the OR / invoice number was already used on another sale, but still allow it.
// The form is held for one quick check; if the cashier says "OK" (or the check can't be made) it submits as usual.
(function () {
    var form = document.getElementById('cartForm');
    var input = document.getElementById('orNumber');
    if (!form || !input || !input.dataset.checkUrl) return;

    form.addEventListener('submit', function (event) {
        var orNo = input.value.trim();
        if (orNo === '' || form.dataset.orChecked === orNo) return; // nothing to check, or already asked about this one
        event.preventDefault();

        var proceed = function () {
            form.dataset.orChecked = orNo;
            form.requestSubmit(); // submitter-less resubmit: the checkout button is not what was clicked, the form is the same
        };
        fetch(input.dataset.checkUrl + '?or_number=' + encodeURIComponent(orNo), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : { used: false }; })
            .then(function (result) {
                if (!result.used || window.confirm('OR no. ' + orNo + ' was already used on receipt #' + result.receipt_no + '. Continue anyway?')) {
                    proceed();
                }
            })
            .catch(proceed); // a failed check never blocks a sale
    });

    // Changing the number means asking again.
    input.addEventListener('input', function () { delete form.dataset.orChecked; });
})();
