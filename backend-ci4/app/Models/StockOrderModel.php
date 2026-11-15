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
        'po_number',
        'so_number',
        'dr_number',
        'accepted_at',
        'shipped_at',
        'received_by',
        'received_at',
    ];

    protected $useTimestamps = false;

    protected $validationRules = [
        'supplier_id' => 'required',
        'order_date'  => 'required|valid_date',
    ];

    public const STATUSES = ['Order Confirmed', 'Preparing', 'Shipped', 'Delivered', 'Cancelled'];
    public const PRIORITIES = ['Urgent', 'Order', 'Planned'];

    /**
     * What a priority is called on screen. 'Order' (the default) and NULL read as "Normal";
     * the stored values stay enum('Urgent', 'Order', 'Planned').
     */
    public static function priorityLabel(?string $priority): string
    {
        return match ($priority) {
            'Urgent'  => 'Urgent',
            'Planned' => 'Planned',
            default   => 'Normal',
        };
    }

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

    /**
     * The name an order goes by on screen: its PO number (PO-2026-0001). The so_id (SO-0001) stays
     * the internal key (routes, links, foreign keys); only an order that has no PO number shows it.
     */
    public static function label(array $order): string
    {
        return (string) (($order['po_number'] ?? '') !== '' ? $order['po_number'] : ($order['so_id'] ?? ''));
    }

    /** The same by so_id, looking the order up (the so_id itself when it is not found). */
    public static function labelFor(string $soId): string
    {
        $order = (new self())->select('so_id, po_number')->find($soId);

        return $order ? self::label($order) : $soId;
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

    /** The forward order of statuses (Cancelled is a separate path: markCancelled()). */
    public const FLOW = ['Order Confirmed', 'Preparing', 'Shipped', 'Delivered'];

    /**
     * Moves an order to $to only if its status right now is one of $from, in one UPDATE ... WHERE
     * status IN (...), so two requests (a double click, a stale tab, two people) can't both make
     * the same move. Returns whether this request made it; when false nothing changed and the
     * caller sends no notification or email.
     */
    public function transition(string $soId, array $from, string $to, array $extra = []): bool
    {
        if ($from === []) {
            return false;
        }

        $this->db->table($this->table)
            ->where('so_id', $soId)
            ->whereIn('status', $from)
            ->update(['status' => $to] + $extra);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Cancels an order (owner Cancel, supplier Decline) if it is still in one of $from. Its
     * reorder alerts go back to Active, so the items show as needing reordering again and can be
     * re-ordered. Shared by both portals. Returns false (nothing changed) when the order had
     * already moved on.
     */
    public function markCancelled(string $soId, array $extra = [], array $from = self::CANCELLABLE): bool
    {
        if (! $this->transition($soId, $from, 'Cancelled', $extra)) {
            return false;
        }

        (new ReorderAlertModel())
            ->where('so_id', $soId)
            ->where('status', 'Ordered')
            ->set(['status' => 'Active', 'so_id' => null])
            ->update();

        return true;
    }

    public const DELIVERED        = 'delivered';
    public const ALREADY_SAVED    = 'already_saved';
    public const ALREADY_RECEIVED = 'already_received';
    public const FAILED           = 'failed';

    /**
     * Receives a Shipped order: marks it Delivered, adds the received quantities to on-hand
     * stock, writes an inventory_log entry per line, and Fulfills the reorder alerts it was
     * created from. Shared by the Owner's Mark as Done and the Staff's Confirm Delivery Received.
     *
     * All in one transaction. The status moves first and only from Shipped
     * (UPDATE ... WHERE status = 'Shipped'), so the stock is added at most once, however many
     * times or by however many people it is confirmed. Stock is added relative to what is there
     * now (current_stock = current_stock + qty). $onceKey (OnceToken) makes a repeat of the same
     * form report ALREADY_SAVED.
     *
     * @return self::DELIVERED|self::ALREADY_SAVED|self::ALREADY_RECEIVED|self::FAILED
     */
    public function markDelivered(string $soId, ?int $userId, ?string $onceKey = null): string
    {
        if (\App\Libraries\OnceToken::used($onceKey)) {
            return self::ALREADY_SAVED;
        }

        $soItemModel       = new SoItemModel();
        $productModel      = new ProductModel();
        $inventoryLogModel = new InventoryLogModel();

        $this->db->transStart();

        if (\App\Libraries\OnceToken::claim($onceKey, 'order_received', $userId)) {
            return self::ALREADY_SAVED;
        }

        // Who confirmed the delivery and when (staff Restock confirm or the owner's Mark as Done),
        // written by the same guarded move, so only the one confirmation that counts is recorded.
        if (! $this->transition($soId, ['Shipped'], 'Delivered', [
            'actual_delivery_date' => date('Y-m-d'),
            'received_by'          => $userId,
            'received_at'          => date('Y-m-d H:i:s'),
        ])) {
            $this->db->transRollback();

            return self::ALREADY_RECEIVED;
        }

        $itemIds = [];
        foreach ($soItemModel->forOrder($soId) as $line) {
            // Prefer what the Supplier actually declared at ship time; only
            // legacy orders placed before that feature existed fall back to
            // the originally ordered quantity.
            $receivedQty = (int) ($line['shipped_quantity'] ?? $line['order_quantity']);

            $newStock = $productModel->changeStock($line['item_id'], $receivedQty);
            if ($newStock === null) {
                continue; // nothing received for this line, or the item no longer exists
            }
            $itemIds[] = $line['item_id'];

            $inventoryLogModel->record(
                $line['item_id'],
                $userId,
                'RESTOCK',
                $receivedQty,
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

        \App\Libraries\OnceToken::complete($onceKey, 'stock_order', $soId);

        if (! $this->db->transComplete()) {
            return self::FAILED;
        }

        if ($itemIds !== []) {
            (new ReorderNoticeModel())->clearResolved($itemIds);
        }

        return self::DELIVERED;
    }
}
