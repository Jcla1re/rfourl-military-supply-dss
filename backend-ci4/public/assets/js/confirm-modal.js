// Replaces window.confirm(): any <form data-confirm="..."> or <a data-confirm="...">
// opens the shared #confirmModal (app/Views/partials/_confirm_modal.php) first.
// Optional: data-confirm-title, data-confirm-ok (button label),
// data-confirm-variant = danger | success | primary (default).
(function () {
    var el = document.getElementById('confirmModal');
    if (!el || typeof bootstrap === 'undefined') return;

    var modal   = bootstrap.Modal.getOrCreateInstance(el);
    var iconBox = document.getElementById('confirmModalIcon');
    var titleEl = document.getElementById('confirmModalTitle');
    var msgEl   = document.getElementById('confirmModalMessage');
    var okBtn   = document.getElementById('confirmModalOk');
    var cancel  = document.getElementById('confirmModalCancel');

    var VARIANTS = {
        danger:  { icon: 'bi-exclamation-triangle', btn: 'btn-maroon' },
        success: { icon: 'bi-check-circle',         btn: 'btn-success' },
        primary: { icon: 'bi-question-circle',      btn: 'btn-success' }
    };

    var pending = null;      // { form, submitter } or { href, target }
    var bypass  = null;      // form currently being submitted after confirmation

    function open(source, action) {
        var variant = VARIANTS[source.getAttribute('data-confirm-variant')] ? source.getAttribute('data-confirm-variant') : 'primary';
        var v = VARIANTS[variant];

        pending = action;
        titleEl.textContent = source.getAttribute('data-confirm-title') || 'Are you sure?';
        msgEl.textContent   = source.getAttribute('data-confirm') || '';
        msgEl.hidden        = msgEl.textContent === '';
        okBtn.textContent   = source.getAttribute('data-confirm-ok') || 'Confirm';

        iconBox.className = 'confirm-icon ' + variant;
        iconBox.innerHTML = '';
        var i = document.createElement('i');
        i.className = 'bi ' + v.icon;
        iconBox.appendChild(i);

        okBtn.className = 'btn ' + v.btn;
        okBtn.disabled = false;
        modal.show();
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('form[data-confirm]') || bypass === form) return;
        e.preventDefault();
        open(form, { form: form, submitter: e.submitter || null });
    }, true);

    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-confirm]') : null;
        if (!a) return;
        e.preventDefault();
        open(a, { href: a.href, target: a.target });
    });

    okBtn.addEventListener('click', function () {
        if (!pending || okBtn.disabled) return;
        okBtn.disabled = true; // no double submits
        var action = pending;

        if (action.form) {
            bypass = action.form;
            if (action.form.requestSubmit) {
                action.form.requestSubmit(action.submitter);
            } else {
                action.form.submit();
            }
        } else if (action.target === '_blank') {
            window.open(action.href, '_blank', 'noopener');
            modal.hide();
        } else {
            window.location.href = action.href;
        }
    });

    // Focus Cancel, so an accidental Enter never confirms a destructive action.
    el.addEventListener('shown.bs.modal', function () { cancel.focus(); });
    el.addEventListener('hidden.bs.modal', function () { pending = null; bypass = null; okBtn.disabled = false; });
    // Coming back via the browser's Back button must not leave the dialog stuck.
    window.addEventListener('pageshow', function (e) { if (e.persisted) { okBtn.disabled = false; bypass = null; } });
})();
