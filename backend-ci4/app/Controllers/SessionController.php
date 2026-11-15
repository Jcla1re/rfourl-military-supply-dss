<?php

namespace App\Controllers;

use App\Libraries\SignedOut;

/**
 * Two small JSON answers for the page's own scripts. Outside the role areas on purpose: neither one
 * ever counts as activity (last_activity is never written here), so asking can't keep anyone signed in.
 *
 *  GET session/status  {signedIn: true, role, secondsLeft} or {signedIn: false, reason, login}.
 *                      A session past the idle limit is signed out here exactly as RoleAuthFilter
 *                      would do it.
 *  GET session/csrf    a fresh CSRF token for a page whose token expired: {name, header, token}.
 *                      Only while signed in; only asked for right after a failed submit, never on a timer.
 */
class SessionController extends BaseController
{
    public function status()
    {
        $session = session();

        if ($session->get('isLoggedIn') && SignedOut::isIdleExpired($session)) {
            $role = $session->get('role');
            SignedOut::clear('idle', $session);

            return $this->json(['signedIn' => false, 'reason' => 'idle', 'login' => site_url(ltrim(SignedOut::loginPath($role), '/'))]);
        }
        if (! $session->get('isLoggedIn')) {
            return $this->json(['signedIn' => false, 'reason' => $session->get('signed_out_reason') ?? 'signed_out', 'login' => null]);
        }

        $answer = ['signedIn' => true, 'role' => $session->get('role'), 'secondsLeft' => SignedOut::secondsLeft($session)];
        $session->close(); // nothing to write: the clock is not touched

        return $this->json($answer);
    }

    public function csrf()
    {
        $session = session();

        if (! $session->get('isLoggedIn') || SignedOut::isIdleExpired($session)) {
            $role = $session->get('role');
            if ($session->get('isLoggedIn')) {
                SignedOut::clear('idle', $session);
            }

            return $this->json(['signedOut' => true, 'reason' => $session->get('signed_out_reason') ?? 'signed_out', 'login' => $role ? site_url(ltrim(SignedOut::loginPath($role), '/')) : null], 401);
        }
        $session->close();

        return $this->json(['name' => csrf_token(), 'header' => csrf_header(), 'token' => csrf_hash()]);
    }

    private function json(array $data, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')->setJSON($data);
    }
}
