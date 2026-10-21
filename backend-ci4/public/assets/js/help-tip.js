// Tooltips, one style everywhere: a white card with a bold title and text (Bootstrap popover,
// .help-popover). Used by:
//   - the (i) buttons (<button class="help-tip">, made by help_tip() / Glossary::tip());
//   - staff Inventory "Admin notified" (.notify-done, data-tip: "Notified by …" / "<date> · <time>");
//   - item names cut off after two lines (.name-clamped, clamp-tip.js: the full name).
// Opens on hover and on keyboard focus. A click pins it open; a click outside, a second click
// on the same element, or Escape closes it. Only one is open at a time. Table header tooltips
// open above the icon when there's room, below otherwise. Event delegation, so elements added
// later (live search, size switch) work without extra setup.
(function () {
    if (typeof bootstrap === 'undefined' || !bootstrap.Popover) return;

    var SEL = '.help-tip, .notify-done, .name-clamped';
    var openEl = null;
    var pinned = false;
    var refocusing = false; // Escape hands focus back without reopening the tooltip

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // Title + body for each kind of element, read at open time (they can change).
    function contentFor(el) {
        if (el.classList.contains('help-tip')) {
            return { title: el.getAttribute('data-help-title') || '', body: el.getAttribute('data-help-body') || '' };
        }
        var lines = (el.getAttribute('data-tip') || '').split('\n');
        var rest = lines.slice(1).filter(Boolean).map(function (l) { return '<p>' + esc(l) + '</p>'; }).join('');
        return { title: lines[0] || '', body: rest };
    }

    function open(el) {
        if (openEl === el) return;
        close();
        var c = contentFor(el);
        if (!c.title && !c.body) return;
        var inHeader = !!el.closest('th, thead');
        var pop = new bootstrap.Popover(el, {
            trigger: 'manual',
            html: true,
            container: 'body',
            placement: inHeader ? 'top' : 'auto',
            fallbackPlacements: inHeader ? ['bottom'] : ['bottom', 'top', 'right', 'left'],
            customClass: 'help-popover' + (c.body ? '' : ' help-popover-title-only') + (el.getAttribute('data-help-class') ? ' ' + el.getAttribute('data-help-class') : ''),
            title: esc(c.title),
            content: c.body
        });
        pop.show();
        if (el.classList.contains('help-tip')) el.setAttribute('aria-expanded', 'true');
        openEl = el;
    }

    function close() {
        if (!openEl) return;
        var el = openEl;
        var pop = bootstrap.Popover.getInstance(el);
        if (pop) pop.dispose(); // fresh content next time
        if (el.classList.contains('help-tip')) el.setAttribute('aria-expanded', 'false');
        openEl = null;
        pinned = false;
    }

    function isPinnable(el) {
        // A POS card's name: the click belongs to the card (adds the item to the cart).
        return !el.closest('.pos-card');
    }

    document.addEventListener('mouseover', function (e) {
        var el = e.target.closest(SEL);
        if (el && !pinned) open(el);
    });
    document.addEventListener('mouseout', function (e) {
        var el = e.target.closest(SEL);
        if (!el || el !== openEl || pinned) return;
        // Stays while it has keyboard focus (not mouse focus from a click on a POS card name).
        if (el.contains(e.relatedTarget) || el.matches(':focus-visible')) return;
        close();
    });
    document.addEventListener('focusin', function (e) {
        var el = e.target.closest(SEL);
        if (el && !pinned && !refocusing) open(el);
    });
    document.addEventListener('focusout', function (e) {
        var el = e.target.closest(SEL);
        if (el && el === openEl && !pinned) close();
    });

    document.addEventListener('click', function (e) {
        var el = e.target.closest(SEL);
        if (el) {
            if (el.classList.contains('help-tip') || el.classList.contains('notify-done')) e.preventDefault();
            if (!isPinnable(el)) return;
            if (openEl === el && pinned) { close(); return; }
            open(el);
            pinned = true;
            return;
        }
        // A click inside the card (to select text, for example) keeps it open.
        if (openEl && !e.target.closest('.help-popover')) close();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !openEl) return;
        var el = openEl;
        var wasPinned = pinned;
        close();
        e.stopPropagation(); // don't also close a modal the tooltip sits in
        if (wasPinned && document.contains(el) && document.activeElement !== el) {
            refocusing = true;
            el.focus();
            refocusing = false;
        }
    }, true);
})();
