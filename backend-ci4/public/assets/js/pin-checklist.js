/* public/assets/js/pin-checklist.js
 * The live checklist under a new checkout PIN (app/Views/partials/_pin_checklist.php): "4 digits",
 * "Not easy to guess", "Both PINs match". Each item is neutral, met (green tick) or broken (red cross).
 * PIN boxes marked data-pin-input keep digits only. When the checklist names a password box and a
 * Save button, Save stays disabled until all three items are met and the password is typed.
 *
 * PinRules.weak() mirrors App\Libraries\PosPin::weakReason() exactly (the common-PIN list comes from
 * the server, data-common); the server still makes the final check. Nothing is stored anywhere.
 */
(function () {
    var PinRules = {
        /** True when a 4-digit PIN is refused by the server's rules. */
        weak: function (pin, common, phoneLast4) {
            if (!/^\d{4}$/.test(pin)) return true;
            var d = pin.split('').map(Number);
            var steps = [(d[1] - d[0] + 10) % 10, (d[2] - d[1] + 10) % 10, (d[3] - d[2] + 10) % 10];
            var same = function (n) { return steps[0] === n && steps[1] === n && steps[2] === n; };
            return (d[0] === d[1] && d[1] === d[2] && d[2] === d[3])     // 0000, 7777
                || same(1) || same(9)                                      // 1234, 4321, 7890, 0987
                || (d[0] === d[2] && d[1] === d[3])                        // 1212, 4545
                || (d[0] === d[1] && d[2] === d[3])                        // 1122, 7788
                || /^(19|20)\d\d$/.test(pin)                               // a year
                || common.indexOf(pin) !== -1                              // a common PIN
                || (phoneLast4 !== '' && pin === phoneLast4);              // the own contact number
        }
    };
    window.PinRules = PinRules;

    var LABELS = { met: 'done', broken: 'not met', '': '' };

    function setState(item, state) {
        item.classList.toggle('is-met', state === 'met');
        item.classList.toggle('is-broken', state === 'broken');
        var sr = item.querySelector('.pin-check-state');
        if (sr) sr.textContent = LABELS[state] ? ' (' + LABELS[state] + ')' : '';
    }

    function wire(list) {
        var pin = document.getElementById(list.dataset.pin);
        var repeat = document.getElementById(list.dataset.repeat);
        if (!pin || !repeat) return;
        var password = list.dataset.password ? document.getElementById(list.dataset.password) : null;
        var save = list.dataset.save ? document.getElementById(list.dataset.save) : null;
        var common = [];
        try { common = JSON.parse(list.dataset.common || '[]'); } catch (e) { common = []; }
        var phoneLast4 = list.dataset.phoneLast4 || '';
        var item = function (name) { return list.querySelector('[data-check="' + name + '"]'); };

        function update() {
            var a = pin.value, b = repeat.value;
            var four = /^\d{4}$/.test(a);
            var strong = four && !PinRules.weak(a, common, phoneLast4);
            var match = four && /^\d{4}$/.test(b) && a === b;

            setState(item('digits'), four ? 'met' : '');
            setState(item('strong'), !four ? '' : (strong ? 'met' : 'broken'));
            setState(item('match'), /^\d{4}$/.test(b) ? (match ? 'met' : 'broken') : '');

            if (save && !save.hasAttribute('data-demo-locked')) {
                var busy = save.form && save.form.hasAttribute('aria-busy');
                if (!busy) save.disabled = !(four && strong && match && password && password.value !== '');
            }
        }

        [pin, repeat].forEach(function (input) {
            input.addEventListener('input', function () {
                if (input.hasAttribute('data-pin-input')) {
                    var digits = input.value.replace(/\D/g, '').slice(0, 4);
                    if (input.value !== digits) input.value = digits;
                }
                // A message from the server goes away once its box is edited.
                if (input.getAttribute('aria-invalid') === 'true') {
                    input.removeAttribute('aria-invalid');
                    var err = document.getElementById(input.id + 'Err');
                    if (err) err.remove();
                }
                update();
            });
        });
        if (password) {
            password.addEventListener('input', function () {
                if (password.getAttribute('aria-invalid') === 'true') {
                    password.removeAttribute('aria-invalid');
                    var err = document.getElementById(password.id + 'Err');
                    if (err) err.remove();
                }
                update();
            });
        }
        // Back button / restored page: start empty again.
        window.addEventListener('pageshow', function () {
            pin.value = ''; repeat.value = ''; if (password) password.value = '';
            update();
        });
        update();
    }

    function init() { document.querySelectorAll('[data-pin-checklist]').forEach(wire); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
