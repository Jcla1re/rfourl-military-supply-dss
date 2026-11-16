<?php

namespace App\Filters;

use App\Libraries\OnceToken;
use App\Libraries\ReturnTo;
use App\Libraries\SignedOut;
use App\Libraries\Toast;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * CodeIgniter's CSRF filter, unchanged in what it checks (parent::before(): the framework's own token
 * check). Only what happens when the check FAILS is different:
 *
 *  - logout               still signs out (an expired token must not trap anyone signed in);
 *  - signed out / idle    the same as any signed-out request (SignedOut): 401 JSON for fetch / AJAX,
 *                         else the login page, with the page to return to;
 *  - the sign-in form     back to /login: "This page needed a refresh. Please sign in again."
 *                         (the username is kept, never the password); a Forgot password step: back
 *                         to that step, "This page needed a refresh. Please try again.";
 *  - fetch / AJAX         419 JSON {"csrfExpired": true}: the page gets a fresh token (session/csrf);
 *  - any other form       back to the page it was on, everything typed kept (old input: never the
 *                         token, the one-time key or a password), with "This page needed a refresh.
 *                         Nothing was saved. Please click <button> again."
 *
 * Nothing is ever sent again automatically: the controller did not run, nothing was saved, and the
 * page shows a fresh token and one-time key for the user's own next click.
 *
 * Development (Security::$redirect off) throws on a failure and production redirects back; both are
 * caught here, so both behave the same.
 */
class CsrfFilter extends CSRF
{
    /** Posted by submit-once.js: the label of the button that was clicked. */
    public const LABEL_FIELD = '_submit_label';

    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            $result = parent::before($request, $arguments);
        } catch (SecurityException) {
            return $this->failed($request);
        }

        // parent::before() returns null when the token is good; anything else is its own
        // "redirect back" for a failed check (production).
        return $result === null ? null : $this->failed($request);
    }

    private function failed(RequestInterface $request)
    {
        $path    = trim(preg_replace('#^index\.php(/|$)#', '', trim($request->getUri()->getPath(), '/')), '/');
        $area    = explode('/', $path)[0];
        $session = session();

        if ($path === 'logout') {
            return null; // let AuthController::logout() sign out
        }

        $roles = ['admin' => 'Admin', 'staff' => 'Staff', 'supplier' => 'Supplier'];
        $role  = $roles[$area] ?? null;

        if ($role !== null && (! $session->get('isLoggedIn') || SignedOut::isIdleExpired($session))) {
            if ($session->get('isLoggedIn')) {
                SignedOut::clear('idle', $session);
            }
            $idle = $session->get('signed_out_reason') === 'idle';

            return SignedOut::respond($request, $role, $idle ? 'idle' : 'signed_out', $idle ? null : 'Please log in to continue.');
        }

        $message = 'Nothing was saved. Please click ' . $this->buttonLabel($request) . ' again.';

        if (SignedOut::isBackground($request)) {
            // 419: not a standard code, so CodeIgniter needs its reason phrase.
            return service('response')->setStatusCode(419, 'Page Expired')->setHeader('Cache-Control', 'no-store')
                ->setJSON(['csrfExpired' => true, 'title' => 'This page needed a refresh', 'message' => $message]);
        }

        $this->keepInput($request);

        if ($area === 'login' || $path === '') {
            // The sign-in form, or a Forgot password step: back to that page (old /login/{role} URLs: /login).
            if (str_starts_with($path . '/', 'login/forgot/')) {
                $back = in_array($path, ['login/forgot/check', 'login/forgot/resend'], true) ? '/login/forgot/check'
                    : ($path === 'login/forgot/new-password' ? '/login/forgot/new-password' : '/login/forgot');

                return redirect()->to($back)->with('error', 'This page needed a refresh. Please try again.');
            }

            return redirect()->to('/login')->with('error', 'This page needed a refresh. Please sign in again.');
        }

        $back = ReturnTo::clean($request->getHeaderLine('Referer'), $role)
            ?? ($role !== null ? ReturnTo::dashboard($role) : '/');

        return redirect()->to($back)->with('toast', Toast::info('This page needed a refresh', $message));
    }

    /** "Checkout & Print Receipt", from the button that was clicked; "the button" if not known. */
    private function buttonLabel(RequestInterface $request): string
    {
        $label = trim(preg_replace('/\s+/', ' ', strip_tags((string) $request->getPost(self::LABEL_FIELD))));

        return $label !== '' ? '"' . mb_substr($label, 0, 40) . '"' : 'the button';
    }

    /** Old input for the page to show again: never the token, the one-time key or a password. */
    private function keepInput(RequestInterface $request): void
    {
        $post = (array) $request->getPost();
        foreach (array_keys($post) as $key) {
            if ($key === config('Security')->tokenName || $key === OnceToken::FIELD || $key === self::LABEL_FIELD || stripos((string) $key, 'pass') !== false) {
                unset($post[$key]);
            }
        }
        session()->setFlashdata('_ci_old_input', ['get' => [], 'post' => $post]);
    }
}
