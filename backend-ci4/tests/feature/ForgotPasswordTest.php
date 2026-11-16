<?php

use App\Libraries\AfterResponse;
use App\Libraries\LoginLockout;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthFixture;

/**
 * One Forgot password flow for everyone: 1 username or email, 2 "Check your email" (6-digit code),
 * 3 new password, done. An Owner or Supplier gets a code; Staff get a temporary password; an
 * unknown name gets nothing. Steps 1 and 2 answer exactly the same in every case (redirect, page,
 * code tries, expiry, resend). Emails go after the response, or the answer is padded. In-memory database.
 *
 * @internal
 */
final class ForgotPasswordTest extends CIUnitTestCase
{
    use AuthFixture;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuthFixture();
    }

    protected function tearDown(): void
    {
        config(\Config\Auth::class)->forgotMinSeconds = 0.0;
        $this->tearDownAuthFixture();
        parent::tearDown();
    }

    /** Who might be typed in, and what they should get behind the scenes. */
    public static function people(): array
    {
        return [
            'unknown username' => ['ghost', 'none'],
            'unknown email'    => ['ghost@example.test', 'none'],
            'staff username'   => ['cashier1', 'temp'],
            'staff email'      => ['ana@example.test', 'temp'],
            'owner username'   => ['owner1', 'code'],
            'owner email'      => ['OWNER@example.test', 'code'],
            'supplier'         => ['tgi', 'code'],
        ];
    }

    /** The next request goes on with the session as the last one left it. */
    private function next(): self
    {
        return $this->withSession($_SESSION ?? []);
    }

    private function ask(string $typed)
    {
        return $this->next()->post('login/forgot', ['identifier' => $typed]);
    }

    private function stepTwoHtml(): string
    {
        return $this->stableHtml((string) $this->next()->get('login/forgot/check')->response()->getBody());
    }

    /** The emailed 6-digit code in the last email to $to. */
    private function codeFor(string $to): ?string
    {
        foreach (array_reverse($this->mail->out) as $m) {
            if (in_array($to, $m['to'], true) && preg_match('/verification code is: (\d{6})/', $m['body'], $x)) {
                return $x[1];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- steps 1 and 2: the same for everyone

    /** @dataProvider people */
    public function testStepOneAndTwoAreIdenticalWhoeverIsTyped(string $typed, string $kind): void
    {
        $reference = null;
        foreach (['ghost', $typed] as $i => $who) {
            $this->clearAuthLimits();
            $result = $this->ask($who);

            $this->assertSame(site_url('login/forgot/check'), $result->getRedirectUrl(), $who);
            $this->assertArrayNotHasKey('error', $_SESSION, $who);
            $this->assertSame(['token', 'kind', 'user_id', 'ident', 'verified', 'burned', 'sent_at', 'expires', 'touched'], array_keys($_SESSION['pwd_reset']));

            $html = $this->stepTwoHtml();
            $reference ??= $html;
            $this->assertSame($reference, $html, "Step 2 for {$who} is the same page as for an unknown name.");
        }

        $this->assertSame($kind, $_SESSION['pwd_reset']['kind']);
        $this->assertStringContainsString('Check your email', $reference);
        $this->assertStringContainsString("If an account matches, we've sent you the next step. It can take a minute.", $reference);
        $this->assertStringContainsString('Got a 6-digit code?', $reference);
        $this->assertStringContainsString('Got a temporary password?', $reference);
        $this->assertSame(6, substr_count($reference, 'name="code[]"'));
        $this->assertStringContainsString('Resend in', $reference);
        $this->assertStringNotContainsString('@example.test', $reference, 'Never the address.');
    }

    public function testWhatIsSentBehindTheScenes(): void
    {
        $this->ask('owner1');
        $this->assertNotNull($this->codeFor('owner@example.test'));
        $this->assertNotNull($this->account($this->ownerId)['reset_otp_hash']);

        $this->ask('tgi');
        $this->assertNotNull($this->codeFor('tgi@example.test'));

        $this->ask('cashier1');
        $staffMail = array_values(array_filter($this->mail->out, static fn ($m) => in_array('ana@example.test', $m['to'], true)));
        $this->assertCount(1, $staffMail);
        $this->assertStringContainsString('Your temporary password is', $staffMail[0]['body']);
        $this->assertStringContainsString('username: cashier1', $staffMail[0]['body']);
        $this->assertNull($this->account($this->staffId)['reset_otp_hash'], 'Staff get no code.');
        $this->assertNotNull($this->account($this->staffId)['temp_password_hash']);
        $this->assertTrue(password_verify('Cashier1Pass1', $this->account($this->staffId)['password_hash']), 'The current password keeps working.');

        $before = count($this->mail->out);
        $this->ask('ghost');
        $this->assertCount($before, $this->mail->out, 'Nothing for an unknown name.');
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'password_forgot_no_match')->countAllResults());
    }

    public function testTheFullNameNoLongerFindsAnAccount(): void
    {
        $this->ask('Ana Cruz')->assertRedirectTo(site_url('login/forgot/check'));

        $this->assertSame('none', $_SESSION['pwd_reset']['kind']);
        $this->assertSame([], $this->mail->out);
    }

    public function testABlankEntryAndTheRateLimitAskTheSameForEveryone(): void
    {
        $this->ask('   ')->assertRedirectTo(site_url('login/forgot'));
        $this->assertSame('Please enter your username or email.', $this->flash('error'));

        foreach (['owner1', 'ghost'] as $who) {
            $this->clearAuthLimits();
            for ($i = 0; $i < 3; $i++) {
                $this->ask($who);
            }
            $this->ask($who)->assertRedirectTo(site_url('login/forgot'));
            $this->assertSame('Too many requests. Please wait a few minutes and try again.', $this->flash('error'), $who);
        }
    }

    public function testStepOnePage(): void
    {
        $body = (string) $this->next()->get('login/forgot')->response()->getBody();

        $this->assertStringContainsString('Forgot password?', $body);
        $this->assertStringContainsString("Enter your username or email. We'll email you the next step.", $body);
        $this->assertStringContainsString('Username or email', $body);
        $this->assertStringContainsString('Back to sign in', $body);
        $this->assertStringContainsString('aria-label="Step 1 of 3"', $body);
        $this->assertStringContainsString('>Continue<', $body);
    }

    public function testStepTwoNeedsAStartedReset(): void
    {
        $this->withSession([])->get('login/forgot/check')->assertRedirectTo(site_url('login/forgot'));
        $this->withSession([])->post('login/forgot/check', ['code' => ['1', '2', '3', '4', '5', '6']])->assertRedirectTo(site_url('login/forgot'));
    }

    // ---------------------------------------------------------------- the code: the same tries for a real and a decoy reset

    /** @dataProvider people */
    public function testWrongCodesAndTheBurnLookTheSameForEveryone(string $typed, string $kind): void
    {
        $this->ask($typed);

        for ($i = 1; $i <= 4; $i++) {
            $this->next()->post('login/forgot/check', ['code' => str_split('000000')])->assertRedirectTo(site_url('login/forgot/check'));
            $this->assertSame('Incorrect code, please try again.', $this->flash('error'), "try {$i}");
        }
        $this->next()->post('login/forgot/check', ['code' => str_split('000000')]);
        $this->assertSame('Too many incorrect attempts. Please request a new code.', $this->flash('error'));

        $this->next()->post('login/forgot/check', ['code' => str_split('000000')]);
        $this->assertSame('Please request a new code.', $this->flash('error'));
        $this->assertNotNull($kind);
    }

    /** @dataProvider people */
    public function testAnExpiredCodeLooksTheSameForEveryone(string $typed): void
    {
        $this->ask($typed);
        $_SESSION['pwd_reset']['expires'] = time() - 1;

        $this->next()->post('login/forgot/check', ['code' => str_split('123456')]);
        $this->assertSame('This code has expired. Please request a new one.', $this->flash('error'));
    }

    public function testTheCodeMustBeSixDigits(): void
    {
        $this->ask('owner1');
        $this->next()->post('login/forgot/check', ['code' => ['1', '2', '3']]);

        $this->assertSame('Enter the 6-digit code from the email.', $this->flash('error'));
    }

    public function testTheRightCodeOpensStepThreeAndPastedDigitsWork(): void
    {
        $this->ask('owner1');
        $code = $this->codeFor('owner@example.test');

        // The six boxes post code[]; a single pasted field works too.
        $this->next()->post('login/forgot/check', ['code' => " {$code} "])->assertRedirectTo(site_url('login/forgot/new-password'));
        $this->assertTrue($_SESSION['pwd_reset']['verified']);
    }

    public function testStepThreeIsOnlyForAVerifiedOwnerOrSupplier(): void
    {
        foreach (['cashier1', 'ghost', 'owner1'] as $who) {
            $this->ask($who);
            $this->next()->get('login/forgot/new-password')->assertRedirectTo(site_url('login/forgot'));
            $this->next()->post('login/forgot/new-password', ['new_password' => 'BrandNew123', 'confirm_password' => 'BrandNew123'])->assertRedirectTo(site_url('login/forgot'));
        }
        $this->assertTrue(password_verify('OwnerPass123', $this->account($this->ownerId)['password_hash']));
    }

    // ---------------------------------------------------------------- step 3 and done

    private function verifiedFor(string $username, string $email): void
    {
        $this->ask($username);
        $this->next()->post('login/forgot/check', ['code' => str_split((string) $this->codeFor($email))])->assertRedirectTo(site_url('login/forgot/new-password'));
    }

    public function testStepThreeRulesAndThePasswordChangedScreen(): void
    {
        $this->verifiedFor('tgi', 'tgi@example.test');

        $page = (string) $this->next()->get('login/forgot/new-password')->response()->getBody();
        foreach (['Set a new password', 'Use at least 8 characters with a letter and a number.', 'At least 8 characters', 'Includes a letter and a number', 'Different from your current password (checked when you save)', 'aria-label="Step 3 of 3"'] as $text) {
            $this->assertStringContainsString($text, $page);
        }

        $back = site_url('login/forgot/new-password');
        $this->next()->post('login/forgot/new-password', ['new_password' => 'short', 'confirm_password' => 'short'])->assertRedirectTo($back);
        $this->assertSame('Password must be at least 8 characters and include a letter and a number.', $this->flash('error'));
        $this->next()->post('login/forgot/new-password', ['new_password' => 'BrandNew123', 'confirm_password' => 'BrandNew124']);
        $this->assertSame('Passwords do not match.', $this->flash('error'));
        $this->next()->post('login/forgot/new-password', ['new_password' => 'SupplierPass1', 'confirm_password' => 'SupplierPass1']);
        $this->assertSame('Choose a different password from your current one.', $this->flash('error'));

        (new LoginLockout())->registerFailure('tgi');
        $this->next()->post('login/forgot/new-password', ['new_password' => 'BrandNew123', 'confirm_password' => 'BrandNew123'])->assertRedirectTo(site_url('login/forgot/done'));
        $this->assertTrue(password_verify('BrandNew123', $this->account($this->supplierUserId)['password_hash']));
        $this->assertNull($this->account($this->supplierUserId)['reset_otp_hash']);
        $this->assertArrayNotHasKey('pwd_reset', $_SESSION);
        $this->assertSame(3, (new LoginLockout())->triesLeft('tgi'), 'A reset clears the sign-in count.');
        $this->assertStringContainsString('Your RfourL password was changed on', end($this->mail->out)['body']);

        $done = (string) $this->next()->get('login/forgot/done')->response()->getBody();
        $this->assertStringContainsString('Password changed', $done);
        $this->assertStringContainsString('You can now sign in with your new password.', $done);
        $this->assertStringContainsString('Back to sign in', $done);
        $this->withSession(['x' => 1])->get('login/forgot/done')->assertRedirectTo(site_url('login'));

        $this->next()->post('login', ['username' => 'tgi', 'password' => 'BrandNew123'])->assertRedirectTo(site_url('/supplier/dashboard'));
    }

    // ---------------------------------------------------------------- resend

    /** @dataProvider people */
    public function testResendWaitsAMinuteThenRepeatsTheSameRequest(string $typed, string $kind): void
    {
        $this->ask($typed);
        $sentBefore = count($this->mail->out);

        $this->next()->post('login/forgot/resend')->assertRedirectTo(site_url('login/forgot/check'));
        $this->assertMatchesRegularExpression('/^Please wait (60|59) seconds before asking again\.$/', (string) $this->flash('error'));
        $this->assertCount($sentBefore, $this->mail->out, 'Nothing sent during the countdown.');

        $_SESSION['pwd_reset']['sent_at'] = time() - 61;
        $this->next()->post('login/forgot/resend')->assertRedirectTo(site_url('login/forgot/check'));
        $this->assertSame("If an account matches, we've sent the next step again. It can take a minute.", $this->flash('notice'));
        $this->assertSame($kind, $_SESSION['pwd_reset']['kind']);
        $this->assertEqualsWithDelta(time(), $_SESSION['pwd_reset']['sent_at'], 2, 'The countdown starts again.');
        $this->assertCount($sentBefore + ($kind === 'none' ? 0 : 1), $this->mail->out, 'A new code or temporary password, nothing for an unknown name.');
    }

    public function testResendIsLimitedToThreeInTenMinutesForEveryone(): void
    {
        foreach (['owner1', 'ghost'] as $who) {
            $this->clearAuthLimits();
            $this->ask($who);
            for ($i = 0; $i < 3; $i++) {
                $_SESSION['pwd_reset']['sent_at'] = time() - 61;
                $this->next()->post('login/forgot/resend');
                $this->assertNotNull($this->flash('notice'), $who);
            }
            $_SESSION['pwd_reset']['sent_at'] = time() - 61;
            $this->next()->post('login/forgot/resend');
            $this->assertSame('Too many requests. Please wait a few minutes and try again.', $this->flash('error'), $who);
        }
    }

    public function testANewCodeReplacesTheOldOne(): void
    {
        $this->ask('owner1');
        $old = $this->codeFor('owner@example.test');
        $_SESSION['pwd_reset']['sent_at'] = time() - 61;
        $this->next()->post('login/forgot/resend');
        $new = $this->codeFor('owner@example.test');

        if ($old !== $new) {
            $this->next()->post('login/forgot/check', ['code' => str_split($old)]);
            $this->assertSame('Incorrect code, please try again.', $this->flash('error'));
        }
        $this->next()->post('login/forgot/check', ['code' => str_split($new)])->assertRedirectTo(site_url('login/forgot/new-password'));
    }

    // ---------------------------------------------------------------- after the response, or padded

    public function testDeferredTheEmailGoesAfterTheSessionIsSavedAndTheResponseIsFinished(): void
    {
        AfterResponse::$forceDeferred = true;
        $steps = [];
        AfterResponse::$closeSession = function () use (&$steps): void {
            $steps[] = ['close', $_SESSION['pwd_reset']['kind'] ?? null, count($this->mail->out)];
        };
        AfterResponse::$finishRequest = function () use (&$steps): void {
            $steps[] = ['finish', null, count($this->mail->out)];
        };

        $this->ask('owner1')->assertRedirectTo(site_url('login/forgot/check'));
        $this->assertSame([], $this->mail->out, 'Not sent during the request.');
        $this->assertSame(1, AfterResponse::pending());
        $this->assertNotNull($this->account($this->ownerId)['reset_otp_hash'], 'The code is stored before the response.');

        AfterResponse::runQueued(); // what PHP does at the end of the request

        $this->assertSame([['close', 'code', 0], ['finish', null, 0]], $steps, 'Session saved (with the pending reset) first, then the response finished, then the email.');
        $this->assertCount(1, $this->mail->out);
        $this->assertSame(0, AfterResponse::pending());
    }

    public function testDeferredAFailedStaffEmailPutsTheAccountBack(): void
    {
        AfterResponse::$forceDeferred = true;
        AfterResponse::$closeSession  = static fn () => null;
        AfterResponse::$finishRequest = static fn () => null;
        $this->mail->fail = true;

        $this->ask('cashier1')->assertRedirectTo(site_url('login/forgot/check'));
        $this->assertNotNull($this->account($this->staffId)['temp_password_hash']);
        AfterResponse::runQueued();

        $this->assertNull($this->account($this->staffId)['temp_password_hash']);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'password_forgot_failed')->countAllResults());
    }

    public function testPaddedEveryAnswerWaitsForTheSameMinimumTime(): void
    {
        AfterResponse::$forceDeferred = false;
        config(\Config\Auth::class)->forgotMinSeconds = 2.0;
        $slept = [];
        AfterResponse::$sleeper = static function (int $micro) use (&$slept): void {
            $slept[] = $micro;
        };

        $this->ask('owner1');
        $this->ask('ghost');

        $this->assertCount(2, $slept, 'Both answers are padded.');
        foreach ($slept as $micro) {
            $this->assertGreaterThan(1_000_000, $micro);
            $this->assertLessThanOrEqual(2_000_000, $micro);
        }
        $this->assertNotNull($this->codeFor('owner@example.test'), 'Sent during the request in this mode.');
    }

    public function testDeferredNeedsNoPadding(): void
    {
        AfterResponse::$forceDeferred = true;
        AfterResponse::$closeSession  = static fn () => null;
        AfterResponse::$finishRequest = static fn () => null;
        config(\Config\Auth::class)->forgotMinSeconds = 2.0;
        $slept = [];
        AfterResponse::$sleeper = static function (int $micro) use (&$slept): void {
            $slept[] = $micro;
        };

        $this->ask('ghost');
        AfterResponse::runQueued();

        $this->assertSame([], $slept);
    }

    public function testWhichModeThisPhpUses(): void
    {
        // The command line (and mod_php) has no fastcgi_finish_request(): padded. PHP-FPM: deferred.
        $this->assertSame(function_exists('fastcgi_finish_request') && ! is_cli() ? 'deferred' : 'padded', AfterResponse::mode());
        $this->assertSame(3.0, (new ReflectionProperty(\Config\Auth::class, 'forgotMinSeconds'))->getDefaultValue(), 'The live default (the tests set it to 0).');
    }
}
