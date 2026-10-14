/* public/assets/js/chart-reveal.js
 *
 * Left-to-right reveal for line charts (Dashboard "Weekly sales trend",
 * Trend Analysis "Items sold per month/day"). Use with
 * options.animation = false and plugins: [revealPlugin].
 *
 * The datasets (lines, dots, fill, dashed comparison line) are clipped to a
 * rectangle that widens from the left over ~1 s (easeOutQuart). Axes, grid
 * and legend are drawn outside beforeDatasetsDraw, so they show from the
 * start. chart.$reveal goes 0 → 1; plugins that should wait for the line to
 * finish (e.g. a "Peak" label) can check chart.$reveal === 1.
 * Plays once, on page load; reduced-motion users see the full chart.
 */
const revealPlugin = {
    id: 'reveal',
    beforeInit(chart) {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        chart.$reveal = reduce ? 1 : 0;
        if (reduce) return;
        const start = performance.now();
        const duration = 1000;
        const ease = t => 1 - Math.pow(1 - t, 4); // easeOutQuart
        const tick = now => {
            if (chart.$reveal === 1) return; // already finished (or forced to 1)
            const t = Math.min(1, (now - start) / duration);
            chart.$reveal = ease(t);
            if (chart.ctx) chart.draw();
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    },
    beforeDatasetsDraw(chart) {
        const { ctx, chartArea: a } = chart;
        ctx.save();
        ctx.beginPath();
        ctx.rect(a.left - 6, a.top - 30, (a.right - a.left + 12) * chart.$reveal, a.bottom - a.top + 36);
        ctx.clip();
    },
    afterDatasetsDraw(chart) {
        chart.ctx.restore();
    }
};

/** Skip (or finish) the reveal, e.g. when the chart's data is swapped. */
function revealNow(chart) {
    chart.$reveal = 1;
}
