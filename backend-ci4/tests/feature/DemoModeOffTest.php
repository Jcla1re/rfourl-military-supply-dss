<?php

use App\Exceptions\DemoLockedException;
use App\Libraries\DemoEmail;
use App\Libraries\DemoGuard;
use App\Libraries\EmailNotifier;
use App\Models\UserModel;
use CodeIgniter\Email\Email;
use Config\Services;
use Tests\Support\DemoTestCase;

/**
 * Demo mode OFF (the live site): nothing changes. No banner, no robots tag, no locks even for a row
 * flagged is_demo, mail goes to its real recipients, and no codes are shown on screen. The DEMO_*
 * settings are present in these tests on purpose, to show they do nothing unless DEMO_MODE is true.
 *
 * @internal
 */
final class DemoModeOffTest extends DemoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every other DEMO_* setting is set, DEMO_MODE is not.
        $this->demoOn();
        $this->demoOff();

        $this->plainMailer = new class (config('Email')) extends Email {
            /** @var list<array{to: mixed, subject: string}> */
            public array $sent = [];

            protected function spoolEmail()
            {
                $this->sent[] = ['to' => $this->recipients, 'subject' => (string) ($this->tmpArchive['subject'] ?? '')];

                return true;
            }

            public function send($autoClear = true)
            {
                $this->setFrom('shop@example.test', 'Shop');

                return parent::send($autoClear);
            }
        };
        Services::injectMock('email', $this->plainMailer);
    }

    private Email $plainMailer;

    public function testModeIsOffUnlessExplicitlyTurnedOn(): void
    {
        $this->assertFalse(demo_mode());
        $this->assertFalse(demo_show_credentials());
        $this->assertFalse(DemoGuard::active());

        foreach (['', 'false', '0', 'no'] as $value) {
            $_SERVER['DEMO_MODE'] = $value;
            $this->assertFalse(demo_mode(), "DEMO_MODE = '{$value}'");
        }
        $_SERVER['DEMO_MODE'] = 'true';
        $this->assertTrue(demo_mode());
    }

    public function testNoBannerRobotsTagOrDemoAccountCardOnAnyPage(): void
    {
        foreach (['/', 'login/admin', 'login/staff', 'login/supplier', 'login/admin/forgot', 'login/staff/forgot'] as $path) {
            $body = $this->get($path)->getBody();
            $this->assertStringNotContainsString('Demo version', $body, $path);
            $this->assertStringNotContainsString('noindex', $body, $path);
            $this->assertStringNotContainsString('demo.css', $body, $path);
            $this->assertStringNotContainsString('Demo-Owner-1', $body, $path);
            $this->assertStringNotContainsString('Demo account', $body, $path);
        }

        $body = $this->actingAs('staff')->get('staff/account')->getBody();
        $this->assertStringNotContainsString('Demo version', $body);
        $this->assertStringNotContainsString('data-demo-locked', $body);
    }

    public function testNothingIsLockedEvenForAnAccountFlaggedAsDemo(): void
    {
        $this->assertFalse(DemoGuard::locksUser((array) $this->user($this->id['demo_owner'])));

        $this->actingAs('demo_owner')->post('admin/settings/account', ['full_name' => 'Renamed', 'email' => 'ourteam+owner@example.test']);
        $this->assertSame('Renamed', $this->user($this->id['demo_owner'])->full_name);

        $this->actingAs('owner')->post('admin/settings/staff-accounts/toggle/' . $this->id['demo_staff']);
        $this->assertSame(0, (int) $this->user($this->id['demo_staff'])->is_active);

        $this->actingAs('demo_supplier')->post('supplier/profile/update', ['contact_person' => 'New Name', 'contact_email' => 'sup1@real.test']);
        $this->assertSame('New Name', $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->contact_person);

        $this->actingAs('owner')->post('admin/suppliers/delete/SUP-001');
        $this->assertSame(0, (int) $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->is_active);
    }

    public function testTheModelAllowsEverythingItAllowedBefore(): void
    {
        $users = new UserModel();

        try {
            $users->insert(['username' => 'second_owner', 'full_name' => 'Second Owner', 'role' => 'Admin', 'password_hash' => 'x']);
            $users->update($this->id['demo_staff'], ['full_name' => 'Changed', 'is_active' => 0]);
            $users->delete($this->id['demo_staff']);
        } catch (DemoLockedException $e) {
            $this->fail('The model must not lock anything when demo mode is off.');
        }

        $this->assertNotNull($users->findByUsername('second_owner'));
        $this->assertNull($this->db->table('users')->where('user_id', $this->id['demo_staff'])->get()->getRow());
    }

    public function testTheMailerIsTheStockMailerAndEmailsGoToTheirRealRecipients(): void
    {
        Services::resetSingle('email');
        $this->assertNotInstanceOf(DemoEmail::class, Services::email(null, false));
        $this->assertInstanceOf(Email::class, Services::email(null, false));

        Services::injectMock('email', $this->plainMailer);
        (new EmailNotifier())->toUser(['email' => 'someone@real.test'], 'Order shipped', 'Your order is on its way.');

        $this->assertSame('Order shipped', $this->plainMailer->sent[0]['subject']);
        $this->assertSame(['someone@real.test'], $this->plainMailer->sent[0]['to']);
    }

    public function testForgotPasswordWorksForEveryAccountAndShowsNoCodeOnScreen(): void
    {
        $this->post('login/admin/forgot', ['email' => 'ourteam+owner@example.test'])->assertRedirectTo(site_url('login/admin/otp'));

        $this->assertNull($this->flash('demo_secret'));
        $this->assertNotNull($this->user($this->id['demo_owner'])->reset_otp_hash);
        $this->assertSame('RfourL Military Supply — Your verification code', $this->plainMailer->sent[0]['subject']);
        $this->assertSame(['ourteam+owner@example.test'], $this->plainMailer->sent[0]['to']);
    }

    public function testNewStaffAccountsDoNotShowTheirTemporaryPassword(): void
    {
        $this->actingAs('owner')->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => 'newperson', 'contact_email' => 'new@real.test']);

        $this->assertNull($this->flash('demo_secret'));
        $this->assertSame('Your staff account is ready', $this->plainMailer->sent[0]['subject']);
        $this->assertSame(['new@real.test'], $this->plainMailer->sent[0]['to']);
    }

    public function testTheCodeBoxAndBannerRenderNothing(): void
    {
        session()->setFlashdata('demo_secret', ['label' => 'Demo code', 'code' => '482913']);

        // (The development view-debug comments are not part of the page content.)
        $blank = static fn (string $html) => trim(preg_replace('/<!--.*?-->/s', '', $html));

        $this->assertSame('', $blank(view('partials/demo_banner')));
        $this->assertSame('', $blank(view('partials/demo_head')));
        $this->assertSame('', $blank(view('partials/demo_credentials', ['role' => 'admin'])));
    }
}
