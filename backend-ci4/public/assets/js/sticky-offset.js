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
    }

    function schedule() {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(measure);
    }

    measure();
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
