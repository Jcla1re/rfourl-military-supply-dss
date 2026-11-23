<?php

namespace App\Libraries;

use App\Controllers\Admin\StockOrderController;
use App\Models\DssParameterModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\ReorderNoticeModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;

/**
 * "Create PO for low items" (Procurement Report > Suppliers): one purchase order with several lines for
 * everything one supplier's items are low on.
 *
 *  - lines()    the review: every size of the supplier's MAIN supplier items (products.supplier_id) at Reorder Now,
 *               and, as an optional second section, the ones at Low Stock; an item already on an open order is
 *               listed as "Already on order (PO-xxxx)" and cannot be picked. Suggested quantity is the Items tab's
 *               Order Qty (EOQ, system minimum and supplier minimum already applied).
 *  - check()    the server's own answer to "may these lines be ordered?": never trusts the browser's list, only the
 *               ticked item ids and quantities. Unit cost is read from products.unit_cost (the main supplier's
 *               price; supplier_prices only holds OTHER suppliers' offers), so it can't be edited or forged.
 *  - create()   saves the order like StockOrderController::store() does (stock_order + so_item lines, pending
 *               alerts to Ordered, staff notices cleared), all in one transaction behind a one-time key.
 *
 * Orders already have lines (so_item), so the PO / SO / DR PDFs, the supplier portal, receiving (stock + inventory
 * log per line) and the Purchases Journal need no change. Nothing here touches SS, ROP, EOQ, minimums, K-Means or
 * the cost comparison.
 */
class SupplierPo
{
    public const NOW = 'Reorder Now';
    public const LOW = 'Low Stock';

    /** A sanity cap on one line's quantity (the form's number input is a whole number from 1). */
    public const MAX_QTY = 999999;

    /**
     * The supplier when it can receive a purchase order (real and active), else null with $why set to
     * 'missing', 'sample' or 'archived'.
     *
     * @return array<string, mixed>|null
     */
    public static function supplier(string $supplierId, ?string &$why = null): ?array
    {
        $why = null;
        $row = $supplierId !== '' ? (new SupplierModel())->find($supplierId) : null;
        if (! $row) {
            $why = 'missing';

            return null;
        }
        if (! empty($row['is_sample'])) {
            $why = 'sample';

            return null;
        }
        if (empty($row['is_active'])) {
            $why = 'archived';

            return null;
        }

        return $row;
    }

    public static function supplierMessage(string $why): string
    {
        return match ($why) {
            'sample'   => 'Sample suppliers are only for price comparison. They cannot receive a purchase order.',
            'archived' => 'This supplier is archived. Restore it before sending an order.',
            default    => 'That supplier was not found.',
        };
    }

    /**
     * The review lines, Reorder Now first and then Low Stock, each in the Items tab's order (name, then size).
     * Each: item_id, name, item_name, size, category, class, stock, rop, status, section ('now' | 'low'), qty (the
     * suggestion), unit_cost, supplier_min (or null), note (the supplier-minimum note or null), on_order (the open
     * order's PO number, or null).
     *
     * @param list<array>|null    $sizes ProcurementReport rows when the caller already has them
     * @param array<string,string>|null $open  item_id => so_id of the open orders
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(string $supplierId, ?array $dss = null, ?array $sizes = null, ?array $open = null): array
    {
        $dss   ??= (new DssParameterModel())->current();
        $sizes ??= ProcurementReport::rows(ProcurementReport::filters([]), $dss);
        $open  ??= (new ProductModel())->openOrders();

        $minimum = max(1, (int) ($dss['minimum_order_qty'] ?? 1));
        $lines   = [];
        foreach ($sizes as $r) {
            if ($r['supplier_id'] !== $supplierId || ! in_array($r['status'], [self::NOW, self::LOW], true)) {
                continue;
            }
            $soId    = $open[$r['item_id']] ?? null;
            $lines[] = [
                'item_id'      => (string) $r['item_id'],
                'name'         => ProductModel::nameWithSize(['item_name' => $r['item_name'], 'size' => $r['size']]),
                'item_name'    => (string) $r['item_name'],
                'size'         => $r['size'],
                'category'     => (string) $r['category'],
                'class'        => $r['class'],
                'stock'        => (int) $r['stock'],
                'rop'          => (int) $r['rop'],
                'status'       => (string) $r['status'],
                'section'      => $r['status'] === self::NOW ? 'now' : 'low',
                // A size with no DSS numbers yet gets the system minimum (or the supplier's, when higher).
                'qty'          => $r['order_qty'] !== null ? (int) $r['order_qty'] : max($minimum, (int) ($r['supplier_min'] ?? 0)),
                'unit_cost'    => (float) $r['unit_cost'],
                'supplier_min' => $r['supplier_min'],
                'note'         => $r['supplier_note'],
                'on_order'     => $soId !== null ? StockOrderModel::labelFor((string) $soId) : null,
            ];
        }
        usort($lines, static fn ($a, $b) => ($a['section'] === 'now' ? 0 : 1) <=> ($b['section'] === 'now' ? 0 : 1));

        return $lines;
    }

    /**
     * What the "Create PO for low items" button needs: per supplier id, how many sizes can be ordered now
     * (not on an open order) at Reorder Now ('now') and at Low Stock ('low'), and how many are already on order.
     *
     * @param list<array> $sizes ProcurementReport rows
     *
     * @return array<string, array{now: int, low: int, on_order: int}>
     */
    public static function countsBySupplier(array $sizes, ?array $open = null): array
    {
        $open ??= (new ProductModel())->openOrders();
        $out   = [];
        foreach ($sizes as $r) {
            if ($r['supplier_id'] === null || ! in_array($r['status'], [self::NOW, self::LOW], true)) {
                continue;
            }
            $c = &$out[$r['supplier_id']];
            $c ??= ['now' => 0, 'low' => 0, 'on_order' => 0];
            if (isset($open[$r['item_id']])) {
                $c['on_order']++;
            } else {
                $c[$r['status'] === self::NOW ? 'now' : 'low']++;
            }
            unset($c);
        }

        return $out;
    }

