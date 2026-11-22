<?php

use App\Libraries\OnceToken;
use App\Libraries\PosCheckout;
use App\Libraries\PosPricing;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\PosPinFixture;
use Tests\Support\ReorderFixture;

/**
 * The Point of Sale "Current order" card (partials/_pos_tab.php, pos.js, pos.css): its markup, the
 * disabled Checkout that ignores hover and press, and the payment / OR rules, which the browser
 * filters as they are typed and the server checks again (PosCheckout): an error comes back under
 * its box with the cart kept, never the PIN.
 *
 * @internal
 */
final class PosCartCardTest extends CIUnitTestCase
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

        $this->addSize('P-1', 'Patrol Shirt', 'M', 50, 2, ['selling_price' => 400]);
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

    private function sale(array $payment, int $qty = 1)
    {
        return $this->asStaff()->post('staff/sales/checkout', $payment + [
            'item_id' => ['P-1'], 'quantity' => [(string) $qty], OnceToken::FIELD => bin2hex(random_bytes(16)),
        ] + PosPinFixture::field());
    }

    private function sales(): int
    {
        return $this->db->table('sales_transaction')->countAllResults();
    }

    private function lastSale(): array
    {
        return $this->db->table('sales_transaction')->orderBy('sales_id', 'DESC')->get()->getRowArray();
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    private function page(): \DOMXPath
    {
        return $this->dom((string) $this->asStaff()->get('staff/sales')->response()->getBody());
    }

    // ------------------------------------------------------------ the server's rules

    /** @return array<string, array{0: array, 1: ?string, 2: ?string}> payment posted, refused field, message */
    public static function payments(): array
    {
        $cash = static fn ($v) => ['payment_method' => 'Cash', 'cash_received' => $v];
        $gcash = static fn ($ref) => ['payment_method' => 'GCash', 'gcash_reference_no' => $ref];
        $split = static fn ($g, $ref, $c) => ['payment_method' => 'Split', 'gcash_amount' => $g, 'gcash_reference_no' => $ref, 'cash_received' => $c];

        return [
            'cash, exact'                   => [$cash('400'), null, null],
            'cash, commas dropped'          => [$cash('1,000'), null, null],
            'cash, 2 decimals'              => [$cash('400.50'), null, null],
            'cash, the most allowed'        => [$cash('1,000,000'), null, null],
            'cash, over ₱1,000,000'         => [$cash('1000000.01'), 'cash_received', PosCheckout::MAX_CASH_ERROR],
            'cash, letters'                 => [$cash('5OO'), 'cash_received', PosCheckout::MONEY_ERROR],
            'cash, negative'                => [$cash('-500'), 'cash_received', PosCheckout::MONEY_ERROR],
            'cash, 3 decimals'              => [$cash('400.505'), 'cash_received', PosCheckout::MONEY_ERROR],
            'cash, short'                   => [$cash('399.99'), 'cash_received', 'Insufficient cash received.'],
            'gcash, 13 digits'              => [$gcash('1234567890123'), null, null],
            'gcash, 12 digits'              => [$gcash('123456789012'), 'gcash_reference_no', PosCheckout::GCASH_REF_ERROR],
            'gcash, 14 digits'              => [$gcash('12345678901234'), 'gcash_reference_no', PosCheckout::GCASH_REF_ERROR],
            'gcash, letters'                => [$gcash('REF-123456789'), 'gcash_reference_no', PosCheckout::GCASH_REF_ERROR],
            'gcash, none'                   => [$gcash(''), 'gcash_reference_no', 'GCash reference number is required.'],
            'split, cash + gcash = total'   => [$split('150', '1234567890123', '250'), null, null],
            'split, change on the cash'     => [$split('150', '1234567890123', '500'), null, null],
            'split, short'                  => [$split('150', '1234567890123', '249'), 'cash_received', 'Insufficient cash received for the remaining balance.'],
            'split, gcash amount letters'   => [$split('1a0', '1234567890123', '250'), 'gcash_amount', PosCheckout::MONEY_ERROR],
            'split, gcash = total'          => [$split('400', '1234567890123', '0'), 'gcash_amount', "GCash amount can't be more than the total."],
            'split, bad reference'          => [$split('150', '12345', '250'), 'gcash_reference_no', PosCheckout::GCASH_REF_ERROR],
            'split, cash over the most'     => [$split('150', '1234567890123', '2000000'), 'cash_received', PosCheckout::MAX_CASH_ERROR],
        ];
    }

    /** @dataProvider payments */
    public function testThePaymentRules(array $payment, ?string $field, ?string $message): void
    {
        $this->sale($payment)->assertRedirectTo(site_url('staff/sales'));

        if ($field === null) {
            $this->assertSame(1, $this->sales(), 'Saved.');
            $this->assertArrayNotHasKey('pos_field_error', $_SESSION);

            return;
        }
        $this->assertSame(0, $this->sales(), 'Nothing sold.');
        $this->assertSame(['field' => $field, 'message' => $message], $_SESSION['pos_field_error'] ?? null);
        $this->assertSame(50, (int) $this->db->table('products')->where('item_id', 'P-1')->get()->getRow()->current_stock);
        $this->assertSame(0, $this->db->table('form_submissions')->countAllResults(), 'The one-time key was not used.');
        // The cart comes back (never the PIN).
        $old = $_SESSION['_ci_old_input']['post'] ?? [];
        $this->assertSame([['P-1'], ['1']], [$old['item_id'] ?? null, $old['quantity'] ?? null]);
        $this->assertArrayNotHasKey('pos_pin', $old);
    }

    public function testWhatIsSavedFromTheTypedAmounts(): void
    {
        $this->sale(['payment_method' => 'Cash', 'cash_received' => '1,000']);
        $this->assertSame([400.0, 0.0], [(float) $this->lastSale()['cash_amount'], (float) $this->lastSale()['gcash_amount']]);
        $this->assertSame(1000.0, (float) $_SESSION['receipt']['cash_received']);
        $this->assertSame(600.0, (float) $_SESSION['receipt']['change']);

        $this->sale(['payment_method' => 'Split', 'gcash_amount' => '150', 'gcash_reference_no' => '1234567890123', 'cash_received' => '250']);
        $row = $this->lastSale();
        $this->assertSame([250.0, 150.0, 400.0], [(float) $row['cash_amount'], (float) $row['gcash_amount'], (float) $row['total_amount']], 'Cash + GCash = the total.');
        $this->assertSame('1234567890123', $row['gcash_reference_no']);
    }

    public function testTheErrorShowsUnderItsBoxNotAtTheTop(): void
    {
        $this->sale(['payment_method' => 'Cash', 'cash_received' => '5OO']);
        $x = $this->dom((string) $this->withSession()->get('staff/sales')->response()->getBody());

        $this->assertSame(PosCheckout::MONEY_ERROR, trim($x->query('//div[@id="cashReceivedErr"]')->item(0)->textContent));
        $this->assertSame('true', $x->query('//input[@id="cashReceived"]')->item(0)->getAttribute('aria-invalid'));
        $this->assertSame(0, $x->query('//div[contains(@class,"alert-danger")]')->length, 'Not also at the top.');
        preg_match('/^\[.*\]$/', $x->query('//form[@id="cartForm"]')->item(0)->getAttribute('data-restore'), $restore);
        $this->assertSame([['id' => 'P-1', 'qty' => 1]], json_decode($restore[0], true), 'The cart comes back.');

        // A Cash+GCash reference: under the Cash+GCash box.
        $this->sale(['payment_method' => 'Split', 'gcash_amount' => '150', 'gcash_reference_no' => '12345', 'cash_received' => '250']);
        $x = $this->dom((string) $this->withSession()->get('staff/sales')->response()->getBody());
        $this->assertSame(PosCheckout::GCASH_REF_ERROR, trim($x->query('//div[@id="gcashRefSplitErr"]')->item(0)->textContent));
        $this->assertSame('', trim($x->query('//div[@id="gcashRefGcashErr"]')->item(0)->textContent));
    }

    public function testAmountsAreReadTheSameWayEverywhere(): void
    {
        $this->assertSame(1000.0, PosCheckout::money('1,000'));
        $this->assertSame(0.0, PosCheckout::money(''));
        $this->assertSame(12.5, PosCheckout::money(' 12.50 '));
        foreach (['-1', '1e3', '12.345', '1.2.3', 'abc', '₱100'] as $bad) {
            $this->assertNull(PosCheckout::money($bad), $bad);
        }
    }

    // ------------------------------------------------------------ the card

    public function testTheCardMarkup(): void
    {
        $x = $this->page();

        $this->assertSame('Current order 0 items', preg_replace('/\s+/', ' ', trim($x->query('//h3[@class="cart-title"]')->item(0)->textContent)));
        $this->assertSame('0 items', $x->query('//span[@id="cartCount"]')->item(0)->textContent);
        $this->assertSame(['Subtotal', 'Discount', 'Total'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//div[@class="cart-totals"]/div/span[1]'))));
        $this->assertStringContainsString('Discounts: 10+ of one item 5% off · matching set 2% off', $x->query('//p[@class="cart-note"]')->item(0)->textContent);
        $this->assertSame(1, $x->query('//p[@class="cart-note"]/i[contains(@class,"bi-info-circle")]')->length);

        $labels = array_map(static fn ($n) => preg_replace('/\s+/', ' ', trim($n->textContent)), iterator_to_array($x->query('//*[contains(concat(" ", @class, " "), " cart-label ")]')));
        $this->assertSame(['Payment method', 'Cash received', 'GCash amount', 'GCash reference no.', 'GCash amount', 'GCash reference no.', 'Cash received', 'Official receipt no. (optional)'], $labels);
        $this->assertSame("From the store's BIR booklet.", trim($x->query('//div[@id="orNumberHint"]')->item(0)->textContent));

        foreach (['cashReceived', 'splitCashReceived', 'splitGcashAmount'] as $id) {
            $in = $x->query('//input[@id="' . $id . '"]')->item(0);
            $this->assertSame(['text', 'decimal', 'off'], [$in->getAttribute('type'), $in->getAttribute('inputmode'), $in->getAttribute('autocomplete')], $id);
            $this->assertTrue($in->hasAttribute('data-money'), $id);
        }
        foreach (['gcashRefGcash', 'gcashRefSplit'] as $id) {
            $in = $x->query('//input[@id="' . $id . '"]')->item(0);
            $this->assertSame(['numeric', '13', '\d{13}', '13'], [$in->getAttribute('inputmode'), $in->getAttribute('maxlength'), $in->getAttribute('pattern'), $in->getAttribute('data-exact')], $id);
        }
        $or = $x->query('//input[@id="orNumber"]')->item(0);
        $this->assertSame(['numeric', '20', '\d{1,20}'], [$or->getAttribute('inputmode'), $or->getAttribute('maxlength'), $or->getAttribute('pattern')]);

        // Payment method buttons say which one is chosen.
        $this->assertSame(['true', 'false', 'false'], array_map(static fn ($b) => $b->getAttribute('aria-pressed'), iterator_to_array($x->query('//button[contains(@class,"pay-btn")]'))));
        // The Change boxes (neutral until cash is typed: pos.js).
        foreach (['cashChangeBox' => 'cartChangeLabel', 'splitChangeBox' => 'splitChangeLabel'] as $box => $label) {
            $this->assertSame('cart-change', $x->query('//div[@id="' . $box . '"]')->item(0)->getAttribute('class'));
            $this->assertSame('Change', $x->query('//span[@id="' . $label . '"]')->item(0)->textContent);
        }
    }

    public function testTheScriptDrawsLinesTotalsAndTheChangeBoxAsDesigned(): void
    {
        $js = file_get_contents(FCPATH . 'assets/js/pos.js');

        $this->assertStringContainsString("const sub = [item.size ? 'Size ' + esc(item.size) : '', esc(peso(item.price)) + ' each']", $js, '"Size S · ₱400 each", no size: "₱400 each".');
        $this->assertStringContainsString('<div class="name">${esc(item.name)}</div>', $js, 'The name alone (no "· S").');
        $this->assertStringContainsString('<button type="button" class="cart-remove" onclick="posRemove(${jsArg(l.id)})" aria-label="Remove ${esc(label)}"', $js);
        $this->assertStringContainsString("window.posRemove = id => { cart[id].capped = false; cart[id].editing = false; delete cart[id].draft; cart[id].qty = 0; renderCart(); };", $js, 'The same as taking the quantity to 0.');
        // Totals from the one pricing result: Subtotal − Discount = Total.
        foreach (["textContent = peso(pricing.subtotal);", "textContent = '−' + peso(pricing.discount);", "textContent = peso(pricing.total);", "textContent = discountLabel(pricing.lines);"] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        // Change: neutral, enough, or "Short by".
        $this->assertStringContainsString("document.getElementById(labelId).textContent = short ? 'Short by' : 'Change';", $js);
        $this->assertStringContainsString("box.classList.toggle('is-short', short);", $js);
    }

    public function testACartLineGivesTheNameTheRoom(): void
    {
        $css = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/pos.css'));
        $js  = file_get_contents(FCPATH . 'assets/js/pos.js');

        // name | stepper | total + ✕
        $this->assertStringContainsString("display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center;\n    gap: 4px 10px;", $css);
        $this->assertMatchesRegularExpression('#<div class="line-end">\s*<div class="line-total">\$\{esc\(peso\(l\.lineTotal\)\)\}</div>\s*<button type="button" class="cart-remove"#', $js, 'The total and ✕ side by side in the last column.');
        $this->assertStringContainsString('.cart-line .name { font-weight: 500; font-size: 14px; line-height: 1.35; overflow-wrap: break-word; }', $css, 'Breaks only a really long word.');
        // A small stepper: 30px box, 28×28 buttons with 14px signs, a 32px number box at 13.5px.
        $this->assertStringContainsString('display: inline-flex; align-items: stretch; height: 30px;', $css);
        $this->assertStringContainsString("width: 28px; height: 28px; padding: 0; border: 0; background: #fff; color: #1F2617;\n    font-size: 14px;", $css);
        $this->assertStringContainsString('width: 32px; height: 28px; padding: 0;', $css);
        $this->assertStringContainsString('font-size: 13.5px; font-variant-numeric: tabular-nums;', $css);
        $this->assertStringContainsString('.cart-line .line-end { display: flex; align-items: center; gap: 6px; }', $css);
        $this->assertStringContainsString('.cart-line .line-total { font-size: 14px; font-weight: 500; text-align: right; min-width: 64px; font-variant-numeric: tabular-nums;', $css);
        $this->assertStringContainsString('flex: 0 0 22px; width: 22px; height: 22px;', $css);
        // Too narrow for one row: the controls move under the name.
        $this->assertStringContainsString(".cart-card { container: cart / inline-size; }", $css);
        $this->assertStringContainsString("@container cart (max-width: 300px) {\n    .cart-line { grid-template-columns: auto minmax(0, 1fr); }\n    .cart-line .info { grid-column: 1 / -1; }", $css);
    }

    public function testTheHeaderAndCheckoutButtonMatchTheBoard(): void
    {
        $x   = $this->page();
        $css = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/pos.css'));

        // Checkout & Print Receipt: text only, no icon, no Bootstrap spacing utilities.
        $button = $x->query('//button[@id="checkoutBtn"]')->item(0);
        $this->assertSame('Checkout & Print Receipt', trim($button->textContent));
        $this->assertSame(0, $x->query('./*', $button)->length, 'No ✓ icon.');
        $this->assertSame('btn btn-success', $button->getAttribute('class'));
        $this->assertStringContainsString(".cart-card #checkoutBtn {\n    --bs-btn-font-size: 14.5px; --bs-btn-font-weight: 600; --bs-btn-border-radius: 10px;\n    display: flex; align-items: center; justify-content: center; width: 100%; height: 44px;\n    margin-top: 16px;", $css);
        $this->assertDoesNotMatchRegularExpression('/#checkoutBtn[^{]*\{[^}]*(?<![-\w])(background|background-color|border-color|color|opacity)\s*:/', $css, 'Colours (also disabled) stay the shared theme ones.');
        $this->assertStringContainsString('.cart-reason { margin: 6px 0 0; font-size: 12px; color: #5C6152; text-align: center; }', $css);

        // Header: no divider under it; the list starts 10px below; the empty message has 26px above and below.
        $this->assertStringContainsString('.cart-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }', $css);
        $this->assertStringContainsString('.cart-empty { text-align: center; color: #7A7E70; padding: 26px 0; }', $css);
        $this->assertStringContainsString('.cart-title { margin: 0; font-size: 15.5px; font-weight: 600;', $css);
        $this->assertStringContainsString('.cart-count { font-size: 12.5px; font-weight: 400; color: #7A7E70;', $css);
        // The dividers above Subtotal and above Total stay.
        $this->assertStringContainsString('.cart-totals { border-top: 1px solid #E3E0D7;', $css);
        $this->assertStringContainsString('.cart-totals .row-line.total { font-size: 16px; font-weight: 600; border-top: 1px solid #E3E0D7;', $css);
    }

    public function testPricingStillAddsUp(): void
    {
        $cart = [
            ['item_id' => 'A', 'item_name' => 'Goa Upper', 'unit_price' => 333.33, 'qty' => 1],
            ['item_id' => 'B', 'item_name' => 'Goa Lower', 'unit_price' => 287.15, 'qty' => 1],
            ['item_id' => 'C', 'item_name' => 'Patch', 'unit_price' => 19.99, 'qty' => 12],
        ];
        $p = PosPricing::priceCart($cart);
        $this->assertGreaterThan(0, $p['discount']);
        $this->assertSame(round($p['subtotal'] - $p['discount'], 2), $p['total'], 'Subtotal − Discount = Total.');
    }

    public function testDisabledButtonsIgnoreHoverAndPress(): void
    {
        // One shared disabled look for every primary button, in every state (admin-theme.css): it beats
        // Bootstrap's press colour (.btn:first-child:active) and hover/focus.
        $theme = str_replace("\r\n", "\n", file_get_contents(FCPATH . 'assets/css/admin-theme.css'));
        $this->assertStringContainsString(":root :is(.btn-success, .inv-action-btn, .lt-submit):is(:disabled, .disabled),\n"
            . ":root :is(.btn-success, .inv-action-btn, .lt-submit):is(:disabled, .disabled):is(:hover, :active, :focus, :focus-visible) {\n"
            . "    background-color: var(--btn-disabled-bg);\n    border-color: var(--btn-disabled-bg);\n    color: var(--btn-disabled-text);\n    cursor: not-allowed;", $theme);
        $this->assertStringContainsString('--btn-disabled-bg: #E8E5DC;', $theme);
        $this->assertStringContainsString('--btn-disabled-text: #8A8D7E;', $theme);

        // Checkout and Save PIN have no colours of their own any more.
        $pos = file_get_contents(FCPATH . 'assets/css/pos.css');
        $pin = file_get_contents(FCPATH . 'assets/css/pin-setup.css');
        $this->assertDoesNotMatchRegularExpression('/#checkoutBtn:disabled/', $pos);
        $this->assertDoesNotMatchRegularExpression('/pin-save[^{]*:disabled/', $pin);
        foreach (['#E6E3DA', '#B9D3AE'] as $colour) {
            $this->assertStringNotContainsString($colour, $pos . $pin, $colour);
        }

        // The page's light secondary buttons keep their own quiet disabled look, which ignores hover and press.
        $this->assertStringContainsString('.pos-clear:disabled, .pos-clear:disabled:is(:hover, :active, :focus) {', $pos);
        $this->assertStringContainsString('.pos-clear:hover:not(:disabled) { background: #FDF1EF; }', $pos);
        $this->assertStringContainsString('.cart-line .qty-ctrl button:disabled, .cart-line .qty-ctrl button:disabled:is(:hover, :active)', $pos);

        // The real disabled attribute, on the server-drawn page.
        $x = $this->page();
        $this->assertTrue($x->query('//button[@id="checkoutBtn"]')->item(0)->hasAttribute('disabled'));
        $this->assertTrue($x->query('//button[@id="clearCart"]')->item(0)->hasAttribute('disabled'));
    }

    public function testNoBoldInTheCard(): void
    {
        $css = file_get_contents(FCPATH . 'assets/css/pos.css');
        $start = strpos($css, '/* ---- The Current order card');
        $end   = strpos($css, '/* Narrow page');
        $this->assertNotFalse($start);
        $this->assertDoesNotMatchRegularExpression('/font-weight:\s*[789]00/', substr($css, $start, $end - $start));
        $this->assertStringNotContainsString('fw-bold', (string) $this->asStaff()->get('staff/sales')->response()->getBody());
    }

    public function testTheBrowserFiltersAmountsLikeTheServerReadsThem(): void
    {
        exec('node -v 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Node.js is not installed.');
        }
        $js = file_get_contents(FCPATH . 'assets/js/pos.js');
        preg_match('/function cleanAmount\(value\) \{.*?\n    \}/s', $js, $fn);
        $this->assertNotEmpty($fn, 'cleanAmount() is in pos.js');

        // [typed, kept] (pairs, not keys: PHP would turn the key '-500' into a number).
        $cases  = [['1,000', '1000'], ['5OO', '5'], ['-500', '500'], ['12.345', '12.34'], ['1.2.3', '1.23'], ['₱ 1,234.5', '1234.5'], ['abc', '']];
        $runner = WRITEPATH . 'clean-amount-check.js';
        file_put_contents($runner, $fn[0] . "\nconst cases = " . json_encode(array_column($cases, 0)) . ";\nprocess.stdout.write(JSON.stringify(cases.map(cleanAmount)));\n");
        try {
            $got = json_decode((string) shell_exec('node ' . escapeshellarg($runner)), true);
        } finally {
            @unlink($runner);
        }
        $this->assertSame(array_column($cases, 1), $got);
        foreach ($got as $clean) {
            $this->assertNotNull(PosCheckout::money($clean), "the server reads '{$clean}'");
        }
    }
}
