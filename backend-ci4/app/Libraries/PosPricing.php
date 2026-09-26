<?php

namespace App\Libraries;

/**
 * Shared POS cart pricing: bulk-quantity discount, matching-"set" discount,
 * and VAT-inclusive breakdown. Used identically by both the admin and
 * staff SalesController so their checkout math can never drift apart, and
 * computed server-side (not trusted from the client) since it directly
 * affects money.
 */
class PosPricing
{
    public const BULK_QTY_THRESHOLD = 10;
    public const BULK_DISCOUNT_RATE = 0.05;
    public const SET_DISCOUNT_RATE  = 0.02;
    public const VAT_RATE           = 0.12;

    private const UPPER_WORDS = ['upper', 'top', 'shirt', 'blouse', 'jacket', 'polo'];
    private const LOWER_WORDS = ['lower', 'bottom', 'pants', 'trousers', 'shorts', 'skirt'];

    /**
     * @param array<int, array{item_id:string, item_name:string, unit_price:float, qty:int}> $lines
     * @return array{
     *     lines: array<int, array{item_id:string, item_name:string, unit_price:float, qty:int, line_subtotal:float, discount:float, badge:?string, line_total:float}>,
     *     subtotal: float, discount: float, total: float, vatable: float, vat: float
     * }
     */
    public static function priceCart(array $lines): array
    {
        $setIndexes = self::detectSetIndexes($lines);

        $subtotal = 0.0;
        $discount = 0.0;
        $pricedLines = [];

        foreach ($lines as $i => $line) {
            $lineSubtotal = round($line['unit_price'] * $line['qty'], 2);
            $subtotal += $lineSubtotal;

            $lineDiscount = 0.0;
            $badge = null;

            if ($line['qty'] >= self::BULK_QTY_THRESHOLD) {
                $lineDiscount = round($lineSubtotal * self::BULK_DISCOUNT_RATE, 2);
                $badge = 'Bundle -5%';
            } elseif (isset($setIndexes[$i])) {
                $lineDiscount = round($lineSubtotal * self::SET_DISCOUNT_RATE, 2);
                $badge = 'Set -2%';
            }

            $discount += $lineDiscount;

            // The set discount is conceptually applied to the combined total
            // of the matched pair, not to either product individually, so
            // each line still shows its own full price; only the aggregate
            // discount/total below reflect the 2% taken off. The bulk
            // discount, by contrast, belongs to a single line and is shown
            // subtracted from that line directly.
            $pricedLines[] = $line + [
                'line_subtotal' => $lineSubtotal,
                'discount'      => $lineDiscount,
                'badge'         => $badge,
                'line_total'    => $badge === 'Set -2%' ? $lineSubtotal : round($lineSubtotal - $lineDiscount, 2),
            ];
        }

        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);
        $total    = max(0, round($subtotal - $discount, 2));

        // Prices are VAT-inclusive: back out the VAT portion of the final total.
        $vat     = round($total - ($total / (1 + self::VAT_RATE)), 2);
        $vatable = round($total - $vat, 2);

        return [
            'lines'    => $pricedLines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total'    => $total,
            'vatable'  => $vatable,
            'vat'      => $vat,
        ];
    }

    /**
     * Finds cart line indexes that form a matching "set" — two different
     * items whose names are identical once an Upper/Top or Lower/Bottom
     * marker word is stripped out (e.g. "Goa Upper" + "Goa Lower"), with
     * at least one of each half present.
     *
     * @param array<int, array{item_name:string}> $lines
     * @return array<int, true> line indexes eligible for the set discount
     */
    private static function detectSetIndexes(array $lines): array
    {
        $groups = [];
        foreach ($lines as $i => $line) {
            [$base, $marker] = self::splitSetName($line['item_name']);
            if ($marker !== null && $base !== '') {
                $groups[$base][$marker][] = $i;
            }
        }

        $setIndexes = [];
        foreach ($groups as $markers) {
            if (! empty($markers['upper']) && ! empty($markers['lower'])) {
                foreach (array_merge($markers['upper'], $markers['lower']) as $i) {
                    $setIndexes[$i] = true;
                }
            }
        }

        return $setIndexes;
    }

    /**
     * @return array{0: string, 1: ?string} [base name with the marker word removed, 'upper'|'lower'|null]
     */
    private static function splitSetName(string $name): array
    {
        $words  = preg_split('/\s+/', trim($name)) ?: [];
        $marker = null;
        $baseWords = [];

        foreach ($words as $word) {
            $lower = strtolower($word);
            if ($marker === null && in_array($lower, self::UPPER_WORDS, true)) {
                $marker = 'upper';
                continue;
            }
            if ($marker === null && in_array($lower, self::LOWER_WORDS, true)) {
                $marker = 'lower';
                continue;
            }
            $baseWords[] = $lower;
        }

        return [implode(' ', $baseWords), $marker];
    }
}
