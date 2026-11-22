<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Owner's Suppliers page: the 4 summary cards show on both tabs with the same numbers,
 * whatever the tab or search (the supplier list itself still follows both).
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class SupplierSummaryCardsTest extends CIUnitTestCase
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

        // Active: SUP-001 (4 days), SUP-002 (8 days). Archived: SUP-003 (30 days, not in the average).
        $this->db->table('suppliers')->update(['lead_time_days' => 4, 'contact_number' => '09170111111'], ['supplier_id' => 'SUP-001']);
        $this->db->table('suppliers')->update(['lead_time_days' => 8, 'contact_number' => '+63 2 8123 4567'], ['supplier_id' => 'SUP-002']);
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-003', 'company_name' => 'Old Boots Co', 'lead_time_days' => 30, 'is_active' => 0]);

        $this->addSize('P-1', 'Tactical Pants', 'M', 4, 6);
        $this->openOrder('SO-0001', ['P-1'], date('Y-m-d', strtotime('+3 days')));
        $this->openOrder('SO-0002', ['P-1'], date('Y-m-d', strtotime('-2 days')), 'Delivered');
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    /** @return array<string, string> label => number shown on the card */
    private function cards(string $url): array
    {
        $html = (string) $this->asOwner()->get($url)->getBody();
        preg_match_all('#<div class="sup-stat"><span>([^<]+)</span><strong>([^<]+)</strong><small>([^<]+)</small></div>#', $html, $m, PREG_SET_ORDER);
        $this->assertCount(4, $m, 'All 4 summary cards (with their captions) show on ' . $url);

        $cards = [];
        foreach ($m as $row) {
            $cards[$row[1]] = $row[2];
        }

        return $cards;
    }

    public function testTheNumbersAreTheSameOnBothTabsAndWithASearch(): void
    {
        $expected = [
            'Total suppliers'    => '3',
            'Avg. delivery time' => '6 days',
            'Active suppliers'   => '2',
            'Pending orders'     => '1',
        ];

        $this->assertSame($expected, $this->cards('admin/suppliers'));
        $this->assertSame($expected, $this->cards('admin/suppliers?status=archived'));
        $this->assertSame($expected, $this->cards('admin/suppliers?search=nothing-matches-this'));
    }

    public function testTotalCountsAnArchivedSupplier(): void
    {
        $this->assertSame('3', $this->cards('admin/suppliers')['Total suppliers']);

        $this->db->table('suppliers')->update(['is_active' => 0], ['supplier_id' => 'SUP-002']);

        $cards = $this->cards('admin/suppliers');
        $this->assertSame('3', $cards['Total suppliers']);
        $this->assertSame('1', $cards['Active suppliers']);
        $this->assertSame('4 days', $cards['Avg. delivery time']);
    }

    public function testTheListStillFollowsTheTabAndSearch(): void
    {
        $active = (string) $this->asOwner()->get('admin/suppliers')->getBody();
        $this->assertStringContainsString('Tactical Gear Inc', $active);
        $this->assertStringNotContainsString('Old Boots Co', $active);

        $archived = (string) $this->asOwner()->get('admin/suppliers?status=archived')->getBody();
        $this->assertStringContainsString('Old Boots Co', $archived);
        $this->assertStringNotContainsString('Tactical Gear Inc', $archived);
        $this->assertStringContainsString("Archived suppliers are hidden from orders and alerts and can't sign in. Restore one to unlock their account.", $archived);

        $none = (string) $this->asOwner()->get('admin/suppliers?search=nothing-matches-this')->getBody();
        $this->assertStringNotContainsString('Tactical Gear Inc', $none);
    }

    public function testTabsAddButtonAndPhoneDisplay(): void
    {
        $html = (string) $this->asOwner()->get('admin/suppliers?status=archived')->getBody();
        $this->assertStringContainsString('Active (2)</a>', $html);
        $this->assertMatchesRegularExpression('#<i class="bi bi-archive" aria-hidden="true"></i> Archived \(1\)</a>#', $html);
        $this->assertStringContainsString('<i class="bi bi-plus-lg"></i> Add Supplier</a>', $html, 'Add Supplier shows on the Archived tab too');

        $active = (string) $this->asOwner()->get('admin/suppliers')->getBody();
        $this->assertStringContainsString('0917 011 1111', $active);
        $this->assertStringContainsString('+63 2 8123 4567', $active, 'Other formats show as stored');
        $this->assertStringNotContainsString('Portal Active', $active);
    }

    public function testArchivedEmptyState(): void
    {
        $this->db->table('suppliers')->update(['is_active' => 1], ['supplier_id' => 'SUP-003']);

        $html = (string) $this->asOwner()->get('admin/suppliers?status=archived')->getBody();
        $this->assertStringContainsString('Archived (0)</a>', $html);
        $this->assertStringContainsString('<div class="title">No archived suppliers</div>', $html);
        $this->assertStringContainsString('Suppliers you archive will appear here.', $html);
    }

    public function testRealSuppliersComeFirstThenTheSampleOnesAndTheTabCountMatchesTheCards(): void
    {
        // Two sample suppliers whose names sort BEFORE the real ones, plus one archived sample.
        $this->db->table('suppliers')->insert(['supplier_id' => 'SMP-001', 'company_name' => 'Aaa Sample Works', 'lead_time_days' => 3, 'is_active' => 1, 'is_sample' => 1]);
        $this->db->table('suppliers')->insert(['supplier_id' => 'SMP-002', 'company_name' => 'Bbb Sample Mills', 'lead_time_days' => 4, 'is_active' => 1, 'is_sample' => 1]);
        $this->db->table('suppliers')->insert(['supplier_id' => 'SMP-003', 'company_name' => 'Ccc Old Sample', 'lead_time_days' => 4, 'is_active' => 0, 'is_sample' => 1]);

        $html = (string) $this->asOwner()->get('admin/suppliers')->getBody();
        preg_match_all('#<div class="name">([^<]+)#', $html, $m);
        $this->assertSame(['Quiapo Patches Co', 'Tactical Gear Inc', 'Aaa Sample Works', 'Bbb Sample Mills'], $m[1], 'Real suppliers first (by name), then samples.');
        $this->assertStringContainsString('Active (2 + 2 sample)</a>', $html, 'The tab count matches the cards: 2 real + 2 sample.');
        $this->assertStringContainsString('Archived (1 + 1 sample)</a>', $html);
        $this->assertSame(1, substr_count($html, 'id="sampleSuppliers"'), 'One heading starts the sample group.');
        $this->assertLessThan(strpos($html, 'Aaa Sample Works'), strpos($html, 'id="sampleSuppliers"'));
        $this->assertGreaterThan(strpos($html, 'Quiapo Patches Co'), strpos($html, 'id="sampleSuppliers"'));
        $this->assertSame(4, preg_match_all('/<div class="sup-card ?(archived)?">/', $html), 'The number of cards equals 2 + 2.');

        // The summary cards still leave the samples out.
        $this->assertSame('3', $this->cards('admin/suppliers')['Total suppliers'], '2 active + 1 archived real suppliers; no sample counted.');
    }
}
