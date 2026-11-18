<?php

use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Natural size order (ProductModel::sizeKey / compareSizes / sortSizes): no size first, letter
 * sizes, letter/number sets, numbers by value, anything else alphabetically. Every size value in
 * the live database is covered (47 values + NULL, checked 2026-11-18).
 *
 * @internal
 */
final class SizeOrderTest extends CIUnitTestCase
{
    /** Every distinct products.size in the database, in the expected order. */
    private const REAL_ORDER = [
        null,
        'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL',
        'XS/31', 'S/32', 'M/35', 'L/33', 'L/34', 'L/35', 'XL/35', 'XL/37', '2XL/38', '2XL/39',
        '4', '5', '5.5', '6', '6.5', '7', '7.5', '8', '8.5', '9', '9.5', '10', '10.5', '11', '11.5', '12',
        '30', '31', '32', '33', '34', '35', '36', '37', '38', '39', '41', '44', '56', '57',
    ];

    public function testEveryRealValueSortsNaturallyFromAnyStartingOrder(): void
    {
        $this->assertCount(48, self::REAL_ORDER, '47 sizes + NULL');

        // What SQL / plain text sorting gave: "10, 10.5 … 2XL … L, M, S, XL".
        $text = self::REAL_ORDER;
        sort($text, SORT_STRING);
        $this->assertSame(self::REAL_ORDER, ProductModel::sortSizes($text));
        $this->assertSame(self::REAL_ORDER, ProductModel::sortSizes(array_reverse(self::REAL_ORDER)));

        mt_srand(20261118);
        for ($i = 0; $i < 20; $i++) {
            $shuffled = self::REAL_ORDER;
            usort($shuffled, static fn () => mt_rand(-1, 1));
            $this->assertSame(self::REAL_ORDER, ProductModel::sortSizes($shuffled), "shuffle {$i}");
        }
    }

    public function testNoSizeComesFirst(): void
    {
        $this->assertSame([null, '', '-', '—', 'XS'], ProductModel::sortSizes(['XS', '—', null, '-', '']));
        $this->assertSame(ProductModel::SIZE_NONE, ProductModel::sizeKey(null)[0]);
        $this->assertSame(ProductModel::SIZE_NONE, ProductModel::sizeKey('  ')[0]);
    }

    public function testLetterSizesIncludingXxsAndBigSizes(): void
    {
        $this->assertSame(
            ['XXS', 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'],
            ProductModel::sortSizes(['5XL', 'M', '3XL', 'XXS', 'L', '4XL', 'XS', '2XL', 'S', 'XL'])
        );
    }

    public function testTwoXlEqualsXxlAndTiesBreakByText(): void
    {
        $this->assertSame(0, ProductModel::compareSizes('2XL', '2XL'));
        $this->assertSame(array_slice(ProductModel::sizeKey('2XL'), 0, 4), array_slice(ProductModel::sizeKey('XXL'), 0, 4), 'same rank');
        $this->assertSame(array_slice(ProductModel::sizeKey('3XL'), 0, 4), array_slice(ProductModel::sizeKey('XXXL'), 0, 4), 'same rank');
        // Equal ranks: the trimmed text decides, case-insensitive, so the order never varies.
        $this->assertSame(['2XL', 'XXL'], ProductModel::sortSizes(['XXL', '2XL']));
        $this->assertSame(['2XL', 'XXL'], ProductModel::sortSizes(['2XL', 'XXL']));
        $this->assertSame(['XL', 'XXL', '3XL', 'XXXL'], ProductModel::sortSizes(['XXXL', '3XL', 'XXL', 'XL']));
    }

    public function testSetsAcceptSlashOrPlusAndComeRightAfterLetters(): void
    {
        $this->assertSame(ProductModel::SIZE_SET, ProductModel::sizeKey('L/33')[0]);
        $this->assertSame(ProductModel::SIZE_SET, ProductModel::sizeKey('L+33')[0]);
        $this->assertSame(array_slice(ProductModel::sizeKey('L/33'), 0, 4), array_slice(ProductModel::sizeKey('L+33'), 0, 4), 'the same set');
        $this->assertSame(['L+33', 'L/33'], ProductModel::sortSizes(['L/33', 'L+33']), 'tie broken by text');
        $this->assertSame(['3XL', 'XS/31', 'S+32', 'L/33', 'L+34', '4'], ProductModel::sortSizes(['4', 'L+34', 'S+32', '3XL', 'L/33', 'XS/31']));
    }

    public function testNumbersByValueIncludingHalves(): void
    {
        $this->assertSame(['5', '5.5', '6', '9.5', '10', '10.5', '30', '44', '56'], ProductModel::sortSizes(['10', '56', '5.5', '30', '10.5', '6', '44', '9.5', '5']));
    }

    public function testTrimmedCaseInsensitiveAndOthersLast(): void
    {
        $this->assertSame([' s ', 'm', 'L ', 'xl', '8', 'free size', 'One Size'], ProductModel::sortSizes(['One Size', '8', 'xl', 'free size', 'L ', 'm', ' s ']));
        $this->assertSame(ProductModel::SIZE_OTHER, ProductModel::sizeKey('One Size')[0]);
        $this->assertSame(ProductModel::SIZE_OTHER, ProductModel::sizeKey('XL/abc')[0], 'not a set');
    }

    public function testRowsSortByNameThenSize(): void
    {
        $rows = [
            ['item_name' => 'Shirt', 'size' => 'XL'], ['item_name' => 'Boots', 'size' => '10'],
            ['item_name' => 'Shirt', 'size' => 'S'],  ['item_name' => 'Boots', 'size' => '5.5'],
            ['item_name' => 'Shirt', 'size' => '2XL'], ['item_name' => 'Cap', 'size' => null],
        ];
        usort($rows, [ProductModel::class, 'compareNameThenSize']);

        $this->assertSame(['Boots 5.5', 'Boots 10', 'Cap ', 'Shirt S', 'Shirt XL', 'Shirt 2XL'], array_map(static fn ($r) => $r['item_name'] . ' ' . $r['size'], $rows));
    }
}
