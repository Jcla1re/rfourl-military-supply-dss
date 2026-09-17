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

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
