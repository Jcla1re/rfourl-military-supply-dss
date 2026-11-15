<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;
use Config\Auth;

/**
 * What happens when a request finds the user signed out, shared by RoleAuthFilter, CsrfFilter and
 * SessionController so they always agree.
 *
 *  - A background request (fetch / AJAX: X-Requested-With, or Accept: application/json) gets a short
 *    JSON answer, never the login page HTML: 401 {"signedOut": true, "reason": ..., "login": ...}.
 *    The page's App.fetch (public/assets/js/session-guard.js) then goes to the login page.
 *  - A normal page request is redirected to that role's login page as before; a GET page (or, for a
 *    form post, the page the form was on) is kept as the page to return to after signing in.
 *
 * On an idle sign-out everything in the session is cleared except: the framework's own
 * __ci_last_regenerate, AttemptStore's attempt_* counters (sign-in lockout, only used when the cache
 * is unavailable) and what the login page needs: signed_out_reason ('idle') and return_to.
 */
final class SignedOut
{
    /** Session keys kept by clear(): see the class comment. */
    private const KEEP = ['__ci_last_regenerate'];

    public static function idleLimit(): int
    {
        return config(Auth::class)->idleLimit();
    }

    /** Signed in, but the idle limit has passed since the last activity. */
    public static function isIdleExpired(?Session $session = null): bool
    {
        $session ??= session();
        $limit = self::idleLimit();
        $last  = (int) $session->get('last_activity');

        return $session->get('isLoggedIn') && $limit > 0 && $last > 0 && (time() - $last) > $limit;
    }

    /** Seconds left before the idle sign-out (0 when past it). */
    public static function secondsLeft(?Session $session = null): int
    {
        $session ??= session();

        return max(0, self::idleLimit() - (time() - (int) $session->get('last_activity')));
    }

    /**
     * Empties the session (see the class comment for the few keys kept) and records why, so the
     * login page can say so. Used for every sign-out a request finds (idle, deactivated, locked).
     */
    public static function clear(string $reason, ?Session $session = null): void
    {
        $session ??= session();
        $remove = [];
        foreach (array_keys($_SESSION ?? []) as $key) {
            if (! in_array($key, self::KEEP, true) && ! str_starts_with((string) $key, 'attempt_')) {
                $remove[] = $key;
            }
        }
        if ($remove !== []) {
            $session->remove($remove);
        }
        $session->set('signed_out_reason', $reason);
    }

    /** A fetch / AJAX request: answered with JSON, never with an HTML page. */
    public static function isBackground(RequestInterface $request): bool
    {
        if ($request instanceof IncomingRequest && $request->isAJAX()) {
            return true;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    /** "/login/staff" for Staff, and so on (the Owner's portal is /login/admin). */
    public static function loginPath(?string $role): string
    {
        $segment = strtolower((string) $role);

        return '/login/' . (in_array($segment, ['admin', 'staff', 'supplier'], true) ? $segment : 'admin');
    }

    /**
     * The answer for a signed-out request to a $role area: 401 JSON for a background request, else a
     * redirect to the login page (with $message as its error, if any), keeping the page to return to.
     *
     * @param int $status 401 signed out; 403 for "signed in, but not as this role"
     */
    public static function respond(RequestInterface $request, ?string $role, string $reason, ?string $message = null, int $status = 401): ResponseInterface
    {
        $login = self::loginPath($role);

        if (self::isBackground($request)) {
            return service('response')
                ->setStatusCode($status)
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON(['signedOut' => $status === 401, 'reason' => $reason, 'message' => $message, 'login' => site_url(ltrim($login, '/'))]);
        }

        // The page to come back to: the page asked for (GET), or the page the form was posted from.
        $method = strtoupper($request->getMethod());
        $target = $method === 'GET'
            ? '/' . ltrim($request->getUri()->getPath(), '/') . (($q = $request->getUri()->getQuery()) !== '' ? '?' . $q : '')
            : $request->getHeaderLine('Referer');
        if (($return = ReturnTo::clean($target, $role)) !== null) {
            session()->set('return_to', $return);
        }

        $redirect = redirect()->to($login);

        return $message !== null ? $redirect->with('error', $message) : $redirect;
    }
}
