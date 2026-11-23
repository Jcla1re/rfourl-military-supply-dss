<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * The supplier scorecard (Procurement Report > Suppliers): how a REAL supplier has performed, computed only from the
 * orders in the system (stock_order + so_item) and never from anything typed in. Read-only: it changes nothing, and no
 * order is ever made up. A sample supplier has no order history, so it is never scored.
 *
 * An order belongs to a period by its PO date (stock_order.order_date): the last 3, 6 or 12 months, or all of them.
 *
 *   Placed / Received / In progress   every order, Delivered, and Order Confirmed / Preparing / Shipped
 *   Cancelled by RfourL / Declined    status Cancelled with cancelled_by set, and without it (the supplier declined)
 *   Received late                     received after the promised date
 *   Open and overdue now              not received yet and the promised date has passed
 *
 *   Promised date  the PO's expected delivery date; when it has none, the PO date + the supplier's stated delivery time
 *   Received date  DATE(received_at), else actual_delivery_date (older orders): the same rule as the order timeline
 *   On time        received on or before the promised date (the same day is on time)
 *   Actual lead    days from the PO date to the received date, against the supplier's stated delivery time
 *   Shipped vs ordered
 *                  sum of min(shipped, ordered) / sum of ordered, by units, over received orders. Receiving records the
 *                  quantity the supplier shipped (it adds exactly that to stock), so this is what the supplier declared
 *                  against what was ordered. A line with no shipped quantity (an older order) is left out.
 *   Ordered value  quantity x unit price of the orders placed in the period, cancelled ones left out: the same total as
 *                  the weekly / monthly supplier report.
 *
 * Fewer than MIN_RECEIVED received orders in the period: the counts and the value are shown, but no percentages or
 * averages ("Not enough orders yet").
 */
class SupplierScorecard
{
    public const MIN_RECEIVED = 5;

    public const PERIODS = ['3' => 'Last 3 months', '6' => 'Last 6 months', '12' => 'Last 12 months', 'all' => 'All time'];

    public const DEFAULT_PERIOD = '12';

    public const SAMPLE = 'Sample – no order history';

    public const IN_PROGRESS = ['Order Confirmed', 'Preparing', 'Shipped'];

    /**
     * The period for ?score=: its key (anything unknown is the default), label and first PO date (null = all time).
     *
     * @return array{key: string, label: string, from: ?string}
     */
    public static function period(?string $key, ?string $today = null): array
    {
        $key  = array_key_exists((string) $key, self::PERIODS) ? (string) $key : self::DEFAULT_PERIOD;
        $from = $key === 'all' ? null : (new \DateTimeImmutable($today ?? 'today'))->modify('-' . (int) $key . ' months')->format('Y-m-d');

        return ['key' => $key, 'label' => self::PERIODS[$key], 'from' => $from];
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }

