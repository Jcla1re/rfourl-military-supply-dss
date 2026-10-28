<?php

namespace App\Libraries;

use App\Models\ProductModel;

/**
 * Works out a demo opening stock for every product so the system starts with a
 * realistic mix of stock statuses (used by `php spark dss:seed-dataset`).
 *
 * The statuses are the app's own (ProductModel::getStatus and its Low Stock band,
 * ProductModel::lowStockLimit), never thresholds of our own: each planned quantity is
 * checked against getStatus() before it is returned.
 *
 * Target mix, by product count: 70% In Stock, 15% Low Stock, 10% Reorder Now, 5% Out of Stock.
 *
 *   In Stock      from the first unit above the Low Stock band up to band + EOQ (at least 3)
 *   Low Stock     inside the Low Stock band just above ROP
 *   Reorder Now   1 .. ROP
 *   Out of Stock  0  (the app only calls an item Out of Stock when it has no ROP)
 *   no ROP        2 .. 10, In Stock (no recent demand)
 *   Class A       at least 8 Reorder Now and 8 Low Stock, so the demo has meaningful alerts
 *
 * Every choice comes from a hash of (seed, step, item_id), so the same items and the same
 * seed always give the same numbers, whatever the order the items arrive in.
 */
final class DemoStockPlanner
{
    public const SEED = 2026;

    /** Target share of products per status. */
    public const MIX = ['In Stock' => 0.70, 'Low Stock' => 0.15, 'Reorder Now' => 0.10, 'Out of Stock' => 0.05];

    /** Class A items put in each of Reorder Now and Low Stock. */
    public const CLASS_A_MIN = 8;

    public function __construct(private int $seed = self::SEED)
    {
    }

