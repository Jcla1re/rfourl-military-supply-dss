<?php

use App\Libraries\StaffPasswordAlert;
use Config\Services;
use Tests\Support\DemoTestCase;

/**
 * Staff "My Account" > Edit Profile: name, email and contact number. The username and role stay with
 * the owner. A new email needs the current password and then a 6-digit code sent to the NEW address;
 * it only changes once the code is entered, and the owner (and the old address) are told.
 *
 * @internal
 */
final class StaffProfileTest extends DemoTestCase
{
    private const KEY = 'pending_email_change';

    private object $mail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mail = new class () {
            public array $sent = [];
            private array $current = [];

            public function __call(string $name, array $args)
            {
                if ($name === 'send') {
                    $this->sent[]  = $this->current;
                    $this->current = [];

                    return true;
                }
                $this->current[$name] = $args[0] ?? null;

                return $this;
            }
        };
        Services::injectMock('email', $this->mail);
    }

    // ---------------------------------------------------------------- helpers

    private function staff(string $who = 'staff', array $extraSession = []): self
    {
        $u = $this->user($this->id[$who]);

        return $this->withSession($extraSession + [
            'isLoggedIn' => true, 'role' => 'Staff', 'user_id' => (int) $u->user_id, 'username' => $u->username,
            'full_name' => $u->full_name, 'last_activity' => time(),
        ]);
    }

    private function saveProfile(array $fields, string $who = 'staff', array $extraSession = [])
    {
        return $this->staff($who, $extraSession)->post('staff/account/profile', $fields + [
            'full_name' => 'Real Cashier', 'email' => 'cashier@real.test', 'phone' => '', 'current_password' => '',
        ]);
    }

    /** @return list<array> the emails sent to this address */
    private function mailsTo(string $address): array
    {
        return array_values(array_filter($this->mail->sent, static fn ($m) => ($m['setTo'] ?? '') === $address));
    }

    /** Asks for an email change with the right password; returns [code, the pending session value]. */
    private function requestChange(string $newEmail = 'new@real.test', array $extra = []): array
    {
        $this->saveProfile($extra + ['email' => $newEmail, 'current_password' => 'CashierPass123']);
        $pending = $_SESSION[self::KEY] ?? null;
        $this->assertNotNull($pending, 'a pending email change should be waiting for its code');

        $mails = $this->mailsTo($newEmail);
        $this->assertCount(1, $mails);
        $this->assertSame(1, preg_match('/code is: (\d{6})/', (string) $mails[0]['setAltMessage'], $m));

        return [$m[1], $pending];
    }

    private function confirm(string $code, array $pending)
    {
        return $this->staff('staff', [self::KEY => $pending])->post('staff/account/email/confirm', ['code' => $code]);
    }

    private function email(): ?string
    {
        return $this->user($this->id['staff'])->email;
    }

    // ---------------------------------------------------------------- editing the profile

    public function testNameAndContactNumberAreSavedAndLoggedWithoutAnOwnerAlert(): void
    {
        $this->saveProfile(['full_name' => 'Renamed Cashier', 'phone' => '0917 000 0000'])->assertRedirectTo(site_url('staff/account'));

        $row = $this->user($this->id['staff']);
        $this->assertSame('Renamed Cashier', $row->full_name);
        $this->assertSame('0917 000 0000', $row->phone);
        $this->assertSame('cashier@real.test', $row->email);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'profile_updated')->countAllResults());
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
        $this->assertSame([], $this->mail->sent);
    }

    public function testTheUsernameAndRoleCannotBeChangedByPostingThem(): void
    {
        $this->saveProfile(['full_name' => 'Renamed Cashier', 'username' => 'hacker', 'role' => 'Admin', 'is_active' => '0', 'supplier_id' => 'SUP-001']);

        $row = $this->user($this->id['staff']);
        $this->assertSame('cashier_normal', $row->username);
        $this->assertSame('Staff', $row->role);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertNull($row->supplier_id);
        $this->assertSame('Renamed Cashier', $row->full_name);
    }

    public function testTheFormOnlyOffersNameEmailAndContactNumber(): void
    {
        $body = $this->staff()->get('staff/account')->getBody();

        foreach (['name="full_name"', 'name="email"', 'name="phone"', 'name="current_password"'] as $field) {
            $this->assertStringContainsString($field, $body);
        }
        $this->assertStringNotContainsString('name="username"', $body);
        $this->assertStringNotContainsString('name="role"', $body);
        $this->assertStringContainsString('Your username and role are set by the owner.', $body);
    }

    public function testValidation(): void
    {
        $cases = [
            'no name'       => [['full_name' => ''], 'Enter your full name.'],
            'name too long' => [['full_name' => str_repeat('a', 101)], 'Your name can be up to 100 characters.'],
            'not an email'  => [['email' => 'not-an-email'], 'Enter a real email address.'],
            'no email'      => [['email' => ''], 'Enter your email address.'],
            'number too long' => [['phone' => str_repeat('9', 21)], 'The contact number can be up to 20 characters.'],
            'email in use'  => [['email' => 'owner@real.test', 'current_password' => 'CashierPass123'], 'That email is already used by another account.'],
        ];

        foreach ($cases as $label => [$fields, $expected]) {
            unset($_SESSION['error']);
            $this->saveProfile($fields);
            $this->assertStringContainsString($expected, (string) $this->flash('error'), $label);
        }

        $row = $this->user($this->id['staff']);
        $this->assertSame('Real Cashier', $row->full_name);
        $this->assertSame('cashier@real.test', $row->email);
        $this->assertSame([], $this->mail->sent);
    }

    public function testAnEmailThatDiffersOnlyInCaseIsNotAChange(): void
    {
        $this->saveProfile(['email' => 'CASHIER@real.test']);

        $this->assertSame([], $this->mail->sent);
        $this->assertArrayNotHasKey(self::KEY, $_SESSION);
    }

    // ---------------------------------------------------------------- changing the email

    public function testChangingTheEmailNeedsTheCurrentPassword(): void
    {
        foreach (['', 'NotMyPassword1'] as $wrong) {
            unset($_SESSION['error']);
            $this->saveProfile(['full_name' => 'Renamed', 'email' => 'new@real.test', 'current_password' => $wrong]);

            $this->assertSame('Current password is incorrect.', $this->flash('error'));
        }

        $this->assertSame('cashier@real.test', $this->email());
        $this->assertSame('Real Cashier', $this->user($this->id['staff'])->full_name, 'nothing is saved when the password is wrong');
        $this->assertSame([], $this->mail->sent);
        $this->assertArrayNotHasKey(self::KEY, $_SESSION);
    }

    public function testTheCodeGoesToTheNewAddressAndTheEmailWaitsForIt(): void
    {
        [$code] = $this->requestChange();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame('cashier@real.test', $this->email(), 'the email must not change before the code is entered');
        $this->assertSame([], $this->mailsTo('cashier@real.test'), 'the code is not sent to the old address');
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
        $this->assertStringContainsString($code, (string) $this->mailsTo('new@real.test')[0]['setMessage']);
        $this->assertStringContainsString('10 minutes', (string) $this->mailsTo('new@real.test')[0]['setAltMessage']);
    }

    public function testAWrongCodeIsRefusedAndTheEmailStays(): void
    {
        [$code, $pending] = $this->requestChange();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->confirm($wrong, $pending);

        $this->assertSame('Incorrect code, please try again.', $this->flash('error'));
        $this->assertSame('cashier@real.test', $this->email());
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
        $this->assertSame(1, $_SESSION[self::KEY]['tries']);
    }

    public function testFiveWrongCodesCancelTheChange(): void
    {
        [$code, $pending] = $this->requestChange();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->confirm($wrong, $_SESSION[self::KEY] ?? $pending);
        }
        $this->assertStringContainsString('Too many incorrect codes', (string) $this->flash('error'));
        $this->assertArrayNotHasKey(self::KEY, $_SESSION);

        // The change is gone: the right code no longer works either.
        $this->staff()->post('staff/account/email/confirm', ['code' => $code]);
        $this->assertStringContainsString('expired', (string) $this->flash('error'));
        $this->assertSame('cashier@real.test', $this->email());
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        [$code, $pending] = $this->requestChange();
        $pending['expires'] = time() - 1;

        $this->confirm($code, $pending);

        $this->assertStringContainsString('expired', (string) $this->flash('error'));
        $this->assertSame('cashier@real.test', $this->email());
    }

    public function testTheRightCodeChangesTheEmailTellsTheOwnerAndWarnsTheOldAddress(): void
    {
        // A name change in the same save: the alert shows the name as it is now.
        [$code, $pending] = $this->requestChange('new@real.test', ['full_name' => 'Renamed Cashier']);

        $this->confirm($code, $pending)->assertRedirectTo(site_url('staff/account'));

        $this->assertSame('new@real.test', $this->email());
        $this->assertArrayNotHasKey(self::KEY, $_SESSION);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'email_changed')->countAllResults());

        // Owner: an unread in-app notice with the Lock action.
        $note = $this->db->table('notifications')->where('title', 'Staff email changed')->get()->getRow();
        $this->assertNotNull($note);
        $this->assertSame('Admin', $note->recipient_role);
        $this->assertSame('Security', $note->type);
        $this->assertSame(StaffPasswordAlert::CATEGORY, $note->category);
        $this->assertSame(0, (int) $note->is_read);
        $this->assertSame('Renamed Cashier changed their email from cashier@real.test to new@real.test on ' . date('M j, Y, g:i A') . '.', $note->message);
        $this->assertSame($this->id['staff'], (int) $note->action_user_id);
        $this->assertNotEmpty($note->action_token);

        // Owner: the same alert by email, with the Lock link.
        $ownerMail = $this->mailsTo('owner@real.test');
        $this->assertCount(1, $ownerMail);
        $this->assertSame('Staff email changed', $ownerMail[0]['setSubject']);
        $this->assertSame(1, preg_match('#notification-action/\d+/[0-9a-f]{64}/lock#', (string) $ownerMail[0]['setMessage']));

        // The old address is warned.
        $oldMail = $this->mailsTo('cashier@real.test');
        $this->assertCount(1, $oldMail);
        $this->assertSame('Your RfourL account email was changed', $oldMail[0]['setSubject']);
        $this->assertSame("Your RfourL account email was changed. If this wasn't you, contact the owner.", $oldMail[0]['setMessage']);
    }

    public function testTheOwnerCanLockTheAccountFromTheEmailChangeAlert(): void
    {
        [$code, $pending] = $this->requestChange();
        $this->confirm($code, $pending);

        $mail = $this->mailsTo('owner@real.test')[0];
        preg_match('#notification-action/(\d+)/([0-9a-f]{64})/lock#', (string) $mail['setMessage'], $m);

        $this->post("notification-action/{$m[1]}/{$m[2]}/lock");

        $this->assertSame(0, (int) $this->user($this->id['staff'])->is_active);
    }

    public function testAnAddressTakenWhileTheCodeWasOutIsRefused(): void
    {
        [$code, $pending] = $this->requestChange();
        $this->db->table('users')->where('user_id', $this->id['supplier'])->update(['email' => 'new@real.test']);

        $this->confirm($code, $pending);

        $this->assertStringContainsString('already used', (string) $this->flash('error'));
        $this->assertSame('cashier@real.test', $this->email());
    }

    public function testACodeFromAnotherSessionDoesNothing(): void
    {
        $this->staff()->post('staff/account/email/confirm', ['code' => '123456']);

        $this->assertStringContainsString('expired', (string) $this->flash('error'));
        $this->assertSame('cashier@real.test', $this->email());
    }

    public function testCancelDropsThePendingChange(): void
    {
        [, $pending] = $this->requestChange();

        $this->staff('staff', [self::KEY => $pending])->post('staff/account/email/cancel')->assertRedirectTo(site_url('staff/account'));

        $this->assertArrayNotHasKey(self::KEY, $_SESSION);
    }

    public function testThePendingCodeBoxShowsOnTheAccountPage(): void
    {
        [, $pending] = $this->requestChange();

        $body = $this->staff('staff', [self::KEY => $pending])->get('staff/account')->getBody();

        $this->assertStringContainsString('name="code"', $body);
        $this->assertStringContainsString('new@real.test', $body);
    }

    // ---------------------------------------------------------------- who may do this

    public function testTheDemoStaffAccountCannotChangeItsProfile(): void
    {
        $this->demoOn();

        $this->saveProfile(['full_name' => 'Changed', 'email' => 'x@real.test', 'current_password' => 'DemoStaff123'], 'demo_staff');
        $this->assertRefused();

        $this->staff('demo_staff', [self::KEY => ['email' => 'x@real.test', 'hash' => password_hash('123456', PASSWORD_DEFAULT), 'expires' => time() + 60, 'tries' => 0]])
            ->post('staff/account/email/confirm', ['code' => '123456']);
        $this->assertRefused();

        $row = $this->user($this->id['demo_staff']);
        $this->assertSame('Demo Staff', $row->full_name);
        $this->assertSame('ourteam+staff@example.test', $row->email);
        $this->assertSame([], $this->mail->sent);
    }

    public function testOnlySignedInStaffCanUseTheseRoutes(): void
    {
        foreach (['staff/account/profile', 'staff/account/email/confirm', 'staff/account/email/cancel'] as $route) {
            $this->post($route, ['full_name' => 'X'])->assertRedirectTo(site_url('login/staff'));
            $this->actingAs('owner')->post($route, ['full_name' => 'X'])->assertRedirectTo(site_url('login/staff'));
        }

        $this->assertSame('Real Cashier', $this->user($this->id['staff'])->full_name);
        $this->assertContains('csrf', (new \Config\Filters())->globals['before'], 'CSRF protection must stay on for every POST');
    }
}