    /**
     * Checks a posted order against the database as it is right now. Only the ticked item ids and the typed
     * quantities come from the browser. Every problem is listed (naming the item), and nothing is returned
     * to save unless there are none: the whole order is refused, never trimmed.
     *
     * @param mixed $picked ticked item ids
     * @param mixed $qtys   item_id => typed quantity
     *
     * @return array{errors: list<string>, lines: list<array{item_id: string, name: string, qty: int, price: float}>}
     */
    public static function check(string $supplierId, mixed $picked, mixed $qtys, ?array $dss = null): array
    {
        $ids = [];
        foreach (is_array($picked) ? $picked : [] as $id) {
            if (is_scalar($id) && (string) $id !== '') {
                $ids[(string) $id] = true;
            }
        }
        $ids = array_map('strval', array_keys($ids));
        if ($ids === []) {
            return ['errors' => ['Tick at least 1 item to order.'], 'lines' => []];
        }
        $qtys = is_array($qtys) ? $qtys : [];

        $byId = [];
        foreach (self::lines($supplierId, $dss) as $l) {
            $byId[$l['item_id']] = $l;
        }
        $productModel = new ProductModel();
        $products     = array_column($productModel->whereIn('item_id', $ids)->findAll(), null, 'item_id');

        $errors = [];
        $lines  = [];
        foreach ($ids as $id) {
            $p = $products[$id] ?? null;
            if (! $p) {
                $errors[] = 'An item in your order (' . mb_substr($id, 0, 20) . ') no longer exists.';
                continue;
            }
            $name = ProductModel::nameWithSize($p);
            if (! $p['is_active']) {
                $errors[] = "{$name} is archived.";
                continue;
            }
            if ((string) $p['supplier_id'] !== $supplierId) {
                $errors[] = "{$name} isn't from this supplier.";
                continue;
            }
            $line = $byId[$id] ?? null;
            if ($line === null) {
                $errors[] = "{$name} is not low on stock any more (now " . $productModel->getStatus($p)['label'] . ').';
                continue;
            }
            if ($line['on_order'] !== null) {
                $errors[] = "{$name} is already on order ({$line['on_order']}).";
                continue;
            }
            $typed = $qtys[$id] ?? null;
            $typed = is_scalar($typed) ? trim((string) $typed) : '';
            if (! ctype_digit($typed) || (int) $typed < 1 || (int) $typed > self::MAX_QTY) {
                $errors[] = "Enter a whole quantity of 1 or more for {$name}.";
                continue;
            }
            if (! ($line['unit_cost'] > 0)) {
                $errors[] = "{$name} has no unit cost. Set one in Inventory first.";
                continue;
            }
            $lines[] = ['item_id' => $id, 'name' => $name, 'qty' => (int) $typed, 'price' => round($line['unit_cost'], 2)];
        }

        return ['errors' => $errors, 'lines' => $errors === [] ? $lines : []];
    }

