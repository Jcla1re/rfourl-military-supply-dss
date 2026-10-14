/* public/assets/js/toast-timer.js
 *
 * Shared toast component (admin, staff and supplier layouts).
 *
 *   [icon]  Title                       [Undo] [×]
 *           "<name>" message
 *   ▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔ countdown bar
 *
 * - Success toasts hide after a few seconds; a thin bar counts down, and
 *   hovering or keyboard focus pauses it (resuming where it stopped).
 * - Error toasts stay until dismissed.
 * - × or Esc dismisses (Esc is left to any open dialog first).
 *
 * Server toasts come from app/Views/partials/_toast.php. Older pages that
 * still flash a plain 'success' / 'error' string render .alert-success /
 * .alert-danger; those are converted into the same component here.
 */

function toastEscapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

function dismissToast(el) {
    if (!el || el.classList.contains('toast-hide')) return;
    clearTimeout(el._toastTimer);
    el.classList.add('toast-hide');
    setTimeout(() => el.remove(), 400);
}

/** Countdown + auto-hide, paused while hovered or focused. */
function startToastTimer(el, duration = 4000) {
    el.style.setProperty('--toast-duration', duration + 'ms');

    const bar = document.createElement('span');
    bar.className = 'toast-timer';
    bar.setAttribute('aria-hidden', 'true');
    el.appendChild(bar);

    let remaining = duration;
    let startedAt = 0;

    const start = () => {
        clearTimeout(el._toastTimer);
        el.classList.remove('is-paused');
        startedAt = Date.now();
        el._toastTimer = setTimeout(() => dismissToast(el), remaining);
    };
    const pause = () => {
        if (el.classList.contains('is-paused')) return;
        clearTimeout(el._toastTimer);
        remaining = Math.max(0, remaining - (Date.now() - startedAt));
        el.classList.add('is-paused');
    };

    el.addEventListener('mouseenter', pause);
    el.addEventListener('mouseleave', start);
    el.addEventListener('focusin', pause);   // e.g. tabbing to Undo or ×
    el.addEventListener('focusout', start);
    start();
}

/** Wires up one .app-toast: × button and (for success) the countdown. */
function initToast(el) {
    if (el._toastReady) return;
    el._toastReady = true;
    el.querySelector('.app-toast-close')?.addEventListener('click', () => dismissToast(el));
    const autohide = parseInt(el.dataset.autohide ?? '4000', 10);
    if (autohide > 0) startToastTimer(el, autohide);
}

/** Builds a toast in the browser (e.g. a failed receipt print). */
function showToast({ type = 'success', title, message = '', name = '' }) {
    const isError = type === 'error';
    const el = document.createElement('div');
    el.className = 'app-toast ' + (isError ? 'is-error' : 'is-success');
    el.dataset.autohide = isError ? '0' : '4000';
    el.setAttribute('role', isError ? 'alert' : 'status');
    el.setAttribute('aria-live', isError ? 'assertive' : 'polite');
    const msg = (name ? `&ldquo;<strong>${toastEscapeHtml(name)}</strong>&rdquo; ` : '') + toastEscapeHtml(message);
    el.innerHTML = `
        <div class="toast-icon ${isError ? 'error' : 'success'}" aria-hidden="true"><i class="bi ${isError ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill'}"></i></div>
        <div class="app-toast-body"><div class="toast-title">${toastEscapeHtml(title)}</div>${msg ? `<div class="toast-msg">${msg}</div>` : ''}</div>
        <button type="button" class="app-toast-close" aria-label="Dismiss"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`;
    document.body.appendChild(el);
    initToast(el);
    return el;
}

/** Older call sites: showClientToast('success' | 'error', message). */
function showClientToast(type, message) {
    return type === 'success'
        ? showToast({ type, title: message })
        : showToast({ type: 'error', title: 'Something went wrong', message });
}

document.addEventListener('DOMContentLoaded', () => {
    // Plain-text flash messages from older pages → the same component.
    // A success message becomes the title; an error keeps its reason below.
    document.querySelectorAll('.alert-success, .toast-success').forEach(el => {
        const text = el.textContent.trim();
        if (text) showToast({ type: 'success', title: text });
        el.remove();
    });
    document.querySelectorAll('.alert-danger').forEach(el => {
        const text = el.textContent.trim();
        if (text) showToast({ type: 'error', title: 'Something went wrong', message: text });
        el.remove();
    });

    document.querySelectorAll('.app-toast').forEach(initToast);
});

// Esc dismisses the newest toast, unless a dialog is open (Esc closes that first).
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (document.querySelector('.inv-modal.show, .order-modal.show, .modal.show')) return;
    const toasts = [...document.querySelectorAll('.app-toast:not(.toast-hide)')];
    if (toasts.length) dismissToast(toasts[toasts.length - 1]);
});
