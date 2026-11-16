/* public/assets/js/auth-forms.js
 *
 * The Forgot password pages and "Set your password" (app/Views/auth/*, staff/set_password.php):
 *  - [data-otp-boxes]: six one-digit boxes. Typing moves to the next box, Backspace to the one
 *    before, and pasting (or the phone's one-time-code autofill) fills all six.
 *  - [data-resend]: "Didn't get it? Resend in 0:60" counts down, then shows the Resend button. The
 *    server enforces the same wait, so this is only what the person sees.
 *  - [data-pw-rules-for]: the rule checks under a new password tick green while typing. Rules the
 *    server checks on save (class is-server) are only shown.
 *  - [data-busy-label] forms and the Resend form: the button is disabled once clicked (the answer
 *    can take a few seconds), so it can't be sent twice.
 * Everything still works without this file: the server checks all of it again.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------ six code boxes
    document.querySelectorAll('[data-otp-boxes]').forEach(function (group) {
        var boxes = Array.prototype.slice.call(group.querySelectorAll('input'));

        function fill(from, digits) {
            for (var i = 0; i < digits.length && from + i < boxes.length; i++) {
                boxes[from + i].value = digits.charAt(i);
            }
            var next = Math.min(from + digits.length, boxes.length - 1);
            boxes[next].focus();
        }

        boxes.forEach(function (box, index) {
            box.addEventListener('input', function () {
                var digits = box.value.replace(/\D/g, '');
                if (digits.length > 1) {          // autofill or a fast typist: spread them out
                    fill(index, digits);
                    return;
                }
                box.value = digits;
                if (digits && index < boxes.length - 1) boxes[index + 1].focus();
            });
            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !box.value && index > 0) {
                    boxes[index - 1].value = '';
                    boxes[index - 1].focus();
                    e.preventDefault();
                } else if (e.key === 'ArrowLeft' && index > 0) {
                    boxes[index - 1].focus();
                } else if (e.key === 'ArrowRight' && index < boxes.length - 1) {
                    boxes[index + 1].focus();
                }
            });
            box.addEventListener('paste', function (e) {
                var text = (e.clipboardData || window.clipboardData).getData('text') || '';
                var digits = text.replace(/\D/g, '').slice(0, boxes.length);
                if (!digits) return;
                e.preventDefault();
                fill(digits.length === boxes.length ? 0 : index, digits);
            });
        });
    });

    // ------------------------------------------------------------------ resend countdown
    document.querySelectorAll('[data-resend]').forEach(function (form) {
        var seconds = parseInt(form.getAttribute('data-seconds'), 10) || 0;
        var wait = form.querySelector('[data-resend-wait]');
        var ready = form.querySelector('[data-resend-ready]');
        var clock = form.querySelector('[data-resend-clock]');

        function show() {
            if (seconds > 0) {
                clock.textContent = '0:' + (seconds < 10 ? '0' : '') + seconds;
                wait.hidden = false;
                ready.hidden = true;
            } else {
                wait.hidden = true;
                ready.hidden = false;
            }
        }
        (function tick() {
            show();
            if (seconds <= 0) return;
            seconds--;
            setTimeout(tick, 1000);
        })();

        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type=submit]');
            if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
        });
    });

    // ------------------------------------------------------------------ live password rules
    document.querySelectorAll('[data-pw-rules-for]').forEach(function (input) {
        var list = document.getElementById(input.getAttribute('data-pw-rules-for'));
        if (!list) return;
        var checks = {
            'length': function (v) { return v.length >= 8; },
            'letter-number': function (v) { return /[A-Za-z]/.test(v) && /\d/.test(v); }
        };
        function update() {
            Object.keys(checks).forEach(function (rule) {
                var item = list.querySelector('[data-rule="' + rule + '"]');
                if (item) item.classList.toggle('is-met', checks[rule](input.value));
            });
        }
        input.addEventListener('input', update);
        update();
    });

    // ------------------------------------------------------------------ no double send
    document.querySelectorAll('form[data-busy-label]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type=submit]');
            if (!btn) return;
            // After this tick, so the button's own value is still sent with the form.
            setTimeout(function () {
                btn.disabled = true;
                btn.innerHTML = form.getAttribute('data-busy-label');
            }, 0);
        });
    });
})();
