function initCountUp(selector) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const elements = Array.from(document.querySelectorAll(selector))
        .map(element => {
            const decimalsValue = Number(element.dataset.decimals ?? 0);
            return {
                element,
                target: Math.abs(Number(element.dataset.count)),
                decimals: Number.isInteger(decimalsValue) ? Math.min(20, Math.max(0, decimalsValue)) : 0,
                prefix: element.dataset.prefix || '',
                suffix: element.dataset.suffix || '',
                originalText: element.textContent
            };
        })
        .filter(item => Number.isFinite(item.target) && item.target > 0);

    if (elements.length === 0) return;

    const startTime = performance.now();
    const duration = 1000;
    const ease = t => 1 - Math.pow(1 - t, 4);
    function frame(now) {
        const progress = Math.min(1, Math.max(0, (now - startTime) / duration));
        const easedProgress = ease(progress);

        elements.forEach(({ element, target, decimals, prefix, suffix, originalText }) => {
            if (progress === 1) {
                element.textContent = originalText;
                return;
            }

            const value = target * easedProgress;
            const displayPrefix = Math.round(value * (10 ** decimals)) === 0 && (prefix === '-' || prefix === '+')
                ? ''
                : prefix;
            const formatted = value.toLocaleString('en-PH', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
            element.textContent = displayPrefix + formatted + suffix;
        });

        if (progress < 1) requestAnimationFrame(frame);
    }

    requestAnimationFrame(frame);
}
