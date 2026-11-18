// Sticky table headers (and side panels) stick at --sticky-top on .main-scroll.
//
// Page tab bars (.sticky-tabs; not tabs inside modals) scroll with the page today
// (admin-theme.css: position: relative), so --sticky-top is 0 and headers stick
// flush under the green header. Only a bar whose computed position is sticky is
// counted: a bar made sticky again would publish its height here, and the table
// headers would stick right under it. admin-theme.css reads it for `thead` and for
// sticky side panels. Re-measured whenever a bar resizes (window width, wrapping, font load).
(function () {
    var scroller = document.querySelector('.main-scroll');
    if (!scroller) return;

    var frame = 0;

    function bars() {
        return Array.prototype.filter.call(document.querySelectorAll('.sticky-tabs'), function (el) {
            return !el.closest('.modal, [role="dialog"]');
        });
    }

    function measure() {
        var height = 0;
        bars().forEach(function (el) {
            if (getComputedStyle(el).position !== 'sticky') return; // scrolls with the page: no offset
            var box = el.getBoundingClientRect();
            if (box.width > 0 && box.height > 0) height = Math.max(height, box.height);
        });
        scroller.style.setProperty('--sticky-top', height + 'px');
        if (window.updateStickyHeaders) window.updateStickyHeaders();
    }

    function schedule() {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(measure);
    }

    // Stuck table headers: .is-stuck on a .data-table while its sticky header row is pinned
    // under the tabs with rows scrolled beneath it (admin-theme.css adds a soft shadow).
    // Global, so a page that swaps a table in (live search) can call it again.
    window.updateStickyHeaders = function () {
        var line = scroller.getBoundingClientRect().top + (parseFloat(scroller.style.getPropertyValue('--sticky-top')) || 0);
        Array.prototype.forEach.call(document.querySelectorAll('.data-table'), function (table) {
            var head = table.tHead;
            var sticky = head && getComputedStyle(head).position === 'sticky';
            var box = table.getBoundingClientRect();
            table.classList.toggle('is-stuck', !!sticky && box.top < line - 0.5 && box.bottom > line);
        });
        // Reorder Alerts' card list (partials/_reorder_board.php): same rule for its sticky
        // .ra-head column header row (reorder-alerts.css turns it white while stuck).
        Array.prototype.forEach.call(document.querySelectorAll('.ra-items'), function (list) {
            var box = list.getBoundingClientRect();
            list.classList.toggle('is-stuck', box.height > 0 && box.top < line - 0.5 && box.bottom > line);
        });
    };

    var stuckFrame = 0;
    scroller.addEventListener('scroll', function () {
        cancelAnimationFrame(stuckFrame);
        stuckFrame = requestAnimationFrame(window.updateStickyHeaders);
    }, { passive: true });

    measure();
    window.updateStickyHeaders();
    window.addEventListener('resize', schedule);
    window.addEventListener('load', schedule);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedule);

    if (typeof ResizeObserver !== 'undefined') {
        var observer = new ResizeObserver(schedule);
        bars().forEach(function (el) { observer.observe(el); });
        // Sidebar collapse changes the content width, which can re-wrap the bar.
        observer.observe(scroller);
    }
})();
