<?php

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * UT-18: stock status around the reorder point.
 *
 * @internal
 */
final class ProductStatusTest extends CIUnitTestCase
{
    private function labelFor(int $stock, int $rop): string
    {
        return (new ProductModel())->getStatus(['current_stock' => $stock, 'manual_rop_warning' => $rop])['label'];
    }

    public function testAtOrBelowReorderPointIsReorderNow(): void
    {
        $this->assertSame('Reorder Now', $this->labelFor(158, 158));
        $this->assertSame('Reorder Now', $this->labelFor(0, 158));
    }

    public function testJustAboveReorderPointIsLowStock(): void
    {
        $this->assertSame('Low Stock', $this->labelFor(159, 158));
    }

    /**
     * UT-18: the Low Stock band is ROP + max(2, 50% of ROP) (ProductModel::LOW_STOCK_PCT and
     * LOW_STOCK_MIN_UNITS; the browser reads the same numbers). ROP 158 -> 158 + 79 = 237 is
     * still Low Stock, 238 is In Stock. Replaced the old 120% band (f284608, "new stock rules").
     */
    public function testLowStockBandIsFiftyPercentOfRopWithAtLeastTwoUnits(): void
    {
        $this->assertSame(237, ProductModel::lowStockLimit(158));
        $this->assertSame('Low Stock', $this->labelFor(237, 158));
        $this->assertSame('In Stock', $this->labelFor(238, 158));

        // A small ROP still gets a band of at least 2 units.
        $this->assertSame('Low Stock', $this->labelFor(6, 4));
        $this->assertSame('In Stock', $this->labelFor(7, 4));
    }

    /** No reorder point means nothing to be near: In Stock with units on hand, Out of Stock (gray) at zero. */
    public function testNoRopMeansInStockUnlessEmptyWhichIsOutOfStock(): void
    {
        $this->assertSame('In Stock', $this->labelFor(5, 0));
        $this->assertSame('Out of Stock', $this->labelFor(0, 0));
    }
}
