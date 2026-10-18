<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Protects role-scoped route groups (admin/staff/supplier).
 * Usage in Routes.php: ['filter' => 'roleauth:Admin']
 */
class RoleAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $requiredRole = $arguments[0] ?? null;

        if (! $session->get('isLoggedIn')) {
            return redirect()->to('/login/' . strtolower($requiredRole ?? 'admin'))
                ->with('error', 'Please log in to continue.');
        }

        // Idle timeout, enforced on the server (the cookie lifetime alone does
        // not stop a copied session ID from working). Every request refreshes
        // the clock, so only inactivity ends the session.
        $limit = (int) config('Session')->expiration;
        $last  = (int) $session->get('last_activity');

        if ($limit > 0 && $last > 0 && (time() - $last) > $limit) {
            $session->remove(['isLoggedIn', 'user_id', 'role', 'supplier_id', 'last_activity']);

            return redirect()->to('/login/' . strtolower($requiredRole ?? 'admin'))
                ->with('error', 'Your session expired after ' . (int) round($limit / 60) . ' minutes of inactivity. Please log in again.');
        }
        $session->set('last_activity', time());

        if ($requiredRole && $session->get('role') !== $requiredRole) {
            return redirect()->to('/login/' . strtolower($requiredRole))
                ->with('error', 'You do not have access to that area.');
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
            $session->remove(['isLoggedIn', 'user_id', 'role', 'supplier_id']);

            return redirect()->to('/login/' . strtolower($requiredRole ?? 'admin'))
                ->with('error', $archived
                    ? 'This supplier account is archived. Please contact the owner.'
                    : 'This account has been deactivated.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
