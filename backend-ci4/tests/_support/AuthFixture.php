<?php

namespace Tests\Support;

use App\Libraries\AfterResponse;
use App\Libraries\AttemptStore;
use App\Libraries\LoginLockout;
use CodeIgniter\Email\Email;
use Config\Services;

/**
 * The sign-in page and Forgot password, on the in-memory 'tests' database: the tables they touch,
 * one account per role (owner1, cashier1, tgi) with real-looking emails, a recording mailer, CSRF
 * off, and every sign-in / forgot limit cleared before and after. Use inside a test class that has
 * DatabaseTestTrait and FeatureTestTrait; call setUpAuthFixture() / tearDownAuthFixture().
 */
trait AuthFixture
{
    protected int $ownerId;
    protected int $staffId;
    protected int $supplierUserId;
    protected RecordingMailer $mail;

    private static array $authTables = ['users', 'suppliers', 'security_log', 'notifications'];

    /** Usernames and emails the tests type, so their limits can be cleared. */
    private static array $typedNames = [
        'owner1', 'cashier1', 'cashier2', 'tgi', 'nobody', 'ghost', 'owner@example.test', 'ana@example.test',
        'tgi@example.test', 'ghost@example.test', 'removed1', 'locked1', 'archived1',
    ];

    protected function setUpAuthFixture(): void
    {
        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $forge = \Config\Database::forge();
        foreach (self::$authTables as $t) {
            $forge->dropTable($t, true);
        }
        $str  = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];
        $when = ['type' => 'DATETIME', 'null' => true];
        $forge->addField([
            'user_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'username' => $str, 'full_name' => $str, 'email' => $str, 'phone' => $str,
            'role' => $str, 'supplier_id' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'is_demo' => ['type' => 'INTEGER', 'default' => 0],
            'password_hash' => $str, 'temp_password_hash' => $str, 'last_login' => $when, 'created_at' => $when, 'deleted_at' => $when,
            'must_change_password' => ['type' => 'INTEGER', 'default' => 0], 'temp_password_kind' => $str, 'temp_password_expires_at' => $when,
            'reset_otp_hash' => $str, 'reset_otp_expires' => $when,
        ] + PosPinFixture::forgeFields());
        $forge->addKey('user_id', true);
        $forge->createTable('users');

        $forge->addField(['supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'company_name' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1]]);
        $forge->addKey('supplier_id', true);
        $forge->createTable('suppliers');
        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SUP-001', 'company_name' => 'Tactical Gear', 'is_active' => 1],
            ['supplier_id' => 'SUP-002', 'company_name' => 'Old Supplier', 'is_active' => 0],
        ]);

        $forge->addField([
            'log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER', 'null' => true], 'username' => $str,
            'role' => $str, 'event' => $str, 'detail' => $str, 'ip_address' => $str, 'created_at' => $when,
        ]);
        $forge->addKey('log_id', true);
        $forge->createTable('security_log');

        $forge->addField([
            'notification_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'recipient_role' => $str, 'recipient_id' => ['type' => 'INTEGER', 'null' => true],
            'type' => $str, 'category' => $str, 'title' => $str, 'message' => ['type' => 'TEXT', 'null' => true], 'link_url' => $str,
            'is_read' => ['type' => 'INTEGER', 'default' => 0], 'status' => $str, 'action_token' => $str, 'action_user_id' => ['type' => 'INTEGER', 'null' => true],
            'action_expires_at' => $when, 'created_at' => $when,
        ]);
        $forge->addKey('notification_id', true);
        $forge->createTable('notifications');

        $this->ownerId        = $this->makeAccount('owner1', 'Owner One', 'Admin', 'OwnerPass123', 'owner@example.test');
        $this->staffId        = $this->makeAccount('cashier1', 'Ana Cruz', 'Staff', 'Cashier1Pass1', 'ana@example.test');
        $this->supplierUserId = $this->makeAccount('tgi', 'Tactical Gear', 'Supplier', 'SupplierPass1', 'tgi@example.test', 'SUP-001');

        $this->mail = new RecordingMailer(['fromEmail' => 'app@example.test', 'fromName' => 'RfourL', 'protocol' => 'mail', 'mailType' => 'text']);
        Services::injectMock('email', $this->mail);

        AfterResponse::resetForTests();
        $this->clearAuthLimits();
    }

    protected function tearDownAuthFixture(): void
    {
        AfterResponse::resetForTests();
        $this->clearAuthLimits();
        Services::resetSingle('email');
        foreach (self::$authTables as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
    }

    protected function clearAuthLimits(): void
    {
        $lockout = new LoginLockout();
        foreach (self::$typedNames as $name) {
            $lockout->reset($name);
            AttemptStore::forget('forgot_ident_' . md5($name));
            AttemptStore::forget('forgot_resend_' . md5($name));
        }
        $lockout->resetIp();
        AttemptStore::forget('forgot_ip_' . md5('0.0.0.0'));
        for ($id = 1; $id <= 12; $id++) {
            AttemptStore::forget('otp_attempts_' . $id);
            AttemptStore::forget('staff_forgot_acct_' . $id);
        }
        PosPinFixture::clearLocks();
    }

    protected function makeAccount(string $username, string $name, string $role, string $password, ?string $email, ?string $supplierId = null): int
    {
        $this->db->table('users')->insert([
            'username' => $username, 'full_name' => $name, 'role' => $role, 'is_active' => 1, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'supplier_id' => $supplierId,
        ]);

        return (int) $this->db->insertID();
    }

    protected function account(int $id): array
    {
        return $this->db->table('users')->where('user_id', $id)->get()->getRowArray();
    }

    protected function flash(string $key)
    {
        return $_SESSION[$key] ?? null;
    }

    /** The page HTML without what changes on every request (the CSRF token, the debug view markers). */
    protected function stableHtml(string $html): string
    {
        $html = preg_replace('/name="csrf_test_name" value="[^"]*"/', 'name="csrf_test_name" value="X"', $html);
        $html = preg_replace('/<!-- DEBUG-VIEW (START|ENDED) \d+ /', '<!-- DEBUG-VIEW $1 N ', $html);

        return preg_replace('/data-seconds="\d+"|0:\d\d/', 'T', $html);
    }
}
