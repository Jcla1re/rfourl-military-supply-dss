/* public/assets/js/pos-pin.js
 * The checkout PIN prompt and "Lock POS" on the Point of Sale page (app/Views/partials/_pos_tab.php).
 *
 * Checkout: when the form is about to be sent without a PIN, the send is held and #pinModal ("Confirm
 * this sale", with the total and payment method from the cart on screen) asks for the cashier's own
 * 4-digit PIN. A refused PIN comes back after the reload and reopens it with the message. Confirm puts it in the hidden pos_pin field and sends the form again
 * with the Checkout button, so submit-once.js still sends it once and the one-time key still guards it.
 * The OR-number check (pos-or-check.js, loaded first) runs before this: a send it holds is left alone.
 * The server checks the PIN (App\Libraries\PosPin); this only asks for it. The PIN box itself has no
 * name, the hidden field is emptied whenever the page is shown again, and nothing is stored.
 *
 * Lock POS: the cart on screen (item IDs and quantities only) is copied into the lock form, so it comes
 * back after unlocking (App\Libraries\PosLock).
 */
(function () {
    var form = document.getElementById('cartForm');
    var modal = document.getElementById('pinModal');
    if (!form || !modal) return;

    var hidden = document.getElementById('posPin');
    var input = document.getElementById('pinInput');
    var ok = document.getElementById('pinConfirm');
    var cancel = document.getElementById('pinCancel');
    var msg = document.getElementById('pinMsg');
    var checkoutBtn = document.getElementById('checkoutBtn');
    var METHOD_LABELS = { Cash: 'Cash', GCash: 'GCash', Split: 'Cash+GCash' };

    /** The hint, or a refusal from the server in red (red box border too). */
    function showMessage(error) {
        msg.textContent = error || msg.dataset.hint || '';
        msg.classList.toggle('is-error', !!error);
        if (error) { msg.setAttribute('role', 'alert'); input.setAttribute('aria-invalid', 'true'); }
        else { msg.removeAttribute('role'); input.removeAttribute('aria-invalid'); }
    }

    /** "Total · Cash   ₱40": what is on screen in the cart now (display only; the server prices the sale). */
    function fillSummary() {
        var total = document.getElementById('cartTotal');
        var method = document.getElementById('paymentMethod');
        document.getElementById('pinSummaryTotal').textContent = total ? total.textContent : '';
        document.getElementById('pinSummaryMethod').textContent = METHOD_LABELS[method && method.value] || 'Cash';
    }

    /**
     * Opens "Confirm this sale". opts.error: the server's refusal, shown in place of the hint.
     * opts.locked: the 3 tries / 60 s lock, so only Cancel is usable for this opening (the server still
     * refuses until the lock ends; opening it again later with Checkout works as usual).
     */
    function open(opts) {
        opts = opts || {};
        fillSummary();
        input.value = '';
        input.disabled = !!opts.locked;
        ok.disabled = true;
        showMessage(opts.error);
        modal.classList.add('show');
        setTimeout(function () { (opts.locked ? cancel : input).focus(); }, 0);
    }
    function close() {
        modal.classList.remove('show');
        input.value = '';
        input.disabled = false;
        showMessage('');
        if (checkoutBtn && !checkoutBtn.disabled) {
            checkoutBtn.focus(); // back to Checkout
        } else if (modal.contains(document.activeElement)) {
            document.activeElement.blur(); // Checkout can't take focus now: never leave it in the closed dialog
        }
    }
    function isOpen() { return modal.classList.contains('show'); }

    form.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;                                  // another check holds the form first
        if (window.SubmitOnce && window.SubmitOnce.isBusy(form)) return; // already on its way
        if (/^\d{4}$/.test(hidden.value)) return;                        // PIN given: the sale goes
        e.preventDefault();
        open();
    });

    input.addEventListener('input', function () {
        var digits = input.value.replace(/\D/g, '').slice(0, 4);
        if (input.value !== digits) input.value = digits;
        if (input.getAttribute('aria-invalid') === 'true') showMessage(''); // typing again: the hint is back
        ok.disabled = digits.length !== 4;
    });
    ok.addEventListener('click', function () {
        if (input.disabled || !/^\d{4}$/.test(input.value)) return;
        hidden.value = input.value;
        input.value = '';
        modal.classList.remove('show');
        form.requestSubmit(checkoutBtn);
    });

    // Keys: Enter confirms (only with 4 digits), Esc cancels, Tab stays inside the dialog.
    modal.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key === 'Enter' && e.target === input) { e.preventDefault(); if (!ok.disabled) ok.click(); return; }
        if (e.key === 'Tab') {
            var items = [input, cancel, ok].filter(function (el) { return !el.disabled; });
            var i = items.indexOf(document.activeElement);
            if (i === -1) { e.preventDefault(); items[0].focus(); return; }
            var next = e.shiftKey ? (i - 1 + items.length) % items.length : (i + 1) % items.length;
            e.preventDefault();
            items[next].focus();
        }
    });
    // The dialog is its own <form> only so browsers pair its password box with the username hint in it
    // (not with the item search); it is never sent.
    var promptForm = document.getElementById('pinPromptForm');
    if (promptForm) {
        promptForm.addEventListener('submit', function (e) { e.preventDefault(); if (!ok.disabled) ok.click(); });
    }
    cancel.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });

    // Back button / page restored from the cache: no PIN is left anywhere on the page.
    window.addEventListener('pageshow', function () { hidden.value = ''; input.value = ''; });

    // A PIN the server refused (PosPinActions::checkoutGate): the dialog opens again with its message,
    // once the cart is back (pos.js, loaded before this file, has rebuilt it).
    if (modal.dataset.pinError) {
        open({ error: modal.dataset.pinError, locked: modal.dataset.pinLocked === '1' });
    }

    var lockForm = document.getElementById('posLockForm');
    if (lockForm) {
        lockForm.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            lockForm.querySelectorAll('input.pos-lock-cart').forEach(function (n) { n.remove(); });
            form.querySelectorAll('input[name="item_id[]"], input[name="quantity[]"]').forEach(function (src) {
                var copy = document.createElement('input');
                copy.type = 'hidden';
                copy.name = src.name;
                copy.value = src.value;
                copy.className = 'pos-lock-cart';
                lockForm.appendChild(copy);
            });
        });
    }
})();
