/* public/assets/js/pw-toggle.js
 *
 * Show/hide password (eye) button, shared by every password field with a .pw-toggle
 * right after its input (sign-in panels, /login pages, set and reset password).
 *
 * - Empty field: the eye is hidden (auth-theme.css fades it via .is-visible) and not
 *   focusable; its space stays reserved, so the text never shifts.
 * - Any character, or a browser autofill: the eye fades in and is enabled, focusable
 *   and clickable. One function (sync) sets visibility and all of that together.
 * - Click: password <-> text, eye <-> eye-slash, aria-label "Show password" /
 *   "Hide password", aria-pressed false / true. It toggles even while the value is
 *   still unreadable: Chrome/Edge only expose an autofilled value after a user
 *   gesture, and the click is that gesture.
 * - Field cleared while the password is shown: back to hidden first, then the eye goes.
 * - State is re-checked on load, input/change, focus, back/forward navigation, the
 *   pw-autofill-start / -end animations auth-theme.css runs on :-webkit-autofill, and
 *   right before a click. While the eye is being pressed it is never hidden, so a
 *   momentary "empty" reading during the gesture can't swallow the click.
 */
(function () {
    function isAutofilled(input) {
        try { if (input.matches(':autofill')) return true; } catch (e) {}
        try { if (input.matches(':-webkit-autofill')) return true; } catch (e) {}
        return false;
    }

    function setShown(input, btn, shown) {
        input.type = shown ? 'text' : 'password';
        btn.querySelector('i').className = shown ? 'bi bi-eye-slash' : 'bi bi-eye';
        btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
    }

    function sync(input, btn, keepVisible) {
        const on = keepVisible || input.value !== '' || isAutofilled(input);
        if (!on && input.type !== 'password') setShown(input, btn, false);
        if (!on && document.activeElement === btn) input.focus();
        btn.classList.toggle('is-visible', on);
        btn.tabIndex = on ? 0 : -1;
        btn.disabled = false;
        btn.style.pointerEvents = '';
        return on;
    }

    // Every other password field (the app's own forms: change password, the Owner's Reset
    // Password dialog, confirmations…) gets the same eye: the field goes into a .pw-wrap with the
    // button right after it (pw-toggle.css). A field can opt out with data-no-pw-toggle.
    document.querySelectorAll('input[type="password"]:not([data-no-pw-toggle])').forEach(input => {
        const next = input.nextElementSibling;
        if (next && next.classList.contains('pw-toggle')) return; // already has one in its markup
        const wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.tabIndex = -1;
        btn.innerHTML = '<i class="bi bi-eye"></i>';
        wrap.appendChild(btn);
    });

    document.querySelectorAll('.pw-toggle').forEach(btn => {
        const input = btn.previousElementSibling;
        if (!input || input.tagName !== 'INPUT') return;
        let pressing = false;
        const update = () => sync(input, btn, pressing);
        const press = () => { pressing = true; update(); };
        // Pressed but no click (dragged off, cancelled): drop the hold after the click would have run.
        const release = () => setTimeout(() => { if (pressing) { pressing = false; update(); } }, 0);

        setShown(input, btn, false);
        btn.addEventListener('pointerdown', press);
        btn.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') press(); });
        window.addEventListener('pointerup', release);
        window.addEventListener('pointercancel', release);
        btn.addEventListener('keyup', release);
        btn.addEventListener('blur', release);
        btn.addEventListener('click', () => {
            sync(input, btn, true);
            setShown(input, btn, input.type === 'password');
            pressing = false;
            // The gesture has exposed the value by now; settle the state once more.
            setTimeout(update, 0);
        });
        input.addEventListener('input', update);
        input.addEventListener('change', update);
        input.addEventListener('focus', update);
        input.addEventListener('animationstart', e => {
            if (e.animationName.indexOf('pw-autofill') === 0) update();
        });
        window.addEventListener('pageshow', update);
        update();
        // Autofill can land a moment after load.
        setTimeout(update, 100);
        setTimeout(update, 600);
    });
})();