    /** Whole days from $from to $to (negative when $to is earlier). */
    private static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }

    /** The promised date of an order (Y-m-d), or null when there is none to judge it by. */
    private static function promised(array $order, string $orderDate, int $statedLead): ?string
    {
        $expected = self::date($order['expected_delivery_date'] ?? null);
        if ($expected !== null) {
            return $expected;
        }

        return $statedLead > 0 ? (new \DateTimeImmutable($orderDate))->modify('+' . $statedLead . ' days')->format('Y-m-d') : null;
    }

    /**
     * The delivery judgement of ONE order, shared by the scorecard and the supplier portal (Dashboard, Completed, Profile)
     * so the two always agree. Same rules as the scorecard: the promised date is the PO's expected delivery date, else the
     * PO date + the supplier's stated delivery time; the received date is DATE(received_at), else actual_delivery_date.
     *
     * on_time is null when the order cannot be judged: no received date, no promised date, or received before it was
     * ordered. The same day is on time. days_late is whole days after the promised date (0 when on time or not judged).
     *
     * @param array<string, mixed> $order      a stock_order row
     * @param int                  $statedLead the supplier's stated delivery time in days
     *
     * @return array{order_date: ?string, promised: ?string, received: ?string, on_time: ?bool, days_late: int, date_problem: bool}
     */
    public static function judge(array $order, int $statedLead): array
    {
        $orderDate = self::date($order['order_date'] ?? null);
        $received  = self::date($order['received_at'] ?? null) ?? self::date($order['actual_delivery_date'] ?? null);
        $promised  = $orderDate !== null ? self::promised($order, $orderDate, $statedLead) : null;
        $problem   = $received !== null && $orderDate !== null && $received < $orderDate;
        $judged    = $received !== null && $promised !== null && ! $problem;

        return [
            'order_date'   => $orderDate,
            'promised'     => $promised,
            'received'     => $received,
            'on_time'      => $judged ? $received <= $promised : null,
            'days_late'    => $judged && $received > $promised ? self::days($promised, $received) : 0,
            'date_problem' => $problem,
        ];
    }

    /**
     * The on-time rate the supplier portal shows, from the supplier's DELIVERED orders (a list it already has): the same
     * judge() and the same rule as the scorecard: fewer than MIN_RECEIVED received orders is "Not enough orders yet".
     *
     * @param list<array<string, mixed>> $delivered Delivered stock_order rows
     *
     * @return array{enough: bool, received: int, judged: int, on_time: int, pct: ?int, message: ?string}
     */
    public static function portalRate(array $delivered, int $statedLead): array
    {
        $judged = $onTime = 0;
        foreach ($delivered as $o) {
            $on = self::judge($o, $statedLead)['on_time'];
            if ($on !== null) {
                $judged++;
                $onTime += $on ? 1 : 0;
            }
        }
        $received = count($delivered);
        $enough   = $received >= self::MIN_RECEIVED;

        return [
            'enough'   => $enough,
            'received' => $received,
            'judged'   => $judged,
            'on_time'  => $onTime,
            'pct'      => $enough && $judged > 0 ? (int) round($onTime / $judged * 100) : null,
            'message'  => $enough ? null : 'Not enough orders yet (' . $received . ' of ' . self::MIN_RECEIVED . ' received)',
        ];
    }

    /**
     * One supplier's scorecard.
     *
     * @param array<string, mixed>                    $supplier     the suppliers row (is_sample, lead_time_days)
     * @param list<array<string, mixed>>              $orders       the supplier's stock_order rows
     * @param array<string, list<array<string, mixed>>> $linesByOrder so_id => so_item rows
     *
     * @return array<string, mixed> status 'sample' | 'ok'; see the keys below
     */
    public static function compute(array $supplier, array $orders, array $linesByOrder, string $periodKey = self::DEFAULT_PERIOD, ?string $today = null): array
    {
        $today  = $today ?? date('Y-m-d');
        $period = self::period($periodKey, $today);
        $stated = (int) ($supplier['lead_time_days'] ?? 0);

        if (! empty($supplier['is_sample'])) {
            return ['status' => 'sample', 'message' => self::SAMPLE, 'period' => $period];
        }

        $n = ['placed' => 0, 'received' => 0, 'in_progress' => 0, 'cancelled_by_owner' => 0, 'cancelled_after_accept' => 0, 'declined' => 0, 'received_late' => 0, 'open_overdue' => 0];
        $judged = $onTime = $timing = $leadDays = 0;
        $noReceivedDate = $dateProblems = $noPromise = 0;
        $filled = $orderedUnits = $fillOrders = $fillSkipped = 0;
        $value = 0.0;

        foreach ($orders as $o) {
            $orderDate = self::date($o['order_date'] ?? null);
            if ($orderDate === null || ($period['from'] !== null && $orderDate < $period['from'])) {
                continue;
            }
            $n['placed']++;
            $status = (string) $o['status'];

            if ($status === 'Cancelled') {
                if (! empty($o['cancelled_by'])) {
                    $n['cancelled_by_owner']++;
                    $n['cancelled_after_accept'] += ! empty($o['accepted_at']) ? 1 : 0;
                } else {
                    $n['declined']++; // the supplier declined it
                }

                continue; // not ordered value, and never part of the delivery measures
            }

            $lines = $linesByOrder[$o['so_id']] ?? [];
            foreach ($lines as $l) {
                $value += (float) $l['order_quantity'] * (float) $l['unit_price'];
            }
            $promised = self::promised($o, $orderDate, $stated);

            if (in_array($status, self::IN_PROGRESS, true)) {
                $n['in_progress']++;
                $n['open_overdue'] += $promised !== null && $promised < $today ? 1 : 0;

                continue;
            }
            if ($status !== 'Delivered') {
                continue;
            }

            $n['received']++;
            $j = self::judge($o, $stated); // the same judgement the supplier portal uses
            if ($j['received'] === null) {
                $noReceivedDate++;
            } elseif ($j['date_problem']) {
                $dateProblems++; // received before it was ordered: a data error, kept out of the timing
            } else {
                $timing++;
                $leadDays += self::days($orderDate, $j['received']);
                if ($j['on_time'] === null) {
                    $noPromise++;
                } else {
                    $judged++;
                    if ($j['on_time']) {
                        $onTime++;
                    } else {
                        $n['received_late']++;
                    }
                }
            }

            $used = false;
            foreach ($lines as $l) {
                if ($l['shipped_quantity'] === null) {
                    continue; // an older order: no shipped quantity recorded
                }
                $used = true;
                $orderedUnits += (int) $l['order_quantity'];
                $filled       += min((int) $l['shipped_quantity'], (int) $l['order_quantity']);
            }
            $used ? $fillOrders++ : $fillSkipped++;
        }

        $enough = $n['received'] >= self::MIN_RECEIVED;
        $avgLead = $enough && $timing > 0 ? round($leadDays / $timing, 1) : null;

        return [
            'status'        => 'ok',
            'period'        => $period,
            'counts'        => $n,
            'enough'        => $enough,
            'min_received'  => self::MIN_RECEIVED,
            'message'       => $enough ? null : 'Not enough orders yet (' . $n['received'] . ' of ' . self::MIN_RECEIVED . ' received)',
            'on_time'       => ['pct' => $enough && $judged > 0 ? (int) round($onTime / $judged * 100) : null, 'on_time' => $onTime, 'of' => $judged],
            'lead'          => ['avg' => $avgLead, 'of' => $timing, 'stated' => $stated, 'diff' => $avgLead !== null ? round($avgLead - $stated, 1) : null],
            'fill'          => ['pct' => $enough && $orderedUnits > 0 ? (int) round($filled / $orderedUnits * 100) : null, 'filled' => $filled, 'ordered' => $orderedUnits, 'orders' => $fillOrders, 'left_out' => $fillSkipped],
            'ordered_value' => round($value, 2),
            'notes'         => ['no_received_date' => $noReceivedDate, 'date_problems' => $dateProblems, 'no_promised_date' => $noPromise],
        ];
    }

    /**
     * The scorecard of every active supplier, in a few queries. A sample supplier is never queried for orders.
     *
     * @return array<string, array<string, mixed>> supplier_id => compute()
     */
    public static function forSuppliers(?string $periodKey = null, ?BaseConnection $db = null, ?string $today = null): array
    {
        $db     ??= db_connect();
        $period   = self::period($periodKey, $today);
        $suppliers = $db->table('suppliers')->where('is_active', 1)->get()->getResultArray();
        $realIds   = array_values(array_map(static fn ($s) => (string) $s['supplier_id'], array_filter($suppliers, static fn ($s) => empty($s['is_sample']))));

        $orders = [];
        $lines  = [];
        if ($realIds !== []) {
            $builder = $db->table('stock_order')->whereIn('supplier_id', $realIds);
            if ($period['from'] !== null) {
                $builder->where('order_date >=', $period['from']);
            }
            foreach ($builder->get()->getResultArray() as $o) {
                $orders[(string) $o['supplier_id']][] = $o;
            }
            $soIds = array_map(static fn ($o) => (string) $o['so_id'], array_merge(...array_values($orders ?: [[]])));
            if ($soIds !== []) {
                foreach ($db->table('so_item')->whereIn('so_id', $soIds)->get()->getResultArray() as $l) {
                    $lines[(string) $l['so_id']][] = $l;
                }
            }
        }

        $out = [];
        foreach ($suppliers as $s) {
            $id       = (string) $s['supplier_id'];
            $out[$id] = self::compute($s, $orders[$id] ?? [], $lines, $period['key'], $today);
        }

        return $out;
    }

    /**
     * The one line shown under a supplier's name in the list.
     *
     * @param array<string, mixed> $card compute()
     */
    public static function summary(array $card): string
    {
        if ($card['status'] === 'sample') {
            return self::SAMPLE;
        }
        if (! $card['enough']) {
            return (string) $card['message'];
        }
        $parts = [];
        if ($card['on_time']['pct'] !== null) {
            $parts[] = 'On time ' . $card['on_time']['pct'] . '%';
        }
        if ($card['fill']['pct'] !== null) {
            $parts[] = 'Shipped vs ordered ' . $card['fill']['pct'] . '%';
        }
        $parts[] = $card['counts']['received'] . ' received';

        return implode(' · ', $parts);
    }
}
