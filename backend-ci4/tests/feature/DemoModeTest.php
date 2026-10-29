<?php

use App\Commands\DemoReset;
use App\Commands\DemoSetup as DemoSetupCommand;
use App\Commands\DemoSnapshot;
use App\Exceptions\DemoLockedException;
use App\Libraries\DemoDb;
use App\Libraries\DemoEmail;
use App\Libraries\DemoGuard;
use App\Libraries\DemoSetup;
use App\Libraries\EmailNotifier;
use App\Models\UserModel;
use CodeIgniter\Test\Filters\CITestStreamFilter;
use Config\Services;
use Tests\Support\DemoTestCase;

/**
 * Demo mode ON: banner and robots tag, locked actions for the demo accounts, mail redirected to
 * DEMO_MAIL_TO, codes shown on screen, and the demo commands refusing a database that is not a demo one.
 * (DemoModeOffTest covers the same things with the mode off.)
 *
 * @internal
 */
final class DemoModeTest extends DemoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('email', $this->mailer);
        $this->demoOn();
    }

    // ---------------------------------------------------------------- UI

    public function testEveryLoginPageHasTheBannerAndTheRobotsTag(): void
    {
        foreach (['/', 'login/admin', 'login/staff', 'login/supplier', 'login/admin/forgot', 'login/staff/forgot'] as $path) {
            $body = $this->get($path)->getBody();
            $this->assertStringContainsString('Demo version: data resets every night at 12:00 AM.', $body, $path);
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $body, $path);
        }
    }

    public function testLoginPagesShowTheDemoAccountOnlyWhenAsked(): void
    {
        $owner = $this->get('login/admin')->getBody();
        $this->assertStringContainsString('demo_owner', $owner);
        $this->assertStringContainsString('Demo-Owner-1', $owner);

        $staff = $this->get('login/staff')->getBody();
        $this->assertStringContainsString('Demo-Staff-1', $staff);
        $this->assertStringNotContainsString('Demo-Owner-1', $staff);

        $this->assertStringContainsString('demo_supplier', $this->get('login/supplier')->getBody());

        $this->demoOn(['DEMO_SHOW_CREDENTIALS' => 'false']);
        $this->assertStringNotContainsString('Demo-Owner-1', $this->get('login/admin')->getBody());
    }

    public function testTheBannerIsOnTheSignedInPagesToo(): void
    {
        $body = $this->actingAs('demo_staff')->get('staff/account')->getBody();
        $this->assertStringContainsString('Demo version: data resets every night at 12:00 AM.', $body);
        $this->assertStringContainsString('disabled title="Disabled in the demo version"', $body);
    }

    public function testTheCodeBoxShowsTheCodeInsideADemoLabel(): void
    {
        session()->setFlashdata('demo_secret', ['label' => 'Demo code', 'code' => '482913']);

        $this->assertStringContainsString('Demo code: <span>482913</span>', view('partials/demo_banner'));
    }

    // ---------------------------------------------------------------- locked actions

    public function testDemoOwnerCannotChangeTheirProfileOrPassword(): void
    {
        $id = $this->id['demo_owner'];

        $this->actingAs('demo_owner')->post('admin/settings/account', ['full_name' => 'Changed', 'email' => 'new@example.test'])
            ->assertRedirectTo(site_url('admin/settings?tab=profile'));
        $this->assertRefused();
        $this->assertSame('Demo Owner', $this->user($id)->full_name);
        $this->assertSame('ourteam+owner@example.test', $this->user($id)->email);

        $this->actingAs('demo_owner')->post('admin/settings/owner-password', [
            'current_password' => 'DemoOwner123', 'new_password' => 'BrandNewPass123', 'confirm_password' => 'BrandNewPass123',
        ]);
        $this->assertRefused();
        $this->assertTrue(password_verify('DemoOwner123', $this->user($id)->password_hash));
    }

    public function testANormalOwnerCanStillEditTheirProfile(): void
    {
        $this->actingAs('owner')->post('admin/settings/account', ['full_name' => 'Renamed Owner', 'email' => 'owner@real.test']);

        $this->assertSame('Renamed Owner', $this->user($this->id['owner'])->full_name);
    }

    public function testDemoStaffCannotBeResetDeactivatedOrRemoved(): void
    {
        $id = $this->id['demo_staff'];

        foreach (['password', 'toggle', 'delete'] as $action) {
            unset($_SESSION['toast'], $_SESSION['error']);
            $this->actingAs('owner')->post("admin/settings/staff-accounts/{$action}/{$id}", ['owner_password' => 'OwnerPass123', 'confirmed' => '1']);
            $this->assertRefused();
        }

        $row = $this->user($id);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertNull($row->deleted_at);
        $this->assertTrue(password_verify('DemoStaff123', $row->password_hash));
    }

    public function testNormalStaffCanStillBeDeactivated(): void
    {
        $this->actingAs('owner')->post('admin/settings/staff-accounts/toggle/' . $this->id['staff']);

        $this->assertSame(0, (int) $this->user($this->id['staff'])->is_active);
    }

    public function testDemoStaffCannotChangeThePassword(): void
    {
        $this->actingAs('demo_staff')->post('staff/account/password', [
            'current_password' => 'DemoStaff123', 'new_password' => 'BrandNewPass123', 'confirm_password' => 'BrandNewPass123',
        ])->assertRedirectTo(site_url('staff/account'));

        $this->assertRefused();
        $this->assertTrue(password_verify('DemoStaff123', $this->user($this->id['demo_staff'])->password_hash));
    }

    public function testDemoSupplierCannotChangeUsernameEmailPasswordOrContact(): void
    {
        $id = $this->id['demo_supplier'];

        $this->actingAs('demo_supplier')->post('supplier/profile/username', ['current_password' => 'DemoSupplier123', 'username' => 'renamed_supplier']);
        $this->assertRefused();
        unset($_SESSION['error']);

        $this->actingAs('demo_supplier')->post('supplier/profile/login-email', ['current_password' => 'DemoSupplier123', 'login_email' => 'new@real.test']);
        $this->assertRefused();
        unset($_SESSION['error']);

        $this->actingAs('demo_supplier')->post('supplier/profile/password', [
            'current_password' => 'DemoSupplier123', 'new_password' => 'BrandNewPass123', 'confirm_password' => 'BrandNewPass123',
        ]);
        $this->assertRefused();
        unset($_SESSION['error']);

        $this->actingAs('demo_supplier')->post('supplier/profile/update', ['contact_person' => 'Someone Else', 'contact_email' => 'sup1@real.test']);
        $this->assertRefused();

        $row = $this->user($id);
        $this->assertSame('demo_supplier', $row->username);
        $this->assertSame('ourteam+supplier@example.test', $row->email);
        $this->assertTrue(password_verify('DemoSupplier123', $row->password_hash));
    }

    public function testDemoSupplierCanStillEditTheRestOfTheirProfile(): void
    {
        $this->actingAs('demo_supplier')->post('supplier/profile/update', [
            'contact_person' => 'Real Person', 'contact_email' => 'sup1@real.test', 'contact_number' => '09171234567', 'preferred_courier' => 'LBC',
        ]);

        $this->assertSame('LBC', $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->preferred_courier);
    }

    public function testForgotPasswordIsRefusedForADemoAccount(): void
    {
        $this->post('login/admin/forgot', ['email' => 'ourteam+owner@example.test'])->assertRedirectTo(site_url('login/admin/forgot'));

        $this->assertRefused();
        $this->assertNull($this->user($this->id['demo_owner'])->reset_otp_hash);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testStaffForgotRequestIsRefusedForTheDemoStaff(): void
    {
        $this->post('login/staff/forgot', ['staff_name' => 'demo_staff', 'reason' => 'x'])->assertRedirectTo(site_url('login/staff/forgot'));

        $this->assertRefused();
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
    }

    public function testApprovingAResetForTheDemoStaffIsRefusedInAppAndByEmailLink(): void
    {
        $this->db->table('notifications')->insert([
            'recipient_role' => 'Admin', 'type' => 'Access Request', 'category' => 'access_request', 'title' => 'Password reset requested',
            'action_token' => 'tok-demo', 'action_user_id' => $this->id['demo_staff'],
        ]);
        $nid = (int) $this->db->insertID();

        $this->actingAs('owner')->post("admin/notifications/approve/{$nid}", ['confirmed' => '1']);
        $this->assertStringContainsString('Disabled in the demo version.', json_encode($this->flash('toast')));

        $page = $this->post("notification-action/{$nid}/tok-demo/approve", ['confirmed' => '1'])->getBody();
        $this->assertStringContainsString('Disabled in the demo version.', $page);

        $this->assertTrue(password_verify('DemoStaff123', $this->user($this->id['demo_staff'])->password_hash));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testSuppliersWithADemoLoginCannotBeArchived(): void
    {
        $this->actingAs('owner')->post('admin/suppliers/delete/SUP-001');
        $this->assertRefused();

        $this->assertSame(1, (int) $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->is_active);
    }

    public function testNoNewOwnerAccountsAndDemoRowsAreProtectedByTheModel(): void
    {
        $users = new UserModel();

        try {
            $users->insert(['username' => 'second_owner', 'full_name' => 'Second Owner', 'role' => 'Admin', 'password_hash' => 'x']);
            $this->fail('A new Admin account should be refused in demo mode.');
        } catch (DemoLockedException $e) {
            $this->assertSame('Disabled in the demo version.', $e->getMessage());
        }

        $users->insert(['username' => 'new_cashier', 'full_name' => 'New Cashier', 'role' => 'Staff', 'password_hash' => 'x']);
        $this->assertNotNull($users->findByUsername('new_cashier'));

        foreach ([['full_name' => 'Changed'], ['email' => 'a@b.test'], ['username' => 'changed'], ['password_hash' => 'x'], ['is_active' => 0], ['deleted_at' => '2026-01-01 00:00:00']] as $change) {
            try {
                $users->update($this->id['demo_staff'], $change);
                $this->fail('Changing ' . key($change) . ' on a demo account should be refused.');
            } catch (DemoLockedException $e) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $users->delete($this->id['demo_staff']);
            $this->fail('Deleting a demo account should be refused.');
        } catch (DemoLockedException $e) {
            $this->addToAssertionCount(1);
        }

        // Signing in still records the login time, and a normal account is untouched by the lock.
        $users->update($this->id['demo_staff'], ['last_login' => '2026-01-01 00:00:00']);
        $users->update($this->id['staff'], ['full_name' => 'Changed Cashier']);
        $this->assertSame('Changed Cashier', $this->user($this->id['staff'])->full_name);

        // Only demo:setup may change them.
        DemoGuard::withoutLocks(fn () => $users->update($this->id['demo_staff'], ['full_name' => 'Demo Staff 2']));
        $this->assertSame('Demo Staff 2', $this->user($this->id['demo_staff'])->full_name);
    }

    // ---------------------------------------------------------------- email and on-screen codes

    public function testTheMailerIsTheDemoMailer(): void
    {
        Services::resetSingle('email');

        $this->assertInstanceOf(DemoEmail::class, Services::email(null, false));
    }

    public function testEveryEmailGoesToTheTeamWithTheRealRecipientInTheSubject(): void
    {
        $mail = $this->mailer;
        $mail->setTo('owner@real.test')->setCC('boss@real.test')->setBCC('spy@real.test')->setSubject('Your login code')->setMessage('Hello');

        $this->assertTrue($mail->send());
        $this->assertSame(['ourteam@example.test'], $mail->sent[0]['to']);
        $this->assertSame('[DEMO to owner@real.test] Your login code', $mail->sent[0]['subject']);
    }

    public function testNothingIsSentWhenNoTeamAddressIsConfigured(): void
    {
        unset($_SERVER['DEMO_MAIL_TO']);
        $this->mailer->setTo('owner@real.test')->setSubject('x')->setMessage('y');

        $this->assertFalse($this->mailer->send());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testNotificationsAndStaffRequestsAreRedirectedToo(): void
    {
        (new EmailNotifier())->toUser(['email' => 'someone@real.test'], 'Order shipped', 'Your order is on its way.');
        $this->post('login/staff/forgot', ['staff_name' => 'cashier_normal', 'reason' => 'forgot']);

        $this->assertGreaterThanOrEqual(2, count($this->mailer->sent));
        foreach ($this->mailer->sent as $mail) {
            $this->assertSame(['ourteam@example.test'], $mail['to']);
            $this->assertStringStartsWith('[DEMO to ', $mail['subject']);
        }
        $this->assertSame('[DEMO to someone@real.test] Order shipped', $this->mailer->sent[0]['subject']);
    }

    public function testTheLoginCodeIsShownOnScreenAsWell(): void
    {
        $this->post('login/admin/forgot', ['email' => 'owner@real.test'])->assertRedirectTo(site_url('login/admin/otp'));

        $secret = $this->flash('demo_secret');
        $this->assertSame('Demo code', $secret['label']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $secret['code']);
        $this->assertTrue(password_verify($secret['code'], $this->user($this->id['owner'])->reset_otp_hash));
        $this->assertSame('[DEMO to owner@real.test] RfourL Military Supply — Your verification code', $this->mailer->sent[0]['subject']);
    }

    public function testATemporaryPasswordForANewStaffAccountIsShownOnScreen(): void
    {
        $this->actingAs('owner')->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => 'newperson', 'contact_email' => 'new@real.test']);

        $secret = $this->flash('demo_secret');
        $this->assertSame('Temporary password for New Person', $secret['label']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $secret['code']);
        $this->assertSame('[DEMO to new@real.test] Your staff account is ready', $this->mailer->sent[0]['subject']);
    }

    // ---------------------------------------------------------------- demo:setup

    public function testSetupCreatesTheDemoAccountsAndRemovesRealPeople(): void
    {
        $this->db->table('notifications')->insert(['recipient_role' => 'Admin', 'type' => 'x', 'title' => 'Real Cashier asked']);
        $this->db->table('security_log')->insert(['username' => 'cashier_normal', 'event' => 'login_success']);

        $logins = (new DemoSetup())->run();

        $this->assertSame(['demo_owner', 'demo_staff', 'demo_supplier'], array_column($logins, 'username'));
        foreach ([['demo_owner', 'Demo-Owner-1', 'ourteam+owner@example.test', 'Admin'], ['demo_staff', 'Demo-Staff-1', 'ourteam+staff@example.test', 'Staff'], ['demo_supplier', 'Demo-Supplier-1', 'ourteam+supplier@example.test', 'Supplier']] as [$username, $password, $email, $role]) {
            $u = $this->db->table('users')->where('username', $username)->get()->getRow();
            $this->assertSame(1, (int) $u->is_demo);
            $this->assertSame(1, (int) $u->is_active);
            $this->assertSame($role, $u->role);
            $this->assertSame($email, $u->email);
            $this->assertTrue(password_verify($password, $u->password_hash), $username);
        }
        $this->assertSame('SUP-001', $this->db->table('users')->where('username', 'demo_supplier')->get()->getRow()->supplier_id);

        $owner = $this->user($this->id['owner']);
        $this->assertSame(0, (int) $owner->is_active);
        $this->assertSame('Former Owner', $owner->full_name);
        $this->assertSame('former_owner_' . $owner->user_id, $owner->username);
        $this->assertSame('ourteam+formerowner' . $owner->user_id . '@example.test', $owner->email);
        $this->assertFalse(password_verify('OwnerPass123', $owner->password_hash));

        $staff = $this->user($this->id['staff']);
        $this->assertSame(0, (int) $staff->is_active);
        $this->assertSame('Cashier 1', $staff->full_name);
        $this->assertFalse(password_verify('CashierPass123', $staff->password_hash));

        $supplierLogin = $this->user($this->id['supplier']);
        $this->assertSame(0, (int) $supplierLogin->is_active);
        $this->assertSame('Other Trading', $supplierLogin->full_name);

        $suppliers = $this->db->table('suppliers')->orderBy('supplier_id')->get()->getResult();
        $this->assertSame(['Demo Trading', 'Other Trading'], array_column($suppliers, 'company_name'));
        $this->assertSame(['ourteam+sup001@example.test', 'ourteam+sup002@example.test'], array_column($suppliers, 'contact_email'));
        $this->assertSame(['Supplier Contact 1', 'Supplier Contact 2'], array_column($suppliers, 'contact_person'));

        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
        $this->assertSame(0, $this->db->table('security_log')->countAllResults());
        $this->assertSame(3, $this->db->table('users')->where('is_active', 1)->countAllResults());
    }

    public function testSetupCanBeRunAgain(): void
    {
        (new DemoSetup())->run();
        (new DemoSetup())->run();

        $this->assertSame(3, $this->db->table('users')->where('is_demo', 1)->countAllResults());
        $this->assertSame(1, $this->db->table('users')->where('username', 'demo_owner')->countAllResults());
    }

    public function testSetupNeedsThePasswordsAndAValidMailAddress(): void
    {
        unset($_SERVER['DEMO_STAFF_PASSWORD']);
        try {
            (new DemoSetup())->run();
            $this->fail('A missing password should stop setup.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DEMO_STAFF_PASSWORD', $e->getMessage());
        }

        $this->demoOn(['DEMO_MAIL_TO' => 'not-an-email']);
        $this->expectException(RuntimeException::class);
        (new DemoSetup())->run();
    }

    // ---------------------------------------------------------------- the demo commands refuse a non-demo database

    public function testDemoDatabaseNames(): void
    {
        $this->assertTrue(demo_db_ok('rfourl_demo'));
        $this->assertTrue(demo_db_ok('RFOURL_DEMO'));
        $this->assertFalse(demo_db_ok('rfourl_dss'));
    }

    public function testTheGuardNeedsBothTheModeAndADemoDatabase(): void
    {
        $default = config('Database')->default;

        try {
            config('Database')->default['database'] = 'rfourl_dss';
            $this->assertStringContainsString('does not contain "demo"', DemoDb::guard());

            config('Database')->default['database'] = 'no_such_demo_db';
            $this->assertNull(DemoDb::guard());

            $this->demoOff();
            $this->assertStringContainsString('Demo mode is off', DemoDb::guard());
        } finally {
            config('Database')->default = $default;
        }
    }

    public function testSetupSnapshotAndResetAllRefuseOnTheLiveDatabase(): void
    {
        $default  = config('Database')->default;
        $snapshot = DemoDb::snapshotPath();
        $before   = is_file($snapshot) ? md5_file($snapshot) : null;

        try {
            config('Database')->default['database'] = 'rfourl_dss';

            foreach ([DemoSetupCommand::class, DemoSnapshot::class, DemoReset::class] as $class) {
                $out = $this->runCommand(new $class(service('logger'), service('commands')));
                $this->assertStringContainsString('does not contain "demo"', $out, $class);
            }
        } finally {
            config('Database')->default = $default;
        }

        $this->assertSame($before, is_file($snapshot) ? md5_file($snapshot) : null, 'the snapshot file must not change');
        $this->assertSame(1, (int) $this->user($this->id['owner'])->is_active, 'no account may be touched');
    }

    public function testSetupSnapshotAndResetAllRefuseWhenDemoModeIsOff(): void
    {
        $default = config('Database')->default;
        $this->demoOff();

        try {
            config('Database')->default['database'] = 'no_such_demo_db';

            foreach ([DemoSetupCommand::class, DemoSnapshot::class, DemoReset::class] as $class) {
                $this->assertStringContainsString('Demo mode is off', $this->runCommand(new $class(service('logger'), service('commands'))), $class);
            }
        } finally {
            config('Database')->default = $default;
        }

        $this->assertSame(1, (int) $this->user($this->id['owner'])->is_active);
    }

    // ---------------------------------------------------------------- dump and session plumbing

    public function testDefinerClausesAreStrippedFromTheDump(): void
    {
        $this->assertSame("\n", DemoDb::stripDefiner("/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */\n"));
        $this->assertSame("CREATE PROCEDURE `p`()\n", DemoDb::stripDefiner("CREATE DEFINER=`root`@`%` PROCEDURE `p`()\n"));
        $this->assertSame("/*!50003 CREATE*/ /*!50003 TRIGGER t\n", DemoDb::stripDefiner("/*!50003 CREATE*/ /*!50017 DEFINER=`a`@`b`*/ /*!50003 TRIGGER t\n"));
        $this->assertSame("INSERT INTO t VALUES ('DEFINER=x');\n", DemoDb::stripDefiner("INSERT INTO t VALUES ('DEFINER=x');\n"));
    }

    public function testClearingSessionsOnlyRemovesSessionFiles(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'demo-sessions-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $session           = config('Session');
        $original          = $session->savePath;
        $session->savePath = $dir;

        try {
            $cookie = $session->cookieName;
            file_put_contents("{$dir}/{$cookie}abc123", 'x');
            file_put_contents("{$dir}/{$cookie}def456", 'x');
            file_put_contents("{$dir}/unrelated.tmp", 'keep');

            DemoDb::clearSessionFiles();

            $this->assertFileDoesNotExist("{$dir}/{$cookie}abc123");
            $this->assertFileDoesNotExist("{$dir}/{$cookie}def456");
            $this->assertFileExists("{$dir}/unrelated.tmp");
        } finally {
            $session->savePath = $original;
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Runs a command and returns what it printed (standard output and errors). */
    private function runCommand($command): string
    {
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        CITestStreamFilter::addErrorFilter();
        CITestStreamFilter::$buffer = '';

        try {
            $command->run([]);

            return CITestStreamFilter::$buffer;
        } finally {
            CITestStreamFilter::removeOutputFilter();
            CITestStreamFilter::removeErrorFilter();
        }
    }
}
