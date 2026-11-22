<?php

use App\Libraries\AttemptStore;
use App\Libraries\PosPin;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\PosPinFixture;
use Tests\Support\ReorderFixture;

/**
 * B1 restyle: the staff "Set up your checkout PIN" card (partials/_pin_setup.php) on the Point of Sale
 * page and in My Account, the live checklist (partials/_pin_checklist.php, pin-checklist.js) also under
 * the owner's PIN fields, and the POS boxes password managers must leave alone. Same rules, same routes:
 * the server checks are in PosPinTest.
 *
 * @internal
 */
final class PinSetupCardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const STAFF_PASSWORD = 'StaffPass123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');
        $this->db->table('users')->update(['password_hash' => password_hash(self::STAFF_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])], ['user_id' => $this->staffId]);
        $this->addSize('P-1', 'Patrol Shirt', 'M', 5, 2, ['selling_price' => 250]);
        AttemptStore::forget('staff_pw_change_' . $this->staffId);
    }

    protected function tearDown(): void
    {
        PosPinFixture::clearLocks();
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function withoutPin(): void
    {
        $this->db->table('users')->update(['pos_pin_hash' => null], ['user_id' => $this->staffId]);
    }

    private function staffPage(?self $session = null): string
    {
        return (string) ($session ?? $this->asStaff())->get('staff/sales')->response()->getBody();
    }

    /** The one <input> with this id (or matching $pattern), as a tag string. */
    private function tag(string $html, string $id, ?string $pattern = null): string
    {
        $pattern ??= '#<input[^>]*\sid="' . preg_quote($id, '#') . '"[^>]*>#';
        $this->assertSame(1, preg_match_all($pattern, $html, $m), "one #{$id}");

        return $m[0][0];
    }

    // ------------------------------------------------------------ the card on the POS page

    public function testThePosCardLooksAsDesigned(): void
    {
        $this->withoutPin();
        $html = $this->staffPage();

        $this->assertStringContainsString('<link href="/assets/css/pin-setup.css" rel="stylesheet">', $html);
        $this->assertMatchesRegularExpression('#<form method="post" action="' . preg_quote(site_url('staff/sales/pin'), '#') . '" class="pin-card" id="posSetupPinSetup" data-submit-once#', $html);
        $this->assertMatchesRegularExpression('#<span class="pin-card-icon" aria-hidden="true">\s*(<!--.*?-->\s*)*<svg class="lock-icon" width="18" height="18"#s', $html, 'The shared padlock (partials/_lock_icon.php), 18px.');
        $this->assertStringContainsString('<h3 class="pin-card-title" id="posSetupPinTitle">Set up your checkout PIN</h3>', $html);
        $this->assertStringContainsString('<p class="pin-card-sub">You&#039;ll type it at every checkout. Only you should know it.</p>', $html);
        $this->assertStringNotContainsString('class="pos-pin-setup"', $html, 'No yellow warning panel for staff.');
        $this->assertStringNotContainsString(PosPin::HINT, $html, 'The long rules paragraph is gone (the checklist replaces it).');

        foreach (['posSetupPin' => 'new_pin', 'posSetupPin2' => 'new_pin_confirm'] as $id => $name) {
            $tag = $this->tag($html, $id);
            foreach (['type="password"', 'name="' . $name . '"', 'inputmode="numeric"', 'maxlength="4"', 'placeholder="••••"', 'autocomplete="new-password"', 'data-pin-input', 'class="pin-input"'] as $attr) {
                $this->assertStringContainsString($attr, $tag, "{$id}: {$attr}");
            }
            $this->assertStringNotContainsString('value=', $tag, "{$id} is always empty");
        }
        $password = $this->tag($html, 'posSetupPinPassword');
        $this->assertStringContainsString('autocomplete="current-password"', $password);
        $this->assertStringNotContainsString('value=', $password);
        $this->assertStringContainsString("<div class=\"pin-hint\" id=\"posSetupPinPasswordHint\">To confirm it's you.</div>", $html);

        // The live checklist, between the PIN boxes and the password; the same common PINs as the server.
        $this->assertMatchesRegularExpression('#<ul class="pin-checklist" aria-live="polite"\s+data-pin-checklist\s+data-pin="posSetupPin"\s+data-repeat="posSetupPin2"\s+data-password="posSetupPinPassword"\s+data-save="posSetupPinSave"#', $html);
        preg_match('#data-common="([^"]*)"#', $html, $common);
        $this->assertSame(PosPin::COMMON, json_decode(html_entity_decode($common[1]), true));
        preg_match_all('#<li data-check="(\w+)"><span class="pin-check-mark" aria-hidden="true"></span><span class="pin-check-text">([^<]*)</span>#', $html, $items);
        $this->assertSame(['digits', 'strong', 'match'], $items[1]);
        $this->assertSame(['4 digits', 'Not easy to guess (no 1234, 1111, 1212 or a year)', 'Both PINs match'], $items[2]);
        $order = array_map(static fn ($needle) => strpos($html, $needle), ['id="posSetupPin2"', 'class="pin-checklist"', 'id="posSetupPinPassword"', 'id="posSetupPinSave"']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'Repeat PIN, checklist, password, Save.');

        // Save: full width, disabled until the checklist and password are done (pin-checklist.js).
        $this->assertStringContainsString('<button type="submit" class="btn btn-success pin-save" id="posSetupPinSave" disabled>Save PIN</button>', $html);
        $this->assertStringContainsString('<script src="/assets/js/pin-checklist.js" defer></script>', $html);
        $this->assertStringContainsString('data-no-pin="1"', $html, 'Checkout stays disabled until the PIN is saved.');
    }

    public function testThePasswordManagerIsToldWhichAccountAndTheSearchBoxIsNoCredential(): void
    {
        $this->withoutPin();
        $html = $this->staffPage();

        // A username hint inside the card, so the username isn't filled into the item search. Only
        // visually hidden: browsers skip display:none, type="hidden" and the hidden attribute.
        $hint = $this->tag($html, 'x', '#<input type="text" name="pin_account"[^>]*>#');
        foreach (['value="staff1"', 'autocomplete="username"', 'readonly', 'tabindex="-1"', 'aria-hidden="true"', 'class="username-hint"', 'position:absolute;width:1px;height:1px', 'clip:rect(0,0,0,0)'] as $attr) {
            $this->assertStringContainsString($attr, $hint, $attr);
        }
        $this->assertDoesNotMatchRegularExpression('#\shidden[\s>]|display:\s*none|type="hidden"#', $hint);
        $search = $this->tag($html, 'posSearch');
        foreach (['type="search"', 'name="pos_item_search"', 'autocomplete="off"'] as $attr) {
            $this->assertStringContainsString($attr, $search);
        }

        // Every box on the page that a person types into says what autofill may do.
        preg_match_all('#<input(?![^>]*type="hidden")(?![^>]*\shidden[\s>])[^>]*>#', $html, $inputs);
        $this->assertNotEmpty($inputs[0]);
        foreach ($inputs[0] as $input) {
            $this->assertMatchesRegularExpression('#autocomplete="(off|new-password|current-password|one-time-code|username)"#', $input, $input);
        }
        foreach (['cashReceived', 'gcashAmountDisplay', 'gcashRefGcash', 'splitGcashAmount', 'gcashRefSplit', 'splitCashReceived', 'orNumber'] as $id) {
            $this->assertStringContainsString('autocomplete="off"', $this->tag($html, $id), $id);
        }
    }

    public function testAServerErrorShowsUnderItsFieldAndEveryBoxComesBackEmpty(): void
    {
        $this->withoutPin();

        $this->asStaff()->post('staff/sales/pin', ['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => 'wrong']);
        $html = $this->staffPage($this->withSession());
        $this->assertStringContainsString('<div class="pin-error" id="posSetupPinPasswordErr" role="alert">Current password is incorrect.</div>', $html);
        $this->assertStringContainsString('aria-invalid="true" aria-describedby="posSetupPinPasswordHint posSetupPinPasswordErr"', $this->tag($html, 'posSetupPinPassword'));
        foreach (['posSetupPin', 'posSetupPin2', 'posSetupPinPassword'] as $id) {
            $this->assertStringNotContainsString('value=', $this->tag($html, $id), $id);
        }
        $this->assertStringNotContainsString('5739', $html);

        $this->asStaff()->post('staff/sales/pin', ['new_pin' => '2020', 'new_pin_confirm' => '2020', 'current_password' => self::STAFF_PASSWORD]);
        $html = $this->staffPage($this->withSession());
        $this->assertMatchesRegularExpression('#<div class="pin-error" id="posSetupPinErr" role="alert">That PIN is too easy to guess\.#', $html);
        $this->assertStringContainsString('aria-invalid="true"', $this->tag($html, 'posSetupPin'));
        $this->assertStringNotContainsString('aria-invalid', $this->tag($html, 'posSetupPinPassword'));
    }

    public function testAfterSavingTheCardIsGoneCheckoutWorksAndOnlyAToastSaysSo(): void
    {
        $this->withoutPin();
        $this->asStaff()->post('staff/sales/pin', ['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::STAFF_PASSWORD]);

        $html = $this->staffPage($this->withSession());
        $this->assertStringNotContainsString('class="pin-card"', $html);
        $this->assertStringContainsString('data-no-pin="0"', $html);
        $this->assertMatchesRegularExpression('#class="app-toast is-success"[^>]*data-autohide="4000"#', $html, 'The usual toast, hiding itself.');
        $this->assertStringContainsString('Checkout PIN saved', $html);

        // The next visit: no banner left on the page, no setup card.
        $again = $this->staffPage();
        $this->assertStringNotContainsString('Checkout PIN saved', $again, 'No lasting banner.');
        $this->assertStringNotContainsString('class="pin-card"', $again);
    }

    public function testTheOwnerGetsTheSameSetupCardSavedThroughMyProfilesRoute(): void
    {
        $this->db->table('users')->update(['pos_pin_hash' => null], ['user_id' => $this->ownerId]);
        $html = (string) $this->asOwner()->get('admin/sales')->response()->getBody();

        // The staff card: title, line, PIN boxes, the live checklist, the current password, Save PIN.
        $this->assertMatchesRegularExpression('#<form method="post" action="' . preg_quote(site_url('admin/settings/sign-in'), '#') . '" class="pin-card" id="posSetupPinSetup" data-submit-once#', $html);
        $this->assertStringContainsString('<input type="hidden" name="return" value="pos">', $html, 'Back to the Point of Sale (a fixed value, not a URL).');
        $this->assertStringContainsString('<h3 class="pin-card-title" id="posSetupPinTitle">Set up your checkout PIN</h3>', $html);
        foreach (['posSetupPin' => 'new_pin', 'posSetupPin2' => 'new_pin_confirm', 'posSetupPinPassword' => 'current_password'] as $id => $name) {
            $tag = $this->tag($html, $id);
            $this->assertStringContainsString('name="' . $name . '"', $tag, $id);
            $this->assertStringNotContainsString('value=', $tag, "{$id} is always empty");
        }
        $this->assertStringContainsString('data-pin="posSetupPin"', $html, 'The live checklist.');
        $this->assertStringContainsString('<button type="submit" class="btn btn-success pin-save" id="posSetupPinSave" disabled>Save PIN</button>', $html);
        $hint = $this->tag($html, 'x', '#<input type="text" name="pin_account"[^>]*>#');
        $this->assertStringContainsString('value="owner1"', $hint, "The owner's username for password managers.");
        // No username or email boxes: the route leaves what isn't posted as it is.
        $form = substr($html, strpos($html, 'id="posSetupPinSetup"'), 4000);
        $this->assertDoesNotMatchRegularExpression('#name="(username|email)"#', $form);

        // The old "Set PIN in My Profile" card is gone.
        foreach (['posOwnerPin', 'Set PIN in My Profile', 'edit=signin'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    /** @dataProvider roles */
    public function testBeforeTheMigrationBothRolesSeeTheSameWhiteNotice(string $base): void
    {
        $html = view('partials/_pos_tab', ['base' => $base, 'groups' => [], 'categories' => [], 'posPinReady' => false, 'posHasPin' => false], ['saveData' => false]);

        $this->assertStringContainsString('<div class="pin-card" id="posPinNotReady" role="status">', $html);
        $this->assertStringContainsString("<h3 class=\"pin-card-title\">Checkout PINs aren't set up yet</h3>", $html);
        $this->assertStringContainsString('The database needs updating (php spark migrate) before anyone can check out.', $html);
        $this->assertStringNotContainsString('pos-pin-setup', $html);
        $this->assertStringNotContainsString('id="posOwnerPin"', $html);
        $this->assertStringNotContainsString('data-pin-checklist', $html, 'No setup form until the columns exist.');
    }

    public static function roles(): array
    {
        return ['Owner' => ['admin'], 'Staff' => ['staff']];
    }

    // ------------------------------------------------------------ My Account and My Profile

    public function testMyAccountUsesTheSameCard(): void
    {
        $html = (string) $this->asStaff()->get('staff/account')->response()->getBody();
        $this->assertMatchesRegularExpression('#<form method="post" action="' . preg_quote(site_url('staff/account/pin'), '#') . '" class="pin-card" id="acctPinSetup"#', $html);
        $this->assertStringContainsString('>Change your checkout PIN</h3>', $html, 'They already have one.');
        $this->assertStringContainsString('data-pin="acctPin"', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-success pin-save" id="acctPinSave" disabled>Save PIN</button>', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $this->tag($html, 'acctPin'));

        $this->withoutPin();
        $html = (string) $this->asStaff()->get('staff/account')->response()->getBody();
        $this->assertStringContainsString('>Set up your checkout PIN</h3>', $html);
    }

    public function testTheOwnerPinFieldsGetTheChecklistOnly(): void
    {
        $html = (string) $this->asOwner()->get('admin/settings?tab=profile')->response()->getBody();
        $this->assertMatchesRegularExpression('#<ul class="pin-checklist" aria-live="polite"\s+data-pin-checklist\s+data-pin="siNewPin"\s+data-repeat="siNewPinConfirm"\s+data-common=#', $html, 'No password or Save link: a blank PIN keeps the current one and Save works as before.');
        $this->assertStringContainsString('<script src="/assets/js/pin-checklist.js" defer></script>', $html);
        $this->assertLessThan(strpos($html, 'id="siCurrentPassword"'), strpos($html, 'class="pin-checklist"'), 'Under the PIN fields, before the current password.');
        $this->assertStringNotContainsString('class="pin-card"', $html);
    }

    // ------------------------------------------------------------ autofill: the structure browsers read

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /**
     * Every password box is inside a <form>, and that form's first text box is a username one
     * (the hint, or the real username field): browsers pair a password box with the text box before it
     * in the same form, and lump every box that is in no form into one group.
     */
    private function assertPasswordBoxesArePairedWithAUsername(string $html, string $page): void
    {
        $x         = $this->dom($html);
        $passwords = $x->query('//input[@type="password"]');
        $this->assertGreaterThan(0, $passwords->length, $page);
        foreach ($passwords as $pw) {
            $id   = $pw->getAttribute('id');
            $form = $x->query('ancestor::form[1]', $pw)->item(0);
            $this->assertNotNull($form, "{$page}: #{$id} is inside a form");
            $first = $x->query('.//input[not(@type="hidden") and not(@type="password") and not(@type="checkbox") and not(@type="radio")][1]', $form)->item(0);
            $this->assertNotNull($first, "{$page}: #{$id}'s form has a username box");
            $this->assertSame('username', $first->getAttribute('autocomplete'), "{$page}: #{$id}'s form starts with a username box");
            $this->assertFalse($first->hasAttribute('hidden'), "{$page}: the username box is not display:none (browsers skip it)");
        }
    }

    /** @dataProvider roles */
    public function testTheItemSearchIsInNoFormAndEveryPasswordBoxHasItsOwnUsername(string $base): void
    {
        $this->db->table('users')->update(['pos_pin_hash' => null], ['user_id' => $base === 'admin' ? $this->ownerId : $this->staffId]);
        $html = (string) ($base === 'admin' ? $this->asOwner() : $this->asStaff())->get($base . '/sales')->response()->getBody();
        $x    = $this->dom($html);

        $search = $x->query('//input[@id="posSearch"]')->item(0);
        $this->assertSame(['search', 'pos_item_search', 'off'], [$search->getAttribute('type'), $search->getAttribute('name'), $search->getAttribute('autocomplete')]);
        $this->assertSame(0, $x->query('ancestor::form', $search)->length, 'The item search is in no form.');
        $this->assertDoesNotMatchRegularExpression('/user|login|email|name/i', $search->getAttribute('id') . ' ' . $search->getAttribute('name'));
        // No password box is left outside a form, where browsers would group it with the search.
        $this->assertSame(0, $x->query('//input[@type="password"][not(ancestor::form)]')->length);
        $this->assertPasswordBoxesArePairedWithAUsername($html, $base . '/sales');

        $prompt = $x->query('//form[@id="pinPromptForm"]')->item(0);
        $this->assertNotNull($prompt, 'The checkout PIN prompt is its own form (never sent).');
        $this->assertSame($base === 'admin' ? 'owner1' : 'staff1', $x->query('.//input[@autocomplete="username"]', $prompt)->item(0)->getAttribute('value'));
    }

    public function testTheSameStructureInMyAccountMyProfileAndTheLockScreen(): void
    {
        $this->assertPasswordBoxesArePairedWithAUsername((string) $this->asStaff()->get('staff/account')->response()->getBody(), 'staff/account');
        $this->assertPasswordBoxesArePairedWithAUsername((string) $this->asOwner()->get('admin/settings?tab=profile')->response()->getBody(), 'admin/settings');

        $this->asStaff()->post('staff/sales/lock');
        $locked = (string) $this->withSession()->get('staff/sales')->response()->getBody();
        $this->assertPasswordBoxesArePairedWithAUsername($locked, 'lock screen');
        $this->assertStringContainsString('autocomplete="one-time-code"', $this->tag($locked, 'unlockPin'));
    }

    // ------------------------------------------------------------ separate cards and the empty cart

    public function testTheCartColumnIsSeparateCardsNotOnePanel(): void
    {
        $this->withoutPin();
        $x = $this->dom($this->staffPage());

        $panel = $x->query('//div[@class="cart-panel"]')->item(0);
        $cards = [];
        foreach ($x->query('./*[@id]', $panel) as $child) {
            $cards[] = $child->getAttribute('id') . ':' . $child->getAttribute('class');
        }
        $this->assertSame(['posSetupPinSetup:pin-card', 'cartForm:cart-card'], $cards, 'The PIN card, then the Current order card: side by side, not nested.');
        $this->assertSame(0, $x->query('//form[@id="cartForm"]//*[contains(@class,"pin-card")]')->length);

        $css = file_get_contents(FCPATH . 'assets/css/pos.css');
        preg_match('/\.cart-panel \{([^}]*)\}/', $css, $panelCss);
        $this->assertStringNotContainsString('background', $panelCss[1], 'No white panel behind the cards.');
        $this->assertStringContainsString('gap: 14px', $panelCss[1]);
        $this->assertStringContainsString('overflow-y: auto', $panelCss[1], 'The column still scrolls.');
        $this->assertStringContainsString('.cart-card { background: #fff; border: 1px solid #E3E0D7; border-radius: 14px;', $css);
        $this->assertStringContainsString('border: 1px solid #E3E0D7; border-radius: 14px;', file_get_contents(FCPATH . 'assets/css/pin-setup.css'));
    }

    /** @dataProvider reasons */
    public function testAnEmptyCartShowsOnlyTheTotalsAndWhyCheckoutIsDisabled(bool $hasPin, string $reason): void
    {
        if (! $hasPin) {
            $this->withoutPin();
        }
        $x = $this->dom($this->staffPage());

        $pay = $x->query('//div[@id="cartPay"]')->item(0);
        $this->assertTrue($pay->hasAttribute('hidden'), 'Hidden until the first item (pos.js).');
        foreach (['paymentMethod', 'cashReceived', 'cartChange', 'gcashPanel', 'splitPanel', 'orNumber'] as $id) {
            $this->assertSame(1, $x->query('.//*[@id="' . $id . '"]', $pay)->length, "#{$id} is in the hidden payment part");
        }
        foreach (['cartEmptyMsg', 'cartSubtotal', 'cartTotal', 'checkoutBtn', 'checkoutReason'] as $id) {
            $this->assertSame(0, $x->query('.//*[@id="' . $id . '"]', $pay)->length, "#{$id} stays visible");
        }
        $button = $x->query('//button[@id="checkoutBtn"]')->item(0);
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertSame('checkoutReason', $button->getAttribute('aria-describedby'));
        $this->assertSame($reason, trim($x->query('//p[@id="checkoutReason"]')->item(0)->textContent));

        // pos.js keeps it current: the payment part with the first item, the reason for each case.
        $js = file_get_contents(FCPATH . 'assets/js/pos.js');
        $this->assertStringContainsString("document.getElementById('cartPay').hidden = ids.length === 0;", $js);
        $sync = substr($js, strpos($js, 'function syncCheckout()'), 1400);
        $order = [
            'reason = form.dataset.reasonNoPin;',
            '} else if (cartLineCount === 0) {',
            'reason = form.dataset.reasonEmpty;',
            "} else if (method === 'Cash' && amountOf('cashReceived') < cartTotal) {",
            'reason = form.dataset.reasonCash;',
        ];
        $at = array_map(static fn ($needle) => strpos($sync, $needle), $order);
        $this->assertNotContains(false, $at, 'Each reason is in syncCheckout().');
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at, 'No PIN, then an empty cart, then not enough cash.');
        $this->assertStringContainsString("document.getElementById('checkoutBtn').disabled = reason !== '';", $sync, 'Enabled only when nothing is missing; the line is then empty (hidden).');
        $form = $x->query('//form[@id="cartForm"]')->item(0);
        $this->assertSame(['Set your PIN above to check out.', 'Add an item to start a sale.', 'Enter the cash received to check out.'], [$form->getAttribute('data-reason-no-pin'), $form->getAttribute('data-reason-empty'), $form->getAttribute('data-reason-cash')]);
        // The one-time key and the saved-cart restore are still on the form.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $x->query('.//input[@name="submission_key"]', $form)->item(0)->getAttribute('value'));
        $this->assertTrue($form->hasAttribute('data-restore-saved'));
    }

    public static function reasons(): array
    {
        return ['no PIN' => [false, 'Set your PIN above to check out.'], 'PIN set, empty cart' => [true, 'Add an item to start a sale.']];
    }

    // ------------------------------------------------------------ the browser rules = the server rules

    public function testTheChecklistRulesMatchTheServerOnEveryPin(): void
    {
        exec('node -v 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Node.js is not installed; pin-checklist.js could not be run.');
        }

        $runner = WRITEPATH . 'pin-rules-check.js';
        file_put_contents($runner, 'const COMMON = ' . json_encode(PosPin::COMMON) . ";
" . <<<'JS'
            const fs = require('fs');
            const [file, phone] = process.argv.slice(2);
            global.window = {};
            global.document = { readyState: 'complete', querySelectorAll: () => [] };
            eval(fs.readFileSync(file, 'utf8'));
            let s = '';
            for (let i = 0; i < 10000; i++) s += window.PinRules.weak(String(i).padStart(4, '0'), COMMON, phone || '') ? '1' : '0';
            process.stdout.write(s);
            JS);

        try {
            foreach (['' => null, '5866' => '0985 214 5866'] as $last4 => $phone) {
                $server = '';
                for ($i = 0; $i < 10000; $i++) {
                    $server .= PosPin::weakReason(sprintf('%04d', $i), $phone) === null ? '0' : '1';
                }
                $browser = shell_exec('node ' . escapeshellarg($runner) . ' ' . escapeshellarg(FCPATH . 'assets/js/pin-checklist.js') . ' ' . escapeshellarg((string) $last4));
                $this->assertSame($server, $browser, 'pin-checklist.js refuses exactly the PINs PosPin refuses' . ($phone ? ' (with a contact number)' : ''));
            }
        } finally {
            @unlink($runner);
        }
    }
}