    /**
     * @param list<array{item_id: string, rop: int, eoq: int, abc: ?string}> $items rop = reorder point (0 = no demand signal)
     *
     * @return array{
     *     stock: array<string,int>,
     *     status: array<string,string>,
     *     targets: array<string,int>,
     *     counts: array<string,int>,
     *     classA: array{total: int, with_rop: int, reorder_now: list<string>, low_stock: list<string>},
     *     notes: list<string>
     * }
     */
    public function plan(array $items): array
    {
        usort($items, static fn ($a, $b) => strcmp((string) $a['item_id'], (string) $b['item_id']));

        $withRop = $noRop = [];
        foreach ($items as $it) {
            $it['rop'] = max(0, (int) $it['rop']);
            $it['eoq'] = max(0, (int) $it['eoq']);
            if ($it['rop'] > 0) {
                $withRop[$it['item_id']] = $it;
            } else {
                $noRop[$it['item_id']] = $it;
            }
        }

        $notes   = [];
        $targets = $this->targets(count($items));

        // Out of Stock is only possible for an item with no ROP.
        $outCount = min($targets['Out of Stock'], count($noRop));
        if ($outCount < $targets['Out of Stock']) {
            $notes[] = "Out of Stock: only {$outCount} products have no reorder point, so that is all that can be Out of Stock (target {$targets['Out of Stock']}).";
        }
        $out = array_slice(array_keys($this->ranked($noRop, 'out')), 0, $outCount);

        // Reorder Now / Low Stock need a reorder point. Scale both down together if the pool is too small.
        $wantR = $targets['Reorder Now'];
        $wantL = $targets['Low Stock'];
        if ($wantR + $wantL > count($withRop)) {
            $total = $wantR + $wantL;
            $wantR = intdiv(count($withRop) * $wantR, $total);
            $wantL = count($withRop) - $wantR;
            $notes[] = "Reorder Now / Low Stock: only " . count($withRop) . " products have a reorder point, so they are scaled down to {$wantR} / {$wantL} (target {$targets['Reorder Now']} / {$targets['Low Stock']}).";
        }

        // Class A first, so the minimum is always met when there are enough Class A items with a ROP.
        $classA = array_filter($withRop, static fn ($it) => $it['abc'] === 'A');
        $aIds   = array_keys($this->ranked($classA, 'classA'));
        $aR     = min(self::CLASS_A_MIN, $wantR, count($aIds));
        $aL     = min(self::CLASS_A_MIN, $wantL, max(0, count($aIds) - $aR));
        if (count($aIds) < 2 * self::CLASS_A_MIN) {
            $notes[] = 'Class A: only ' . count($aIds) . ' Class A products have a reorder point, so the minimum of ' . self::CLASS_A_MIN . ' Reorder Now + ' . self::CLASS_A_MIN . " Low Stock is cut to {$aR} + {$aL}.";
        }
        $reorder = array_slice($aIds, 0, $aR);
        $low     = array_slice($aIds, $aR, $aL);

        // Fill the rest of both quotas from the other products that have a ROP.
        $taken   = array_flip(array_merge($reorder, $low));
        $pool    = array_keys($this->ranked(array_diff_key($withRop, $taken), 'fill'));
        $needR   = max(0, $wantR - count($reorder));
        $reorder = array_merge($reorder, array_slice($pool, 0, $needR));
        $pool    = array_slice($pool, $needR);
        $needL   = max(0, $wantL - count($low));
        $low     = array_merge($low, array_slice($pool, 0, $needL));

        $status = [];
        foreach ($items as $it) {
            $status[$it['item_id']] = 'In Stock';
        }
        foreach ($reorder as $id) {
            $status[$id] = 'Reorder Now';
        }
        foreach ($low as $id) {
            $status[$id] = 'Low Stock';
        }
        foreach ($out as $id) {
            $status[$id] = 'Out of Stock';
        }

        // Quantities.
        $stock  = [];
        $model  = new ProductModel();
        foreach ($items as $it) {
            $id  = $it['item_id'];
            $rop = $it['rop'];
            $u   = $this->unit($id, 'qty');

            switch ($status[$id]) {
                case 'Reorder Now':
                    $stock[$id] = 1 + (int) floor($u * $rop);                         // 1 .. ROP
                    break;
                case 'Low Stock':
                    $limit      = ProductModel::lowStockLimit($rop);
                    $stock[$id] = $rop + 1 + (int) floor($u * ($limit - $rop));       // ROP+1 .. limit
                    break;
                case 'Out of Stock':
                    $stock[$id] = 0;
                    break;
                default: // In Stock
                    if ($rop === 0) {
                        $stock[$id] = 2 + (int) floor($u * 9);                        // 2 .. 10
                        break;
                    }
                    $limit = ProductModel::lowStockLimit($rop);                       // highest on-hand count that is still Low Stock
                    $lo    = max(3, $limit + 1);                                      // band + 1 ...
                    $hi    = max($lo, $limit + $it['eoq']);                           // ... up to band + EOQ
                    $stock[$id] = $lo + (int) floor($u * ($hi - $lo + 1));
            }

            $actual = $model->getStatus(['current_stock' => $stock[$id], 'manual_rop_warning' => $rop])['label'];
            if ($actual !== $status[$id]) {
                throw new \RuntimeException("Item {$id}: planned {$status[$id]} with stock {$stock[$id]} and ROP {$rop}, but the app calls it {$actual}.");
            }
        }

        $counts = array_fill_keys(array_keys(self::MIX), 0);
        foreach ($status as $s) {
            $counts[$s]++;
        }

        return [
            'stock'           => $stock,
            'status'          => $status,
            'targets'         => $targets,
            'counts'          => $counts,
            'classA'          => [
                'total'       => count(array_filter($items, static fn ($it) => $it['abc'] === 'A')),
                'with_rop'    => count($aIds),
                'reorder_now' => array_values(array_filter($aIds, static fn ($id) => $status[$id] === 'Reorder Now')),
                'low_stock'   => array_values(array_filter($aIds, static fn ($id) => $status[$id] === 'Low Stock')),
            ],
            'notes'           => $notes,
        ];
    }

    /**
     * Whole-number targets per status for $n products (largest remainder, so they add up to $n).
     *
     * @return array<string,int>
     */
    public function targets(int $n): array
    {
        $out  = [];
        $frac = [];
        foreach (self::MIX as $label => $share) {
            $out[$label]  = (int) floor($n * $share + 1e-9);
            $frac[$label] = $n * $share - $out[$label];
        }
        $left = $n - array_sum($out);
        arsort($frac); // stable for equal fractions: earlier statuses win
        foreach (array_keys($frac) as $label) {
            if ($left-- <= 0) {
                break;
            }
            $out[$label]++;
        }

        return $out;
    }

    /** A repeatable number in [0, 1) for this item and step. */
    private function unit(string $itemId, string $step): float
    {
        return crc32("{$this->seed}:{$step}:{$itemId}") / 4294967296;
    }

    /**
     * Items in a repeatable "random" order for this step (keys kept).
     *
     * @param array<string,array> $items keyed by item_id
     *
     * @return array<string,array>
     */
    private function ranked(array $items, string $step): array
    {
        uksort($items, fn ($a, $b) => [$this->unit($a, $step), $a] <=> [$this->unit($b, $step), $b]);

        return $items;
    }
}
