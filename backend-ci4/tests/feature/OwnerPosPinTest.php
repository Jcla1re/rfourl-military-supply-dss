<?php

use App\Libraries\AttemptStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\PosPinFixture;
use Tests\Support\ReorderFixture;

/**
 * The owner's first checkout PIN from the Point of Sale (partials/_pin_setup.php with return=pos), saved
 * through My Profile's own route (Admin\SettingsController::updateSignIn: the same current-password check
 * and owner_signin_change_{id} counter), and My Profile's Sign-in & security on the MyProfilePinV1 board.
 *
 * @internal
 */
final class OwnerPosPinTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const PASSWORD = 'OwnerPass123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');
        $this->db->table('users')->update([
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'email'         => 'owner@example.test',
            'pos_pin_hash'  => null,
        ], ['user_id' => $this->ownerId]);
        AttemptStore::forget('owner_signin_change_' . $this->ownerId);
    }

    protected function tearDown(): void
    {
        AttemptStore::forget('owner_signin_change_' . $this->ownerId);
        PosPinFixture::clearLocks();
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function owner(): array
    {
        return $this->db->table('users')->where('user_id', $this->ownerId)->get()->getRowArray();
    }

    /** What the POS card posts: the PIN, its repeat, the password and return=pos (no username or email). */
    private function fromPos(array $fields)
    {
        return $this->asOwner()->post('admin/settings/sign-in', $fields + ['return' => 'pos']);
    }

    private function securityLog(string $event): int
    {
        return $this->db->table('security_log')->where('event', $event)->countAllResults();
    }

    // ------------------------------------------------------------ from the Point of Sale

    public function testAPinOnlySaveFromThePosKeepsEverythingElseAndComesBack(): void
    {
        $this->fromPos(['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::PASSWORD])
            ->assertRedirectTo(site_url('admin/sales'));

        $this->assertSame(['success', 'Checkout PIN saved.'], [$_SESSION['toast']['type'] ?? null, $_SESSION['toast']['title'] ?? null]);
        $row = $this->owner();
        $this->assertTrue(password_verify('5739', $row['pos_pin_hash']), 'Saved as a hash.');
        $this->assertSame(['owner1', 'owner@example.test'], [$row['username'], $row['email']], 'Not posted = unchanged.');
        $this->assertTrue(password_verify(self::PASSWORD, $row['password_hash']), 'The password is untouched.');
        $this->assertSame([1, 0, 0], [$this->securityLog('pos_pin_set'), $this->securityLog('username_changed'), $this->securityLog('email_changed')]);
        $this->assertArrayNotHasKey('field_errors', $_SESSION);

        // The setup card is gone; Checkout can be used.
        $html = (string) $this->asOwner()->get('admin/sales')->response()->getBody();
        $this->assertStringNotContainsString('id="posSetupPinSetup"', $html);
        $this->assertStringContainsString('data-no-pin="0"', $html);
    }

    /** @return array<string, array{0: array, 1: string, 2: string}> */
    public static function refusals(): array
    {
        return [
            'weak PIN'          => [['new_pin' => '1234', 'new_pin_confirm' => '1234', 'current_password' => self::PASSWORD], 'new_pin', 'That PIN is too easy to guess.'],
            'repeat differs'    => [['new_pin' => '5739', 'new_pin_confirm' => '5738', 'current_password' => self::PASSWORD], 'new_pin_confirm', "The two PINs don't match."],
            'no password'       => [['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => ''], 'current_password', 'Enter your current password'],
            'wrong password'    => [['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => 'nope'], 'current_password', 'Current password is incorrect.'],
            'nothing typed'     => [['new_pin' => '', 'new_pin_confirm' => '', 'current_password' => self::PASSWORD], 'new_pin', 'Type your new PIN in both boxes'],
        ];
    }

    /** @dataProvider refusals */
    public function testARefusalShowsInThePosCard(array $fields, string $field, string $message): void
    {
        $this->fromPos($fields)->assertRedirectTo(site_url('admin/sales'));

        $this->assertStringStartsWith($message, (string) ($_SESSION['pin_error'] ?? ''));
        $this->assertSame($field, $_SESSION['pin_error_field'] ?? null);
        $this->assertArrayNotHasKey('field_errors', $_SESSION, 'Not on My Profile.');
        $this->assertArrayNotHasKey('_ci_old_input', $_SESSION, 'Nothing typed is kept.');
        $this->assertNull($this->owner()['pos_pin_hash']);

        // In the card, under its box, with every box empty.
        $html = (string) $this->withSession()->get('admin/sales')->response()->getBody();
        $box  = ['new_pin' => 'posSetupPin', 'new_pin_confirm' => 'posSetupPin2', 'current_password' => 'posSetupPinPassword'][$field];
        $this->assertMatchesRegularExpression('#<div class="pin-error" id="' . $box . 'Err" role="alert">' . preg_quote(esc($message), '#') . '#', $html);
        $this->assertDoesNotMatchRegularExpression('#id="posSetupPin(2|Password)?"[^>]*value=#', $html);
    }

    public function testWrongPasswordsShareTheSignInCounterAndTheSixthIsRefused(): void
    {
        foreach (range(1, 5) as $i) {
            $this->fromPos(['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => 'nope']);
        }
        $this->fromPos(['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::PASSWORD]);
        $this->assertSame(['Too many attempts. Please wait a few minutes and try again.', 'form'], [$_SESSION['pin_error'] ?? null, $_SESSION['pin_error_field'] ?? null]);
        $this->assertNull($this->owner()['pos_pin_hash'], 'Even the right password is refused for now.');

        // The same counter as My Profile's form.
        $this->asOwner()->post('admin/settings/sign-in', ['username' => 'owner1', 'email' => 'owner@example.test', 'new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::PASSWORD]);
        $this->assertSame('Too many attempts. Please wait a few minutes and try again.', $_SESSION['field_errors']['fields']['form'] ?? null);
    }

    public function testAFormatErrorIsNotAnAttempt(): void
    {
        $this->fromPos(['new_pin' => '1111', 'new_pin_confirm' => '1111', 'current_password' => self::PASSWORD]);
        $this->assertSame(0, AttemptStore::count('owner_signin_change_' . $this->ownerId));
    }

    public function testOnlyTheKnownReturnValueIsUsed(): void
    {
        foreach (['https://evil.example/', '/admin/sales', '//evil.example', 'POS'] as $return) {
            $this->asOwner()->post('admin/settings/sign-in', ['return' => $return, 'new_pin' => '1234', 'new_pin_confirm' => '1234', 'current_password' => self::PASSWORD])
                ->assertRedirectTo(site_url('admin/settings?tab=profile'));
            $this->assertSame('signin', $_SESSION['field_errors']['form'] ?? null, $return);
        }
    }

    public function testMyProfileStillWorksWhenEveryFieldIsPosted(): void
    {
        $this->asOwner()->post('admin/settings/sign-in', ['username' => 'owner1', 'email' => 'owner@example.test', 'email_confirm' => '', 'new_password' => '', 'new_password_confirm' => '', 'new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::PASSWORD])
            ->assertRedirectTo(site_url('admin/settings?tab=profile'));
        $this->assertSame('Checkout PIN changed.', $_SESSION['success'] ?? null);

        // A posted, empty email is still refused (only a field that isn't posted is left as it is).
        $this->asOwner()->post('admin/settings/sign-in', ['username' => 'owner1', 'email' => '', 'new_pin' => '6150', 'new_pin_confirm' => '6150', 'current_password' => self::PASSWORD]);
        $this->assertSame('Enter your email address.', $_SESSION['field_errors']['fields']['email'] ?? null);
    }

    // ------------------------------------------------------------ My Profile on the board

    private function profile(string $query = ''): string
    {
        return (string) $this->asOwner()->get('admin/settings?tab=profile' . $query)->response()->getBody();
    }

    public function testViewModeShowsNotSetYetInGrayOrASetPillWithTheDate(): void
    {
        $this->assertStringContainsString('<dt>Checkout PIN</dt><dd><span class="pf-empty">Not set yet</span></dd>', $this->profile());

        $this->db->table('users')->update(['pos_pin_hash' => PosPinFixture::hash(), 'pos_pin_set_at' => '2026-11-22 09:30:00'], ['user_id' => $this->ownerId]);
        $html = $this->profile();
        $this->assertStringContainsString('<dt>Checkout PIN</dt><dd><span class="pf-pin-set">Set</span> <span class="pf-pin-date">Nov 22, 2026</span></dd>', $html);
        $this->assertStringNotContainsString(PosPinFixture::PIN, $html);
        $this->assertStringContainsString('<p class="pf-sub">Your sign-in details and checkout PIN. Changes need your current password.</p>', $html);
    }

    public function testEditModeHasThreeGroupsOneFormOneSave(): void
    {
        $html = $this->profile('&edit=signin');
        $form = substr($html, strpos($html, 'id="siForm"'));
        $form = substr($form, 0, strpos($form, '</form>'));

        preg_match_all('#<div class="pf-group-head( is-divided)?"[^>]*><h3 class="pf-group-title">([^<]+)</h3>(?:<span class="pf-group-hint" id="(\w+)">([^<]+)</span>)?</div>#', $form, $m);
        $this->assertSame(['Sign-in', 'Password', 'Checkout PIN'], $m[2]);
        $this->assertSame(['', ' is-divided', ' is-divided'], $m[1], 'A line above each group but the first.');
        $this->assertSame(['', 'Leave blank to keep your current password.', 'Needed for every checkout on the Point of Sale.'], $m[4]);
        $this->assertSame(1, substr_count($form, 'name="current_password"'));
        $this->assertSame(1, substr_count($form, 'type="submit"'));

        foreach (['siNewPin', 'siNewPinConfirm'] as $id) {
            preg_match('#<input id="' . $id . '"[^>]*>#', $form, $tag);
            foreach (['class="pf-pin-input"', 'placeholder="••••"', 'data-no-pw-toggle', 'autocomplete="off"', 'maxlength="4"'] as $attr) {
                $this->assertStringContainsString($attr, $tag[0], "{$id}: {$attr}");
            }
        }
        // The New password box still points to its hint (now the group's).
        $this->assertMatchesRegularExpression('#<input id="siNewPassword"[^>]*aria-describedby="siNewPasswordHint"#', $form);

        $css = str_replace("\r\n", "\n", $html);
        foreach ([
            '.pf-group-head.is-divided { margin-top: 20px; padding-top: 18px; border-top: 1px solid #F0EDE6; }',
            '.pf-group-title { margin: 0; font-size: 14px; font-weight: 600; color: #1F2617; }',
            '.pf-group-hint { font-size: 12.5px; color: #7A7E70; }',
            '.pf-form .pf-field input.pf-pin-input { width: 180px; max-width: 100%; padding: 0 0 0 .6em; font-size: 20px; letter-spacing: .6em; text-align: center; }',
            '.pf-rows .pf-empty { color: #7A7E70; }',
            '.pf-pin-set { display: inline-block; padding: 2px 9px; border-radius: 999px; background: #EAF1E3; color: #2F5A27; font-size: 12px; font-weight: 600;',
            '.pf-pin-date { margin-left: 6px; font-size: 13px; color: #7A7E70; }',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css, $rule);
        }
    }

    public function testTheChecklistCirclesAreDashedUntilCheckedOnBothCards(): void
    {
        $css = file_get_contents(FCPATH . 'assets/css/pin-setup.css');
        $this->assertStringContainsString('border: 1.5px dashed #C9CCBF; border-radius: 50%;', $css);
        $this->assertStringContainsString('.pin-checklist li.is-met .pin-check-mark { background: #4E9A45; border-color: #4E9A45; border-style: solid;', $css);
        $this->assertStringContainsString('.pin-checklist li.is-broken .pin-check-mark { border-color: #B3261E; border-style: solid;', $css);
        // The one checklist partial (and stylesheet) is used by My Profile and the PIN setup card alike.
        $this->assertStringContainsString('pin-setup.css', $this->profile());
    }

    public function testTheDeepLinkLandsInsideTheContentAreaAndThePageItselfNeverScrolls(): void
    {
        $html = $this->profile('&edit=signin');
        $this->assertStringContainsString("const pinGroup = document.getElementById('siPinGroup'), area = pinGroup.closest('.main-scroll');", $html);
        $this->assertStringContainsString("document.getElementById('siNewPin').focus({ preventScroll: true });", $html);

        $theme = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/admin-theme.css'));
        $this->assertMatchesRegularExpression('/\.app-shell \{\s*height: 100vh;\s*overflow: hidden;[^}]*overflow: clip;\s*\}/', $theme, 'clip: a #link or focus() can no longer scroll the shell.');
        $this->assertMatchesRegularExpression('/\.app-shell \.main-panel \{[^}]*overflow: hidden;[^}]*overflow: clip;[^}]*\}/', $theme);
        $this->assertMatchesRegularExpression('/\.app-shell \.main-panel \.main-scroll \{\s*flex: 1;\s*overflow-y: auto;\s*\}/', $theme, 'Only the content area scrolls.');

        // Visually hidden helpers are placed relative to the page unless anchored, and could make the page
        // taller than the window (the blank space at the bottom): both are anchored.
        $this->assertStringContainsString('.pin-checklist li { position: relative;', file_get_contents(FCPATH . 'assets/css/pin-setup.css'), 'The checklist\'s screen-reader-only state stays inside its item.');
        $this->assertStringContainsString('border:0;top:0;left:0;"', file_get_contents(APPPATH . 'Views/partials/_username_hint.php'), 'The username hint sits at the page corner.');
    }
}
