<?php

namespace Tests\Support;

use App\Libraries\DemoEmail;
use App\Libraries\LoginLockout;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Shared setup for the demo-mode tests: a small users/suppliers/notifications schema,
 * one account of each kind (demo and normal), a mailer that records what it is given,
 * and switches for DEMO_MODE and the other DEMO_* settings (restored after every test).
 */
abstract class DemoTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    /** @var array<string, int> */
    protected array $id = [];

    /** @var array<string, mixed> original values of the DEMO_* keys, put back in tearDown */
    private array $savedEnv = [];

    private const KEYS = ['DEMO_MODE', 'DEMO_MAIL_TO', 'DEMO_RESET_TIME', 'DEMO_SHOW_CREDENTIALS', 'DEMO_OWNER_PASSWORD', 'DEMO_STAFF_PASSWORD', 'DEMO_SUPPLIER_PASSWORD'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $k) {
            $this->savedEnv[$k] = [$_SERVER[$k] ?? null, $_ENV[$k] ?? null];
            unset($_SERVER[$k], $_ENV[$k]);
        }

        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $this->buildSchema();

        $this->id['owner']       = $this->makeUser('owner_normal', 'Real Owner', 'Admin', 'OwnerPass123', 'owner@real.test', 0);
        $this->id['demo_owner']  = $this->makeUser('demo_owner', 'Demo Owner', 'Admin', 'DemoOwner123', 'ourteam+owner@example.test', 1);
        $this->id['staff']       = $this->makeUser('cashier_normal', 'Real Cashier', 'Staff', 'CashierPass123', 'cashier@real.test', 0);
        $this->id['demo_staff']  = $this->makeUser('demo_staff', 'Demo Staff', 'Staff', 'DemoStaff123', 'ourteam+staff@example.test', 1);
        $this->id['supplier']    = $this->makeUser('supplier_normal', 'Real Supplier', 'Supplier', 'SupplierPass123', 'supplier@real.test', 0, 'SUP-002');
        $this->id['demo_supplier'] = $this->makeUser('demo_supplier', 'Demo Supplier', 'Supplier', 'DemoSupplier123', 'ourteam+supplier@example.test', 1, 'SUP-001');

        $this->mailer = new RecordingDemoEmail(config('Email'));
        // Rate limits live in the cache and would otherwise carry over from one run to the next.
        foreach ([['otp_ip', '0.0.0.0'], ['staff_forgot', '0.0.0.0'], ['otp_mail', 'owner@real.test'], ['otp_mail', 'ourteam+owner@example.test']] as [$name, $subject]) {
            \App\Libraries\AttemptStore::forget($name . '_' . md5(strtolower($subject)));
        }
        (new LoginLockout())->reset('admin');
        (new LoginLockout())->reset('staff');
        (new LoginLockout())->reset('supplier');
    }

    protected RecordingDemoEmail $mailer;

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        foreach (self::KEYS as $k) {
            unset($_SERVER[$k], $_ENV[$k]);
            [$server, $env] = $this->savedEnv[$k] ?? [null, null];
            if ($server !== null) {
                $_SERVER[$k] = $server;
            }
            if ($env !== null) {
                $_ENV[$k] = $env;
            }
        }
        foreach (['users', 'suppliers', 'notifications', 'security_log'] as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------ switches

    protected function demoOn(array $settings = []): void
    {
        $settings += [
            'DEMO_MODE' => 'true', 'DEMO_MAIL_TO' => 'ourteam@example.test', 'DEMO_RESET_TIME' => '12:00 AM',
            'DEMO_SHOW_CREDENTIALS' => 'true', 'DEMO_OWNER_PASSWORD' => 'Demo-Owner-1', 'DEMO_STAFF_PASSWORD' => 'Demo-Staff-1',
            'DEMO_SUPPLIER_PASSWORD' => 'Demo-Supplier-1',
        ];
        foreach ($settings as $k => $v) {
            $_SERVER[$k] = $v;
        }
    }

    protected function demoOff(): void
    {
        unset($_SERVER['DEMO_MODE']);
    }

    // ------------------------------------------------------------ sessions

    protected function actingAs(string $who): self
    {
        $u = $this->user($this->id[$who]);

        return $this->withSession([
            'isLoggedIn' => true, 'role' => $u->role, 'user_id' => (int) $u->user_id, 'username' => $u->username,
            'full_name' => $u->full_name, 'supplier_id' => $u->supplier_id, 'last_activity' => time(),
        ]);
    }

    protected function user(int $id): object
    {
        return $this->db->table('users')->where('user_id', $id)->get()->getRow();
    }

    /** Fails the test unless this was refused with the demo message. */
    protected function assertRefused(): void
    {
        $this->assertStringContainsString('Disabled in the demo version.', json_encode([$this->flash('toast'), $this->flash('error')]));
    }

    /** A flash message set by the request that just ran. */
    protected function flash(string $key)
    {
        return $_SESSION[$key] ?? null;
    }

    // ------------------------------------------------------------ fixtures

    protected function makeUser(string $username, string $name, string $role, string $password, ?string $email, int $demo, ?string $supplier = null): int
    {
        $this->db->table('users')->insert([
            'username' => $username, 'full_name' => $name, 'role' => $role, 'is_active' => 1, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'is_demo' => $demo, 'supplier_id' => $supplier,
        ]);

        return (int) $this->db->insertID();
    }

    private function buildSchema(): void
    {
        $forge = \Config\Database::forge();
        foreach (['users', 'suppliers', 'notifications', 'security_log'] as $t) {
            $forge->dropTable($t, true);
        }
        $make = function (string $table, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($table);
        };
        $make('users', [
            'user_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'username' => ['type' => 'VARCHAR', 'constraint' => 50], 'full_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'email' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'phone' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'role' => ['type' => 'VARCHAR', 'constraint' => 20], 'is_active' => ['type' => 'INTEGER', 'default' => 1],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255], 'last_login' => ['type' => 'DATETIME', 'null' => true],
            'reset_otp_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'reset_otp_expires' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true], 'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            'must_change_password' => ['type' => 'INTEGER', 'default' => 0], 'temp_password_kind' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'temp_password_expires_at' => ['type' => 'DATETIME', 'null' => true], 'is_demo' => ['type' => 'INTEGER', 'default' => 0],
        ], 'user_id');
        $make('suppliers', [
            'supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'company_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'category' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true], 'lead_time_days' => ['type' => 'INTEGER', 'default' => 7],
            'preferred_courier' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'contact_person' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'contact_email' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'contact_number' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'is_active' => ['type' => 'INTEGER', 'default' => 1], 'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ], 'supplier_id');
        $make('notifications', [
            'notification_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'recipient_role' => ['type' => 'VARCHAR', 'constraint' => 20],
            'recipient_id' => ['type' => 'INTEGER', 'null' => true], 'type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'category' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            'message' => ['type' => 'TEXT', 'null' => true], 'link_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'is_read' => ['type' => 'INTEGER', 'default' => 0], 'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'action_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true], 'action_user_id' => ['type' => 'INTEGER', 'null' => true],
            'action_expires_at' => ['type' => 'DATETIME', 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ], 'notification_id');
        $make('security_log', [
            'log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER', 'null' => true],
            'username' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'role' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'event' => ['type' => 'VARCHAR', 'constraint' => 50], 'detail' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ], 'log_id');

        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SUP-001', 'company_name' => 'Demo Trading', 'contact_person' => 'Real Person', 'contact_email' => 'sup1@real.test', 'contact_number' => '09171234567', 'is_active' => 1],
            ['supplier_id' => 'SUP-002', 'company_name' => 'Other Trading', 'contact_person' => 'Other Person', 'contact_email' => 'sup2@real.test', 'contact_number' => '09179999999', 'is_active' => 1],
        ]);
    }
}

/** A DemoEmail that records the message instead of sending it. */
class RecordingDemoEmail extends DemoEmail
{
    /** @var list<array{to: mixed, subject: string}> */
    public array $sent = [];

    protected function sendWithMail()
    {
        $this->sent[] = ['to' => $this->recipients, 'subject' => (string) ($this->tmpArchive['subject'] ?? '')];

        return true;
    }

    public function spoolEmail()
    {
        return $this->sendWithMail();
    }

    public function send($autoClear = true)
    {
        $this->setFrom('shop@example.test', 'Shop');

        return parent::send($autoClear);
    }
}
