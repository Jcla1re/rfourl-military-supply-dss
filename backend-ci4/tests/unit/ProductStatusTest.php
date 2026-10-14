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
     * UT-18: ROP 158 -> 189 is still Low Stock, 190 is In Stock (the band is
     * 120% of ROP, ProductModel::LOW_STOCK_BAND; ST-07 uses the same figure).
     */
    public function testLowStockBandIsOneHundredTwentyPercentOfRop(): void
    {
        $this->assertSame('Low Stock', $this->labelFor(189, 158));
        $this->assertSame('In Stock', $this->labelFor(190, 158));
    }

    public function testNoRopMeansInStockUnlessEmpty(): void
    {
        $this->assertSame('In Stock', $this->labelFor(5, 0));
        $this->assertSame('Reorder Now', $this->labelFor(0, 0));
    }
}
