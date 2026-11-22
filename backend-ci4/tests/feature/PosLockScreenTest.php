<?php

use App\Libraries\PosPin;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\PosPinFixture;
use Tests\Support\ReorderFixture;

/**
 * The Lock POS screen (partials/_pos_locked.php), the Lock POS button and the "Unlocked" note, as on
 * the approved PosLockV1 board. How locking works (PosLock, the PIN check, the 3 tries / 60 s lock)
 * is in PosPinTest.
 *
 * @internal
 */
final class PosLockScreenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');
        $this->addSize('P-1', 'Patrol Shirt', 'M', 5, 2, ['selling_price' => 400]);
    }

    protected function tearDown(): void
    {
        PosPinFixture::clearLocks();
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    public static function roles(): array
    {
        return ['Owner' => ['admin'], 'Staff' => ['staff']];
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /** Locks the POS for $role and returns the session and the lock screen. */
    private function locked(string $role): array
    {
        ($role === 'admin' ? $this->asOwner() : $this->asStaff())->post($role . '/sales/lock', ['item_id' => ['P-1'], 'quantity' => ['1']]);
        $session = $_SESSION;

        return [$session, (string) $this->withSession($session)->get($role . '/sales')->response()->getBody()];
    }

    /** @dataProvider roles */
    public function testTheLockScreenMatchesTheBoard(string $role): void
    {
        [, $html] = $this->locked($role);
        $x        = $this->dom($html);
        $card     = $x->query('//section[@class="pos-locked-card"]')->item(0);
        $this->assertNotNull($card);

        $icon = $x->query('.//div[@class="pos-locked-icon"]/*[local-name()="svg"]', $card)->item(0);
        $this->assertNotNull($icon, 'The shared padlock in the soft green tile.');
        $this->assertSame(['lock-icon', '20', '1.8'], [$icon->getAttribute('class'), $icon->getAttribute('width'), $icon->getAttribute('stroke-width')]);
        $this->assertSame('Point of Sale is locked', trim($x->query('.//h2[@class="pos-locked-title"]', $card)->item(0)->textContent));
        $by = $x->query('.//p[@class="pos-locked-by"]', $card)->item(0);
        $this->assertMatchesRegularExpression('/^Locked by .+ at \d{1,2}:\d\d [AP]M\.$/', trim(preg_replace('/\s+/', ' ', $by->textContent)));
        $this->assertSame($role === 'admin' ? 'Owner One' : 'Ana Cruz', $x->query('.//span[@class="pos-locked-name"]', $by)->item(0)->textContent, 'The real name, not bold.');
        $this->assertSame(0, $x->query('.//strong', $by)->length);

        // A normal field label, the box, the hint.
        $this->assertSame('Checkout PIN', trim($x->query('.//label[@for="unlockPin"]', $card)->item(0)->textContent));
        $this->assertStringNotContainsString('Enter your checkout PIN to unlock', $html);
        $pin = $x->query('.//input[@id="unlockPin"]', $card)->item(0);
        $this->assertSame(
            ['password', PosPin::FIELD, 'pos-unlock-input', 'numeric', '4', '••••', 'one-time-code', 'unlockPinMsg'],
            [$pin->getAttribute('type'), $pin->getAttribute('name'), $pin->getAttribute('class'), $pin->getAttribute('inputmode'), $pin->getAttribute('maxlength'), $pin->getAttribute('placeholder'), $pin->getAttribute('autocomplete'), $pin->getAttribute('aria-describedby')]
        );
        $this->assertTrue($pin->hasAttribute('data-no-pw-toggle'), 'No show/hide eye: a PIN typed at the counter is never shown.');
        $this->assertFalse($pin->hasAttribute('value'));
        $this->assertSame('Enter your 4-digit PIN to continue.', trim($x->query('.//div[@id="unlockPinMsg"]', $card)->item(0)->textContent));
        $this->assertSame('username', $x->query('.//form[@id="posUnlockForm"]//input[@autocomplete="username"]', $card)->item(0)->getAttribute('autocomplete'), 'The hidden username hint stays.');

        // Unlock: text only, disabled until 4 digits (pos-unlock.js).
        $btn = $x->query('.//button[@id="unlockBtn"]', $card)->item(0);
        $this->assertSame(['btn btn-success pos-unlock-btn', 'Unlock'], [$btn->getAttribute('class'), trim($btn->textContent)]);
        $this->assertSame(0, $x->query('./*', $btn)->length, 'No icon on Unlock.');
        $this->assertTrue($btn->hasAttribute('disabled'));
        $this->assertStringContainsString('<script src="/assets/js/pos-unlock.js" defer></script>', $html);

        $this->assertSame('Not you? Sign out from the menu.', trim($x->query('.//p[@class="pos-locked-note"]', $card)->item(0)->textContent));
    }

    public function testAWrongPinShowsTheErrorUnderTheBoxInsteadOfTheHint(): void
    {
        [$session] = $this->locked('staff');
        $this->withSession($session)->post('staff/sales/unlock', [PosPin::FIELD => '9371']);
        $x = $this->dom((string) $this->withSession()->get('staff/sales')->response()->getBody());

        $msg = $x->query('//div[@id="unlockPinMsg"]')->item(0);
        $this->assertSame(['pos-unlock-msg is-error', 'alert'], [$msg->getAttribute('class'), $msg->getAttribute('role')]);
        $this->assertSame('Wrong PIN. 2 tries left before checkout locks for 60 seconds.', trim($msg->textContent));
        $this->assertSame('Enter your 4-digit PIN to continue.', $msg->getAttribute('data-hint'), 'Typing again brings the hint back.');
        $pin = $x->query('//input[@id="unlockPin"]')->item(0);
        $this->assertSame('true', $pin->getAttribute('aria-invalid'), 'Red border.');
        $this->assertFalse($pin->hasAttribute('value'), 'The box is cleared.');
        $this->assertSame(0, $x->query('//div[contains(@class,"app-toast")]')->length, 'Not also as a toast.');
    }

    public function testTheStylesMatchTheBoard(): void
    {
        $css = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/pos.css'));
        foreach ([
            "width: 380px; max-width: 100%; box-sizing: border-box; padding: 26px 26px 20px;\n    background: #fff; border: 1px solid #E3E0D7; border-radius: 14px; text-align: center;",
            'width: 44px; height: 44px; margin: 0 auto 12px; border-radius: 12px; background: #EAF1E3; color: #2F5A27;',
            '.pos-locked-title { margin: 0; font-size: 18px; font-weight: 600; line-height: 1.3; color: #1F2617; }',
            '.pos-locked-by { margin: 4px 0 0; font-size: 13.5px; color: #5C6152; }',
            '.pos-locked-name { font-weight: 500; color: #3A4030; }',
            '.pos-unlock-form { margin-top: 18px; text-align: left; }',
            '.pos-unlock-label { display: block; margin: 0 0 6px; font-size: 13px; font-weight: 500; color: #1F2617; }',
            "display: block; width: 100%; height: 42px; box-sizing: border-box; padding: 0 0 0 .6em;\n    border: 1px solid #D6D2C6; border-radius: 10px;",
            'font-size: 22px; letter-spacing: .6em; text-align: center;',
            '.pos-unlock-input[aria-invalid="true"] { border-color: #B3261E; }',
            '.pos-unlock-msg { margin-top: 6px; font-size: 12px; color: #5C6152; }',
            '.pos-unlock-msg.is-error { color: #B3261E; }',
            "--bs-btn-font-size: 14.5px; --bs-btn-font-weight: 600; --bs-btn-border-radius: 10px;\n    display: flex; align-items: center; justify-content: center; width: 100%; height: 44px;\n    margin-top: 14px;",
            '.pos-locked-note { margin: 14px 0 0; font-size: 12px; color: #5C6152; }',
            '.pos-pin-input::-ms-reveal, .pos-unlock-input::-ms-reveal { display: none; }',
            // Lock POS
            "display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; box-sizing: border-box;\n    border: 1px solid #D6D2C6; border-radius: 10px; background: #fff; color: #1F2617;",
            'font-family: inherit; font-size: 13px; font-weight: 500;',
            '.pos-lock .lock-icon { display: block; flex: 0 0 auto; }',
            '.pos-lock:hover:not(:disabled) { border-color: #4A6741; color: #2F5A27; }',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css, $rule);
        }
        $this->assertStringNotContainsString('pos-unlocked-note', $css, 'The in-column note is gone (a toast now).');
        $this->assertDoesNotMatchRegularExpression('/\.pos-unlock-btn[^{]*\{[^}]*(?<![-\w])(background|background-color|border-color|color|opacity)\s*:/', $css, 'Unlock keeps the theme green and its one disabled look.');
    }

    /** The page after unlocking: the app's toast, no note in the cart column. */
    private function afterUnlock(array $cart): \DOMXPath
    {
        $this->asStaff()->post('staff/sales/lock', $cart);
        $this->withSession($_SESSION)->post('staff/sales/unlock', [PosPin::FIELD => PosPinFixture::PIN]);

        return $this->dom((string) $this->withSession()->get('staff/sales')->response()->getBody());
    }

    /** @return array{0: string, 1: string, 2: string} [class, autohide, title] of the "Unlocked" toast */
    private function unlockedToast(\DOMXPath $x): array
    {
        $toast = $x->query('//div[contains(@class,"app-toast")][@data-toast-key="pos-unlocked"]')->item(0);
        $this->assertNotNull($toast, 'The usual toast.');

        return [$toast->getAttribute('class'), $toast->getAttribute('data-autohide'), trim($x->query('.//div[@class="toast-title"]', $toast)->item(0)->textContent)];
    }

    public function testUnlockingShowsTheUsualToastWithTheOrderKept(): void
    {
        $x = $this->afterUnlock(['item_id' => ['P-1'], 'quantity' => ['1']]);

        $this->assertSame(['app-toast is-success', '4000', 'Unlocked. Your order is still here.'], $this->unlockedToast($x), 'Same component, style and 4 s as the other toasts.');
        $this->assertSame(0, $x->query('//div[@id="posUnlockedNote"]')->length, 'No note after the cart card any more.');
        $this->assertSame(0, $x->query('//*[contains(@class,"pos-unlocked-note")]')->length);
        $this->assertSame(0, $x->query('//div[@id="unlockPinMsg"]')->length, 'Back on the Point of Sale page.');
    }

    public function testUnlockingAnEmptyCartJustSaysUnlocked(): void
    {
        $x = $this->afterUnlock([]); // nothing in the cart

        $this->assertSame(['app-toast is-success', '4000', 'Unlocked.'], $this->unlockedToast($x));
        $this->assertSame(0, $x->query('//div[@id="posUnlockedNote"]')->length);

        // If none of the kept items could come back (sold out meanwhile), pos.js changes the toast to just
        // "Unlocked." while it is showing, once the cart is rebuilt. No note or timer of its own any more.
        $js = file_get_contents(FCPATH . 'assets/js/pos.js');
        $this->assertStringContainsString("const unlockedToast = document.querySelector('.app-toast[data-toast-key=\"pos-unlocked\"] .toast-title');", $js);
        $this->assertStringContainsString("if (unlockedToast && cartLineCount === 0) unlockedToast.textContent = 'Unlocked.';", $js);
        $this->assertLessThan(strpos($js, 'if (unlockedToast && cartLineCount === 0)'), strpos($js, 'cartReady = true;'), 'After the cart is rebuilt.');
        $this->assertStringNotContainsString('hideUnlockedNote', $js);
        $this->assertStringNotContainsString('posUnlockedNote', $js);
    }

    public function testTheLockButtonAndThePrompt(): void
    {
        $html = (string) $this->asStaff()->get('staff/sales')->response()->getBody();
        $x    = $this->dom($html);

        $lock = $x->query('//button[@id="posLockBtn"]')->item(0);
        $this->assertSame(['pos-lock', 'Lock POS'], [$lock->getAttribute('class'), trim($lock->textContent)]);
        $this->assertSame('16', $x->query('./*[local-name()="svg"][@class="lock-icon"]', $lock)->item(0)->getAttribute('width'), 'The shared padlock, 16px.');
        // The checkout PIN prompt has no eye either.
        $this->assertTrue($x->query('//input[@id="pinInput"]')->item(0)->hasAttribute('data-no-pw-toggle'));
        $this->assertSame(0, $x->query('//div[@data-toast-key="pos-unlocked"]')->length, 'The toast only right after unlocking.');
    }
}
