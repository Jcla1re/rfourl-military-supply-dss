<?php

use App\Libraries\PasswordPolicy;
use Tests\Support\DemoTestCase;

/**
 * Settings > My Profile for the Owner: two cards that each open their own form with a pen.
 * Personal Information (name + contact number) posts to admin/settings/account; Sign-in & Security
 * (username, email, password, current password once) posts to admin/settings/sign-in. A refused save
 * reopens only its own form, with the message under the field.
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

        $this->db->table('users')->where('user_id', $this->id['owner'])->update(['phone' => '0917 123 4567', 'last_login' => '2026-11-17 08:00:00', 'created_at' => '2025-01-15 09:00:00']);
    }

    protected function tearDown(): void
    {
        foreach (['dss_parameters', 'notification_preferences', 'pdss_computation', 'app_settings'] as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        parent::tearDown();
    }

    /** The page, with flash data as the next request would see it (and optional extra session keys). */
    private function profile(array $flash = [], array $session = []): string
    {
        $session += [
            'isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->id['owner'], 'username' => 'owner_normal', 'full_name' => 'Real Owner', 'last_activity' => time(),
            'previous_login' => '2026-11-07 15:21:00',
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

        // The read-only rows: name and contact number (the email moved to Sign-in & Security).
        $view = $x->query('//div[@id="pfView"]')->item(0);
        $this->assertNotNull($view);
        $this->assertFalse($view->hasAttribute('hidden'), 'view mode is showing');
        $this->assertSame(['Full Name' => 'Real Owner', 'Contact Number' => '0917 123 4567'], $this->rows($x, $view));

        // The pen.
        $pen = $x->query('//button[@id="pfEditBtn"]')->item(0);
        $this->assertNotNull($pen);
        $this->assertSame('Edit personal information', $pen->getAttribute('aria-label'));
        $this->assertSame('Edit', $pen->getAttribute('data-tooltip'));
        $this->assertSame('button', $pen->getAttribute('type'));
        $this->assertFalse($pen->hasAttribute('hidden'));
        $this->assertSame(1, $x->query('.//i[contains(@class,"bi-pencil")]', $pen)->length);

        // The inputs exist but the form is closed; no email and no password here.
        $form = $x->query('//form[@id="pfForm"]')->item(0);
        $this->assertTrue($form->hasAttribute('hidden'), 'the edit form starts closed');
        $this->assertStringEndsWith('admin/settings/account', $form->getAttribute('action'));
        $this->assertTrue($form->hasAttribute('data-submit-once'));
        $this->assertSame('Real Owner', $x->query('.//input[@name="full_name"]', $form)->item(0)->getAttribute('value'));
        $this->assertSame('0917 123 4567', $x->query('.//input[@name="phone"]', $form)->item(0)->getAttribute('value'));
        $this->assertSame(0, $x->query('.//input[@name="email"]', $form)->length, 'the email is not in this form any more');
        $this->assertSame(0, $x->query('.//input[@type="password"]', $form)->length, 'no password needed for name and number');

        // Cancel and Save, with a CSRF field in the form.
        $this->assertSame('Cancel', trim($x->query('.//button[@id="pfCancelBtn"]', $form)->item(0)->textContent));
        $this->assertSame('Save', trim($x->query('.//button[@type="submit"]', $form)->item(0)->textContent));
        $this->assertSame(1, $x->query('.//input[@type="hidden"][@name="csrf_test_name"]', $form)->length);
    }

    public function testTheFullNameHasTheAddItemStyleCounterAndNoHardLimit(): void
    {
        $html = $this->profile();
        $x    = $this->dom($html);

        // "n / 60" on the label's line, like Item name in Add item.
        $row   = $x->query('//form[@id="pfForm"]//div[contains(@class,"im-label-row")]')->item(0);
        $count = $x->query('.//small[@id="pfNameCount"]', $row)->item(0);
        $this->assertSame('Full Name', trim($x->query('./label[@for="pfFullName"]', $row)->item(0)->textContent));
        $this->assertSame('10 / 60', trim($count->textContent), '"Real Owner" is 10 characters');
        $this->assertSame('im-count', $count->getAttribute('class'));

        // Typing past 60 is allowed: no maxlength; the counter and the message are tied to the field.
        $input = $x->query('//input[@id="pfFullName"]')->item(0);
        $this->assertFalse($input->hasAttribute('maxlength'));
        $this->assertSame('pfNameCount pfFullNameErr', $input->getAttribute('aria-describedby'));
        $this->assertSame('', trim($x->query('//div[@id="pfFullNameErr"]')->item(0)->textContent), 'nothing red to start with');

        // The live check: characters (not bytes), amber from 50, red past 60 with the message, Save disabled.
        $this->assertStringContainsString('const len = [...name.value].length, over = len > NAME_MAX;', $html);
        $this->assertStringContainsString("NAME_MAX = 60, NAME_OVER_MSG = 'Full name can be up to 60 characters.'", $html);
        $this->assertStringContainsString("nameCount.classList.toggle('is-near', len >= 50 && !over);", $html);
        $this->assertStringContainsString("nameCount.classList.toggle('is-over', over);", $html);
        $this->assertStringContainsString('nameSave.disabled = saveLocked || over;', $html);
    }

    public function testAFullNameOverSixtyIsShownRedWhenThePageOpens(): void
    {
        $long = str_repeat('Ñ', 61);
        $x    = $this->dom($this->profile([
            'error'         => 'Full name can be up to 60 characters.',
            'field_errors'  => ['form' => 'personal', 'fields' => ['full_name' => 'Full name can be up to 60 characters.']],
            '_ci_old_input' => ['get' => [], 'post' => ['full_name' => $long, 'phone' => '']],
        ]));

        $count = $x->query('//small[@id="pfNameCount"]')->item(0);
        $this->assertSame('61 / 60', trim($count->textContent), 'Ñ counts as one character');
        $this->assertStringContainsString('is-over', $count->getAttribute('class'));
        $input = $x->query('//input[@id="pfFullName"]')->item(0);
        $this->assertSame($long, $input->getAttribute('value'));
        $this->assertSame('is-invalid', $input->getAttribute('class'));
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertSame('Full name can be up to 60 characters.', trim($x->query('//div[@id="pfFullNameErr"]')->item(0)->textContent));
    }

    public function testAnEmptyContactNumberShowsADash(): void
    {
        $this->db->table('users')->where('user_id', $this->id['owner'])->update(['phone' => null]);

        $x = $this->dom($this->profile());

        $this->assertSame('—', $this->rows($x, $x->query('//div[@id="pfView"]')->item(0))['Contact Number']);
    }

    public function testTheEditScriptKeepsTheEmailConfirmRuleAndCancelRestoresTheValues(): void
    {
        $html = $this->profile();

        // The email-changed logic (second email box) and the Cancel behaviour are in the page.
        $this->assertStringContainsString('email.dataset.original', $html);
        $this->assertStringContainsString('confirmField.hidden = !changed;', $html);
        $this->assertStringContainsString('i.value = i.dataset.original', $html);
        $this->assertStringContainsString("form.querySelectorAll('input[type=password]').forEach((i) => { i.value = ''; });", $html, 'Cancel empties the password fields');
        $this->assertSame(4, substr_count($html, 'data-original='), 'name, phone, username and email remember their saved values');
    }

    // ---------------------------------------------------------------- a refused save reopens only its own form

    public function testARefusedPersonalSaveReopensThatFormWithTheMessageUnderTheField(): void
    {
        $x = $this->dom($this->profile([
            'error'        => 'Use an 11-digit mobile number like 0917 123 4567.',
            'field_errors' => ['form' => 'personal', 'fields' => ['phone' => 'Use an 11-digit mobile number like 0917 123 4567.']],
            '_ci_old_input' => ['get' => [], 'post' => ['full_name' => 'Real Owner', 'phone' => '12345']],
        ]));

        $this->assertTrue($x->query('//div[@id="pfView"]')->item(0)->hasAttribute('hidden'));
        $this->assertFalse($x->query('//form[@id="pfForm"]')->item(0)->hasAttribute('hidden'));
        $this->assertTrue($x->query('//button[@id="pfEditBtn"]')->item(0)->hasAttribute('hidden'), 'the pen is hidden while editing');
        $this->assertTrue($x->query('//form[@id="siForm"]')->item(0)->hasAttribute('hidden'), 'the other form stays closed');

        $phone = $x->query('//input[@id="pfPhone"]')->item(0);
        $this->assertSame('12345', $phone->getAttribute('value'), 'what was typed is kept');
        $this->assertSame('true', $phone->getAttribute('aria-invalid'));
        $this->assertSame('pfPhoneErr', $phone->getAttribute('aria-describedby'));
        $this->assertSame('Use an 11-digit mobile number like 0917 123 4567.', trim($x->query('//div[@id="pfPhoneErr"]')->item(0)->textContent));
        $this->assertSame(0, $x->query('//div[contains(@class,"set-body")]/div[contains(@class,"alert-danger")]')->length, 'shown under the field, not again at the top');
    }

    public function testARefusedSignInSaveReopensThatFormKeepsTypedValuesAndNeverAPassword(): void
    {
        $x = $this->dom($this->profile([
            'error'        => "The two emails don't match.",
            'field_errors' => ['form' => 'signin', 'fields' => ['email_confirm' => "The two emails don't match."]],
            // refuseForm() never keeps *pass* fields; the page must not show them either way.
            '_ci_old_input' => ['get' => [], 'post' => ['username' => 'owner_new', 'email' => 'new@real.test', 'email_confirm' => 'nwe@real.test']],
        ]));

        $this->assertTrue($x->query('//div[@id="siView"]')->item(0)->hasAttribute('hidden'));
        $this->assertFalse($x->query('//form[@id="siForm"]')->item(0)->hasAttribute('hidden'));
        $this->assertTrue($x->query('//form[@id="pfForm"]')->item(0)->hasAttribute('hidden'), 'the other form stays closed');
        $this->assertSame('owner_new', $x->query('//input[@id="siUsername"]')->item(0)->getAttribute('value'));
        $this->assertSame('new@real.test', $x->query('//input[@id="siEmail"]')->item(0)->getAttribute('value'));
        $this->assertFalse($x->query('//div[@id="siEmailConfirmField"]')->item(0)->hasAttribute('hidden'), 'the second email box shows: the email was changed');
        $this->assertSame("The two emails don't match.", trim($x->query('//div[@id="siEmailConfirmErr"]')->item(0)->textContent));
        foreach ($x->query('//form[@id="siForm"]//input[@type="password"]') as $pw) {
            $this->assertSame('', $pw->getAttribute('value'), $pw->getAttribute('name') . ' is never filled in');
        }
    }

    public function testAFormLevelMessageShowsInsideTheForm(): void
    {
        $x = $this->dom($this->profile([
            'error'        => 'Too many attempts. Please wait a few minutes and try again.',
            'field_errors' => ['form' => 'signin', 'fields' => ['form' => 'Too many attempts. Please wait a few minutes and try again.']],
        ]));

        $this->assertStringContainsString('Too many attempts', $x->query('//form[@id="siForm"]//div[contains(@class,"alert-danger")]')->item(0)->textContent);
    }

    public function testOtherErrorsStillShowAtTheTopAndOpenNoForm(): void
    {
        $x = $this->dom($this->profile(['error' => 'Account not found.']));

        $this->assertTrue($x->query('//form[@id="pfForm"]')->item(0)->hasAttribute('hidden'));
        $this->assertTrue($x->query('//form[@id="siForm"]')->item(0)->hasAttribute('hidden'));
        $this->assertStringContainsString('Account not found.', $x->query('//div[contains(@class,"alert-danger")]')->item(0)->textContent);
    }

    public function testSuccessMessagesStillShow(): void
    {
        foreach (['Profile updated.', 'Username changed. Use your new username the next time you sign in.', 'Password changed.', 'Sign-in details updated.'] as $message) {
            $x = $this->dom($this->profile(['success' => $message]));

            $this->assertStringContainsString($message, $x->query('//div[contains(@class,"alert-success")]')->item(0)->textContent);
        }
    }

    // ---------------------------------------------------------------- Account Details and Sign-in & Security

    public function testAccountDetailsIsReadOnlyAndShowsThePreviousSignIn(): void
    {
        $x    = $this->dom($this->profile());
        $card = $x->query('//h5[normalize-space()="Account Details"]/ancestor::div[contains(@class,"set-card")][1]')->item(0);

        // Last sign-in is the one before this session (session.previous_login), not users.last_login.
        $this->assertSame(
            ['Role' => 'Owner', 'Account Status' => 'Active', 'Member Since' => 'January 15, 2025', 'Last sign-in' => 'Nov 7, 2026 3:21 PM'],
            $this->rows($x, $card)
        );
        $this->assertStringNotContainsString('Username', $card->textContent);
        $this->assertSame(0, $x->query('.//input|.//button', $card)->length, 'nothing to edit here');
        $this->assertStringContainsString('Last sign-in: Nov 7, 2026 3:21 PM', preg_replace('/\s+/', ' ', $x->query('//div[contains(@class,"set-profile-head")]')->item(0)->textContent));
    }

    public function testTheFirstSignInSaysSo(): void
    {
        $x    = $this->dom($this->profile([], ['previous_login' => null]));
        $card = $x->query('//h5[normalize-space()="Account Details"]/ancestor::div[contains(@class,"set-card")][1]')->item(0);

        $this->assertSame('This is your first sign-in', $this->rows($x, $card)['Last sign-in']);
    }

    public function testSignInAndSecurityHasUsernameEmailPasswordOneEditButtonAndTheNote(): void
    {
        $x    = $this->dom($this->profile());
        $card = $x->query('//div[@id="pfSecurity"]')->item(0);
        $this->assertNotNull($card);
        $this->assertSame('Sign-in & Security', trim($x->query('.//h5', $card)->item(0)->textContent));

        $this->assertSame(['Username' => 'owner_normal', 'Email Address' => 'owner@real.test', 'Password' => '••••••••'], $this->rows($x, $x->query('.//div[@id="siView"]', $card)->item(0)));
        $text = preg_replace('/\s+/', ' ', $card->textContent);
        $this->assertStringContainsString('For your security, changing your username, email or password asks for your current password.', $text);

        // One Edit button for the whole card; no dialogs any more.
        $this->assertSame(1, $x->query('.//button[@type="button"][contains(@class,"pf-pen")]', $card)->length);
        $pen = $x->query('.//button[@id="siEditBtn"]', $card)->item(0);
        $this->assertSame('Edit sign-in details', $pen->getAttribute('aria-label'));
        $this->assertSame(1, $x->query('.//i[contains(@class,"bi-pencil")]', $pen)->length);
        $this->assertSame(0, $x->query('//*[@data-bs-toggle="modal"][contains(@data-bs-target,"owner")]')->length, 'the old username / password dialogs are gone');
    }

    public function testTheSignInFormPostsEveryFieldToTheOneRoute(): void
    {
        $x    = $this->dom($this->profile());
        $form = $x->query('//form[@id="siForm"]')->item(0);

        $this->assertTrue($form->hasAttribute('hidden'), 'closed until Edit');
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertStringEndsWith('admin/settings/sign-in', $form->getAttribute('action'));
        $this->assertTrue($form->hasAttribute('data-submit-once'));
        $this->assertSame(1, $x->query('.//input[@name="csrf_test_name"]', $form)->length);

        $username = $x->query('.//input[@name="username"]', $form)->item(0);
        $this->assertSame('owner_normal', $username->getAttribute('value'));
        $this->assertSame('[A-Za-z0-9_\-]+', $username->getAttribute('pattern'));
        $this->assertSame('4', $username->getAttribute('minlength'));
        $this->assertSame('50', $username->getAttribute('maxlength'));

        $email = $x->query('.//input[@name="email"]', $form)->item(0);
        $this->assertSame('email', $email->getAttribute('type'));
        $this->assertSame('owner@real.test', $email->getAttribute('value'));
        $this->assertTrue($x->query('//div[@id="siEmailConfirmField"]')->item(0)->hasAttribute('hidden'), 'the second email box only shows once the email changes');
        $this->assertSame(1, $x->query('.//input[@name="email_confirm"]', $form)->length);

        foreach (['new_password' => 'new-password', 'new_password_confirm' => 'new-password', 'current_password' => 'current-password'] as $name => $autocomplete) {
            $input = $x->query(".//input[@name=\"{$name}\"]", $form)->item(0);
            $this->assertNotNull($input, $name);
            $this->assertSame('password', $input->getAttribute('type'), $name);
            $this->assertSame($autocomplete, $input->getAttribute('autocomplete'), $name);
            $this->assertStringContainsString('pass', $name, 'every password field name contains "pass" (kept out of old input)');
        }
        $this->assertTrue($x->query('.//input[@name="current_password"]', $form)->item(0)->hasAttribute('required'));
        $this->assertFalse($x->query('.//input[@name="new_password"]', $form)->item(0)->hasAttribute('required'), 'blank keeps the current password');
        $this->assertSame(PasswordPolicy::HINT, $x->query('.//input[@name="new_password"]', $form)->item(0)->getAttribute('placeholder'));
        $this->assertSame('Cancel', trim($x->query('.//button[@id="siCancelBtn"]', $form)->item(0)->textContent));
        $this->assertSame('Save', trim($x->query('.//button[@type="submit"]', $form)->item(0)->textContent));
    }

    public function testOnlyTheTwoProfileFormsPostToSettingsRoutes(): void
    {
        $html = $this->profile();
        $x    = $this->dom($html);

        $this->assertStringNotContainsString('admin/settings/owner-password', $html);
        $this->assertStringNotContainsString('admin/settings/username', $html);
        $this->assertSame(1, substr_count($html, 'admin/settings/sign-in'), 'the sign-in route is posted to from one place only');
        $this->assertSame(0, $x->query('//h5[contains(@class,"pf-title")][normalize-space()="Change Password"]')->length, 'no Change Password card on the page');
        $this->assertSame(2, $x->query('//form[contains(@action,"admin/settings/")]')->length, 'only the two forms: personal information and sign-in');
    }

    public function testEveryPasswordFieldIsLeftToThePageScriptForItsOneEye(): void
    {
        $html = $this->profile();

        // pw-toggle.js (loaded by the layout) adds exactly one eye to each field; none is written into the markup here.
        $this->assertStringContainsString('<script src="/assets/js/pw-toggle.js"></script>', $html);
        $this->assertStringContainsString('<link href="/assets/css/pw-toggle.css" rel="stylesheet">', $html);
        $this->assertSame(0, substr_count($html, 'class="pw-toggle"'));
        $this->assertSame(3, substr_count($html, 'type="password"'), 'new password, its confirm, and the current password');
    }

    // ---------------------------------------------------------------- the demo owner

    public function testEveryActionButtonIsDisabledForTheDemoOwner(): void
    {
        $this->demoOn();
        $html = $this->withSession([
            'isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->id['demo_owner'], 'username' => 'demo_owner', 'full_name' => 'Demo Owner', 'last_activity' => time(),
        ])->get('admin/settings?tab=profile')->response()->getBody();
        $x = $this->dom($html);

        foreach (['//button[@id="pfEditBtn"]', '//button[@id="siEditBtn"]', '//form[@id="pfForm"]//button[@type="submit"]', '//form[@id="siForm"]//button[@type="submit"]'] as $path) {
            $button = $x->query($path)->item(0);
            $this->assertNotNull($button, $path);
            $this->assertTrue($button->hasAttribute('disabled'), $path);
            $this->assertSame('Disabled in the demo version', $button->getAttribute('title'), $path);
        }
    }

    public function testTheTabOpensAfterARedirectToIt(): void
    {
        $this->actingAs('owner')->post('admin/settings/sign-in', ['username' => 'bad name!', 'email' => 'owner@real.test', 'current_password' => 'OwnerPass123'])
            ->assertRedirectTo(site_url('admin/settings?tab=profile'));

        $this->assertStringContainsString('My Profile', $this->profile());
    }
}
