<?php

use App\Libraries\PasswordPolicy;
use Tests\Support\DemoTestCase;

/**
 * Settings > My Profile for the Owner: a view mode with an edit pen, an edit form that only opens on
 * request (or after a failed save of that form), the Sign-in & Security card, and the two dialogs.
 * The forms still post to the same routes with the same field names.
 *
 * @internal
 */
final class OwnerProfileTabTest extends DemoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();
        $str   = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $num   = ['type' => 'REAL', 'null' => true];
        foreach (['dss_parameters', 'notification_preferences', 'pdss_computation', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'ordering_cost' => $num, 'holding_cost_per_unit' => $num, 'service_level_target' => $num, 'z_score' => $num, 'demand_lookback_days' => $num, 'minimum_order_qty' => $num]);
        $forge->addKey('id', true);
        $forge->createTable('dss_parameters');
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'rop_alerts' => ['type' => 'INTEGER', 'default' => 1], 'updated_by' => ['type' => 'INTEGER', 'null' => true]]);
        $forge->addKey('id', true);
        $forge->createTable('notification_preferences');
        $forge->addField(['computation_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'computed_at' => ['type' => 'DATETIME', 'null' => true], 'item_id' => $str]);
        $forge->addKey('computation_id', true);
        $forge->createTable('pdss_computation');
        $forge->addField(['key' => $str, 'value' => $str]);
        $forge->createTable('app_settings');

        $this->db->table('users')->where('user_id', $this->id['owner'])->update(['phone' => '0917 123 4567', 'last_login' => '2026-11-07 15:21:00', 'created_at' => '2025-01-15 09:00:00']);
    }

    protected function tearDown(): void
    {
        foreach (['dss_parameters', 'notification_preferences', 'pdss_computation', 'app_settings'] as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        parent::tearDown();
    }

    private function profile(array $flash = []): string
    {
        $session = [
            'isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->id['owner'], 'username' => 'owner_normal', 'full_name' => 'Real Owner', 'last_activity' => time(),
        ];
        foreach ($flash as $key => $value) {
            $session[$key] = $value;
            $session['__ci_vars'][$key] = 'new';
        }

        return $this->withSession($session)->get('admin/settings?tab=profile')->response()->getBody();
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new DOMXPath($dom);
    }

    /** @return array<string, string> label => value of every row in this card or block */
    private function rows(DOMXPath $x, DOMNode $scope): array
    {
        $out = [];
        foreach ($x->query('.//div[contains(@class,"set-detail")]', $scope) as $row) {
            $out[trim($x->query('./span', $row)->item(0)->textContent)] = trim(preg_replace('/\s+/', ' ', $x->query('./strong|./span[2]|./div/strong', $row)->item(0)->textContent));
        }

        return $out;
    }

    // ---------------------------------------------------------------- Personal Information: view mode first

    public function testPersonalInformationStartsInViewModeWithAnEditPen(): void
    {
        $x = $this->dom($this->profile());

        // The read-only rows, in the same label / value style as Account Details.
        $view = $x->query('//div[@id="pfView"]')->item(0);
        $this->assertNotNull($view);
        $this->assertFalse($view->hasAttribute('hidden'), 'view mode is showing');
        $this->assertSame(['Full Name' => 'Real Owner', 'Email Address' => 'owner@real.test', 'Contact Number' => '0917 123 4567'], $this->rows($x, $view));

        // The pen.
        $pen = $x->query('//button[@id="pfEditBtn"]')->item(0);
        $this->assertNotNull($pen);
        $this->assertSame('Edit personal information', $pen->getAttribute('aria-label'));
        $this->assertSame('Edit', $pen->getAttribute('data-tooltip'));
        $this->assertSame('button', $pen->getAttribute('type'));
        $this->assertFalse($pen->hasAttribute('hidden'));
        $this->assertSame(1, $x->query('.//i[contains(@class,"bi-pencil")]', $pen)->length);

        // The inputs exist but the form is closed.
        $form = $x->query('//form[@id="pfForm"]')->item(0);
        $this->assertTrue($form->hasAttribute('hidden'), 'the edit form starts closed');
        $this->assertStringEndsWith('admin/settings/account', $form->getAttribute('action'));
        $this->assertSame('Real Owner', $x->query('.//input[@name="full_name"]', $form)->item(0)->getAttribute('value'));
        $this->assertSame('owner@real.test', $x->query('.//input[@name="email"]', $form)->item(0)->getAttribute('value'));
        $this->assertSame('0917 123 4567', $x->query('.//input[@name="phone"]', $form)->item(0)->getAttribute('value'));
        $this->assertTrue($x->query('//div[@id="pfEmailPwField"]')->item(0)->hasAttribute('hidden'), 'the current password is only asked for when the email changes');

        // Cancel and Save, with a CSRF field in the form.
        $this->assertSame('Cancel', trim($x->query('.//button[@id="pfCancelBtn"]', $form)->item(0)->textContent));
        $this->assertSame('Save', trim($x->query('.//button[@type="submit"]', $form)->item(0)->textContent));
        $this->assertSame(1, $x->query('.//input[@type="hidden"][@name="csrf_test_name"]', $form)->length);
    }

    public function testAnEmptyContactNumberShowsADash(): void
    {
        $this->db->table('users')->where('user_id', $this->id['owner'])->update(['phone' => null]);

        $x = $this->dom($this->profile());

        $this->assertSame('—', $this->rows($x, $x->query('//div[@id="pfView"]')->item(0))['Contact Number']);
    }

    public function testTheEditScriptKeepsTheCurrentPasswordRuleAndCancelRestoresTheValues(): void
    {
        $html = $this->profile();

        // The email-changed logic and the Cancel behaviour are in the page.
        $this->assertStringContainsString("email.dataset.original", $html);
        $this->assertStringContainsString("field.hidden = !changed;", $html);
        $this->assertStringContainsString("pw.required = changed;", $html);
        $this->assertStringContainsString("i.value = i.dataset.original", $html);
        $this->assertSame(3, substr_count($html, 'data-original='), 'name, email and phone remember their saved values');
    }

    public function testAFailedSaveOfThisFormReopensItInEditMode(): void
    {
        foreach (['Enter your current password to change your email.', 'The email field must contain a valid email address.', 'The email field must contain a unique value.'] as $error) {
            $x = $this->dom($this->profile(['error' => $error]));

            $this->assertTrue($x->query('//div[@id="pfView"]')->item(0)->hasAttribute('hidden'), $error);
            $this->assertFalse($x->query('//form[@id="pfForm"]')->item(0)->hasAttribute('hidden'), $error);
            $this->assertTrue($x->query('//button[@id="pfEditBtn"]')->item(0)->hasAttribute('hidden'), 'the pen is hidden while editing');
            $this->assertStringContainsString($error, $x->query('//div[contains(@class,"alert-danger")]')->item(0)->textContent, 'the message still shows');
        }
    }

    public function testErrorsFromTheOtherFormsDoNotOpenEditMode(): void
    {
        foreach (['Current password is incorrect.', 'That username is already taken.', 'Passwords do not match.'] as $error) {
            $x = $this->dom($this->profile(['error' => $error]));

            $this->assertFalse($x->query('//div[@id="pfView"]')->item(0)->hasAttribute('hidden'), $error);
            $this->assertTrue($x->query('//form[@id="pfForm"]')->item(0)->hasAttribute('hidden'), $error);
            $this->assertStringContainsString($error, $x->query('//div[contains(@class,"alert-danger")]')->item(0)->textContent);
        }
    }

    public function testSuccessMessagesStillShow(): void
    {
        foreach (['Profile updated.', 'Username updated. Use it the next time you sign in.', 'Owner password updated.'] as $message) {
            $x = $this->dom($this->profile(['success' => $message]));

            $this->assertStringContainsString($message, $x->query('//div[contains(@class,"alert-success")]')->item(0)->textContent);
        }
    }

    // ---------------------------------------------------------------- Account Details and Sign-in & Security

    public function testAccountDetailsIsReadOnlyAndNoLongerHasTheUsername(): void
    {
        $x    = $this->dom($this->profile());
        $card = $x->query('//h5[normalize-space()="Account Details"]/ancestor::div[contains(@class,"set-card")][1]')->item(0);

        $this->assertSame(
            ['Role' => 'Owner', 'Account Status' => 'Active', 'Member Since' => 'January 15, 2025', 'Last Login' => 'Nov 7, 2026 3:21 PM'],
            $this->rows($x, $card)
        );
        $this->assertStringNotContainsString('Username', $card->textContent);
        $this->assertSame(0, $x->query('.//input|.//button', $card)->length, 'nothing to edit here');
    }

    public function testSignInAndSecurityHasUsernamePasswordAndTheNote(): void
    {
        $x    = $this->dom($this->profile());
        $card = $x->query('//div[@id="pfSecurity"]')->item(0);
        $this->assertNotNull($card);
        $this->assertSame('Sign-in & Security', trim($x->query('.//h5', $card)->item(0)->textContent));

        $rows = $this->rows($x, $card);
        $this->assertSame('owner_normal', $rows['Username']);
        $this->assertSame('••••••••', $rows['Password']);
        $text = preg_replace('/\s+/', ' ', $card->textContent);
        $this->assertStringContainsString('For your security, changing your username, email or password asks for your current password.', $text);

        $username = $x->query('.//button[@aria-label="Change username"]', $card)->item(0);
        $this->assertNotNull($username);
        $this->assertSame('#ownerUsernameModal', $username->getAttribute('data-bs-target'));
        $this->assertSame('modal', $username->getAttribute('data-bs-toggle'));
        $this->assertSame(1, $x->query('.//i[contains(@class,"bi-pencil")]', $username)->length);

        $password = $x->query('.//button[@data-bs-target="#ownerPasswordModal"]', $card)->item(0);
        $this->assertNotNull($password);
        $this->assertStringContainsString('Change password', $password->textContent);
        $this->assertSame(1, $x->query('.//i[contains(@class,"bi-key")]', $password)->length);
        $this->assertStringContainsString('btn-outline-dark', $password->getAttribute('class'));
    }

    // ---------------------------------------------------------------- the dialogs

    public function testTheUsernameDialogPostsToTheUsernameRoute(): void
    {
        $x     = $this->dom($this->profile());
        $modal = $x->query('//div[@id="ownerUsernameModal"]')->item(0);
        $this->assertNotNull($modal);

        $form = $x->query('.//form', $modal)->item(0);
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertStringEndsWith('admin/settings/username', $form->getAttribute('action'));
        $this->assertSame(1, $x->query('.//input[@name="csrf_test_name"]', $form)->length);

        $username = $x->query('.//input[@name="username"]', $form)->item(0);
        $this->assertSame('owner_normal', $username->getAttribute('value'));
        $this->assertSame('[A-Za-z0-9_\-]+', $username->getAttribute('pattern'));
        $this->assertSame('4', $username->getAttribute('minlength'));
        $this->assertSame('50', $username->getAttribute('maxlength'));
        $this->assertSame('password', $x->query('.//input[@name="current_password"]', $form)->item(0)->getAttribute('type'));
    }

    public function testThePasswordDialogPostsToTheOwnerPasswordRoute(): void
    {
        $x     = $this->dom($this->profile());
        $modal = $x->query('//div[@id="ownerPasswordModal"]')->item(0);
        $this->assertNotNull($modal);

        $form = $x->query('.//form', $modal)->item(0);
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertStringEndsWith('admin/settings/owner-password', $form->getAttribute('action'));
        $this->assertSame(1, $x->query('.//input[@name="csrf_test_name"]', $form)->length);

        foreach (['current_password', 'new_password', 'confirm_password'] as $name) {
            $input = $x->query(".//input[@name=\"{$name}\"]", $form)->item(0);
            $this->assertNotNull($input, $name);
            $this->assertSame('password', $input->getAttribute('type'));
            $this->assertTrue($input->hasAttribute('required'));
        }
        $this->assertSame(PasswordPolicy::HINT, $x->query('.//input[@name="new_password"]', $form)->item(0)->getAttribute('placeholder'));
        $this->assertSame('Cancel', trim($x->query('.//button[@data-bs-dismiss="modal"][contains(@class,"btn-outline-dark")]', $form)->item(0)->textContent));
        $this->assertSame('Save', trim($x->query('.//button[@type="submit"]', $form)->item(0)->textContent));
    }

    public function testTheOldAlwaysVisibleChangePasswordFormIsGone(): void
    {
        $html = $this->profile();
        $x    = $this->dom($html);

        $this->assertSame(1, substr_count($html, 'admin/settings/owner-password'), 'the route is posted to from one place only');
        $this->assertSame(1, $x->query('//div[@id="ownerPasswordModal"]//form[contains(@action,"admin/settings/owner-password")]')->length, 'and that is inside the dialog');
        $this->assertSame(0, $x->query('//h5[contains(@class,"pf-title")][normalize-space()="Change Password"]')->length, 'no Change Password card on the page');
        $this->assertSame(3, $x->query('//form[contains(@action,"admin/settings/")]')->length, 'only the three forms: personal information, username, password');
    }

    public function testEveryPasswordFieldIsLeftToThePageScriptForItsOneEye(): void
    {
        $html = $this->profile();

        // pw-toggle.js (loaded by the layout) adds exactly one eye to each field; none is written into the markup here.
        $this->assertStringContainsString('<script src="/assets/js/pw-toggle.js"></script>', $html);
        $this->assertStringContainsString('<link href="/assets/css/pw-toggle.css" rel="stylesheet">', $html);
        $this->assertSame(0, substr_count($html, 'class="pw-toggle"'));
        $this->assertSame(5, substr_count($html, 'type="password"'), 'email change, username dialog, and the three password dialog fields');
    }

    // ---------------------------------------------------------------- the demo owner

    public function testEveryActionButtonIsDisabledForTheDemoOwner(): void
    {
        $this->demoOn();
        $html = $this->withSession([
            'isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->id['demo_owner'], 'username' => 'demo_owner', 'full_name' => 'Demo Owner', 'last_activity' => time(),
        ])->get('admin/settings?tab=profile')->response()->getBody();
        $x = $this->dom($html);

        foreach (['//button[@id="pfEditBtn"]', '//button[@aria-label="Change username"]', '//button[@data-bs-target="#ownerPasswordModal"]', '//form[@id="pfForm"]//button[@type="submit"]', '//div[@id="ownerPasswordModal"]//button[@type="submit"]'] as $path) {
            $button = $x->query($path)->item(0);
            $this->assertNotNull($button, $path);
            $this->assertTrue($button->hasAttribute('disabled'), $path);
            $this->assertSame('Disabled in the demo version', $button->getAttribute('title'), $path);
        }
    }

    public function testTheTabOpensAfterARedirectToIt(): void
    {
        $this->actingAs('owner')->post('admin/settings/username', ['username' => 'bad name!', 'current_password' => 'OwnerPass123'])
            ->assertRedirectTo(site_url('admin/settings?tab=profile'));

        $this->assertStringContainsString('My Profile', $this->profile());
    }
}
