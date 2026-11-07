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
        'hidden_until_recovered',
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
     * Only a manual dismissal sets hidden_until_recovered: the alert stays
     * hidden and no new one is raised for that size until it recovers.
     */
    public const STATUSES          = ['Active', 'Acknowledged', 'Ordered', 'Fulfilled', 'Dismissed'];
    public const PENDING_STATUSES  = ['Active', 'Acknowledged'];
    public const OPEN_STATUSES     = ['Active', 'Acknowledged', 'Ordered'];

    public function openAlerts(): array
    {
        return $this->select('reorder_alert.*, products.item_name, products.size, products.category, products.current_stock, products.supplier_id')
            ->join('products', 'products.item_id = reorder_alert.item_id', 'left')
            ->whereIn('reorder_alert.status', self::PENDING_STATUSES)
            ->where('products.is_active', 1) // archived items' alerts stay hidden until restored
            ->mostUrgentFirst()
            ->findAll();
    }

    /** Alerts the Owner dismissed whose size hasn't recovered yet (active items only). */
    public function dismissedAlerts(): array
    {
        return $this->select('reorder_alert.*, products.item_name, products.size, products.category, products.current_stock, products.supplier_id')
            ->join('products', 'products.item_id = reorder_alert.item_id', 'left')
            ->where('reorder_alert.status', 'Dismissed')
            ->where('reorder_alert.hidden_until_recovered', 1)
            ->where('products.is_active', 1)
            ->mostUrgentFirst()
            ->findAll();
    }

    /**
     * The Owner's Reorder Alerts tabs: every active size at Reorder Now (the same
     * rule and the same sizes as Inventory's Reorder Now), each in exactly one tab,
     * so the tab counts add up to the headline:
     *   ordered   = on an open stock order, or its alert is "Ordered" (an order wins)
     *   dismissed = dismissed until its stock recovers
     *   order     = everything else ("To order")
     * Alerts for sizes no longer at Reorder Now are not listed.
     *
     * @return list<array> products row + 'tab', 'alert' (the size's alert row or null), 'so_id'
     */
    public function reorderNowBoard(): array
    {
        $productModel = new ProductModel();

        $sizes = [];
        foreach ($productModel->where('is_active', 1)->findAll() as $p) {
            if ($productModel->getStatus($p)['label'] === 'Reorder Now') {
                $sizes[$p['item_id']] = $p;
            }
        }
        if ($sizes === []) {
            return [];
        }

        // One alert per size; if old data has more, Ordered beats pending beats dismissed.
        $rank   = ['Ordered' => 0, 'Active' => 1, 'Acknowledged' => 1, 'Dismissed' => 2];
        $alerts = [];
        $rows   = $this->whereIn('item_id', array_map('strval', array_keys($sizes)))
            ->groupStart()
                ->whereIn('status', self::OPEN_STATUSES)
                ->orGroupStart()->where('status', 'Dismissed')->where('hidden_until_recovered', 1)->groupEnd()
            ->groupEnd()
            ->orderBy('alert_id', 'DESC')
            ->findAll();
        foreach ($rows as $a) {
            $current = $alerts[$a['item_id']] ?? null;
            if ($current === null || $rank[$a['status']] < $rank[$current['status']]) {
                $alerts[$a['item_id']] = $a;
            }
        }

        $openOrders = $productModel->openOrders();
        $board      = [];
        foreach ($sizes as $itemId => $p) {
            $alert  = $alerts[$itemId] ?? null;
            $status = $alert['status'] ?? null;
            $soId   = ($status === 'Ordered' ? $alert['so_id'] : null) ?: ($openOrders[$itemId] ?? null);

            $tab = match (true) {
                $soId !== null || $status === 'Ordered' => 'ordered',
                $status === 'Dismissed'                 => 'dismissed',
                default                                 => 'order',
            };
            $board[] = $p + ['tab' => $tab, 'alert' => $alert, 'so_id' => $soId];
        }

        return $board;
    }

    /**
     * Stock 0 first, then the lowest stock compared with its reorder point, then
     * item name. Size and alert ID only keep the order the same between page loads.
     * A size with no reorder point can't be compared, so it goes last. Needs the
     * products join.
     */
    private function mostUrgentFirst(): static
    {
        $products = $this->db->prefixTable('products');

        $this->orderBy("({$products}.current_stock <= 0)", 'DESC', false)
            ->orderBy("COALESCE({$products}.current_stock * 1.0 / NULLIF({$products}.manual_rop_warning, 0), 999999)", 'ASC', false)
            ->orderBy('products.item_name', 'ASC')
            ->orderBy('products.size', 'ASC')
            ->orderBy('reorder_alert.alert_id', 'ASC');

        return $this;
    }

    /** Dismisses one pending alert; it stays hidden until its size recovers. */
    public function dismissPending(int $alertId): void
    {
        $this->where('alert_id', $alertId)
            ->whereIn('status', self::PENDING_STATUSES)
            ->set(['status' => 'Dismissed', 'hidden_until_recovered' => 1])
            ->update();
    }

    /**
     * Items that must not get a new alert: already open (an "Ordered" one has
     * a stock order in flight, so no second order) or dismissed until recovery.
     *
     * @return list<string>
     */
    public function blockingItemIds(): array
    {
        $rows = $this->db->table($this->table)->select('item_id')
            ->whereIn('status', self::OPEN_STATUSES)
            ->orGroupStart()
                ->where('status', 'Dismissed')
                ->where('hidden_until_recovered', 1)
            ->groupEnd()
            ->get()->getResultArray();

        return array_column($rows, 'item_id');
    }

    /**
     * Count of alerts still needing attention (Active or Acknowledged,
     * i.e. not yet ordered, fulfilled, or dismissed).
     */
    public function pendingCount(): int
    {
        return $this->join('products', 'products.item_id = reorder_alert.item_id')
            ->whereIn('reorder_alert.status', self::PENDING_STATUSES)
            ->where('products.is_active', 1) // archived items don't count
            ->countAllResults();
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
     * - A size the Owner dismissed stays hidden with no new alert while it is
     *   still "Reorder Now"; once it recovers the dismissal ends, so a later
     *   drop raises a fresh alert. Archived items keep theirs until restored.
     */
    public function syncFromLiveInventory(): void
    {
        $productModel      = new ProductModel();
        $computationModel  = new PdssComputationModel();
        $dssParameterModel = new DssParameterModel();

        $products = $productModel->where('is_active', 1)->findAll();

        $criticalByItem = [];
        $activeByItem   = [];
        foreach ($products as $p) {
            $activeByItem[$p['item_id']] = true;
            if ($productModel->getStatus($p)['label'] === 'Reorder Now') {
                $criticalByItem[$p['item_id']] = $p;
            }
        }

        // Dismissals end once the size has recovered.
        $recoveredIds = [];
        foreach ($this->where('status', 'Dismissed')->where('hidden_until_recovered', 1)->findAll() as $alert) {
            if (isset($activeByItem[$alert['item_id']]) && ! isset($criticalByItem[$alert['item_id']])) {
                $recoveredIds[] = $alert['alert_id'];
            }
        }
        if ($recoveredIds) {
            $this->whereIn('alert_id', $recoveredIds)->set(['hidden_until_recovered' => 0])->update();
        }

        // Sizes with an open alert, or one dismissed until recovery, get no new one
        // (raiseAlerts() checks again); skipping them here saves their lookups.
        $blockedByItem = array_flip($this->blockingItemIds());

        $fallbackEoq = (int) ($dssParameterModel->current()['minimum_order_qty'] ?? 5);

        $newRows = [];
        foreach ($criticalByItem as $itemId => $p) {
            if (isset($blockedByItem[$itemId])) {
                continue;
            }

            $rop        = (int) ($p['manual_rop_warning'] ?? 0);
            $computation = $computationModel->latestFor($itemId);

            $newRows[] = [
                'item_id'          => $itemId,
                // Reorder Now always has ROP > 0 now: on hand 0 with ROP 0 is
                // "Out of Stock" (no demand) and never raises an alert.
                'trigger_reason'   => "Stock ({$p['current_stock']}) at/below reorder point ({$rop})",
                'recommended_eoq'  => $computation['eoq_value'] ?? $fallbackEoq,
                'stock_at_trigger' => (int) $p['current_stock'],
                'status'           => 'Active',
            ];
        }
        $this->raiseAlerts($newRows);

        // Only auto-dismiss Active/Acknowledged alerts whose stock recovered
        // on its own — never "Ordered" ones, even if stock happens to look
        // fine again before delivery, since markDelivered() still needs to
        // find them by status='Ordered' to mark them Fulfilled on arrival.
        // Archived items keep their alerts too, so a restored item gets its alert back.
        $pendingByItem = array_column(
            $this->whereIn('status', self::PENDING_STATUSES)->findAll(),
            null,
            'item_id'
        );

        $staleIds = [];
        foreach ($pendingByItem as $itemId => $alert) {
            if (isset($activeByItem[$itemId]) && ! isset($criticalByItem[$itemId])) {
                $staleIds[] = $alert['alert_id'];
            }
        }
        if ($staleIds) {
            $this->whereIn('alert_id', $staleIds)->set(['status' => 'Dismissed'])->update();
        }
    }

    /**
     * Saves new alerts and tells the Owner. The one place alerts are raised, for
     * both the live sync and the DSS run (dss:run), so no size is ever raised
     * without a notification. Sizes that already have an open alert, or one
     * dismissed until recovery, are skipped: an "Ordered" one has a stock order
     * in flight and must not get a second order.
     *
     * @param list<array<string,mixed>> $rows reorder_alert rows (item_id, trigger_reason, ...)
     *
     * @return list<array<string,mixed>> the rows actually saved
     */
    public function raiseAlerts(array $rows): array
    {
        $blocked = array_flip($this->blockingItemIds());
        $new     = [];
        foreach ($rows as $row) {
            if (! isset($blocked[$row['item_id']])) {
                $new[] = $row;
                $blocked[$row['item_id']] = true; // never two alerts for one size in one call
            }
        }

        foreach (array_chunk($new, 200) as $chunk) {
            $this->db->table($this->table)->insertBatch($chunk);
        }
        $this->notifyOwnerOfNewAlerts($new);

        return $new;
    }

    /**
     * One in-app notification per newly raised alert, titled with the size when
     * the item has one; when many are raised at once (first sync after seeding,
     * a DSS run, a bulk import) a single summary is sent instead so the Owner's
     * inbox is not flooded. Off when the Settings switch "Reorder At Alerts"
     * (rop_alerts) is off.
     *
     * @param list<array<string,mixed>> $newRows
     */
    private function notifyOwnerOfNewAlerts(array $newRows): void
    {
        if ($newRows === [] || ! (new NotificationPreferenceModel())->isEnabled('rop_alerts')) {
            return;
        }

        $notifications = new NotificationModel();
        $link          = '/admin/reorder-alerts';

        if (count($newRows) > 5) {
            $notifications->push(
                'Admin',
                'Reorder Alert',
                count($newRows) . ' items reached their reorder point',
                'Open Reorder Alerts to review and order them.',
                null,
                'reorder_alert',
                $link
            );

            return;
        }

        $names = [];
        foreach ((new ProductModel())->whereIn('item_id', array_column($newRows, 'item_id'))->findAll() as $p) {
            $names[$p['item_id']] = ProductModel::nameWithSize($p);
        }

        foreach ($newRows as $row) {
            $name = $names[$row['item_id']] ?? $row['item_id'];
            $notifications->push(
                'Admin',
                'Reorder Alert',
                "Reorder alert: {$name}",
                $row['trigger_reason'] . '. Suggested order quantity: ' . (int) $row['recommended_eoq'] . '.',
                null,
                'reorder_alert',
                $link
            );
        }
    }
}
