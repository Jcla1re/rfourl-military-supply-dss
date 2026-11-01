// Sticky page tabs + table headers share one offset: the table header must stick
// directly under the tab bar, so it needs the tab bar's real height.
//
// Measures the visible .sticky-tabs bars (page-level tab bars; not tabs inside
// modals) and publishes the tallest as --sticky-top on .main-scroll (0 when the
// page has none). admin-theme.css reads it for `thead` and for sticky side panels.
// Re-measured whenever a bar resizes (window width, wrapping, font load).
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
