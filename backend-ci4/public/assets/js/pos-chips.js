/* public/assets/js/pos-chips.js
 * The Point of Sale category chips (app/Views/partials/_pos_tab.php) stay on one line; the row scrolls
 * sideways. This shows the soft fade on a side that has more chips (is-scroll-left / is-scroll-right
 * on .pos-chips-wrap, styled in pos.css), and scrolls a chip fully into view when it is clicked or
 * reached with Tab. Filtering by category is pos.js; nothing here changes it.
 */
(function () {
    var wrap = document.getElementById('posChipsWrap');
    var row = wrap && wrap.querySelector('.pos-chips');
    if (!row) return;

    function update() {
        var max = row.scrollWidth - row.clientWidth;
        wrap.classList.toggle('is-scroll-left', row.scrollLeft > 1);
        wrap.classList.toggle('is-scroll-right', max - row.scrollLeft > 1);
    }

    function reveal(chip) {
        chip.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    row.addEventListener('scroll', update, { passive: true });
    // The width changes with the window, the sidebar (open/collapsed) and the cart column.
    if (window.ResizeObserver) {
        new ResizeObserver(update).observe(row);
    } else {
        window.addEventListener('resize', update);
    }
    row.addEventListener('click', function (e) {
        var chip = e.target.closest('button');
        if (chip) reveal(chip);
    });
    row.addEventListener('focusin', function (e) {
        if (e.target.matches('button')) reveal(e.target);
    });
    // Fonts change the chips' width once Poppins has loaded.
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(update);
    update();
})();
