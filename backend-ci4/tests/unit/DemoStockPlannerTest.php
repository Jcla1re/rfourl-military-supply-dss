<?php

use App\Libraries\DemoStockPlanner;
use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The demo opening stock: every product lands in the status it was planned for, judged by
 * the app's own ProductModel::getStatus(), and the same input always gives the same numbers.
 *
 * @internal
 */
final class DemoStockPlannerTest extends CIUnitTestCase
{
    /** 200 products: 120 with demand (ROP 3..62, a quarter of them Class A), 80 without. */
    private function items(): array
    {
        $items = [];
        for ($i = 1; $i <= 200; $i++) {
            $withDemand = $i <= 120;
            $items[]    = [
                'item_id' => sprintf('%04d', $i),
                'rop'     => $withDemand ? 3 + ($i * 7) % 60 : 0,
                'eoq'     => $withDemand ? 10 + ($i * 3) % 40 : 10,
                'abc'     => $withDemand && $i % 4 === 0 ? 'A' : ($withDemand ? 'B' : 'C'),
            ];
        }

        return $items;
    }

    public function testTargetsAddUpAndFollowTheMix(): void
    {
        $planner = new DemoStockPlanner();

        $this->assertSame(['In Stock' => 668, 'Low Stock' => 133, 'Reorder Now' => 89, 'Out of Stock' => 0], $planner->targets(890));
        $this->assertSame(890, array_sum($planner->targets(890)));
        $this->assertSame(100, array_sum($planner->targets(100)));
    }

    public function testEveryProductIsInItsPlannedStatusAccordingToTheApp(): void
    {
        $plan  = (new DemoStockPlanner())->plan($this->items());
        $model = new ProductModel();

        foreach ($this->items() as $it) {
            $label = $model->getStatus(['current_stock' => $plan['stock'][$it['item_id']], 'manual_rop_warning' => $it['rop']])['label'];
            $this->assertSame($plan['status'][$it['item_id']], $label, "item {$it['item_id']}");
        }
        $this->assertSame(200, array_sum($plan['counts']));
        $this->assertSame($plan['targets']['Reorder Now'], $plan['counts']['Reorder Now']);
        $this->assertSame($plan['targets']['Low Stock'], $plan['counts']['Low Stock']);
        $this->assertSame($plan['targets']['Out of Stock'], $plan['counts']['Out of Stock']);
    }

    public function testNoProductStartsWithoutStock(): void
    {
        $plan = (new DemoStockPlanner())->plan($this->items());

        $this->assertSame(0, $plan['counts']['Out of Stock']);
        $this->assertSame([], array_keys(array_filter($plan['stock'], static fn ($q) => $q < 1)));
        // the products with no reorder point (no demand) get 2-10 and are In Stock
        foreach ($this->items() as $it) {
            if ($it['rop'] === 0) {
                $this->assertGreaterThanOrEqual(2, $plan['stock'][$it['item_id']]);
                $this->assertLessThanOrEqual(10, $plan['stock'][$it['item_id']]);
                $this->assertSame('In Stock', $plan['status'][$it['item_id']]);
            }
        }
    }

    public function testQuantityRules(): void
    {
        $plan  = (new DemoStockPlanner())->plan($this->items());
        $byId  = array_column($this->items(), null, 'item_id');

        foreach ($plan['stock'] as $id => $qty) {
            $rop    = $byId[$id]['rop'];
            $status = $plan['status'][$id];

            if ($status === 'Reorder Now') {
                $this->assertGreaterThanOrEqual(1, $qty);
                $this->assertLessThanOrEqual($rop, $qty);
            } elseif ($status === 'Low Stock') {
                $this->assertGreaterThan($rop, $qty);
                $this->assertLessThanOrEqual(ProductModel::lowStockLimit($rop), $qty);
            } elseif ($status === 'Out of Stock') {
                $this->assertSame(0, $qty);
                $this->assertSame(0, $rop, 'only an item with no reorder point can be Out of Stock');
            } elseif ($rop === 0) {
                $this->assertGreaterThanOrEqual(2, $qty);
                $this->assertLessThanOrEqual(10, $qty);
            } else {
                $this->assertGreaterThanOrEqual(3, $qty);
                // band + 1 up to band + EOQ
                $this->assertGreaterThan(ProductModel::lowStockLimit($rop), $qty);
                $this->assertLessThanOrEqual(max(3, ProductModel::lowStockLimit($rop) + $byId[$id]['eoq']), $qty);
            }
        }
    }

    public function testClassAGetsAtLeastEightReorderNowAndEightLowStock(): void
    {
        $plan = (new DemoStockPlanner())->plan($this->items());

        $this->assertGreaterThanOrEqual(8, count($plan['classA']['reorder_now']));
        $this->assertGreaterThanOrEqual(8, count($plan['classA']['low_stock']));
    }

    public function testTheSameInputAlwaysGivesTheSameStockAndAnotherSeedDoesNot(): void
    {
        $a = (new DemoStockPlanner(2026))->plan($this->items());
        $b = (new DemoStockPlanner(2026))->plan(array_reverse($this->items())); // order of arrival does not matter
        $c = (new DemoStockPlanner(7))->plan($this->items());

        $this->assertSame($a['stock'], $b['stock']);
        $this->assertNotSame($a['stock'], $c['stock']);
    }

    public function testTooFewProductsWithDemandScalesTheTargetsDownAndSaysSo(): void
    {
        $items = [];
        for ($i = 1; $i <= 100; $i++) {
            $items[] = ['item_id' => sprintf('%04d', $i), 'rop' => $i <= 10 ? 5 : 0, 'eoq' => 10, 'abc' => $i <= 3 ? 'A' : 'C'];
        }

        $plan = (new DemoStockPlanner())->plan($items);

        $this->assertSame(10, $plan['counts']['Reorder Now'] + $plan['counts']['Low Stock']);
        $this->assertNotEmpty($plan['notes']);
    }
}
