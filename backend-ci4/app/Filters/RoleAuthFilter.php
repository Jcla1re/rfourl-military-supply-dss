<?php

namespace App\Filters;

use App\Libraries\SignedOut;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Auth;

/**
 * Protects role-scoped route groups (admin/staff/supplier).
 * Usage in Routes.php: ['filter' => 'roleauth:Admin']
 *
 * A signed-out request (idle, never signed in, deactivated, locked, wrong role) is answered by
 * App\Libraries\SignedOut: 401 JSON for fetch / AJAX, a redirect to the login page for a normal page.
 */
class RoleAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $requiredRole = $arguments[0] ?? null;

        if (! $session->get('isLoggedIn')) {
            // After an idle sign-out the login page shows its own notice instead of an error.
            $idle = $session->get('signed_out_reason') === 'idle';

            return SignedOut::respond($request, $requiredRole, $idle ? 'idle' : 'signed_out', $idle ? null : 'Please log in to continue.');
        }

        // Idle timeout, enforced on the server (the cookie lifetime alone does
        // not stop a copied session ID from working). Every request refreshes
        // the clock, so only inactivity ends the session. Config\Auth::idleLimit().
        if (SignedOut::isIdleExpired($session)) {
            SignedOut::clear('idle', $session);

            return SignedOut::respond($request, $requiredRole, 'idle');
        }
        // A background poll (the DSS status, the session status check) is not the user doing
        // anything: checked above, but it does not move the clock.
        if ($request->getHeaderLine(Auth::BACKGROUND_HEADER) !== '1') {
            $session->set('last_activity', time());
        }

        if ($requiredRole && $session->get('role') !== $requiredRole) {
            return SignedOut::respond($request, $requiredRole, 'no_access', 'You do not have access to that area.', 403);
        }

        // Re-check on every request, so an Admin deactivating an account (or
        // archiving a supplier) cuts off that person immediately, not only at
        // their next login.
        $user = (new \App\Models\UserModel())->find((int) $session->get('user_id'));
        $active = $user && $user['is_active'] && empty($user['deleted_at']);

        if ($active && $user['role'] === 'Supplier') {
            $supplier = (new \App\Models\SupplierModel())->find($user['supplier_id']);
            $active   = $supplier && $supplier['is_active'];
        }

        if (! $active) {
            $archived = $user && $user['role'] === 'Supplier' && $user['is_active'];
            // A staff account the Owner locked (inactive, not removed). The request stops here,
            // before any controller runs, so a checkout posted after the lock records no sale.
            $locked   = $user && $user['role'] === 'Staff' && ! $user['is_active'] && empty($user['deleted_at']);
            $checkout = $request->getMethod() === 'POST' && preg_match('#(^|/)staff/sales/checkout$#', trim($request->getUri()->getPath(), '/')); // also behind index.php/
            $reason   = $archived ? 'archived' : ($locked ? 'locked' : 'deactivated');
            SignedOut::clear($reason, $session);

            $message = match (true) {
                $archived => 'This supplier account is archived. Please contact the owner.',
                $locked   => 'Your account was locked by the Owner. Please contact the Owner.' . ($checkout ? ' Your last sale was not saved.' : ''),
                default   => 'This account has been deactivated.',
            };

            return SignedOut::respond($request, $requiredRole, $reason, $message);
        }

        // On a temporary password, the only page a staff member can open is "set your own password".
        if ($requiredRole === 'Staff' && ! empty($user['must_change_password'])
            && $request->getUri()->getSegment(2) !== 'set-password') {
            if (SignedOut::isBackground($request)) {
                return service('response')->setStatusCode(401)->setHeader('Cache-Control', 'no-store')
                    ->setJSON(['signedOut' => false, 'reason' => 'set_password', 'message' => null, 'login' => site_url('staff/set-password')]);
            }

            return redirect()->to('/staff/set-password');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Signed-in pages are never stored by the browser, so Back after signing out shows nothing.
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->setHeader('Pragma', 'no-cache');

        return $response;
    }
}
