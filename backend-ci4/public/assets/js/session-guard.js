/* public/assets/js/session-guard.js
 *
 * Loaded in the <head> of all three layouts, before any page script. Two jobs:
 *
 * 1. App.fetch(url, options, { background }): the one way this app's pages make background requests.
 *    Like fetch(), plus:
 *  - It always says it is a background request (X-Requested-With), so a signed-out answer is short
 *    JSON from the server (App\Libraries\SignedOut), never the login page's HTML.
 *  - Signed out (401 {"signedOut": true}, or a redirect): the page goes to the login page, which then
 *    returns here after signing in. The promise never settles, so the caller shows nothing more
 *    (no error toast, no login HTML pasted into the page, no sale sent).
 *  - Expired CSRF token (419 {"csrfExpired": true}): the page's token fields get a fresh token
 *    (session/csrf) and a "This page needed a refresh" toast says to click again. Nothing is sent
 *    again automatically. The promise rejects with { handled: true } so the caller can reset itself.
 *  - { background: true } marks a poll that is not the user doing anything (X-Background-Poll): it
 *    does not count as activity for the idle sign-out. Only the DSS status poll uses it.
 *
 * 2. The idle watcher: a page left open leaves for the login page when the server's idle sign-out
 *    has happened, in every open tab.
 *  - Activity is only what reaches the server: this page being served, and App.fetch requests that
 *    are not background polls. Never mouse or keyboard. The latest one is shared between tabs
 *    (localStorage "rfl.session.activity").
 *  - Every 30 seconds, and when the page is shown again (visibilitychange, focus, pageshow: e.g.
 *    a laptop waking up), the deadline is worked out here, with no request. Only near the deadline
 *    is the server asked (GET session/status with X-Background-Poll, so asking never counts as
 *    activity). That is the only request a timer ever makes.
 *  - Still signed in (another tab was active): the deadline moves; nothing else happens.
 *  - Signed out: "session:leaving" fires (the POS saves its cart), the other tabs are told
 *    (BroadcastChannel, or localStorage "rfl.session.signal" where there is none), the page is
 *    hidden and replaced by the login page (reason=idle and the page to return to).
 *  - The logout button: the other tabs go to their login page with no idle notice, and the saved
 *    POS cart is deleted before the form is sent.
 *  - It never reloads a page, sends a form again, or keeps the session alive.
 *
 * window.APP_SESSION (the layout, App\Libraries\SignedOut::pageConfig):
 *   { role, userId, idleSeconds, testMode, loginUrl, statusUrl, csrfUrl }
 */
