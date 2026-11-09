<?php

use App\Libraries\PosIcon;
use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * The Point of Sale tab (partials/_pos_tab.php), the same for the Owner and Staff: the header
 * without a date, the toolbar, each card's search data (name + item IDs), ID/size line and
 * category icon, and the server refusing a quantity above stock at checkout (nothing is sold,
 * stock is untouched). Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class PosPageTest extends CIUnitTestCase
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

        // Checkout's tables, to prove nothing is written when it refuses.
        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log'] as $t) {
            $forge->dropTable($t, true);
            $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'note' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true]]);
            $forge->addKey('id', true);
            $forge->createTable($t);
        }

        $this->addSize('0290', 'Tactical Pants', 'S', 5, 2, ['selling_price' => 350]);
        $this->addSize('0291', 'Tactical Pants', 'M', 9, 2, ['selling_price' => 400]);
        $this->addSize('0292', 'Tactical Pants', 'L', 3, 2, ['selling_price' => 400]);
        $this->addSize('0089', 'Combat Boots', 'S', 4, 1, ['category' => 'Shoes', 'selling_price' => 1800]);
        $this->addSize('0664', 'Double Magpouch', null, 12, 3, ['category' => 'Equipment', 'selling_price' => 650]);
        $this->addSize('0700', 'Brass Medal', null, 0, 0, ['category' => 'Metal', 'selling_price' => 120]);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log'] as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function page(string $role): string
    {
        return (string) ($role === 'admin' ? $this->asOwner() : $this->asStaff())->get($role . '/sales')->getBody();
    }

    /** The card whose data-item-name is $name. */
    private function card(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('#<div class="pos-card[^"]*"\s+data-cat="[^"]*"\s+data-name="[^"]*"\s+data-ids="[^"]*"\s+data-item-name="' . preg_quote($name, '#') . '".*?<div class="price">.*?</div>#s', $html, $m), "Card for {$name}.");

        return $m[0];
    }

    public static function roles(): array
    {
        return ['Owner' => ['admin'], 'Staff' => ['staff']];
    }

    /** @dataProvider roles */
    public function testTheToolbarAndHeader(string $role): void
    {
        $html = $this->page($role);

        $this->assertStringNotContainsString('bi-calendar3', $html, 'No date in the header.');
        $this->assertStringNotContainsString(date('l, F j Y'), $html);
        $this->assertStringContainsString('<link href="/assets/css/pos.css" rel="stylesheet">', $html);
        $this->assertStringContainsString('<script src="/assets/js/pos.js" defer></script>', $html);
        $this->assertStringContainsString('placeholder="Search by item name or ID&hellip;"', $html);
        $this->assertStringContainsString('<div class="pos-count" id="posCount" aria-live="polite">4 items</div>', $html);
        $this->assertStringContainsString('<div class="page-wrap pos-page">', $html);
        $this->assertStringContainsString('<button type="button" class="pos-clear" id="clearCart" disabled>Clear</button>', $html);
        $this->assertStringContainsString('action="' . site_url($role . '/sales/checkout') . '" id="cartForm"', $html, 'The same checkout form.');
        // Each category chip carries its icon; "All Items" has none.
        $this->assertStringContainsString('<button type="button" class="active" data-cat="" aria-pressed="true">All Items</button>', $html);
        // (The page's HTML is re-serialized on output: viewBox is written lower-case, which
        // browsers read as viewBox inside SVG, and <path/> gets a closing tag.)
        foreach (ProductModel::CATEGORIES as $c) {
            $this->assertMatchesRegularExpression('#<button type="button" data-cat="' . $c . '" aria-pressed="false"><svg class="pos-icon" width="16" height="16"[^>]*><path d="' . preg_quote(PosIcon::PATHS[$c], '#') . '"></path></svg>' . $c . '</button>#', $html);
        }
    }

    /** @dataProvider roles */
    public function testEachCardCarriesItsIdsForSearchAndAnIdOrSizesLine(string $role): void
    {
        $html = $this->page($role);

        $pants = $this->card($html, 'Tactical Pants');
        preg_match('/data-ids="([^"]*)"/', $pants, $ids);
        $this->assertEqualsCanonicalizing(['0290', '0291', '0292'], explode(' ', $ids[1]), "Every size's ID, so a search by ID finds the card.");
        $this->assertStringContainsString('<div class="sub">3 sizes</div>', $pants);
        $this->assertStringContainsString('<span class="pos-card-icon"><svg class="pos-icon"', $pants);
        $this->assertStringContainsString('<path d="' . PosIcon::PATHS['Clothes'] . '"></path>', $pants);
        $this->assertStringContainsString('<span class="stock green">17 in stock</span>', $pants);
        $this->assertMatchesRegularExpression('/(₱|&#8369;)350(–|&ndash;|&#8211;)400\s*<\/div>$/', $pants, 'Price ranges as before.');

        $boots = $this->card($html, 'Combat Boots');
        $this->assertStringContainsString('data-ids="0089"', $boots);
        $this->assertMatchesRegularExpression('/<div class="sub">0089 (·|&middot;) Size S<\/div>/', $boots);
        $this->assertStringContainsString('<path d="' . PosIcon::PATHS['Shoes'] . '"></path>', $boots);

        $pouch = $this->card($html, 'Double Magpouch');
        $this->assertStringContainsString('<div class="sub">0664</div>', $pouch, 'No size: the ID only.');

        $medal = $this->card($html, 'Brass Medal');
        $this->assertStringContainsString('<div class="pos-card disabled"', $medal);
        $this->assertStringContainsString('<span class="stock out">Out of stock</span>', $medal);
        $this->assertStringNotContainsString('bi-box-seam"></i></div>', $html, 'No box icon on the cards.');
    }

    public function testTheIconHelper(): void
    {
        foreach (ProductModel::CATEGORIES as $c) {
            $svg = PosIcon::svg($c, 16);
            $this->assertStringContainsString('width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"', $svg, $c);
            $this->assertStringContainsString('<path d="' . PosIcon::PATHS[$c] . '"/>', $svg);
        }
        $this->assertSame('', PosIcon::svg('Unknown'));
        $this->assertSame('', PosIcon::svg(null));
    }

    /** @dataProvider roles */
    public function testCheckoutRefusesAQuantityAboveStock(string $role): void
    {
        $session = $role === 'admin' ? $this->asOwner() : $this->asStaff();
        $response = $session->post($role . '/sales/checkout', [
            'item_id' => ['0290'], 'quantity' => ['6'], 'payment_method' => 'Cash', 'cash_received' => '5000',
        ]);

        $this->assertSame(site_url($role . '/sales'), $response->getRedirectUrl());
        $this->assertSame('Not enough stock for Tactical Pants.', $_SESSION['error'] ?? null);
        $this->assertSame(5, (int) $this->db->table('products')->where('item_id', '0290')->get()->getRow()->current_stock, 'Stock untouched.');
        foreach (['sales_transaction', 'sales_item', 'inventory_log'] as $t) {
            $this->assertSame(0, $this->db->table($t)->countAllResults(), "Nothing written to {$t}.");
        }
    }
}
