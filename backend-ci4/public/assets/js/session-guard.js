/* public/assets/js/session-guard.js
 *
 * App.fetch(url, options, { background }): the one way this app's pages make background requests
 * (loaded in the <head> of all three layouts, before any page script). Like fetch(), plus:
 *
 *  - It always says it is a background request (X-Requested-With), so a signed-out answer is short
 *    JSON from the server (App\Libraries\SignedOut), never the login page's HTML.
 *  - Signed out (401 {"signedOut": true}, or a redirect): the page goes to the login page, which then
 *    returns here after signing in. The promise never settles, so the caller shows nothing more
 *    (no error toast, no login HTML pasted into the page, no sale sent).
 *  - Expired CSRF token (419 {"csrfExpired": true}): the page's token fields get a fresh token
 *    (session/csrf) and a "This page needed a refresh" toast says to click again. Nothing is sent
 *    again automatically. The promise rejects with { handled: true } so the caller can reset itself.
 *  - { background: true } marks a poll that is not the user doing anything (X-Background-Poll): it
 *    does not count as activity for the 30-minute idle sign-out. Only the DSS status poll uses it.
 *
 * window.APP_SESSION (from the layout): { loginUrl, csrfUrl }.
 */
(function () {
    'use strict';

    var cfg = window.APP_SESSION || {};
    var leaving = false;

    function never() { return new Promise(function () {}); }

    function goToLogin(loginUrl) {
        if (leaving) return;
        leaving = true;
        var url = loginUrl || cfg.loginUrl || '/';
        var here = location.pathname + location.search;
        location.assign(url + (url.indexOf('?') === -1 ? '?' : '&') + 'return=' + encodeURIComponent(here));
    }

    function refreshToken(data) {
        var toast = function () {
            if (typeof showToast === 'function') {
                showToast({ type: 'info', title: (data && data.title) || 'This page needed a refresh', message: (data && data.message) || 'Nothing was saved. Please click the button again.' });
            }
        };
        return fetch(cfg.csrfUrl || '/session/csrf', { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status === 401 || (res.body && res.body.signedOut)) { goToLogin(res.body && res.body.login); return; }
                var t = res.body || {};
                if (t.name && t.token) {
                    document.querySelectorAll('input[name="' + t.name + '"]').forEach(function (input) { input.value = t.token; });
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    if (meta) meta.setAttribute('content', t.token);
                }
                toast();
            })
            .catch(toast);
    }

    function appFetch(url, options, opts) {
        options = Object.assign({ credentials: 'same-origin', redirect: 'manual' }, options || {});
        var headers = new Headers(options.headers || {});
        headers.set('X-Requested-With', 'XMLHttpRequest');
        if (opts && opts.background) headers.set('X-Background-Poll', '1');
        options.headers = headers;

        return fetch(url, options).then(function (response) {
            // A redirect only happens here when the server sent this request to the sign-in page.
            if (response.type === 'opaqueredirect') { goToLogin(); return never(); }
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                return response.clone().json().then(function (data) {
                    if (data && (data.signedOut || data.reason === 'no_access' || data.reason === 'set_password')) { goToLogin(data.login); return never(); }
                    if (data && data.csrfExpired) {
                        return refreshToken(data).then(function () { throw { handled: true, csrfExpired: true }; });
                    }
                    return response;
                }, function () { return response; });
            }
            return response;
        });
    }

    window.App = Object.assign(window.App || {}, { fetch: appFetch, goToLogin: goToLogin, refreshToken: refreshToken });
})();