(function () {
    'use strict';

    var cfg = window.APP_SESSION || {};
    var KEYS = { activity: 'rfl.session.activity', signal: 'rfl.session.signal', cart: 'rfl.pos.cart' };
    var CHECK_EVERY = 30000; // ms: the local check; the server is asked only near the deadline
    var idleMs = (parseInt(cfg.idleSeconds, 10) || 0) * 1000;
    var leaving = false;
    var leftTo = null;
    var asking = false;
    var soon = null;
    var lastActivity = 0;
    var channel = null;
    try { if (typeof BroadcastChannel === 'function') channel = new BroadcastChannel('rfl-session'); } catch (e) { channel = null; }

    // ------------------------------------------------------------------ localStorage (never fatal)

    function read(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
    function write(key, value) {
        try {
            if (value === null) window.localStorage.removeItem(key); else window.localStorage.setItem(key, value);
        } catch (e) { /* private mode, storage full: the watcher still works in this tab */ }
    }

    // ------------------------------------------------------------------ activity and the deadline

    function sharedActivity() {
        var at = parseInt(read(KEYS.activity), 10);
        return isNaN(at) ? 0 : at;
    }

    /** A request reached the server just now (it counted as activity there). */
    function noteActivity() {
        var now = Date.now();
        lastActivity = now;
        if (now > sharedActivity()) write(KEYS.activity, String(now));
    }

    /** The server's own count (session/status): authoritative, for this tab and the others. */
    function serverActivity(secondsLeft) {
        var at = Date.now() - (idleMs - Math.max(0, secondsLeft) * 1000);
        lastActivity = at;
        write(KEYS.activity, String(at));
    }

    function msLeft() {
        return Math.max(lastActivity, sharedActivity()) + idleMs - Date.now();
    }

    // ------------------------------------------------------------------ leaving

    function loginUrlFor(reason, loginUrl) {
        var url = loginUrl || cfg.loginUrl || '/';
        var params = [];
        if (reason === 'idle') params.push('reason=idle');
        if (reason !== 'logout') params.push('return=' + encodeURIComponent(location.pathname + location.search));
        return params.length ? url + (url.indexOf('?') === -1 ? '?' : '&') + params.join('&') : url;
    }

    /** Nothing from the old page stays on screen while the login page loads. */
    function hidePage() {
        try {
            var style = document.createElement('style');
            style.textContent = 'html.session-left body{visibility:hidden!important}';
            (document.head || document.documentElement).appendChild(style);
            document.documentElement.classList.add('session-left');
        } catch (e) { /* the page is replaced next anyway */ }
    }

    function announce(reason) {
        try { window.dispatchEvent(new CustomEvent('session:leaving', { detail: { reason: reason } })); } catch (e) { /* old browser */ }
    }

    /** Tells the other tabs of this browser (they share the session cookie). */
    function tell(message) {
        message.at = Date.now();
        if (channel) {
            try { channel.postMessage(message); return; } catch (e) { /* fall back to storage */ }
        }
        write(KEYS.signal, JSON.stringify(message));
        write(KEYS.signal, null);
    }

    /**
     * Leaves for the login page. reason: 'idle' (the notice shows), 'logout' (no notice, no return
     * page), or another signed-out reason. fromOtherTab: told by another tab, so not told again.
     */
    function leave(reason, loginUrl, fromOtherTab) {
        if (leaving) return;
        leaving = true;
        clearTimeout(soon);
        announce(reason);
        // "Signed in, but not here" (another role, or a password to set) is this tab's business only.
        if (!fromOtherTab && reason !== 'no_access' && reason !== 'set_password') {
            tell({ type: reason === 'logout' ? 'logout' : 'signed-out', reason: reason });
        }
        hidePage();
        leftTo = loginUrlFor(reason, loginUrl);
        location.replace(leftTo);
    }

    function heard(message) {
        if (!message || leaving || !idleMs) return;
        if (message.type === 'logout') leave('logout', null, true);
        else if (message.type === 'signed-out') leave(message.reason || 'signed_out', null, true);
    }

    // ------------------------------------------------------------------ the check

    function askServer() {
        if (asking || leaving || !cfg.statusUrl) return;
        asking = true;
        fetch(cfg.statusUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Background-Poll': '1', Accept: 'application/json' },
            credentials: 'same-origin', cache: 'no-store', redirect: 'manual'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (status) {
                asking = false;
                if (!status || leaving) return;
                if (status.signedIn) {
                    serverActivity(Number(status.secondsLeft) || 0);
                    // Close to the end: one more look right after it, instead of up to 30 s late.
                    if (Number(status.secondsLeft) * 1000 <= CHECK_EVERY) {
                        clearTimeout(soon);
                        soon = setTimeout(check, Number(status.secondsLeft) * 1000 + 1000);
                    }
                    return;
                }
                leave(status.reason === 'idle' ? 'idle' : (status.reason || 'signed_out'), status.login);
            }, function () {
                asking = false; // offline or the server is down: asked again at the next check
            });
    }

    /** Local only; the server is asked only when the deadline is near or past. */
    function check() {
        if (leaving || !idleMs) return;
        if (msLeft() <= CHECK_EVERY) askServer();
    }

    // ------------------------------------------------------------------ App.fetch

    function goToLogin(loginUrl, reason) {
        leave(reason || 'signed_out', loginUrl);
    }

    function never() { return new Promise(function () {}); }

    function refreshToken(data) {
        var toast = function () {
            if (typeof showToast === 'function') {
                showToast({ type: 'info', title: (data && data.title) || 'This page needed a refresh', message: (data && data.message) || 'Nothing was saved. Please click the button again.' });
            }
        };
        return fetch(cfg.csrfUrl || '/session/csrf', { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status === 401 || (res.body && res.body.signedOut)) { goToLogin(res.body && res.body.login, res.body && res.body.reason); return; }
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
        var background = !!(opts && opts.background);
        headers.set('X-Requested-With', 'XMLHttpRequest');
        if (background) headers.set('X-Background-Poll', '1');
        options.headers = headers;

        return fetch(url, options).then(function (response) {
            // A redirect only happens here when the server sent this request to the sign-in page.
            if (response.type === 'opaqueredirect') { goToLogin(); return never(); }
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                return response.clone().json().then(function (data) {
                    if (data && (data.signedOut || data.reason === 'no_access' || data.reason === 'set_password')) { goToLogin(data.login, data.reason); return never(); }
                    if (data && data.csrfExpired) {
                        return refreshToken(data).then(function () { throw { handled: true, csrfExpired: true }; });
                    }
                    return response;
                }, function () { return response; });
            }
            // It reached the server as the user's own request: the idle clock there started again.
            if (!background) noteActivity();
            return response;
        });
    }

    window.App = Object.assign(window.App || {}, { fetch: appFetch, goToLogin: goToLogin, refreshToken: refreshToken, CART_KEY: KEYS.cart });

    // ------------------------------------------------------------------ start (signed-in pages only)

    if (!idleMs) return;

    noteActivity(); // this page was just served, which the server counted as activity

    if (channel) channel.onmessage = function (e) { heard(e.data); };
    window.addEventListener('storage', function (e) {
        if (e.key === KEYS.signal && e.newValue) {
            try { heard(JSON.parse(e.newValue)); } catch (err) { /* not ours */ }
        }
    });

    setInterval(check, CHECK_EVERY);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') check(); });
    window.addEventListener('focus', check);
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        // Shown again from the browser's back/forward cache: never the old page after leaving it,
        // and otherwise the server says whether this session is still signed in.
        if (leftTo) { hidePage(); location.replace(leftTo); return; }
        askServer();
    });

    // The logout button: after every other submit handler, and only if nobody cancelled it.
    document.addEventListener('DOMContentLoaded', function () {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.hasAttribute || !form.hasAttribute('data-logout') || e.defaultPrevented) return;
            leaving = true; // this tab is on its way out; no more checks
            clearTimeout(soon);
            announce('logout');
            write(KEYS.cart, null);
            tell({ type: 'logout' });
        });
    });
})();
