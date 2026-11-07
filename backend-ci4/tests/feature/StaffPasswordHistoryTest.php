<?php

use App\Libraries\AttemptStore;
use App\Libraries\PasswordHistory;
use App\Libraries\PasswordPolicy;
use Config\Services;
use Tests\Support\DemoTestCase;

/**
 * Staff can't go back to a password they used recently: the current one and the last three before it
 * (My Account and Set Password), owner-issued temporary passwords are never counted, and the
 * suggestion offered is a fresh, valid, unused one that never reaches the logs.
 *
 * @internal
 */
final class StaffPasswordHistoryTest extends DemoTestCase
{
    private const REFUSED = "You can't use your old password. Try something new, like: ";

    private object $mail;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();
        $forge->dropTable('password_history', true);
        $forge->addField([
            'id'            => ['type' => 'INTEGER', 'auto_increment' => true],
            'user_id'       => ['type' => 'INTEGER'],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('password_history');

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

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('password_history', true);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** Changes the staff member's password from My Account, with the 5-tries-per-10-minutes counter reset. */
    private function change(string $current, string $new)
    {
        AttemptStore::forget('staff_pw_change_' . $this->id['staff']);

        return $this->actingAs('staff')->post('staff/account/password', [
            'current_password' => $current, 'new_password' => $new, 'confirm_password' => $new,
        ]);
    }

    private function historyCount(): int
    {
        return $this->db->table('password_history')->where('user_id', $this->id['staff'])->countAllResults();
    }

    private function assertRefusedWithASuggestion(): string
    {
        $error = (string) $this->flash('error');
        $this->assertStringStartsWith(self::REFUSED, $error);

        $suggestion = substr($error, strlen(self::REFUSED));
        $this->assertSame($suggestion, $this->flash('password_suggestion'));
        $this->assertNull(PasswordPolicy::check($suggestion));
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+-[A-Z][a-z]+\d{2}[!#$%&*]$/', $suggestion);
        $this->assertFalse($this->userModelInUse($suggestion));

        return $suggestion;
    }

    private function userModelInUse(string $password): bool
    {
        return (new \App\Models\UserModel())->staffPasswordInUse($password);
    }

    /** Owner resets the staff member's password; returns the temporary password from the email. */
    private function ownerResets(): string
    {
        $this->actingAs('owner')->post('admin/settings/staff-accounts/password/' . $this->id['staff'], ['owner_password' => 'OwnerPass123', 'confirmed' => '1']);

        foreach (array_reverse($this->mail->sent) as $m) {
            if (($m['setTo'] ?? '') === 'cashier@real.test' && preg_match('#letter-spacing:2px;">([A-Za-z0-9]{12})</p>#', (string) ($m['setMessage'] ?? ''), $x)) {
                return $x[1];
            }
        }
        $this->fail('No temporary password was emailed.');
    }

    private function setPassword(string $new)
    {
        return $this->actingAs('staff')->post('staff/set-password', ['new_password' => $new, 'confirm_password' => $new]);
    }

    // ---------------------------------------------------------------- My Account

    public function testReusingTheCurrentPasswordIsRefusedWithASuggestion(): void
    {
        $this->change('CashierPass123', 'CashierPass123')->assertRedirectTo(site_url('staff/account'));

        $this->assertRefusedWithASuggestion();
        $this->assertTrue(password_verify('CashierPass123', $this->user($this->id['staff'])->password_hash));
        $this->assertSame(0, $this->historyCount());
    }

    public function testPasswordsFromHistoryAreRefusedAndANewOneWorks(): void
    {
        $this->change('CashierPass123', 'SecondPass456');
        $this->assertSame(1, $this->historyCount());
        $this->change('SecondPass456', 'ThirdPass789');
        $this->change('ThirdPass789', 'FourthPass012');

        // Current + the last three: all refused, each with a suggestion.
        foreach (['FourthPass012', 'ThirdPass789', 'SecondPass456', 'CashierPass123'] as $old) {
            unset($_SESSION['error'], $_SESSION['password_suggestion']);
            $this->change('FourthPass012', $old);
            $this->assertRefusedWithASuggestion();
        }
        $this->assertTrue(password_verify('FourthPass012', $this->user($this->id['staff'])->password_hash));

        // A new one works.
        $this->change('FourthPass012', 'BrandNewPass345')->assertRedirectTo(site_url('staff/account'));
        $this->assertTrue(password_verify('BrandNewPass345', $this->user($this->id['staff'])->password_hash));
    }

    public function testOnlyTheLastThreeAreRememberedSoTheFourthOldestIsAllowedAgain(): void
    {
        $this->change('CashierPass123', 'SecondPass456');
        $this->change('SecondPass456', 'ThirdPass789');
        $this->change('ThirdPass789', 'FourthPass012');
        $this->change('FourthPass012', 'FifthPass3456');

        $this->assertSame(3, $this->historyCount());

        // "SecondPass456" is still among the last three and stays refused...
        $this->change('FifthPass3456', 'SecondPass456');
        $this->assertRefusedWithASuggestion();

        // ...but the original password is now the fourth-oldest and can be used again.
        unset($_SESSION['error'], $_SESSION['password_suggestion']);
        $this->change('FifthPass3456', 'CashierPass123')->assertRedirectTo(site_url('staff/account'));
        $this->assertNull($this->flash('error'));
        $this->assertTrue(password_verify('CashierPass123', $this->user($this->id['staff'])->password_hash));
    }

    public function testTheUniquePasswordCheckAndItsWordingAreUnchanged(): void
    {
        $this->change('CashierPass123', 'DemoStaff123');

        $this->assertSame("This password can't be used. Please choose a different one.", $this->flash('error'));
    }

    public function testRefusedReuseIsLoggedWithoutAnyPassword(): void
    {
        $this->change('CashierPass123', 'CashierPass123');
        $suggestion = $this->assertRefusedWithASuggestion();

        $logs = json_encode($this->db->table('security_log')->get()->getResultArray());
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'password_reuse_blocked')->countAllResults());
        $this->assertStringNotContainsString('CashierPass123', $logs);
        $this->assertStringNotContainsString($suggestion, $logs);
    }

    public function testTheSuggestionAndItsUseThisButtonAreOnThePage(): void
    {
        $page = $this->withSession([
            'isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->id['staff'], 'username' => 'cashier_normal', 'last_activity' => time(),
            'error' => self::REFUSED . 'Mango-Tiger47!', 'password_suggestion' => 'Mango-Tiger47!',
            '__ci_vars' => ['error' => 'new', 'password_suggestion' => 'new'],
        ])->get('staff/account');

        $body = $page->getBody();
        $this->assertStringContainsString(self::REFUSED . 'Mango-Tiger47!', html_entity_decode($body));
        $this->assertStringContainsString('id="useSuggestion"', $body);
        $this->assertStringContainsString('data-value="Mango-Tiger47!"', $body);
        $this->assertStringContainsString("['acctNew', 'acctConfirm']", $body);
    }

    public function testSuggestionsAreFreshValidAndNotInUse(): void
    {
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $s = PasswordHistory::suggest($this->id['staff']);
            $this->assertNull(PasswordPolicy::check($s));
            $this->assertFalse($this->userModelInUse($s));
            $seen[$s] = true;
        }
        $this->assertGreaterThan(5, count($seen));
    }

