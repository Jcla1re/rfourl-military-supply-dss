<?php

use App\Libraries\PasswordPolicy;
use Tests\Support\DemoTestCase;

/**
 * Settings > My Profile for the Owner: a "who you are" card on the left (initials avatar, name, Owner
 * pill, account facts) and two cards on the right that each open their own form with Edit.
 * Personal information (name + contact number) posts to admin/settings/account; Sign-in & security
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

    /** @return array<string, string> label => value of every row (<dt> / <dd>) in this card or block */
    private function rows(DOMXPath $x, DOMNode $scope): array
    {
        $out = [];
        foreach ($x->query('.//div[dt]', $scope) as $row) {
            $out[trim($x->query('./dt', $row)->item(0)->textContent)] = trim(preg_replace('/\s+/', ' ', $x->query('./dd', $row)->item(0)->textContent));
        }

        return $out;
    }

    /** The left "who you are" card. */
    private function whoCard(DOMXPath $x): DOMNode
    {
        $card = $x->query('//section[contains(@class,"pf-who")]')->item(0);
        $this->assertNotNull($card);

        return $card;
    }

    /** The Edit button: Inventory's pen (the same inline SVG) and the word "Edit". */
    private function assertEditButton(DOMXPath $x, DOMNode $button, string $label): void
    {
        $this->assertSame($label, $button->getAttribute('aria-label'));
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertStringContainsString('pf-edit', $button->getAttribute('class'));
        $this->assertSame('Edit', trim($button->textContent));
        $svg = $x->query('./*[local-name()="svg"]', $button)->item(0);
        $this->assertNotNull($svg, 'the pen icon');
        $this->assertSame('true', $svg->getAttribute('aria-hidden'));
        $this->assertSame('16', $svg->getAttribute('width'), 'drawn at 16px, never 0');
        $this->assertSame('16', $svg->getAttribute('height'));
        $this->assertSame(['M12 20h9', 'M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z'], array_map(static fn ($p) => $p->getAttribute('d'), iterator_to_array($x->query('./*[local-name()="path"]', $svg))));
    }

    // ---------------------------------------------------------------- page structure

    public function testThePageHasTheTwoColumnLayoutWithoutTheOldTitleOrCards(): void
    {
        $html = $this->profile();
        $x    = $this->dom($html);

        // The header tabs are unchanged.
        $tabs = $x->query('//div[contains(@class,"set-tabs")][contains(@class,"sticky-tabs")]/a');
        $this->assertSame(['My Profile', 'Manage Accounts', 'Notifications', 'DSS Parameter', 'Data & Records'], array_map(static fn ($a) => trim($a->textContent), iterator_to_array($tabs)));

        // No page title / subtitle, no top header card, no Account Details card.
        $this->assertStringNotContainsString('Manage your personal information.', $html);
        $this->assertSame(0, $x->query('//h4[normalize-space()="My Profile"]')->length);
        $this->assertStringNotContainsString('Account Details', $html);
        $this->assertStringNotContainsString('set-profile-head', $html);
        $this->assertStringNotContainsString('★', $html);

        // Left card first, then the right column with the two cards.
        $layout = $x->query('//div[contains(@class,"pf-layout")]')->item(0);
        $this->assertNotNull($layout);
        $children = $x->query('./*', $layout);
        $this->assertSame(2, $children->length);
        $this->assertStringContainsString('pf-who', $children->item(0)->getAttribute('class'));
        $this->assertStringContainsString('pf-main', $children->item(1)->getAttribute('class'));
        $this->assertSame(['pfCard', 'pfSecurity'], array_map(static fn ($c) => $c->getAttribute('id'), iterator_to_array($x->query('./section', $children->item(1)))));
        $this->assertStringContainsString('.pf-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 20px; align-items: stretch; }', $html);
        $this->assertStringContainsString('@media (max-width: 900px) { .pf-layout { grid-template-columns: minmax(0, 1fr); } }', $html);

        // Card titles: sentence case.
        $this->assertSame(['Personal information', 'Sign-in & security'], array_map(static fn ($h) => trim($h->textContent), iterator_to_array($x->query('//h2[contains(@class,"pf-title")]'))));
    }

    public function testTheLeftCardShowsTheInitialsAvatarNameOwnerPillAndAccountFacts(): void
    {
        $x    = $this->dom($this->profile());
        $card = $this->whoCard($x);

        $avatar = $x->query('.//div[contains(@class,"pf-avatar")]', $card)->item(0);
        $this->assertSame('img', $avatar->getAttribute('role'));
        $this->assertSame('Real Owner', $avatar->getAttribute('aria-label'));
        $this->assertSame('RO', trim($avatar->textContent));
        $this->assertSame('true', $x->query('./span', $avatar)->item(0)->getAttribute('aria-hidden'));
        $this->assertSame('Real Owner', trim($x->query('.//div[contains(@class,"pf-who-name")]', $card)->item(0)->textContent));

        // The Owner pill exactly as on Manage Accounts: the one neutral role pill, no inline colour, no star.
        $pill = $x->query('.//span[contains(@class,"acct-role-pill")]', $card)->item(0);
        $this->assertSame('acct-role-pill', $pill->getAttribute('class'));
        $this->assertFalse($pill->hasAttribute('style'), 'no yellow Owner background');
        $this->assertSame('Owner', trim($pill->textContent));

        // Last sign-in is the one before this session (session.previous_login), not users.last_login.
        $this->assertSame(
            ['Account status' => 'Active', 'Member since' => 'January 15, 2025', 'Last sign-in' => 'Nov 7, 2026 3:21 PM'],
            $this->rows($x, $card)
        );
        $this->assertSame(1, $x->query('.//dd/span[contains(@class,"status-pill")][contains(@class,"acct")][contains(@class,"green")]', $card)->length, 'green dot + Active');
        $this->assertStringNotContainsString('Username', $card->textContent);
        $this->assertSame(0, $x->query('.//input|.//button', $card)->length, 'nothing to edit here');
    }

    public function testTheLeftCardKeepsItsSizeWhileACardIsEdited(): void
    {
        // View mode: the left card stretches with the right column (no lock class).
        $html = $this->profile();
        $who  = $this->whoCard($this->dom($html));
        $this->assertSame('set-card pf-who', $who->getAttribute('class'));
        $this->assertSame('pfWho', $who->getAttribute('id'));

        // Opening a card locks the view-mode height; Cancel removes it; one column never locks.
        $this->assertStringContainsString("edit.addEventListener('click', () => { lockWho(); show(true); });", $html);
        $this->assertStringContainsString("who.style.height = who.getBoundingClientRect().height + 'px';", $html);
        $this->assertStringContainsString("oneColumn = window.matchMedia('(max-width: 900px)')", $html);
        $this->assertMatchesRegularExpression('/show\(false\);\s+unlockWho\(\);/', $html);
        $this->assertStringContainsString('.pf-who.is-locked,', $html);
        $this->assertStringContainsString('@media (max-width: 900px) { .pf-who.is-locked { height: auto !important; } }', $html);

        // A refused save reopens a form after the reload: the card uses its own height, no stretch.
        foreach (['personal' => 'phone', 'signin' => 'username'] as $form => $field) {
            $x = $this->dom($this->profile(['field_errors' => ['form' => $form, 'fields' => [$field => 'Not valid.']]]));
            $this->assertSame('set-card pf-who is-natural', $this->whoCard($x)->getAttribute('class'), $form);
        }
    }

    // ---------------------------------------------------------------- Personal information: view mode first

    public function testPersonalInformationStartsInViewModeWithAnEditButton(): void
    {
        $x = $this->dom($this->profile());

        // The read-only rows: name and contact number (the email is in Sign-in & security).
        $view = $x->query('//dl[@id="pfView"]')->item(0);
        $this->assertNotNull($view);
        $this->assertFalse($view->hasAttribute('hidden'), 'view mode is showing');
        $this->assertSame(['Full name' => 'Real Owner', 'Contact number' => '0917 123 4567'], $this->rows($x, $view));

        // Edit.
        $pen = $x->query('//button[@id="pfEditBtn"]')->item(0);
        $this->assertNotNull($pen);
        $this->assertEditButton($x, $pen, 'Edit personal information');
        $this->assertFalse($pen->hasAttribute('hidden'));

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
        $this->assertSame('Save changes', trim($x->query('.//button[@type="submit"]', $form)->item(0)->textContent));
        $this->assertSame(1, $x->query('.//input[@type="hidden"][@name="csrf_test_name"]', $form)->length);

        // Sentence-case labels in the 180px label column, the footer like Add item.
        $this->assertSame(['Full name', 'Contact number'], array_map(static fn ($l) => trim($l->textContent), iterator_to_array($x->query('.//label', $form))));
        $this->assertSame(1, $x->query('.//div[contains(@class,"pf-foot")]/span[contains(@class,"pf-changes")][@aria-live="polite"]', $form)->length);
    }

    public function testTheContactNumberIsShownGroupedButSavedAsIs(): void
    {
        foreach (['09852145866' => '0985 214 5866', '0985-214-5866' => '0985 214 5866', '+639852145866' => '+639852145866', '12345' => '12345'] as $saved => $shown) {
            $saved = (string) $saved; // PHP turns the '12345' key into an int
            $this->db->table('users')->where('user_id', $this->id['owner'])->update(['phone' => $saved]);
            $x = $this->dom($this->profile());

            $this->assertSame($shown, $this->rows($x, $x->query('//dl[@id="pfView"]')->item(0))['Contact number'], $saved);
            $input = $x->query('//input[@id="pfPhone"]')->item(0);
            $this->assertSame($saved, $input->getAttribute('value'), 'the form keeps the saved value');
            $this->assertSame($saved, $input->getAttribute('data-original'));
        }
    }

    public function testTheFullNameHasTheAddItemStyleCounterAndNoHardLimit(): void
    {
        $html = $this->profile();
        $x    = $this->dom($html);

        // "n / 60" on the label's line, like Item name in Add item.
        $row   = $x->query('//form[@id="pfForm"]//div[contains(@class,"im-label-row")]')->item(0);
        $count = $x->query('.//small[@id="pfNameCount"]', $row)->item(0);
        $this->assertSame('Full name', trim($x->query('./label[@for="pfFullName"]', $row)->item(0)->textContent));
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
        $this->assertStringContainsString('if (personal) { personal.sync(); } // Save disabled while over the limit', $html);
        $this->assertStringContainsString('save.disabled = saveLocked || n > 0 || !changed();', $html);
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

        $this->assertSame('—', $this->rows($x, $x->query('//dl[@id="pfView"]')->item(0))['Contact number']);
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

    public function testSaveWaitsForARealChangeAndShowsTheErrorCount(): void
    {
        $html = $this->profile();

        // Compared to the saved values (changing a field back disables Save again).
        $this->assertStringContainsString('const personalChanged = () => name.value.trim() !== name.dataset.original.trim()', $html);
        $this->assertStringContainsString('|| phoneDigits(phone.value) !== phoneDigits(phone.dataset.original);', $html);
        // A new password counts as a change; the current password alone does not.
        $this->assertStringContainsString("|| newPass.value !== '' || newPass2.value !== '';", $html);
        $this->assertStringNotContainsString("getElementById('siCurrentPassword')", $html);
        $this->assertStringContainsString('const text = n > 0 ? `Fix ${n} ${n === 1 ? \'error\' : \'errors\'} to save` : \'\';', $html);
        // Only one card edits at a time: the other card's Edit is disabled meanwhile.
        $this->assertStringContainsString('const locked = !!open && open !== c;', $html);
        // The app's one disabled look (admin-theme.css: hover, press and focus change nothing); no override here.
        $this->assertStringNotContainsString('#B9D3AE', $html);
        $this->assertDoesNotMatchRegularExpression('/\.pf-save[^{]*:disabled/', $html);
        $this->assertStringContainsString(':root :is(.btn-success, .inv-action-btn, .lt-submit):is(:disabled, .disabled):is(:hover, :active, :focus, :focus-visible) {', file_get_contents(FCPATH . 'assets/css/admin-theme.css'));
        $this->assertMatchesRegularExpression('#<button type="submit" class="btn btn-success pf-save"#', $html, 'Save changes is a .btn-success, so the shared rule applies.');
    }

    // ---------------------------------------------------------------- a refused save reopens only its own form

    public function testARefusedPersonalSaveReopensThatFormWithTheMessageUnderTheField(): void
    {
        $x = $this->dom($this->profile([
            'error'        => 'Use an 11-digit mobile number like 0917 123 4567.',
            'field_errors' => ['form' => 'personal', 'fields' => ['phone' => 'Use an 11-digit mobile number like 0917 123 4567.']],
            '_ci_old_input' => ['get' => [], 'post' => ['full_name' => 'Real Owner', 'phone' => '12345']],
        ]));

        $this->assertTrue($x->query('//dl[@id="pfView"]')->item(0)->hasAttribute('hidden'));
        $this->assertFalse($x->query('//form[@id="pfForm"]')->item(0)->hasAttribute('hidden'));
        $this->assertTrue($x->query('//button[@id="pfEditBtn"]')->item(0)->hasAttribute('hidden'), 'Edit is hidden while editing');
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

        $this->assertTrue($x->query('//dl[@id="siView"]')->item(0)->hasAttribute('hidden'));
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

    // ---------------------------------------------------------------- Sign-in & security

    public function testTheFirstSignInSaysSo(): void
    {
        $x = $this->dom($this->profile([], ['previous_login' => null]));

        $this->assertSame('This is your first sign-in', $this->rows($x, $this->whoCard($x))['Last sign-in']);
    }

    public function testSignInAndSecurityHasUsernameEmailPasswordOneEditButtonAndTheNote(): void
    {
        $x    = $this->dom($this->profile());
        $card = $x->query('//section[@id="pfSecurity"]')->item(0);
        $this->assertNotNull($card);
        $this->assertSame('Sign-in & security', trim($x->query('.//h2', $card)->item(0)->textContent));

        $this->assertSame(['Username' => 'owner_normal', 'Email' => 'owner@real.test', 'Password' => '••••••••', 'Checkout PIN' => 'Not set yet'], $this->rows($x, $x->query('.//dl[@id="siView"]', $card)->item(0)));
        // The one gray line under the title.
        $this->assertSame('Your sign-in details and checkout PIN. Changes need your current password.', trim($x->query('./p[contains(@class,"pf-sub")]', $card)->item(0)->textContent));

        // One Edit button for the whole card; no dialogs any more.
        $this->assertSame(1, $x->query('.//button[@type="button"][contains(@class,"pf-edit")]', $card)->length);
        $pen = $x->query('.//button[@id="siEditBtn"]', $card)->item(0);
        $this->assertEditButton($x, $pen, 'Edit sign-in and security');
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
        $this->assertSame('Save changes', trim($x->query('.//button[@type="submit"]', $form)->item(0)->textContent));

        // Sentence-case labels; the current password last, in its light box with its hint.
        $this->assertSame(
            ['Username', 'Email', 'Confirm email', 'New password', 'Repeat new password', 'New PIN', 'Repeat PIN', 'Current password'],
            array_map(static fn ($l) => trim($l->textContent), iterator_to_array($x->query('.//label', $form)))
        );
        // B1: the optional checkout PIN (blank = keep), never filled in and never kept as old input (PosPin::strip()).
        foreach (['new_pin', 'new_pin_confirm'] as $name) {
            $input = $x->query(".//input[@name=\"{$name}\"]", $form)->item(0);
            $this->assertSame(['password', 'off', false, false], [$input->getAttribute('type'), $input->getAttribute('autocomplete'), $input->hasAttribute('value'), $input->hasAttribute('required')], $name);
        }
        $box = $x->query('.//div[contains(@class,"pf-current")]', $form)->item(0);
        $this->assertSame(1, $x->query('.//input[@name="current_password"]', $box)->length);
        $this->assertSame(0, $x->query('following-sibling::div[contains(@class,"pf-field")]', $box)->length, 'nothing after the current password');

        // Hints under the fields (the password's sits in its group heading now), read out with them.
        foreach (['siUsername' => '4–50 letters, numbers, - or _', 'siNewPassword' => 'Leave blank to keep your current password.', 'siCurrentPassword' => 'Needed to save any change in this section.'] as $id => $hint) {
            $this->assertSame($hint, trim($x->query("//*[@id=\"{$id}Hint\"]")->item(0)->textContent), $id);
            $this->assertSame("{$id}Hint", $x->query("//input[@id=\"{$id}\"]")->item(0)->getAttribute('aria-describedby'), $id);
        }
    }

    public function testASignInErrorIsTiedToItsFieldAfterTheHint(): void
    {
        $x = $this->dom($this->profile([
            'error'        => 'The current password is incorrect.',
            'field_errors' => ['form' => 'signin', 'fields' => ['current_password' => 'The current password is incorrect.']],
        ]));

        $input = $x->query('//input[@id="siCurrentPassword"]')->item(0);
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertSame('siCurrentPasswordHint siCurrentPasswordErr', $input->getAttribute('aria-describedby'));
        $this->assertSame('The current password is incorrect.', trim($x->query('//div[@id="siCurrentPasswordErr"]')->item(0)->textContent));
        $this->assertSame('', $input->getAttribute('value'));
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
        $this->assertSame(5, substr_count($html, 'type="password"'), 'new password, its confirm, the checkout PIN, its repeat, and the current password');
    }

    // ---------------------------------------------------------------- sidebar footer and Manage Accounts

    public function testTheOwnerSidebarShowsTheInitialsAvatarAndAOneLineName(): void
    {
        $x = $this->dom($this->profile([], ['full_name' => 'Richelle D. Paras']));

        // Expanded footer: the avatar (hidden from screen readers: the name is next to it) and the name with its title.
        $link = $x->query('//div[contains(@class,"user-footer")]/a[contains(@class,"user-footer-link")]')->item(0);
        $avatar = $x->query('./span[@class="user-avatar"]', $link)->item(0);
        $this->assertNotNull($avatar);
        $this->assertSame('RP', trim($avatar->textContent));
        $this->assertSame('true', $avatar->getAttribute('aria-hidden'));
        $this->assertSame(0, $x->query('.//i[contains(@class,"bi-person-circle")]', $link)->length, 'no person icon');
        $name = $x->query('.//div[@class="user-name"]', $link)->item(0);
        $this->assertSame('Richelle D. Paras', trim($name->textContent));
        $this->assertSame('Richelle D. Paras', $name->getAttribute('title'));

        // Collapsed sidebar: the account menu button shows the same avatar; its label stays "Account menu".
        $button = $x->query('//button[@id="accountMenuBtn"]')->item(0);
        $this->assertSame('Account menu', $button->getAttribute('aria-label'));
        $this->assertSame('RP', trim($x->query('./span[@class="user-avatar"][@aria-hidden="true"]', $button)->item(0)->textContent));
        $this->assertSame(0, $x->query('.//i', $button)->length);
    }

    public function testManageAccountsUsesTheSameInitials(): void
    {
        $body = $this->withSession([
            'isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->id['owner'], 'username' => 'owner_normal', 'full_name' => 'Real Owner', 'last_activity' => time(),
        ])->get('admin/settings?tab=accounts')->response()->getBody();
        $x = $this->dom($body);

        $first = $x->query('//div[contains(@class,"acct-row")][1]/div[contains(@class,"acct-avatar")]')->item(0);
        $this->assertSame('RO', trim($first->textContent));
        $this->assertSame('true', $first->getAttribute('aria-hidden'));
        $this->assertSame(1, $x->query('//span[@class="acct-role-pill"][not(@style)][normalize-space()="Owner"]')->length, 'the Owner pill My Profile reuses');
        $this->assertStringNotContainsString('fdf1c0', $body);
        // Owner and Staff pills share one neutral style at weight 600.
        $this->assertStringContainsString('.acct-role-pill { background: #e9e7e1; border-radius: var(--radius-pill); padding: 4px 12px; font-size: 12px; font-weight: 600; }', $body);
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
