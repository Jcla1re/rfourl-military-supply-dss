<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Each role has its own Help page (task guides), and only that role can open it.
 *
 * @internal
 */
final class HelpPagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();
        foreach (['users', 'notifications'] as $table) {
            $forge->dropTable($table, true);
        }
        $forge->addField([
            'user_id'       => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'full_name'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'role'          => ['type' => 'VARCHAR', 'constraint' => 20],
            'is_active'     => ['type' => 'INTEGER', 'default' => 1],
            'supplier_id'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('user_id', true);
        $forge->createTable('users');
        $forge->addField([
            'notification_id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'recipient_role'  => ['type' => 'VARCHAR', 'constraint' => 20],
            'recipient_id'    => ['type' => 'INTEGER', 'null' => true],
            'is_read'         => ['type' => 'INTEGER', 'default' => 0],
        ]);
        $forge->addKey('notification_id', true);
        $forge->createTable('notifications');

        foreach (['Admin', 'Staff'] as $role) {
            $this->db->table('users')->insert(['username' => strtolower($role), 'full_name' => $role, 'role' => $role, 'is_active' => 1, 'password_hash' => 'x']);
        }
    }

    protected function tearDown(): void
    {
        foreach (['users', 'notifications'] as $table) {
            \Config\Database::forge()->dropTable($table, true);
        }
        parent::tearDown();
    }

    private function actingAs(string $role, int $id)
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => $role, 'user_id' => $id, 'username' => strtolower($role), 'full_name' => $role, 'last_activity' => time()]);
    }

    public function testOwnerSeesTheOwnerGuides(): void
    {
        $result = $this->actingAs('Admin', 1)->get('admin/help');

        $result->assertOK();
        $result->assertSee('Approve a staff password reset');
        $result->assertSee('Run the DSS engine');
        $result->assertSee('Read the ABC classification');
        $result->assertSee('Create a stock order');
    }

    public function testStaffSeesTheStaffGuides(): void
    {
        $result = $this->actingAs('Staff', 2)->get('staff/help');

        $result->assertOK();
        $result->assertSee('Record a sale');
        $result->assertSee('Log a delivery');
        $result->assertSee('Change my password');
        $result->assertSee('I forgot my password');
    }

    public function testEachHelpPageIsSelfContainedPerRole(): void
    {
        $this->actingAs('Staff', 2)->get('admin/help')->assertRedirectTo(site_url('login/admin'));
        $this->actingAs('Staff', 2)->get('supplier/help')->assertRedirectTo(site_url('login/supplier'));
        $this->actingAs('Admin', 1)->get('staff/help')->assertRedirectTo(site_url('login/staff'));
    }

    public function testLoggedOutVisitorsAreSentToLogin(): void
    {
        $this->get('admin/help')->assertRedirectTo(site_url('login/admin'));
        $this->get('staff/help')->assertRedirectTo(site_url('login/staff'));
        $this->get('supplier/help')->assertRedirectTo(site_url('login/supplier'));
    }

    public function testEveryGuideHasThreeToSevenSteps(): void
    {
        foreach (['owner', 'staff', 'supplier'] as $role) {
            foreach (\App\Libraries\HelpGuides::for($role) as $guide) {
                $this->assertGreaterThanOrEqual(3, count($guide['steps']), $guide['title']);
                $this->assertLessThanOrEqual(7, count($guide['steps']), $guide['title']);
            }
        }
    }
}
