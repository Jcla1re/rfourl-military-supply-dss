/* public/assets/js/pos-unlock.js
 * The Lock POS screen (app/Views/partials/_pos_locked.php): the PIN box keeps digits only, and Unlock
 * stays disabled until 4 digits are typed. Typing again after a refused unlock brings the hint back.
 * The server checks the PIN (App\Libraries\PosPin); nothing is stored here.
 */
(function () {
    var input = document.getElementById('unlockPin');
    var btn = document.getElementById('unlockBtn');
    var msg = document.getElementById('unlockPinMsg');
    if (!input || !btn) return;
    var form = input.form;

    function sync() {
        var digits = input.value.replace(/\D/g, '').slice(0, 4);
        if (input.value !== digits) input.value = digits;
        if (!(window.SubmitOnce && window.SubmitOnce.isBusy(form))) btn.disabled = digits.length !== 4;
    }

    input.addEventListener('input', function () {
        if (input.getAttribute('aria-invalid') === 'true' && msg) {
            input.removeAttribute('aria-invalid');
            msg.classList.remove('is-error');
            msg.removeAttribute('role');
            msg.textContent = msg.dataset.hint || '';
        }
        sync();
    });
    // Back button / restored page: empty again.
    window.addEventListener('pageshow', function () { input.value = ''; sync(); });
    sync();
})();
