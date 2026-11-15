<?php

namespace App\Libraries;

use App\Models\SoItemModel;
use App\Models\SupplierModel;
use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The paper trail of a stock order: Purchase Order -> Sales Order -> Delivery Receipt -> Received.
 *
 *  - numbers  PO-YYYY-####, SO-YYYY-####, DR-YYYY-#### (one running count per kind and year);
 *             the PO number is given when the order is placed, the SO number when the supplier
 *             accepts it and the DR number when the supplier ships it (ensureNumbers()).
 *  - steps    the data behind the timeline on the order page.
 *  - PDFs     the three documents, made with Dompdf in the same style as the sales PDF.
 *
 * Nothing here changes a status or touches stock.
 */
class ProcurementDocs
{
    public const TYPES = [
        'po' => ['label' => 'Purchase Order', 'column' => 'po_number', 'prefix' => 'PO'],
        'so' => ['label' => 'Sales Order', 'column' => 'so_number', 'prefix' => 'SO'],
        'dr' => ['label' => 'Delivery Receipt', 'column' => 'dr_number', 'prefix' => 'DR'],
    ];

    // ------------------------------------------------------------------ numbers

    /** The next free number of a kind in a year, e.g. "PO-2026-0007". */
    public static function nextNumber(string $type, ?int $year = null, ?BaseConnection $db = null): string
    {
        $db     ??= db_connect();
        $kind     = self::TYPES[$type];
        $stem     = $kind['prefix'] . '-' . ($year ?? (int) date('Y')) . '-';
        $max      = 0;
        $existing = $db->table('stock_order')->select($kind['column'])->like($kind['column'], $stem, 'after')->get()->getResultArray();

        foreach ($existing as $row) {
            $max = max($max, (int) substr((string) $row[$kind['column']], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Gives an order the numbers its status has reached and does not have yet: a PO always,
     * an SO from Preparing on, a DR from Shipped on. Also stamps when the supplier steps
     * happened. Safe to call any number of times; a number is never changed once given.
     */
    public static function ensureNumbers(string $soId): void
    {
        $db    = db_connect();
        $order = $db->table('stock_order')->where('so_id', $soId)->get()->getRowArray();
        if (! $order) {
            return;
        }

        $status = $order['status'];
        $now    = date('Y-m-d H:i:s');
        $wanted = ['po' => []];
        if (in_array($status, ['Preparing', 'Shipped', 'Delivered'], true)) {
            $wanted['so'] = empty($order['accepted_at']) ? ['accepted_at' => $now] : [];
        }
        if (in_array($status, ['Shipped', 'Delivered'], true)) {
            $wanted['dr'] = empty($order['shipped_at']) ? ['shipped_at' => $now] : [];
        }

        foreach ($wanted as $type => $stamp) {
            $column = self::TYPES[$type]['column'];
            if (! empty($order[$column])) {
                continue;
            }
            // Two requests can pick the same number; the unique index turns the loser into an error, so try again.
            for ($try = 0; $try < 5; $try++) {
                try {
                    $db->table('stock_order')->where('so_id', $soId)->where($column, null)->update([$column => self::nextNumber($type, null, $db)] + $stamp);
                    break;
                } catch (\Throwable $e) {
                    if ($try === 4) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Numbers every existing order from the status it is in now (the migration runs this).
     * Oldest first, the year taken from the order date, so the numbers read in order.
     * Cancelled orders only get a PO: there is no way to tell how far they got.
     */
    public static function backfill(BaseConnection $db): void
    {
        $counters = [];
        $next     = static function (string $type, int $year) use (&$counters, $db): string {
            $kind = self::TYPES[$type];
            $stem = $kind['prefix'] . '-' . $year . '-';
            if (! isset($counters[$stem])) {
                $max = 0;
                foreach ($db->table('stock_order')->select($kind['column'])->like($kind['column'], $stem, 'after')->get()->getResultArray() as $r) {
                    $max = max($max, (int) substr((string) $r[$kind['column']], strlen($stem)));
                }
                $counters[$stem] = $max;
            }

            return $stem . str_pad((string) ++$counters[$stem], 4, '0', STR_PAD_LEFT);
        };

        $orders = $db->table('stock_order')->orderBy('order_date', 'ASC')->orderBy('so_id', 'ASC')->get()->getResultArray();
        foreach ($orders as $o) {
            $year   = (int) date('Y', strtotime((string) ($o['order_date'] ?: 'now')));
            $status = $o['status'];
            $set    = [];

            if (empty($o['po_number'])) {
                $set['po_number'] = $next('po', $year);
            }
            if (empty($o['so_number']) && in_array($status, ['Preparing', 'Shipped', 'Delivered'], true)) {
                $set['so_number'] = $next('so', $year);
            }
            if (empty($o['dr_number']) && in_array($status, ['Shipped', 'Delivered'], true)) {
                $set['dr_number'] = $next('dr', $year);
            }
            if ($set) {
                $db->table('stock_order')->where('so_id', $o['so_id'])->update($set);
            }
        }
    }

    // ------------------------------------------------------------------ what exists

    public static function hasDocument(array $order, string $type): bool
    {
        return isset(self::TYPES[$type]) && ! empty($order[self::TYPES[$type]['column']]);
    }

    /** @return list<string> the document types this order has so far, in order: po, so, dr */
    public static function available(array $order): array
    {
        return array_values(array_filter(array_keys(self::TYPES), static fn ($t) => self::hasDocument($order, $t)));
    }

    /** "Nov 14, 2026 3:05 PM"; just the date when only a date is known; null when empty. */
    public static function when(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $time = strtotime($value);
        if (! $time) {
            return null;
        }

        return date('H:i:s', $time) === '00:00:00' ? date('M j, Y', $time) : date('M j, Y g:i A', $time);
    }

    private static function userName($userId): ?string
    {
        return $userId ? ((new UserModel())->find($userId)['full_name'] ?? null) : null;
    }

    // ------------------------------------------------------------------ timeline

    /**
     * The four steps of the order timeline. state: done | pending | stopped (a cancelled order
     * stops at the first step it never reached, with the reason as the note).
     *
     * @param array $order stock_order row; cancelled_by_name / company_name are used when present
     *
     * @return list<array{key:string,label:string,number:?string,when:?string,actor:?string,doc:?string,state:string,note:?string}>
     */
    public static function steps(array $order): array
    {
        $supplier = $order['company_name'] ?? ((new SupplierModel())->find($order['supplier_id'] ?? '')['company_name'] ?? null);
        $received = ($order['status'] ?? '') === 'Delivered';

        $steps = [
            ['key' => 'po', 'label' => 'Purchase Order', 'number' => $order['po_number'] ?? null, 'done' => ! empty($order['po_number']),
                'when' => self::when($order['order_date'] ?? null), 'actor' => ($n = self::userName($order['user_id'] ?? null)) ? 'Prepared by ' . $n : 'Prepared by the owner', 'doc' => 'po'],
            ['key' => 'so', 'label' => 'Sales Order', 'number' => $order['so_number'] ?? null, 'done' => ! empty($order['so_number']),
                'when' => self::when($order['accepted_at'] ?? null), 'actor' => $supplier ? 'Accepted by ' . $supplier : 'Accepted by the supplier', 'doc' => 'so'],
            ['key' => 'dr', 'label' => 'Delivery Receipt', 'number' => $order['dr_number'] ?? null, 'done' => ! empty($order['dr_number']),
                'when' => self::when($order['shipped_at'] ?? null), 'actor' => $supplier ? 'Shipped by ' . $supplier : 'Shipped by the supplier', 'doc' => 'dr'],
            ['key' => 'received', 'label' => 'Received', 'number' => null, 'done' => $received,
                'when' => self::when($order['received_at'] ?? null) ?? self::when($order['actual_delivery_date'] ?? null),
                'actor' => ($n = self::userName($order['received_by'] ?? null)) ? 'Received by ' . $n : 'Received', 'doc' => 'dr'],
        ];

        $cancelled = ($order['status'] ?? '') === 'Cancelled';
        $stoppedAt = false;
        foreach ($steps as &$step) {
            $step['state'] = $step['done'] ? 'done' : 'pending';
            $step['note']  = null;
            if ($cancelled && ! $step['done'] && ! $stoppedAt) {
                $stoppedAt     = true;
                $step['state'] = 'stopped';
                $step['note']  = \App\Models\StockOrderModel::cancelNote($order) ?? 'Cancelled';
            }
            // A step that has not happened has no actor, date or document to show.
            if (! $step['done']) {
                $step['when'] = $step['actor'] = $step['doc'] = null;
            }
            unset($step['done']);
        }
        unset($step);

        return $steps;
    }

    // ------------------------------------------------------------------ PDFs

    /** The data a document view needs. Null when the order has not reached that document. */
    public static function documentData(array $order, string $type): ?array
    {
        if (! self::hasDocument($order, $type)) {
            return null;
        }

        $received = ($order['status'] ?? '') === 'Delivered';
        $lines    = [];
        $total    = 0.0;
        foreach ((new SoItemModel())->forOrder($order['so_id']) as $l) {
            $shipped  = $l['shipped_quantity'] ?? $l['order_quantity'];
            $lineCost = (float) $l['order_quantity'] * (float) $l['unit_price'];
            $total   += $lineCost;
            $lines[]  = [
                'name'      => $l['item_name'] ?? $l['item_id'],
                'size'      => trim((string) ($l['size'] ?? '')),
                'ordered'   => (int) $l['order_quantity'],
                'shipped'   => $l['shipped_quantity'] !== null ? (int) $l['shipped_quantity'] : null,
                'received'  => $received ? (int) $shipped : null, // what was added to stock on receipt
                'unit_cost' => (float) $l['unit_price'],
                'line'      => $lineCost,
            ];
        }

        return [
            'type'        => $type,
            'title'       => self::TYPES[$type]['label'],
            'number'      => $order[self::TYPES[$type]['column']],
            'order'       => $order,
            'supplier'    => (new SupplierModel())->find($order['supplier_id'] ?? '') ?? [],
            'lines'       => $lines,
            'total'       => $total,
            'prepared_by' => self::userName($order['user_id'] ?? null),
            'received_by' => self::userName($order['received_by'] ?? null),
            'received'    => $received,
            'received_at' => self::when($order['received_at'] ?? null) ?? self::when($order['actual_delivery_date'] ?? null),
            'dates'       => [
                'order'    => self::when($order['order_date'] ?? null),
                'accepted' => self::when($order['accepted_at'] ?? null),
                'shipped'  => self::when($order['shipped_at'] ?? null),
                'expected' => self::when($order['expected_delivery_date'] ?? null),
            ],
        ];
    }

    /** The PDF of one document as raw bytes, or null when the order has not reached it. */
    public static function pdf(array $order, string $type): ?string
    {
        $data = self::documentData($order, $type);
        if ($data === null) {
            return null;
        }

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml(view('procurement/' . $type, $data));
        $dompdf->render();

        return $dompdf->output();
    }

    /** The PDF as an inline download named after its number, or null when it does not exist. */
    public static function respond(ResponseInterface $response, array $order, string $type): ?ResponseInterface
    {
        $body = self::pdf($order, $type);
        if ($body === null) {
            return null;
        }

        return $response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $order[self::TYPES[$type]['column']] . '.pdf"')
            ->setBody($body);
    }
}
