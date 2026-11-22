<?php

use App\Libraries\OnceToken;
use App\Libraries\PosPin;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\PosPinFixture;
use Tests\Support\ReorderFixture;

/**
 * The checkout PIN dialog, "Confirm this sale" (approved PosPinPromptV1 board: partials/_pos_tab.php
 * #pinModal, pos-pin.js, pos.css), and one size for the main POS buttons. The PIN check, the 3 tries /
 * 60 s lock and the one-time key are PosPinTest's.
 *
 * @internal
 */
final class PosPinDialogTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const TABLES = ['sales_transaction', 'sales_item', 'inventory_log', 'form_submissions'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        foreach (self::TABLES as $t) {
            $forge->dropTable($t, true);
        }
        $id   = ['type' => 'INTEGER', 'auto_increment' => true];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];
        $make = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $forge->addUniqueKey('receipt_no');
        $make('sales_transaction', ['sales_id' => $id, 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => ['type' => 'DATETIME', 'null' => true], 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real] + \Tests\Support\SalesVoidColumns::FIELDS, 'sales_id');
        $make('sales_item', ['sales_item_id' => $id, 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real], 'sales_item_id');
        $make('inventory_log', ['log_id' => $id, 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => ['type' => 'DATETIME', 'null' => true]], 'log_id');
        $forge->addUniqueKey('submission_key');
        $make('form_submissions', ['id' => $id, 'submission_key' => ['type' => 'CHAR', 'constraint' => 32], 'action' => $str, 'user_id' => $int, 'result_type' => $str, 'result_id' => $str, 'created_at' => ['type' => 'DATETIME']], 'id');

        $this->addSize('P-1', 'Patrol Shirt', 'M', 5, 2, ['selling_price' => 40]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
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

    private function page(string $role, ?self $session = null): \DOMXPath
    {
        return $this->dom((string) ($session ?? ($role === 'admin' ? $this->asOwner() : $this->asStaff()))->get($role . '/sales')->response()->getBody());
    }

    private function checkout(string $pin, string $key)
    {
        return $this->asStaff()->post('staff/sales/checkout', [
            'item_id' => ['P-1'], 'quantity' => ['1'], 'payment_method' => 'Cash', 'cash_received' => '100', OnceToken::FIELD => $key, PosPin::FIELD => $pin,
        ]);
    }

    /** @dataProvider roles */
    public function testTheDialogMatchesTheBoard(string $role): void
    {
        $x     = $this->page($role);
        $modal = $x->query('//div[@id="pinModal"]')->item(0);

        $this->assertSame(['dialog', 'true', 'pinTitle', 'pinSub', '', '0'], [$modal->getAttribute('role'), $modal->getAttribute('aria-modal'), $modal->getAttribute('aria-labelledby'), $modal->getAttribute('aria-describedby'), $modal->getAttribute('data-pin-error'), $modal->getAttribute('data-pin-locked')]);
        $icon = $x->query('.//span[@class="pin-prompt-icon"]/*[local-name()="svg"]', $modal)->item(0);
        $this->assertSame(['lock-icon', '18'], [$icon->getAttribute('class'), $icon->getAttribute('width')], 'The shared padlock, 18px, in the soft green tile.');
        $this->assertSame('Confirm this sale', trim($x->query('.//h3[@id="pinTitle"]', $modal)->item(0)->textContent));
        $this->assertSame('Your own PIN records who made this sale.', trim($x->query('.//p[@id="pinSub"]', $modal)->item(0)->textContent));

        // The summary strip, filled from the cart on screen when it opens.
        $this->assertSame('Total · Cash', trim(preg_replace('/\s+/', ' ', $x->query('.//span[@class="pin-prompt-method"]', $modal)->item(0)->textContent)));
        $this->assertSame(1, $x->query('.//strong[@id="pinSummaryTotal"]', $modal)->length);

        $this->assertSame('Checkout PIN', trim($x->query('.//label[@for="pinInput"]', $modal)->item(0)->textContent));
        $pin = $x->query('.//input[@id="pinInput"]', $modal)->item(0);
        $this->assertSame(['password', 'numeric', '4', '••••', 'one-time-code', 'pinMsg'], [$pin->getAttribute('type'), $pin->getAttribute('inputmode'), $pin->getAttribute('maxlength'), $pin->getAttribute('placeholder'), $pin->getAttribute('autocomplete'), $pin->getAttribute('aria-describedby')]);
        $this->assertTrue($pin->hasAttribute('data-no-pw-toggle'), 'No eye.');
        $this->assertFalse($pin->hasAttribute('name'), 'Never sent itself: only the hidden pos_pin is.');
        $this->assertSame('Enter your 4-digit PIN.', trim($x->query('.//div[@id="pinMsg"]', $modal)->item(0)->textContent));
        $this->assertSame('username', $x->query('.//form[@id="pinPromptForm"]//input[@autocomplete="username"]', $modal)->item(0)->getAttribute('autocomplete'), 'The username hint stays.');

        $buttons = $x->query('.//div[@class="pin-prompt-actions"]/button', $modal);
        $this->assertSame(['Cancel', 'Confirm sale'], [trim($buttons->item(0)->textContent), trim($buttons->item(1)->textContent)]);
        $this->assertSame(['btn pin-prompt-btn pin-prompt-cancel', 'btn btn-success pin-prompt-btn'], [$buttons->item(0)->getAttribute('class'), $buttons->item(1)->getAttribute('class')]);
        $this->assertTrue($buttons->item(1)->hasAttribute('disabled'), 'Confirm sale waits for 4 digits.');
        $this->assertSame(0, $x->query('.//*[contains(@class,"pin-prompt-keys")]', $modal)->length, 'No key hint line under the buttons (Enter and Esc still work).');
        $this->assertStringNotContainsString('Enter to confirm', (string) $modal->textContent);
    }

    public function testTheStylesAreScopedToThisDialog(): void
    {
        $css = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/pos.css'));
        foreach ([
            '#pinModal { background: rgba(31, 38, 23, .38); }',
            "width: 400px; max-width: calc(100% - 32px); max-height: none; box-sizing: border-box; overflow: visible;\n    padding: 24px; border-radius: 14px;",
            "flex: 0 0 38px; width: 38px; height: 38px; border-radius: 10px; background: #EAF1E3; color: #2F5A27;",
            '#pinModal .pin-prompt-title { margin: 0; font-size: 18px; font-weight: 600; line-height: 1.3; color: #1F2617; }',
            '#pinModal .pin-prompt-sub { margin: 2px 0 0; font-size: 13px; color: #5C6152; }',
            "display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px;\n    padding: 10px 14px; border-radius: 10px; background: #F7F5F0;",
            '#pinModal .pin-prompt-method { font-size: 13px; color: #5C6152; }',
            '#pinModal .pin-prompt-total { font-size: 18px; font-weight: 600; color: #1F2617;',
            '#pinModal .pin-prompt-label { display: block; margin: 16px 0 6px; font-size: 13px; font-weight: 500; color: #1F2617; }',
            "display: block; width: 100%; max-width: none; height: 42px; margin: 0; box-sizing: border-box; padding: 0 0 0 .6em;\n    border: 1px solid #D6D2C6; border-radius: 10px;",
            'font-family: inherit; font-size: 22px; letter-spacing: .6em; text-align: center;',
            '#pinModal .pos-pin-input[aria-invalid="true"] { border-color: #B3261E; }',
            '#pinModal .pin-prompt-msg { margin-top: 6px; font-size: 12px; color: #5C6152; }',
            '#pinModal .pin-prompt-msg.is-error { color: #B3261E; }',
            '#pinModal .pin-prompt-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 18px; }',
            '--bs-btn-bg: #fff; --bs-btn-color: #1F2617; --bs-btn-border-color: #D6D2C6;',
            '.pos-pin-input::-ms-reveal, .pos-unlock-input::-ms-reveal { display: none; }',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css, $rule);
        }
        // The shared dialog look (Inventory, Orders, the purchase order dialog) is untouched.
        $theme = file_get_contents(FCPATH . 'assets/css/admin-theme.css');
        $this->assertStringContainsString('.inv-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }', $theme);
        $this->assertDoesNotMatchRegularExpression('/^\.inv-modal[^{]*\{[^}]*rgba\(31, 38, 23/m', $css, 'No unscoped .inv-modal rule in pos.css.');
    }

    public function testTheBehaviour(): void
    {
        $js = file_get_contents(FCPATH . 'assets/js/pos-pin.js');
        foreach ([
            // The strip: what the cart shows now (display only).
            "var METHOD_LABELS = { Cash: 'Cash', GCash: 'GCash', Split: 'Cash+GCash' };",
            "document.getElementById('pinSummaryTotal').textContent = total ? total.textContent : '';",
            "document.getElementById('pinSummaryMethod').textContent = METHOD_LABELS[method && method.value] || 'Cash';",
            // The cursor starts in the box (Cancel when locked); focus goes back to Checkout.
            'setTimeout(function () { (opts.locked ? cancel : input).focus(); }, 0);',
            'checkoutBtn.focus(); // back to Checkout',
            "document.activeElement.blur(); // Checkout can't take focus now: never leave it in the closed dialog",
            // Keys: Esc cancels, Enter only with 4 digits, Tab stays inside.
            "if (e.key === 'Escape') { e.preventDefault(); close(); return; }",
            "if (e.key === 'Enter' && e.target === input) { e.preventDefault(); if (!ok.disabled) ok.click(); return; }",
            "var items = [input, cancel, ok].filter(function (el) { return !el.disabled; });",
            'ok.disabled = digits.length !== 4;',
            // A refused PIN reopens the dialog with its message; locked: only Cancel for that opening.
            "open({ error: modal.dataset.pinError, locked: modal.dataset.pinLocked === '1' });",
            'input.disabled = !!opts.locked;',
            "if (input.getAttribute('aria-invalid') === 'true') showMessage(''); // typing again: the hint is back",
            // The sale still goes through the checkout form with the Checkout button (submit-once, one-time key).
            'form.requestSubmit(checkoutBtn);',
        ] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
    }

    public function testAWrongPinReopensTheDialogWithTheErrorAndKeepsTheKey(): void
    {
        $key = bin2hex(random_bytes(16));
        $this->checkout('9371', $key)->assertRedirectTo(site_url('staff/sales'));
        $this->assertArrayNotHasKey('toast', $_SESSION, 'Not a toast.');

        $modal = $this->page('staff', $this->withSession())->query('//div[@id="pinModal"]')->item(0);
        $this->assertSame('Wrong PIN. 2 tries left before checkout locks for 60 seconds. Nothing was saved.', $modal->getAttribute('data-pin-error'));
        $this->assertSame('0', $modal->getAttribute('data-pin-locked'));
        $this->assertSame([0, 0], [$this->db->table('sales_transaction')->countAllResults(), $this->db->table('form_submissions')->countAllResults()], 'Nothing sold; the one-time key is not used up.');

        $this->checkout(PosPinFixture::PIN, $key);
        $this->assertSame(1, $this->db->table('sales_transaction')->countAllResults(), 'The same key with the right PIN.');
    }

    public function testTheLockoutReopensTheDialogWithOnlyCancel(): void
    {
        foreach (range(1, 3) as $i) {
            $this->checkout('9371', bin2hex(random_bytes(16)));
        }
        $modal = $this->page('staff', $this->withSession())->query('//div[@id="pinModal"]')->item(0);
        $this->assertStringStartsWith('Checkout locked. 3 wrong PINs.', $modal->getAttribute('data-pin-error'));
        $this->assertSame('1', $modal->getAttribute('data-pin-locked'));

        // The next ordinary page: a normal dialog again (the server still enforces the wait).
        $modal = $this->page('staff')->query('//div[@id="pinModal"]')->item(0);
        $this->assertSame(['', '0'], [$modal->getAttribute('data-pin-error'), $modal->getAttribute('data-pin-locked')]);
    }

    public function testNoPinOrALockedPosStillUseAToast(): void
    {
        $this->db->table('users')->update(['pos_pin_hash' => null], ['user_id' => $this->staffId]);
        $this->checkout('1111', bin2hex(random_bytes(16)));
        $this->assertSame('Set your checkout PIN first', $_SESSION['toast']['title'] ?? null);
        $this->assertArrayNotHasKey('checkout_pin_error', $_SESSION);
    }

    public function testTheMainPosButtonsShareOneSize(): void
    {
        $pos = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/pos.css'));
        $pin = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/pin-setup.css'));

        // Checkout & Print Receipt
        $this->assertStringContainsString(".cart-card #checkoutBtn {\n    --bs-btn-font-size: 14.5px; --bs-btn-font-weight: 600; --bs-btn-border-radius: 10px;\n    display: flex; align-items: center; justify-content: center; width: 100%; height: 44px;", $pos);
        // Save PIN (staff POS, staff My Account, owner POS)
        $this->assertStringContainsString(".pin-card .pin-save {\n    --bs-btn-font-size: 14.5px; --bs-btn-font-weight: 600; --bs-btn-border-radius: 10px;\n    display: flex; align-items: center; justify-content: center; width: 100%; height: 44px;", $pin);
        // Unlock, Confirm sale and Cancel
        $this->assertStringContainsString("#pinModal .pin-prompt-btn,\n.pos-locked-card .pos-unlock-btn {\n    --bs-btn-font-size: 14.5px; --bs-btn-border-radius: 10px;\n    display: flex; align-items: center; justify-content: center; width: 100%; height: 44px;", $pos);
        $this->assertStringContainsString("#pinModal .pin-prompt-btn.btn-success,\n.pos-locked-card .pos-unlock-btn { --bs-btn-font-weight: 600; }", $pos);
        $this->assertStringContainsString('--bs-btn-font-weight: 500;', substr($pos, strpos($pos, '#pinModal .pin-prompt-cancel {'), 600), 'Cancel 500.');
        $this->assertDoesNotMatchRegularExpression('/height: 42px;\s*\n\s*margin-top: 14px;/', $pos, 'Unlock is no longer 42px.');

        // No colours of their own: the theme's, and the shared disabled look.
        foreach (['/\.pin-card \.pin-save \{[^}]*/', '/#pinModal \.pin-prompt-btn,\n\.pos-locked-card \.pos-unlock-btn \{[^}]*/'] as $rule) {
            preg_match($rule, $pos . "\n" . $pin, $m);
            $this->assertDoesNotMatchRegularExpression('/(?<![-\w])(background|background-color|border-color|color|opacity)\s*:/', $m[0]);
        }
    }
}
