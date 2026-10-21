// Help pop-ups: every <button class="help-tip"> (made by help_tip() / Glossary::tip()) opens a
// Bootstrap popover on click or tap. One pop-up is open at a time. It closes on a click or tap
// outside it, on a second click on the same (i), and on Escape (focus goes back to the button).
// Started once per page through event delegation, so (i) buttons that are added later
// (for example a table reloaded by live search) work without extra setup.
(function () {
    if (typeof bootstrap === 'undefined' || !bootstrap.Popover) return;

    var openBtn = null;

    function popoverFor(btn) {
        var instance = bootstrap.Popover.getInstance(btn);
        if (instance) return instance;

        return new bootstrap.Popover(btn, {
            trigger: 'manual',
            html: true,
            container: 'body',
            placement: 'auto',
            fallbackPlacements: ['bottom', 'top', 'right', 'left'],
            customClass: 'help-popover',
            title: btn.getAttribute('data-help-title') || '',
            content: btn.getAttribute('data-help-body') || ''
        });
    }

    function close(btn) {
        if (!btn) return;
        var instance = bootstrap.Popover.getInstance(btn);
        if (instance) instance.hide();
        btn.setAttribute('aria-expanded', 'false');
        if (openBtn === btn) openBtn = null;
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.help-tip');

        if (btn) {
            event.preventDefault();
            if (openBtn === btn) {
                close(btn);
                return;
            }
            close(openBtn);
            popoverFor(btn).show();
            btn.setAttribute('aria-expanded', 'true');
            openBtn = btn;
            return;
        }

        // A click inside the pop-up (to select text, for example) keeps it open.
        if (openBtn && !event.target.closest('.help-popover')) close(openBtn);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openBtn) {
            var btn = openBtn;
            close(btn);
            if (document.contains(btn)) btn.focus();
        }
    });
})();