    // ---------------------------------------------------------------- temporary passwords and Set Password

    public function testAnOwnerResetRemembersTheRealOldPasswordButNeverTheTemporaryOne(): void
    {
        $this->change('CashierPass123', 'SecondPass456'); // history: CashierPass123
        $temp = $this->ownerResets();                      // history: + SecondPass456

        $this->assertSame(2, $this->historyCount());
        foreach ($this->db->table('password_history')->get()->getResult() as $row) {
            $this->assertFalse(password_verify($temp, $row->password_hash), 'a temporary password must not be remembered');
        }
        $this->assertTrue(PasswordHistory::reuses((array) $this->user($this->id['staff']), 'CashierPass123', false));
        $this->assertTrue(PasswordHistory::reuses((array) $this->user($this->id['staff']), 'SecondPass456', false));

        // Choosing a password of their own does not add the temporary one either.
        $this->setPassword('ChosenPass789');
        $this->assertSame(2, $this->historyCount());
        $this->assertTrue(password_verify('ChosenPass789', $this->user($this->id['staff'])->password_hash));
    }

    public function testSetPasswordRefusesEarlierPasswordsWithASuggestionButTheTemporaryOneKeepsItsOwnMessage(): void
    {
        $this->change('CashierPass123', 'SecondPass456');
        $temp = $this->ownerResets();

        foreach (['SecondPass456', 'CashierPass123'] as $old) {
            unset($_SESSION['error'], $_SESSION['password_suggestion']);
            $this->setPassword($old)->assertRedirectTo(site_url('staff/set-password'));
            $this->assertRefusedWithASuggestion();
            $this->assertSame(1, (int) $this->user($this->id['staff'])->must_change_password);
        }

        // The temporary password itself: the page's existing wording, and it is not an "old password".
        unset($_SESSION['error'], $_SESSION['password_suggestion']);
        $this->setPassword($temp);
        $this->assertSame('Choose a different password from the temporary one.', $this->flash('error'));
        $this->assertNull($this->flash('password_suggestion'));

        // A new one is accepted.
        unset($_SESSION['error']);
        $this->setPassword('ChosenPass789')->assertRedirectTo(site_url('/staff/dashboard'));
        $this->assertSame(0, (int) $this->user($this->id['staff'])->must_change_password);
    }

    public function testSetPasswordOnTheSuggestionPageShowsTheUseThisButton(): void
    {
        $this->change('CashierPass123', 'SecondPass456');
        $this->ownerResets();

        $page = $this->withSession([
            'isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->id['staff'], 'username' => 'cashier_normal', 'last_activity' => time(),
            'error' => self::REFUSED . 'Mango-Tiger47!', 'password_suggestion' => 'Mango-Tiger47!',
            '__ci_vars' => ['error' => 'new', 'password_suggestion' => 'new'],
        ])->get('staff/set-password');

        $this->assertStringContainsString('data-value="Mango-Tiger47!"', $page->getBody());
        $this->assertStringContainsString('input[name=new_password], input[name=confirm_password]', $page->getBody());
    }

    public function testANewAccountsFirstTemporaryPasswordIsNotRemembered(): void
    {
        $this->actingAs('owner')->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => 'newperson', 'contact_email' => 'new@real.test']);

        $this->assertSame(0, $this->db->table('password_history')->countAllResults());
    }

    public function testNothingBreaksWhenTheHistoryTableIsMissing(): void
    {
        \Config\Database::forge()->dropTable('password_history', true);

        $this->change('CashierPass123', 'SecondPass456')->assertRedirectTo(site_url('staff/account'));
        $this->assertTrue(password_verify('SecondPass456', $this->user($this->id['staff'])->password_hash));
        $this->ownerResets();
    }
}
