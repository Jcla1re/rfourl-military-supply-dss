<?php

namespace App\Libraries;

use App\Models\DssParameterModel;
use App\Models\PdssComputationModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Best supplier per item (Procurement Report > Best supplier): which supplier would cost least a year for one size,
 * which is cheapest and which is fastest. COMPARISON ONLY: it reads, it never writes. It does not change an item's
 * main supplier (products.supplier_id), Safety Stock, Reorder Point, EOQ, K-Means or the Cost comparison tab: the
 * main supplier still drives the DSS.
 *
 * For every supplier that offers the item (the main supplier first, from products.unit_cost / supplier_min_qty, then
 * the price-list offers in supplier_prices):
 *
 *   L   = the offer's own lead time, else the supplier's delivery time, else 7 days
 *   SS  = ceil(Z x sigma x sqrt(L))                      (DssFormulas::safetyStock)
 *   Q   = max(EOQ, system minimum, the supplier's own minimum)      EOQ = DssFormulas::eoq(D, S, H)
 *   yearly cost = D x unit price + (D / Q) x S + (Q / 2 + SS) x H
 *
 * D is the saved yearly demand (pdss_computation.annual_demand: the same whole-piece D the DSS uses for EOQ and the
 * Cost comparison tab), sigma its saved standard deviation, Z the Z-score the DSS used for the item's class, S the
 * ordering cost and H the storage cost per piece per year (dss_parameters).
 *
 * Tags, one winner each (a supplier can hold several): Recommended = lowest yearly cost, Cheapest = lowest unit price,
 * Fastest = shortest lead time. Ties go to the main supplier (no switching for nothing), then the lower yearly cost,
 * the shorter lead time, the lower price, then the name. A supplier with no price (or a price of 0) is listed but
 * left out of the tags and the saving. Sample suppliers take part and may win; they are labelled and cannot be
 * ordered from.
 *
 * Special cases: one offer in total is "Only supplier" (no tags); no sales in the window (D = 0) is "can't compare";
 * a class A item with only one REAL supplier gets the single-source warning.
 */
class BestSupplier
{
    public const DEFAULT_LEAD_TIME = 7;

    public const NO_SALES = "No sales in the window – can't compare";

    public const NO_PRICES = 'No supplier has a price for this item, so there is nothing to compare.';

    public const TAG_RECOMMENDED = 'Recommended';
    public const TAG_CHEAPEST    = 'Cheapest';
    public const TAG_FASTEST     = 'Fastest';

    public const SHOW = ['other' => 'Items with other suppliers', 'warn' => 'Single-source class A', 'all' => 'All items'];

    /**
     * The comparison for one size.
     *
     * $item: annual_demand (D, whole pieces), std_dev (sigma), z, class ('A' | 'B' | 'C' | null), ordering_cost (S),
     *        holding_cost (H), minimum_order_qty (system minimum).
     * $offers: list of supplier_id, name, is_main, is_sample, unit_price, lead_time_days (null = unknown),
     *          min_order_qty (null = none), the main supplier first.
     *
     * @param array<string, mixed>       $item
     * @param list<array<string, mixed>> $offers
     *
     * @return array{status: string, offer_count: int, real_count: int, sample_count: int, only_supplier: bool,
     *               single_source_warning: ?string, rows: list<array<string, mixed>>, recommended: ?string,
     *               cheapest: ?string, fastest: ?string, saving_vs_main: ?float, inputs: array<string, mixed>}
     */
    public static function compare(array $item, array $offers): array
    {
        $demand  = (int) round((float) ($item['annual_demand'] ?? 0));
        $sigma   = max(0.0, (float) ($item['std_dev'] ?? 0));
        $z       = (float) ($item['z'] ?? 0);
        $order   = (float) ($item['ordering_cost'] ?? 0);
        $holding = (float) ($item['holding_cost'] ?? 0);
        $system  = (int) ($item['minimum_order_qty'] ?? 0);
        $class   = $item['class'] ?? null;
        $eoq     = DssFormulas::eoq($demand, $order, $holding);

        $real    = count(array_filter($offers, static fn ($o) => empty($o['is_sample'])));
        $samples = count($offers) - $real;

        $rows = [];
        foreach ($offers as $o) {
            $price = round((float) ($o['unit_price'] ?? 0), 2);
            $lead  = (int) ($o['lead_time_days'] ?? 0) > 0 ? (int) $o['lead_time_days'] : self::DEFAULT_LEAD_TIME;
            $min   = isset($o['min_order_qty']) && (int) $o['min_order_qty'] > 0 ? (int) $o['min_order_qty'] : null;
            $row   = [
                'supplier_id'   => (string) $o['supplier_id'],
                'name'          => (string) $o['name'],
                'is_main'       => ! empty($o['is_main']),
                'is_sample'     => ! empty($o['is_sample']),
                'unit_price'    => $price,
                'priced'        => $price > 0,
                'lead_time_days' => $lead,
                'min_order_qty' => $min,
                'q'             => null,
                'ss'            => null,
                'yearly_cost'   => null,
                'tags'          => [],
            ];
            if ($demand > 0) {
                // Q is never below 1, so a missing EOQ and minimums can't divide by zero.
                $q              = max(1, $eoq, $system, (int) $min);
                $ss             = DssFormulas::safetyStock($z, $sigma, $lead);
                $row['q']       = $q;
                $row['ss']      = $ss;
                $row['yearly_cost'] = $row['priced'] ? round($demand * $price + ($demand / $q) * $order + ($q / 2 + $ss) * $holding, 2) : null;
            }
            $rows[] = $row;
        }

        $status = $demand <= 0 ? 'no_sales' : (array_filter($rows, static fn ($r) => $r['priced']) === [] ? 'no_prices' : 'ok');
        $single = count($offers) === 1;

        $out = [
            'status'                => $status,
            'offer_count'           => count($offers),
            'real_count'            => $real,
            'sample_count'          => $samples,
            'only_supplier'         => $single,
            'single_source_warning' => self::warning($class, $real, $samples),
            'rows'                  => $rows,
            'recommended'           => null,
            'cheapest'              => null,
            'fastest'               => null,
            'saving_vs_main'        => null,
            'inputs'                => ['annual_demand' => $demand, 'std_dev' => $sigma, 'z' => $z, 'class' => $class, 'eoq' => $eoq,
                                        'ordering_cost' => $order, 'holding_cost' => $holding, 'minimum_order_qty' => $system],
        ];

        if ($status !== 'ok' || $single) {
            return $out; // nothing to rank: no sales, no prices, or nobody to compare with
        }

        $priced = array_values(array_filter($rows, static fn ($r) => $r['priced']));
        $by     = static function (callable $compare) use ($priced): string {
            $sorted = $priced;
            usort($sorted, $compare);

            return $sorted[0]['supplier_id'];
        };
        $main = static fn ($r) => $r['is_main'] ? 0 : 1;

        $out['recommended'] = $by(static fn ($a, $b) => [$a['yearly_cost'], $main($a), $a['lead_time_days'], $a['unit_price'], $a['name']] <=> [$b['yearly_cost'], $main($b), $b['lead_time_days'], $b['unit_price'], $b['name']]);
        $out['cheapest']    = $by(static fn ($a, $b) => [$a['unit_price'], $main($a), $a['yearly_cost'], $a['lead_time_days'], $a['name']] <=> [$b['unit_price'], $main($b), $b['yearly_cost'], $b['lead_time_days'], $b['name']]);
        $out['fastest']     = $by(static fn ($a, $b) => [$a['lead_time_days'], $main($a), $a['yearly_cost'], $a['unit_price'], $a['name']] <=> [$b['lead_time_days'], $main($b), $b['yearly_cost'], $b['unit_price'], $b['name']]);

        foreach ($out['rows'] as &$r) {
            foreach ([self::TAG_RECOMMENDED => 'recommended', self::TAG_CHEAPEST => 'cheapest', self::TAG_FASTEST => 'fastest'] as $tag => $key) {
                if ($out[$key] === $r['supplier_id']) {
                    $r['tags'][] = $tag;
                }
            }
        }
        unset($r);

        // What the recommended supplier saves a year against the main supplier (0 when the main supplier is the recommended one).
        $mainRow = null;
        $best    = null;
        foreach ($out['rows'] as $r) {
            $mainRow = $r['is_main'] && $r['priced'] ? $r : $mainRow;
            $best    = $r['supplier_id'] === $out['recommended'] ? $r : $best;
        }
        if ($mainRow !== null && $best !== null) {
            $out['saving_vs_main'] = round(max(0.0, $mainRow['yearly_cost'] - $best['yearly_cost']), 2);
        }

        return $out;
    }

    /** The class A single-source warning: only one REAL supplier (sample offers do not count as a second source). */
    private static function warning(?string $class, int $real, int $samples): ?string
    {
        if ($class !== 'A' || $real !== 1) {
            return null;
        }

        return $samples > 0
            ? 'Only one real supplier for a fast-moving item (other offers shown are sample data).'
            : 'Only one supplier for a fast-moving item.';
    }

    // ------------------------------------------------------------------ for the page

    /**
     * The comparison for every given size, with a handful of queries (not three per item).
     *
     * @param list<array<string, mixed>> $sizes ProcurementReport rows (item_id, item_name, size, category, class,
     *                                          supplier_id, unit_cost, supplier_min, annual_demand)
     *
     * @return array<string, array<string, mixed>> item_id => compare() result plus the item's id, name, size, category, class
     */
    public static function forItems(array $sizes, ?array $dss = null, ?BaseConnection $db = null): array
    {
        $db  ??= db_connect();
        $dss ??= (new DssParameterModel())->current();

        $computations = [];
        foreach ((new PdssComputationModel())->latestPerItem() as $c) {
            $computations[(string) $c['item_id']] = $c;
        }
        $suppliers = array_column($db->table('suppliers')->get()->getResultArray(), null, 'supplier_id');
        $prices    = [];
        foreach ($db->table('supplier_prices')->get()->getResultArray() as $p) {
            $prices[(string) $p['item_id']][] = $p;
        }

        $out = [];
        foreach ($sizes as $r) {
            $id = (string) $r['item_id'];
            $c  = $computations[$id] ?? null;
            // Z as dss:run uses it: the item's saved Z, else the default from the DSS settings.
            $z = $c !== null && (float) ($c['service_level_z'] ?? 0) > 0 ? (float) $c['service_level_z'] : (float) ($dss['z_score'] ?? 0);

            $out[$id] = self::compare([
                'annual_demand'     => $c !== null ? (int) ($c['annual_demand'] ?? 0) : 0,
                'std_dev'           => $c !== null ? (float) ($c['demand_std_dev'] ?? 0) : 0.0,
                'z'                 => $z,
                'class'             => $r['class'],
                'ordering_cost'     => (float) ($dss['ordering_cost'] ?? 0),
                'holding_cost'      => (float) ($dss['holding_cost_per_unit'] ?? 0),
                'minimum_order_qty' => (int) ($dss['minimum_order_qty'] ?? 0),
            ], self::offers($r, $suppliers, $prices[$id] ?? [])) + [
                'item_id'   => $id,
                'item_name' => (string) $r['item_name'],
                'size'      => $r['size'],
                'category'  => (string) $r['category'],
                'class'     => $r['class'],
            ];
        }

        return $out;
    }

    /**
     * One item's offers, in the shape SupplierPriceModel::offersForItem() gives them: the main supplier first (its cost
     * is products.unit_cost, its minimum products.supplier_min_qty, its lead time the supplier's own), then the
     * price-list offers of ACTIVE suppliers, cheapest first. An offer's lead time is its own, else the supplier's.
     *
     * @param array<string, array> $suppliers supplier_id => suppliers row
     * @param list<array>          $priceRows supplier_prices rows of this item
     *
     * @return list<array<string, mixed>>
     */
    public static function offers(array $size, array $suppliers, array $priceRows): array
    {
        $offers = [];
        $mainId = (string) ($size['supplier_id'] ?? '');
        if ($mainId !== '' && isset($suppliers[$mainId])) {
            $s        = $suppliers[$mainId];
            $offers[] = [
                'supplier_id'    => $mainId,
                'name'           => (string) $s['company_name'],
                'is_main'        => true,
                'is_sample'      => ! empty($s['is_sample']),
                'unit_price'     => (float) $size['unit_cost'],
                'lead_time_days' => (int) $s['lead_time_days'],
                'min_order_qty'  => ! empty($size['supplier_min']) ? (int) $size['supplier_min'] : null,
            ];
        }

        $others = [];
        foreach ($priceRows as $p) {
            $s = $suppliers[(string) $p['supplier_id']] ?? null;
            if ($s === null || (string) $p['supplier_id'] === $mainId || empty($s['is_active'])) {
                continue; // an archived supplier cannot be ordered from
            }
            $others[] = [
                'supplier_id'    => (string) $p['supplier_id'],
                'name'           => (string) $s['company_name'],
                'is_main'        => false,
                'is_sample'      => ! empty($s['is_sample']) || ! empty($p['is_sample']),
                'unit_price'     => (float) $p['unit_price'],
                'lead_time_days' => $p['lead_time_days'] !== null ? (int) $p['lead_time_days'] : (int) $s['lead_time_days'],
                'min_order_qty'  => $p['min_order_qty'] !== null ? (int) $p['min_order_qty'] : null,
            ];
        }
        usort($others, static fn ($a, $b) => [$a['unit_price'], $a['name']] <=> [$b['unit_price'], $b['name']]);

        return array_merge($offers, $others);
    }

    /**
     * Which of the compared items to list: 'other' = more than one offer (the default), 'warn' = the class A
     * single-source warning, 'all'.
     *
     * @param array<string, array<string, mixed>> $results forItems()
     *
     * @return array<string, array<string, mixed>>
     */
    public static function filter(array $results, string $show): array
    {
        return match ($show) {
            'all'   => $results,
            'warn'  => array_filter($results, static fn ($r) => $r['single_source_warning'] !== null),
            default => array_filter($results, static fn ($r) => $r['offer_count'] > 1),
        };
    }

    // ------------------------------------------------------------------ CSV

    private static function safe(?string $value): string
    {
        $value = (string) $value;

        return ($value !== '' && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
    }

    /** What the page says about an item instead of a comparison, or '' when it was compared. */
    public static function note(array $result): string
    {
        return match (true) {
            $result['status'] === 'no_sales'  => self::NO_SALES,
            $result['status'] === 'no_prices' => self::NO_PRICES,
            $result['only_supplier']          => 'Only supplier',
            default                           => '',
        };
    }

    /**
     * The CSV: one line per item and supplier (an item with no comparison is one line with the note).
     *
     * @param array<string, array<string, mixed>> $results forItems()
     */
    public static function csv(array $results): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads peso signs correctly
        fputcsv($out, ['Item ID', 'Item', 'Size', 'Class', 'Yearly Demand', 'Supplier', 'Role', 'Sample', 'Unit Price', 'Lead Time (days)', 'Minimum Order', 'Order Qty (Q)', 'Safety Stock (SS)', 'Yearly Cost', 'Tags', 'Note']);

        $num = static fn (?float $n): string => $n === null ? '' : number_format($n, 2, '.', '');
        foreach ($results as $r) {
            $note = trim(self::note($r) . ' ' . ($r['single_source_warning'] ?? ''));
            foreach ($r['rows'] as $row) {
                fputcsv($out, [
                    self::safe($r['item_id']), self::safe($r['item_name']), self::safe($r['size'] ?? '—'), $r['class'] ?? '—', $r['inputs']['annual_demand'],
                    self::safe($row['name']), $row['is_main'] ? 'Main supplier' : 'Other supplier', $row['is_sample'] ? 'Sample' : '',
                    $row['priced'] ? $num($row['unit_price']) : 'No price set', $row['lead_time_days'], $row['min_order_qty'] ?? '',
                    $row['q'] ?? '', $row['ss'] ?? '', $num($row['yearly_cost']), implode(', ', $row['tags']), $note,
                ]);
            }
            if ($r['rows'] === []) {
                fputcsv($out, [self::safe($r['item_id']), self::safe($r['item_name']), self::safe($r['size'] ?? '—'), $r['class'] ?? '—', $r['inputs']['annual_demand'], '', '', '', '', '', '', '', '', '', '', 'No supplier']);
            }
        }
        fputcsv($out, []);
        fputcsv($out, ['Basis', 'Yearly cost = D x unit price + (D / Q) x ordering cost + (Q / 2 + SS) x storage cost. D = yearly demand, Q = max(EOQ, system minimum, supplier minimum), SS = Z x sigma x sqrt(lead time). Comparison only: the main supplier still drives the DSS. Sample suppliers cannot be ordered from.']);

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
