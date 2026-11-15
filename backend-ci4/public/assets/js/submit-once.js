/* public/assets/js/submit-once.js
 *
 * Any <form data-submit-once> is sent once: a double click, Enter + click or Enter twice
 * sends a single request. Loaded in all three layouts.
 *
 * - On the first submit that really goes ahead (no other handler cancelled it: a
 *   validation, the confirm dialog...), the form is marked busy at once, so any further
 *   submit is blocked. Right after, every submit button in it is disabled and shows
 *   data-busy-label (on the form; default "Processing…") with a small spinner.
 *   The buttons are disabled a tick later on purpose: a button disabled during the
 *   submit event would be left out of the posted data.
 * - The page normally leaves (the forms are normal posts). If the user comes back with
 *   the Back button (pageshow), the form and its buttons are restored.
 * - window.SubmitOnce.isBusy(form) lets page scripts (pos.js) leave a busy button alone.
 *
 * The server is the real protection (App\Libraries\OnceToken); this only spares the
 * second request.
 */
(function () {
    var SELECTOR = 'form[data-submit-once]';

    function buttons(form) {
        var list = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'));
        if (form.id) {
            list = list.concat(Array.prototype.slice.call(document.querySelectorAll('[form="' + form.id + '"][type="submit"]')));
        }
        return list;
    }

    function setBusy(form) {
        var label = form.getAttribute('data-busy-label') || 'Processing…';
        form.setAttribute('aria-busy', 'true');
        buttons(form).forEach(function (btn) {
            if (btn.hasAttribute('data-once-label')) return;
            btn.setAttribute('data-once-label', btn.tagName === 'INPUT' ? btn.value : btn.innerHTML);
            btn.setAttribute('data-once-disabled', btn.disabled ? '1' : '0');
            btn.disabled = true;
            if (btn.tagName === 'INPUT') {
                btn.value = label;
            } else {
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>';
                btn.appendChild(document.createTextNode(label));
            }
        });
    }

    function reset(form) {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        buttons(form).forEach(function (btn) {
            if (!btn.hasAttribute('data-once-label')) return;
            if (btn.tagName === 'INPUT') btn.value = btn.getAttribute('data-once-label');
            else btn.innerHTML = btn.getAttribute('data-once-label');
            btn.disabled = btn.getAttribute('data-once-disabled') === '1';
            btn.removeAttribute('data-once-label');
            btn.removeAttribute('data-once-disabled');
        });
    }

    // Capture phase, before any other handler: a form already on its way is never sent again.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches && form.matches(SELECTOR) && form.dataset.submitting === '1') {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // Bubble phase, after the form's own handlers: only a submit nobody cancelled counts.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches(SELECTOR) || e.defaultPrevented) return;
        form.dataset.submitting = '1';
        setTimeout(function () { setBusy(form); }, 0);
    });

    // Back button (page restored from the cache): usable again.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll(SELECTOR).forEach(reset);
    });

    window.SubmitOnce = {
        isBusy: function (form) { return !!form && form.dataset.submitting === '1'; },
        reset: reset
    };
})();