    /**
     * Saves one purchase order with the given (already checked) lines. In one transaction: the one-time key is
     * claimed, the items are locked and checked again (so two people ordering the same item at the same moment
     * cannot both succeed), then the order, its lines, the alerts and the staff notices are written.
     *
     * @param list<array{item_id: string, name: string, qty: int, price: float}> $lines
     *
     * @return array{status: string, so_id?: string, po_number?: string, lines?: int, units?: int, total?: float, earlier?: array, errors?: list<string>}
     *         status: 'created' | 'duplicate' (same form sent again) | 'refused' (changed meanwhile) | 'failed'
     */
    public static function create(string $supplierId, array $lines, ?string $expected, ?string $onceKey, ?int $userId, ?array $dss = null): array
    {
        $db     = db_connect();
        $orders = new StockOrderModel();
        $ids    = array_column($lines, 'item_id');
        $qtys   = array_column($lines, 'qty', 'item_id');

        $soId     = $orders->generateNextId();
        $poNumber = ProcurementDocs::nextNumber('po');

        $db->transStart();

        if ($earlier = OnceToken::claim($onceKey, 'supplier_po', $userId)) {
            return ['status' => 'duplicate', 'earlier' => $earlier];
        }

        // MariaDB: lock the items' rows, then read the open orders with a locking read (it sees what another
        // order committed while this one waited, which a plain read in this transaction might not).
        if ($db->getPlatform() === 'MySQLi') { // not DBDriver: that is the app's own class name (PlatformCheckTest)
            $db->query('SELECT item_id FROM ' . $db->prefixTable('products') . ' WHERE item_id IN ? FOR UPDATE', [$ids]);
            $busy = $db->query(
                'SELECT si.item_id, so.so_id FROM ' . $db->prefixTable('so_item') . ' si JOIN ' . $db->prefixTable('stock_order') . " so ON so.so_id = si.so_id WHERE so.status NOT IN ('Delivered', 'Cancelled') AND si.item_id IN ? FOR UPDATE",
                [$ids]
            )->getResultArray();
            if ($busy !== []) {
                $db->transRollback();
                $names = array_column($lines, 'name', 'item_id');

                return ['status' => 'refused', 'errors' => array_map(
                    static fn ($b) => ($names[$b['item_id']] ?? $b['item_id']) . ' is already on order (' . StockOrderModel::labelFor((string) $b['so_id']) . ').',
                    $busy
                )];
            }
        }

        $again = self::check($supplierId, $ids, $qtys, $dss);
        if ($again['errors'] !== []) {
            $db->transRollback();

            return ['status' => 'refused', 'errors' => $again['errors']];
        }

        $ordered = (new ProductModel())->whereIn('item_id', $ids)->findAll();

        $orders->insert([
            'so_id'                  => $soId,
            'supplier_id'            => $supplierId,
            'user_id'                => $userId,
            'priority'               => StockOrderController::dialogPriority($ordered), // Urgent when an item is out of stock
            'status'                 => 'Order Confirmed',
            'order_date'             => date('Y-m-d'),
            'expected_delivery_date' => $expected,
            'tracking_no'            => null, // the supplier enters it when shipping
            'po_number'              => $poNumber,
        ]);

        $items = new SoItemModel();
        $units = 0;
        $total = 0.0;
        foreach ($again['lines'] as $line) {
            $items->insert([
                'so_id'          => $soId,
                'item_id'        => $line['item_id'],
                'order_quantity' => $line['qty'],
                'unit_price'     => $line['price'],
            ]);
            $units += $line['qty'];
            $total += $line['qty'] * $line['price'];
        }

        // Same as StockOrderController::store(): any pending alert for these items is now covered by this
        // order (delivery marks it Fulfilled), and the staff "Owner notified" on them is answered.
        (new ReorderAlertModel())
            ->whereIn('item_id', $ids)
            ->whereIn('status', ReorderAlertModel::PENDING_STATUSES)
            ->set(['status' => 'Ordered', 'so_id' => $soId])
            ->update();
        (new ReorderNoticeModel())->clear($ids, 'ordered');

        OnceToken::complete($onceKey, 'stock_order', $soId);

        if (! $db->transComplete()) {
            return ['status' => 'failed'];
        }

        return ['status' => 'created', 'so_id' => $soId, 'po_number' => $poNumber, 'lines' => count($again['lines']), 'units' => $units, 'total' => round($total, 2)];
    }
}
