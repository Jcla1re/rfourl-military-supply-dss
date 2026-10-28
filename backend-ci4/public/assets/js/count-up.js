function initCountUp(selector) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const elements = Array.from(document.querySelectorAll(selector))
        .map(element => ({
            element,
            target: Number(element.dataset.count),
            prefix: element.dataset.prefix || '',
            originalText: element.textContent
        }))
        .filter(item => Number.isFinite(item.target) && item.target > 0);

    if (elements.length === 0) return;

    const startTime = performance.now();
    const duration = 1000;
    const ease = t => 1 - Math.pow(1 - t, 4);
    const format = value => value.toLocaleString('en-PH', { maximumFractionDigits: 0 });

    function frame(now) {
        const progress = Math.min(1, Math.max(0, (now - startTime) / duration));
        const easedProgress = ease(progress);

        elements.forEach(({ element, target, prefix, originalText }) => {
            if (progress === 1) {
                element.textContent = originalText;
                return;
            }

            const value = Math.round(target * easedProgress);
            element.textContent = prefix + format(value);
        });

        if (progress < 1) requestAnimationFrame(frame);
    }

    requestAnimationFrame(frame);
}
