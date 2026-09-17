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
    ];

    protected $useTimestamps = false;

    protected $validationRules = [
        'supplier_id' => 'required',
        'order_date'  => 'required|valid_date',
    ];

    public const STATUSES = ['Order Confirmed', 'Preparing', 'Shipped', 'Delivered', 'Cancelled'];
    public const PRIORITIES = ['Urgent', 'Order', 'Planned'];

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
        $builder = $this->select('stock_order.*, suppliers.company_name')
            ->join('suppliers', 'suppliers.supplier_id = stock_order.supplier_id', 'left')
            ->orderBy('stock_order.order_date', 'DESC');

        if ($status) {
            $builder->where('stock_order.status', $status);
        }

        return $builder->findAll();
    }

    public function findWithDetails(string $soId): ?array
    {
        $order = $this->select('stock_order.*, suppliers.company_name, suppliers.contact_person, suppliers.contact_email, suppliers.contact_number')
            ->join('suppliers', 'suppliers.supplier_id = stock_order.supplier_id', 'left')
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
     * Marks an order Delivered, adds the received quantities to on-hand stock,
     * and writes an inventory_log entry per line. Shared by the Admin and
     * Supplier portals so delivery receiving only lives in one place.
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

            $newStock = (int) $product['current_stock'] + (int) $line['order_quantity'];
            $productModel->update($line['item_id'], ['current_stock' => $newStock]);

            $inventoryLogModel->record(
                $line['item_id'],
                $userId,
                'Restock',
                (int) $line['order_quantity'],
                $newStock,
                $soId,
                'Received from stock order ' . $soId
            );
        }
    }
}
