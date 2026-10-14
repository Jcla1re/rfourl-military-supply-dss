<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * UT-08 / IT-01 / ST-14: role-based access control. These cases are all
 * rejected by RoleAuthFilter before any database query, so no DB is needed.
 *
 * @internal
 */
final class RoleAccessTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public static function protectedPages(): array
    {
        return [
            'admin dashboard'    => ['/admin/dashboard', 'admin'],
            'admin settings'     => ['/admin/settings', 'admin'],
            'admin orders'       => ['/admin/orders', 'admin'],
            'staff dashboard'    => ['/staff/dashboard', 'staff'],
            'staff log'          => ['/staff/log-transaction', 'staff'],
            'supplier dashboard' => ['/supplier/dashboard', 'supplier'],
            'supplier orders'    => ['/supplier/new-orders', 'supplier'],
        ];
    }

    /**
     * @dataProvider protectedPages
     */
    public function testLoggedOutUserIsSentToLogin(string $path, string $role): void
    {
        $this->get($path)->assertRedirectTo(site_url("login/{$role}"));
    }

    public function testStaffCannotOpenAdminPage(): void
    {
        $result = $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => 1])
            ->get('/admin/dashboard');

        $result->assertRedirectTo(site_url('login/admin'));
    }

    public function testSupplierCannotOpenStaffPage(): void
    {
        $result = $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => 1])
            ->get('/staff/dashboard');

        $result->assertRedirectTo(site_url('login/staff'));
    }
}
