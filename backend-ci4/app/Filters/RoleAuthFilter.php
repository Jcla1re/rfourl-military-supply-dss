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

        if ($requiredRole && $session->get('role') !== $requiredRole) {
            return redirect()->to('/login/' . strtolower($requiredRole))
                ->with('error', 'You do not have access to that area.');
        }

        // Re-check on every request, so an Admin deactivating an account (or
        // archiving a supplier) cuts off that person immediately, not only at
        // their next login.
        $user = (new \App\Models\UserModel())->find((int) $session->get('user_id'));
        $active = $user && $user['is_active'];

        if ($active && $user['role'] === 'Supplier') {
            $supplier = (new \App\Models\SupplierModel())->find($user['supplier_id']);
            $active   = $supplier && $supplier['is_active'];
        }

        if (! $active) {
            $session->remove(['isLoggedIn', 'user_id', 'role', 'supplier_id']);

            return redirect()->to('/login/' . strtolower($requiredRole ?? 'admin'))
                ->with('error', 'This account has been deactivated.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
