<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * Sizes show in natural order (ProductModel::sizeKey) on every list: the Inventory size picker
 * (owner and staff), the POS size picker, the Inventory "All sizes" filter, Reorder Alerts (To
 * order list and the sizes inside an order card) and the Procurement Report. The rows are
 * inserted in a scrambled order, so plain text or insertion order would fail.
 *
 * @internal
 */
final class SizeOrderPagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private string $originalPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPrefix = $this->db->DBPrefix;
        $this->db->setPrefix('');
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $when = ['type' => 'DATETIME', 'null' => true];
        $make = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $make('sales_transaction', ['sales_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => $when, 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real] + \Tests\Support\SalesVoidColumns::FIELDS, 'sales_id');
        $make('sales_item', ['sales_item_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real, 'is_bulk' => ['type' => 'INTEGER', 'default' => 0]], 'sales_item_id');
        $make('inventory_log', ['log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => $when], 'log_id');
        $make('app_settings', ['key' => $str, 'value' => $str], 'key');

        // Scrambled on purpose (text order would be 2XL, M, S, XL / 10, 5.5, 9 / L/33, S/32, XS/31).
        // Shirt: every size at Reorder Now with the same urgency, so only the size decides its order.
        foreach (['XL', 'S', '2XL', 'M'] as $size) {
            $this->addSize('SH-' . $size, 'Shirt', $size, 1, 3, ['selling_price' => 300]);
            $this->alertFor('SH-' . $size);
        }
        foreach (['10', '5.5', '9'] as $size) {
            $this->addSize('BO-' . $size, 'Boots', $size, 20, 3, ['category' => 'Shoes', 'selling_price' => 900]);
        }
        foreach (['L/33', 'XS/31', 'S/32'] as $size) {
            $this->addSize('UN-' . str_replace('/', '-', $size), 'Uniform Set', $size, 20, 3, ['selling_price' => 1200]);
        }
        // Cap: both sizes on one open order (Reorder Alerts' Ordered tab shows them in one card).
        foreach (['57', '56'] as $size) {
            $this->addSize('CP-' . $size, 'Cap', $size, 1, 3, ['category' => 'Accessories', 'selling_price' => 150]);
            $this->alertFor('CP-' . $size);
        }
        $this->openOrder('SO-1', ['CP-57', 'CP-56'], '2026-12-01');
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        $this->db->setPrefix($this->originalPrefix);
        parent::tearDown();
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new DOMXPath($dom);
    }

    /** @return list<list<string>> each Inventory row's size picker, in order */
    private function inventoryPickers(string $html): array
    {
        $x   = $this->dom($html);
        $out = [];
        foreach ($x->query('//select[contains(@class,"inv-size-select")]') as $select) {
            $out[] = array_map(static fn ($o) => $o->getAttribute('data-size'), iterator_to_array($x->query('./option', $select)));
        }

        return $out;
    }

    public function testTheInventorySizePickerIsInNaturalOrderForOwnerAndStaff(): void
    {
        $pages = [
            'owner' => (string) $this->asOwner()->get('admin/inventory')->response()->getBody(),
            'staff' => (string) $this->asStaff()->get('staff/inventory')->response()->getBody(),
        ];
        foreach ($pages as $who => $html) {
            $pickers = $this->inventoryPickers($html);
            $this->assertContains(['5.5', '9', '10'], $pickers, $who);
            $this->assertContains(['56', '57'], $pickers, $who);
            $this->assertContains(['S', 'M', 'XL', '2XL'], $pickers, $who);
            $this->assertContains(['XS/31', 'S/32', 'L/33'], $pickers, $who);
        }
    }

    public function testThePosSizePickerIsInNaturalOrderForOwnerAndStaff(): void
    {
        $pages = [
            'owner' => (string) $this->asOwner()->get('admin/sales?tab=pos')->response()->getBody(),
            'staff' => (string) $this->asStaff()->get('staff/sales?tab=pos')->response()->getBody(),
        ];
        foreach ($pages as $who => $html) {
            $x     = $this->dom($html);
            $cards = [];
            foreach ($x->query('//div[contains(@class,"pos-card")][@data-variants]') as $card) {
                $cards[$card->getAttribute('data-item-name')] = array_column(json_decode($card->getAttribute('data-variants'), true), 'size');
            }
            $this->assertSame(['S', 'M', 'XL', '2XL'], $cards['Shirt'] ?? null, $who);
            $this->assertSame(['5.5', '9', '10'], $cards['Boots'] ?? null, $who);
            $this->assertSame(['XS/31', 'S/32', 'L/33'], $cards['Uniform Set'] ?? null, $who);
            $this->assertSame(['56', '57'], $cards['Cap'] ?? null, $who);
        }
    }

    public function testPosCardsKeepTheirPreviousOrder(): void
    {
        // Same name in two categories: the card whose first row comes first in the old SQL order
        // (name, then size as text: "10" before "57") is first, even with the higher item_id.
        $this->addSize('A-1', 'Belt', '57', 20, 3, ['category' => 'Accessories', 'selling_price' => 100]);
        $this->addSize('Z-9', 'Belt', '10', 20, 3, ['category' => 'Clothes', 'selling_price' => 100]);

        $expected = ['Belt|Clothes', 'Belt|Accessories', 'Boots|Shoes', 'Cap|Accessories', 'Shirt|Clothes', 'Uniform Set|Clothes'];
        foreach (['owner' => $this->asOwner()->get('admin/sales?tab=pos'), 'staff' => $this->asStaff()->get('staff/sales?tab=pos')] as $who => $result) {
            $x     = $this->dom((string) $result->response()->getBody());
            $cards = array_map(static fn ($c) => $c->getAttribute('data-item-name') . '|' . $c->getAttribute('data-cat'), iterator_to_array($x->query('//div[contains(@class,"pos-card")][@data-variants]')));
            $this->assertSame($expected, $cards, "{$who}: alphabetical by name, as before");
        }
    }

    public function testTheAllSizesFilterGroupsSetsUnderClothingPlusWaist(): void
    {
        $x      = $this->dom((string) $this->asOwner()->get('admin/inventory')->response()->getBody());
        $groups = [];
        foreach ($x->query('//select[@id="sizeFilter"]/optgroup') as $group) {
            $groups[$group->getAttribute('label')] = array_map(static fn ($o) => $o->getAttribute('value'), iterator_to_array($x->query('./option', $group)));
        }

        $this->assertSame(['S', 'M', 'XL', '2XL'], $groups['Clothing'] ?? null);
        $this->assertSame(['XS/31', 'S/32', 'L/33'], $groups['Clothing + waist'] ?? null, 'the "/" sets, not under Other');
        $this->assertSame(['5.5', '9', '10'], $groups['Shoes'] ?? null);
        $this->assertSame(['56', '57'], $groups['Other'] ?? null);
    }

    public function testReorderAlertsListsSizesInNaturalOrder(): void
    {
        // To order: same urgency for every Shirt size, so the size breaks the tie.
        $x   = $this->dom((string) $this->asOwner()->get('admin/reorder-alerts')->response()->getBody());
        $ids = array_map(static fn ($r) => $r->getAttribute('data-item-id'), iterator_to_array($x->query('//*[@data-ra-row][@data-tab="order"]')));
        $this->assertSame(['SH-S', 'SH-M', 'SH-XL', 'SH-2XL'], array_values(array_filter($ids, static fn ($id) => str_starts_with($id, 'SH-'))));

        // Ordered: the sizes inside one order card.
        $x     = $this->dom((string) $this->asOwner()->get('admin/reorder-alerts?tab=ordered')->response()->getBody());
        $sizes = array_map(static fn ($d) => trim($d->textContent), iterator_to_array($x->query('//div[contains(@class,"ra-ordered")]//div[contains(@class,"ra-size-row")]/div[contains(@class,"ra-c-size")]')));
        $this->assertSame(['56', '57'], $sizes);
    }

    public function testTheProcurementReportListsSizesInNaturalOrder(): void
    {
        // By name, then size (the PDF and CSV use the same rows: ProcurementReport::rows()).
        $expected = ['BO-5.5', 'BO-9', 'BO-10', 'CP-56', 'CP-57', 'SH-S', 'SH-M', 'SH-XL', 'SH-2XL', 'UN-XS-31', 'UN-S-32', 'UN-L-33'];
        $ids      = fn (string $url): array => array_map(static fn ($r) => $r->getAttribute('data-item-id'), iterator_to_array($this->dom((string) $this->asOwner()->get($url)->response()->getBody())->query('//tr[contains(@class,"pr-row")]')));

        $this->assertSame($expected, $ids('admin/procurement-report?per_page=25'));
        // Sorted before the page is cut: page 2 of 10 continues the same order.
        $this->assertSame(array_slice($expected, 0, 10), $ids('admin/procurement-report'));
        $this->assertSame(array_slice($expected, 10), $ids('admin/procurement-report?page=2'));
    }
}
