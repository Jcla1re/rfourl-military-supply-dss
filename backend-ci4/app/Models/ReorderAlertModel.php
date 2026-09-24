<?php

namespace App\Models;

use CodeIgniter\Model;

class ReorderAlertModel extends Model
{
    protected $table            = 'reorder_alert';
    protected $primaryKey       = 'alert_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'item_id',
        'computation_id',
        'so_id',
        'trigger_reason',
        'recommended_eoq',
        'stock_at_trigger',
        'status',
    ];

    protected $useTimestamps = false;

    /**
     * Full 5-state workflow per the Data Dictionary. Active = just raised;
     * Acknowledged = admin has seen it but not acted; Ordered = a stock
     * order was created from it (see StockOrderController::store()) and
     * auto-transitions to Fulfilled when that order is delivered (see
     * StockOrderModel::markDelivered()); Dismissed = manually closed
     * without ordering (false positive, stock corrected by hand, etc.) or
     * auto-closed by syncFromLiveInventory() once stock is replenished.
     */
    public const STATUSES          = ['Active', 'Acknowledged', 'Ordered', 'Fulfilled', 'Dismissed'];
    public const PENDING_STATUSES  = ['Active', 'Acknowledged'];
    public const OPEN_STATUSES     = ['Active', 'Acknowledged', 'Ordered'];

    public function openAlerts(): array
    {
        return $this->select('reorder_alert.*, products.item_name, products.category, products.current_stock, products.supplier_id')
            ->join('products', 'products.item_id = reorder_alert.item_id', 'left')
            ->whereIn('reorder_alert.status', self::PENDING_STATUSES)
            ->orderBy('reorder_alert.alert_timestamp', 'DESC')
            ->findAll();
    }

    /**
     * Count of alerts still needing attention (Active or Acknowledged,
     * i.e. not yet ordered, fulfilled, or dismissed).
     */
    public function pendingCount(): int
    {
        return $this->whereIn('status', self::PENDING_STATUSES)->countAllResults();
    }

    /**
     * Alerts whose stock actually arrived (Fulfilled) in the last 7 days.
     * Dismissed alerts don't count — nothing was received for those.
     */
    public function resolvedThisWeek(): int
    {
        return $this->where('status', 'Fulfilled')
            ->where('alert_timestamp >=', date('Y-m-d H:i:s', strtotime('-7 days')))
            ->countAllResults();
    }

    /**
     * Reconciles reorder_alert against live inventory (current_stock vs
     * products.manual_rop_warning — the same rule ProductModel::getStatus()
     * uses to show "Reorder Now" in the Inventory table), so the Reorder
     * Alerts page always matches what Inventory shows right now instead of
     * whatever dss:run last computed. Called at the top of both the admin
     * and staff Reorder Alerts pages.
     *
     * - Any product currently "Reorder Now" with no pending alert yet gets
     *   one raised on the spot (covers stock changes since the last
     *   dss:run — no need to wait for the next scheduled run).
     * - Any pending alert whose product is no longer "Reorder Now" (stock
     *   was replenished) is auto-dismissed, so it stops cluttering the
     *   list and pendingCount()/openAlerts() stay accurate.
     */
    public function syncFromLiveInventory(): void
    {
        $productModel      = new ProductModel();
        $computationModel  = new PdssComputationModel();
        $dssParameterModel = new DssParameterModel();

        $products = $productModel->where('is_active', 1)->findAll();

        $criticalByItem = [];
        foreach ($products as $p) {
            if ($productModel->getStatus($p)['label'] === 'Reorder Now') {
                $criticalByItem[$p['item_id']] = $p;
            }
        }

        $existingByItem = array_column(
            $this->whereIn('status', self::PENDING_STATUSES)->findAll(),
            null,
            'item_id'
        );

        $fallbackEoq = (int) ($dssParameterModel->current()['minimum_order_qty'] ?? 5);

        $newRows = [];
        foreach ($criticalByItem as $itemId => $p) {
            if (isset($existingByItem[$itemId])) {
                continue;
            }

            $rop         = (int) ($p['manual_rop_warning'] ?? 0);
            $computation = $computationModel->latestFor($itemId);

            $newRows[] = [
                'item_id'          => $itemId,
                'trigger_reason'   => $rop > 0
                    ? "Stock ({$p['current_stock']}) at/below reorder point ({$rop})"
                    : 'Out of stock — no demand history yet; review manually',
                'recommended_eoq'  => $computation['eoq_value'] ?? $fallbackEoq,
                'stock_at_trigger' => (int) $p['current_stock'],
                'status'           => 'Active',
            ];
        }
        if ($newRows) {
            $this->insertBatch($newRows);
        }

        $staleIds = [];
        foreach ($existingByItem as $itemId => $alert) {
            if (! isset($criticalByItem[$itemId])) {
                $staleIds[] = $alert['alert_id'];
            }
        }
        if ($staleIds) {
            $this->whereIn('alert_id', $staleIds)->set(['status' => 'Dismissed'])->update();
        }
    }
}
