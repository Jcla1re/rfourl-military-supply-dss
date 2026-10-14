<?php

use App\Libraries\PosPricing;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Point of Sale totals: UT-26 (subtotal/total), plus the bulk and set discounts.
 *
 * @internal
 */
final class PosPricingTest extends CIUnitTestCase
{
    private function line(string $id, string $name, float $price, int $qty): array
    {
        return ['item_id' => $id, 'item_name' => $name, 'unit_price' => $price, 'qty' => $qty];
    }

    /** UT-26 */
    public function testSubtotalAndTotalWithNoDiscount(): void
    {
        $cart = PosPricing::priceCart([
            $this->line('A', 'Uniform Set M', 780, 1),
            $this->line('B', 'Accessories', 150, 2),
        ]);

        $this->assertSame(1080.0, $cart['subtotal']);
        $this->assertSame(0.0, $cart['discount']);
        $this->assertSame(1080.0, $cart['total']);
    }

    public function testBulkDiscountStartsAtTenUnits(): void
    {
        $nine = PosPricing::priceCart([$this->line('A', 'Cap', 100, 9)]);
        $ten  = PosPricing::priceCart([$this->line('A', 'Cap', 100, 10)]);

        $this->assertSame(0.0, $nine['discount']);
        $this->assertSame(50.0, $ten['discount']);
        $this->assertSame(950.0, $ten['total']);
    }

    public function testMatchingUpperAndLowerGetTwoPercentSetDiscount(): void
    {
        $cart = PosPricing::priceCart([
            $this->line('A', 'Goa Upper', 500, 1),
            $this->line('B', 'Goa Lower', 500, 1),
        ]);

        $this->assertSame(1000.0, $cart['subtotal']);
        $this->assertSame(20.0, $cart['discount']);
        $this->assertSame(980.0, $cart['total']);
    }

    public function testUpperWithoutMatchingLowerGetsNoDiscount(): void
    {
        $cart = PosPricing::priceCart([
            $this->line('A', 'Goa Upper', 500, 1),
            $this->line('B', 'Different Lower', 500, 1),
        ]);

        $this->assertSame(0.0, $cart['discount']);
    }

    public function testEmptyCartIsZero(): void
    {
        $this->assertEquals(0, PosPricing::priceCart([])['total']);
    }
}
