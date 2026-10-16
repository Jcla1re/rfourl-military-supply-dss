/* public/assets/js/toast-timer.js
 *
 * Shared toast component (admin, staff and supplier layouts).
 *
 *   [icon]  Title                       [Undo] [×]
 *           "<name>" message
 *   ▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔ countdown bar
 *
 * - Types: success, info (neutral, Deep Olive icon) and error.
 * - Success and info toasts hide after a few seconds; a thin bar counts
 *   down, and hovering or keyboard focus pauses it (resuming where it
 *   stopped). Error toasts, and toasts marked sticky, stay until dismissed.
 * - busy: a persistent "in progress" toast (e.g. "A recalculation is
 *   already running"): a spinner instead of the icon (a static hourglass
 *   with reduced motion), no countdown; usually replaced by its result via key.
 * - × or Esc dismisses (Esc is left to any open dialog first).
 * - Several toasts stack top-centre, newest on top, 8px apart (.toast-stack).
 * - A toast with a key (data-toast-key) is replaced in place by the next
 *   toast with the same key, e.g. "A recalculation is already running"
 *   becomes "Recalculated 672 items" when that run finishes.
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

/** The fixed container all toasts live in (created on first use). */
function toastStack() {
    let stack = document.querySelector('.toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
    }
    return stack;
}

/** Puts a toast in the stack: newest on top, or in place of a toast with the same key. */
function placeToast(el) {
    const key = el.dataset.toastKey;
    const old = key ? [...document.querySelectorAll('.toast-stack .app-toast')].find(t => t !== el && t.dataset.toastKey === key && !t.classList.contains('toast-hide')) : null;
    if (old) {
        clearTimeout(old._toastTimer);
        el.classList.add('toast-swap'); // same spot, no slide-in
        old.replaceWith(el);
    } else {
        toastStack().prepend(el);
    }
}

/** Dismisses the visible toast with this key, if any. */
function dismissToastByKey(key) {
    document.querySelectorAll('.toast-stack .app-toast').forEach(t => { if (t.dataset.toastKey === key) dismissToast(t); });
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

/** Wires up one .app-toast: stack position, × button and (unless it stays) the countdown. */
function initToast(el) {
    if (el._toastReady) return;
    el._toastReady = true;
    placeToast(el);
    el.querySelector('.app-toast-close')?.addEventListener('click', () => dismissToast(el));
    const autohide = parseInt(el.dataset.autohide ?? '4000', 10);
    if (autohide > 0) startToastTimer(el, autohide);
}

const TOAST_ICONS = { success: 'bi-check-circle-fill', info: 'bi-info-circle-fill', error: 'bi-exclamation-circle-fill' };

/**
 * Builds a toast in the browser (e.g. a failed receipt print).
 * key: replaces the visible toast with the same key. sticky: no auto-hide.
 * busy: in progress (spinner, no auto-hide).
 */
function showToast({ type = 'success', title, message = '', name = '', key = '', sticky = false, busy = false }) {
    if (!TOAST_ICONS[type]) type = 'success';
    const isError = type === 'error';
    const el = document.createElement('div');
    el.className = 'app-toast is-' + type;
    el.dataset.autohide = isError || sticky || busy ? '0' : '4000';
    if (key) el.dataset.toastKey = key;
    if (busy) { el.classList.add('is-busy'); el.setAttribute('aria-busy', 'true'); }
    el.setAttribute('role', isError ? 'alert' : 'status');
    el.setAttribute('aria-live', isError ? 'assertive' : 'polite');
    const msg = (name ? `&ldquo;<strong>${toastEscapeHtml(name)}</strong>&rdquo; ` : '') + toastEscapeHtml(message);
    el.innerHTML = `
        <div class="toast-icon ${type}" aria-hidden="true">${busy ? '<span class="toast-spinner"></span><i class="bi bi-hourglass-split toast-busy-static"></i>' : `<i class="bi ${TOAST_ICONS[type]}"></i>`}</div>
        <div class="app-toast-body"><div class="toast-title">${toastEscapeHtml(title)}</div>${msg ? `<div class="toast-msg">${msg}</div>` : ''}</div>
        <button type="button" class="app-toast-close" aria-label="Dismiss"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`;
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

// Esc dismisses the newest toast (top of the stack), unless a dialog is open (Esc closes that first).
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (document.querySelector('.inv-modal.show, .order-modal.show, .modal.show')) return;
    dismissToast(document.querySelector('.toast-stack .app-toast:not(.toast-hide)'));
});
