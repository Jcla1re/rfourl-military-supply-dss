<?php

namespace App\Models;

use CodeIgniter\Model;

class StockOrderModel extends Model
{
    protected $table            = 'stock_order';
    protected $primaryKey       = 'so_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'so_id',
        'supplier_id',
        'user_id',
        'priority',
        'status',
        'order_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'tracking_no',
        'decline_reason',
        'cancel_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $useTimestamps = false;

    protected $validationRules = [
        'supplier_id' => 'required',
        'order_date'  => 'required|valid_date',
    ];

    public const STATUSES = ['Order Confirmed', 'Preparing', 'Shipped', 'Delivered', 'Cancelled'];
    public const PRIORITIES = ['Urgent', 'Order', 'Planned'];

    /** Only these can be cancelled by the owner. Shipped goods are already on the way. */
    public const CANCELLABLE = ['Order Confirmed', 'Preparing'];

    public const CANCEL_REASONS = ['Ordered by mistake', 'Wrong item or quantity', 'No longer needed', 'Found another supplier', 'Other'];

    public static function canCancel(array $order): bool
    {
        return in_array($order['status'] ?? '', self::CANCELLABLE, true);
    }

    /**
     * The one-line history note for a cancelled order, e.g.
     * "Cancelled by Richelle · Oct 21, 2026 · Ordered by mistake" or
     * "Declined by supplier · Oct 21, 2026 · Out of stock". Null if the order is not cancelled.
     */
    public static function cancelNote(array $order): ?string
    {
        if (($order['status'] ?? '') !== 'Cancelled') {
            return null;
        }

        $reason = $order['cancel_reason'] ?? $order['decline_reason'] ?? null;

        if (! empty($order['cancelled_by'])) {
            $who = 'Cancelled by ' . ($order['cancelled_by_name'] ?? 'the owner');
        } elseif (! empty($order['decline_reason']) || ! empty($order['cancelled_at'])) {
            $who = 'Declined by supplier';
        } else {
            $who = 'Cancelled';
        }
        $when = ! empty($order['cancelled_at']) ? date('M j, Y', strtotime($order['cancelled_at'])) : null;

        return implode(' · ', array_filter([$who, $when, $reason]));
    }

    /** The same note as the supplier sees it. */
    public static function supplierCancelNote(array $order): ?string
    {
        if (($order['status'] ?? '') !== 'Cancelled') {
            return null;
        }

        $reason = $order['cancel_reason'] ?? $order['decline_reason'] ?? null;
        $who    = ! empty($order['cancelled_by']) ? 'Cancelled by RfourL' : 'Declined by you';

        return implode(' · ', array_filter([$who, $reason]));
    }

    public function generateNextId(): string
    {
        $count = $this->countAll();
        return 'SO-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Stock orders joined with supplier name, for listing.
     */
    public function listWithSupplier(?string $status = null): array
    {
        $builder = $this->select('stock_order.*, suppliers.company_name, cb.full_name AS cancelled_by_name')
            ->join('suppliers', 'suppliers.supplier_id = stock_order.supplier_id', 'left')
            ->join('users cb', 'cb.user_id = stock_order.cancelled_by', 'left')
            ->orderBy('stock_order.order_date', 'DESC');

        if ($status) {
            $builder->where('stock_order.status', $status);
        }

        return $builder->findAll();
    }

    public function findWithDetails(string $soId): ?array
    {
        $order = $this->select('stock_order.*, suppliers.company_name, suppliers.contact_person, suppliers.contact_email, suppliers.contact_number, cb.full_name AS cancelled_by_name')
            ->join('suppliers', 'suppliers.supplier_id = stock_order.supplier_id', 'left')
            ->join('users cb', 'cb.user_id = stock_order.cancelled_by', 'left')
            ->where('stock_order.so_id', $soId)
            ->first();

        return $order;
    }

    /**
     * Orders belonging to one supplier, newest first.
     */
    public function forSupplier(string $supplierId, ?array $statuses = null): array
    {
        $builder = $this->where('supplier_id', $supplierId)->orderBy('order_date', 'DESC');

        if ($statuses) {
            $builder->whereIn('status', $statuses);
        }

        return $builder->findAll();
    }

    /**
     * Cancels an order (admin status change or supplier decline). Its
     * reorder alerts go back to Active, so the items show as needing
     * reordering again and can be re-ordered. Shared by both portals.
     */
    public function markCancelled(string $soId, array $extra = []): void
    {
        $this->update($soId, ['status' => 'Cancelled'] + $extra);

        (new ReorderAlertModel())
            ->where('so_id', $soId)
            ->where('status', 'Ordered')
            ->set(['status' => 'Active', 'so_id' => null])
            ->update();
    }

    /**
     * Marks an order Delivered, adds the received quantities to on-hand stock,
     * writes an inventory_log entry per line, and Fulfills any reorder_alert
     * that this order was created from. Shared by the Admin and Supplier
     * portals so delivery receiving only lives in one place.
     */
    public function markDelivered(string $soId, ?int $userId): void
    {
        $this->update($soId, [
            'status'                => 'Delivered',
            'actual_delivery_date'  => date('Y-m-d'),
        ]);

        $soItemModel       = new SoItemModel();
        $productModel      = new ProductModel();
        $inventoryLogModel = new InventoryLogModel();

        foreach ($soItemModel->forOrder($soId) as $line) {
            $product = $productModel->find($line['item_id']);
            if (! $product) {
                continue;
            }

            // Prefer what the Supplier actually declared at ship time; only
            // legacy orders placed before that feature existed fall back to
            // the originally ordered quantity.
            $receivedQty = $line['shipped_quantity'] ?? $line['order_quantity'];

            $newStock = (int) $product['current_stock'] + (int) $receivedQty;
            $productModel->update($line['item_id'], ['current_stock' => $newStock]);

            $inventoryLogModel->record(
                $line['item_id'],
                $userId,
                'RESTOCK',
                (int) $receivedQty,
                $newStock,
                $soId,
                'Received from stock order ' . $soId
            );
        }

        (new ReorderAlertModel())
            ->where('so_id', $soId)
            ->where('status', 'Ordered')
            ->set(['status' => 'Fulfilled'])
            ->update();
    }
}
