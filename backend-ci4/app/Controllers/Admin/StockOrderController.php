<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\EmailNotifier;
use App\Libraries\PurchaseOrderLine;
use App\Libraries\Toast;
use App\Models\SecurityLogModel;
use App\Models\NotificationModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\ReorderNoticeModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;
use App\Models\UserModel;

class StockOrderController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;
    protected $supplierModel;
    protected $productModel;
    protected $reorderAlertModel;
    protected $userModel;
    protected $notificationModel;
    protected $emailNotifier;

    public function __construct()
    {
        $this->stockOrderModel   = new StockOrderModel();
        $this->soItemModel       = new SoItemModel();
        $this->supplierModel     = new SupplierModel();
        $this->productModel      = new ProductModel();
        $this->reorderAlertModel = new ReorderAlertModel();
        $this->userModel         = new UserModel();
        $this->notificationModel = new NotificationModel();
        $this->emailNotifier     = new EmailNotifier();
    }

    /**
     * The Supplier-portal account tied to a given supplier_id, so an order
     * notification (in-app or email) reaches only that one supplier's
     * inbox instead of broadcasting to every supplier account.
     */
    private function supplierUser(string $supplierId): ?array
    {
        return $this->userModel->where('supplier_id', $supplierId)->where('role', 'Supplier')->first();
    }

    private function supplierUserId(string $supplierId): ?int
    {
        return $this->supplierUser($supplierId)['user_id'] ?? null;
    }

    public function index()
    {
        $tab = $this->request->getGet('tab') === 'history' ? 'history' : 'status';

        $allOrders = $this->stockOrderModel->listWithSupplier();

        $counts = [
            'Order Confirmed' => 0,
            'Preparing'       => 0,
            'Shipped'         => 0,
            'Delivered'       => 0,
            'Cancelled'       => 0,
        ];
        foreach ($allOrders as $o) {
            $counts[$o['status']] = ($counts[$o['status']] ?? 0) + 1;
        }

        $activeOrders  = array_values(array_filter($allOrders, fn ($o) => ! in_array($o['status'], ['Delivered', 'Cancelled'], true)));
        $historyOrders = array_values(array_filter($allOrders, fn ($o) => in_array($o['status'], ['Delivered', 'Cancelled'], true)));
        $historyCount  = count($historyOrders); // before History's filters: for "View history"

        $historySearch = trim((string) ($this->request->getGet('search') ?? ''));
        if ($historySearch !== '') {
            $needle = strtolower($historySearch);
            $historyOrders = array_values(array_filter($historyOrders, function ($o) use ($needle) {
                return str_contains(strtolower($o['so_id']), $needle)
                    || str_contains(strtolower($o['company_name'] ?? ''), $needle);
            }));
        }

        // order_date is stored as 'Y-m-d', so plain string comparison sorts
        // correctly without needing to parse it.
        $historyDateFrom = trim((string) ($this->request->getGet('date_from') ?? ''));
        $historyDateTo   = trim((string) ($this->request->getGet('date_to') ?? ''));
        if ($historyDateFrom !== '') {
            $historyOrders = array_values(array_filter($historyOrders, fn ($o) => $o['order_date'] >= $historyDateFrom));
        }
        if ($historyDateTo !== '') {
            $historyOrders = array_values(array_filter($historyOrders, fn ($o) => $o['order_date'] <= $historyDateTo));
        }

        foreach ($activeOrders as &$o) {
            $lines             = $this->soItemModel->forOrder($o['so_id']);
            $o['lines']        = $lines;
            $o['total_units']  = array_sum(array_column($lines, 'order_quantity'));
            $o['estimated']    = array_sum(array_map(fn ($l) => $l['order_quantity'] * $l['unit_price'], $lines));
            $o['primary_item'] = $lines[0]['item_name'] ?? null;
        }
        unset($o);

        $month = (int) ($this->request->getGet('month') ?? date('n'));
        $year  = (int) ($this->request->getGet('year') ?? date('Y'));
        if ($month < 1) { $month = 12; $year--; }
        if ($month > 12) { $month = 1; $year++; }

        // The calendar marks only active orders (the cards): a Cancelled (or supplier-declined,
        // stored as Cancelled) or Delivered order isn't a restock coming. An active order whose
        // expected date has passed is overdue (amber); one due today keeps the Today square
        // with a dot.
        $restockDates = [];
        $overdueDates = [];
        $today        = date('Y-m-d');
        foreach ($activeOrders as $o) {
            if (! empty($o['expected_delivery_date'])) {
                $due = date('Y-m-d', strtotime($o['expected_delivery_date']));
                if ($due < $today) {
                    $overdueDates[] = $due;
                } else {
                    $restockDates[] = $due;
                }
            }
        }

        // The shared purchase order dialog in "empty start" mode: the owner picks a supplier,
        // then adds that supplier's items. Each item's line is built like on Inventory and
        // Reorder Alerts (App\Libraries\PurchaseOrderLine), grouped by supplier_id.
        $suppliers = $this->supplierModel->where('is_active', 1)->findAll();
        $catalog   = $this->orderCatalog();

        // "Reorder" on a cancelled order opens that dialog with the same supplier, items,
        // quantities, unit prices and priority. Nothing is created until the owner submits it.
        $reorderPrefill = null;
        $reorderFrom    = trim((string) ($this->request->getGet('reorder') ?? ''));
        if ($reorderFrom !== '') {
            $source = $this->stockOrderModel->find($reorderFrom);
            if ($source && $source['status'] === 'Cancelled') {
                $reorderPrefill = $this->reorderPrefill($source, $catalog, array_column($suppliers, null, 'supplier_id'));
            }
        }

        $data = [
            'reorderPrefill' => $reorderPrefill,
            'title'          => 'Orders',
            'active'         => 'orders',
            'tab'            => $tab,
            'orders'          => $activeOrders,
            'historyOrders'   => $historyOrders,
            'historySearch'   => $historySearch,
            'historyDateFrom' => $historyDateFrom,
            'historyDateTo'   => $historyDateTo,
            'suppliers'       => $suppliers,
            'orderCatalog'    => $catalog,
            'followedUpToday' => $this->followedUpToday(),
            'statuses'        => StockOrderModel::STATUSES,
            'counts'         => $counts,
            'calMonth'       => $month,
            'calYear'        => $year,
            'restockDates'   => $restockDates,
            'overdueDates'   => $overdueDates,
            'historyCount'   => $historyCount,
            'success'        => session()->getFlashdata('success'),
            'error'          => session()->getFlashdata('error'),
        ];

        return view('admin/orders', $data);
    }

    /**
     * supplier_id => the purchase order lines of that supplier's active items, for the
     * Orders page dialog's item picker. Same numbers as Inventory (latest DSS run).
     *
     * @return array<string, list<array>>
     */
    private function orderCatalog(): array
    {
        $dss = [];
        foreach ((new PdssComputationModel())->latestPerItem() as $c) {
            $dss[$c['item_id']] = ['eoq_value' => $c['eoq_value'], 'safety_stock' => $c['safety_stock'], 'lead_time_days' => $c['lead_time_days']];
        }
        $supplierMap = array_column($this->supplierModel->findAll(), null, 'supplier_id');

        $products = $this->productModel->where('is_active', 1)->where('supplier_id IS NOT NULL')->where('supplier_id !=', '')->findAll();
        // By name, then sizes in their usual order (S, M, L, ...).
        $rank = array_flip(ProductModel::sortSizes(array_values(array_unique(array_map(static fn ($p) => (string) ($p['size'] ?? ''), $products)))));
        usort($products, static fn ($a, $b) => [strtolower((string) $a['item_name']), $rank[(string) ($a['size'] ?? '')] ?? 0]
            <=> [strtolower((string) $b['item_name']), $rank[(string) ($b['size'] ?? '')] ?? 0]);

        $catalog = [];
        foreach ($products as $p) {
            $catalog[$p['supplier_id']][] = PurchaseOrderLine::build($p + ($dss[$p['item_id']] ?? []), $supplierMap);
        }

        return $catalog;
    }

    /**
     * The dialog's data for "Reorder" on a cancelled order: its supplier, its lines with the
     * original quantity and unit price on top of today's numbers, its priority, and one
     * warning naming the items left out (archived, or now from another supplier).
     */
    private function reorderPrefill(array $source, array $catalog, array $activeSuppliers): array
    {
        $supplierId = (string) $source['supplier_id'];
        $byItem     = array_column($catalog[$supplierId] ?? [], null, 'item_id');
        $lines      = [];
        $leftOut    = [];

        foreach ($this->soItemModel->forOrder($source['so_id']) as $l) {
            if (isset($byItem[$l['item_id']])) {
                $lines[] = $byItem[$l['item_id']] + ['qty' => (int) $l['order_quantity'], 'price' => round((float) $l['unit_price'], 2)];
                continue;
            }
            $product   = $this->productModel->find($l['item_id']);
            $name      = $product ? ProductModel::nameWithSize($product) : ($l['item_name'] ?? $l['item_id']);
            $leftOut[] = $name . (! $product || ! $product['is_active'] ? ' (archived)' : ' (now from another supplier)');
        }

        $warning = null;
        if (! isset($activeSuppliers[$supplierId])) {
            $warning    = "The supplier of {$source['so_id']} is archived, so its items weren't added. Choose a supplier to start.";
            $lines      = [];
            $supplierId = '';
        } elseif ($leftOut) {
            $warning = 'Left out of this reorder: ' . implode(', ', $leftOut) . '.';
        }

        return [
            'from'        => $source['so_id'],
            'supplier_id' => $supplierId,
            'priority'    => in_array($source['priority'], StockOrderModel::PRIORITIES, true) ? $source['priority'] : 'Order',
            'lines'       => $lines,
            'warning'     => $warning,
        ];
    }

    /** The title of a follow-up notification; also how a second one on the same day is spotted. */
    public static function followUpTitle(string $soId): string
    {
        return "Follow-up on {$soId}";
    }

    /** so_id => true for the orders the owner already followed up on today. */
    private function followedUpToday(): array
    {
        $prefix = self::followUpTitle('');
        $rows   = $this->notificationModel
            ->select('title')
            ->where('recipient_role', 'Supplier')
            ->like('title', $prefix, 'after')
            ->where('created_at >=', date('Y-m-d 00:00:00'))
            ->findAll();

        $ids = [];
        foreach ($rows as $r) {
            $ids[substr($r['title'], strlen($prefix))] = true;
        }

        return $ids;
    }

    /**
     * The follow-up text, the same in the bell and the email. Before or on the due date it
     * asks for an update; after it, it says the order hasn't arrived.
     */
    public static function followUpMessage(array $order, ?string $today = null): string
    {
        $soId = $order['so_id'];
        $due  = $order['expected_delivery_date'] ?? null;
        if (empty($due)) {
            return "RfourL is asking for an update on {$soId}.";
        }
        $label = date('M j', strtotime($due));

        return date('Y-m-d', strtotime($due)) < ($today ?? date('Y-m-d'))
            ? "{$soId} was due {$label} and hasn't arrived. Please give an update."
            : "RfourL is asking for an update on {$soId} (due {$label}).";
    }

    /**
     * "Follow up": asks the supplier for an update on an open order. Nothing on the order
     * changes. At most once per order per day (spotted from today's notifications).
     */
    public function flagDelayed($soId)
    {
        $order = $this->stockOrderModel->findWithDetails((string) $soId);

        if (! $order) {
            return redirect()->to('/admin/orders')->with('toast', Toast::error("Couldn't follow up", 'Order not found.'));
        }
        if (in_array($order['status'], ['Delivered', 'Cancelled'], true)) {
            return redirect()->to('/admin/orders')->with('toast', Toast::error("Couldn't follow up", "Order {$soId} is already {$order['status']}."));
        }
        if (isset($this->followedUpToday()[$soId])) {
            return redirect()->to('/admin/orders')->with('toast', Toast::info("You already followed up on {$soId} today."));
        }

        $title        = self::followUpTitle($soId);
        $message      = self::followUpMessage($order);
        $supplierUser = $this->supplierUser($order['supplier_id']);

        $this->notificationModel->insert([
            'recipient_role' => 'Supplier',
            'recipient_id'   => $supplierUser['user_id'] ?? null,
            'type'           => 'Order Status',
            'category'       => 'order_status',
            'title'          => $title,
            'message'        => $message,
            'link_url'       => '/supplier/deliveries',
            'is_read'        => 0,
            'created_at'     => date('Y-m-d H:i:s'), // "today" in the app's time zone
        ]);
        $this->emailNotifier->toUser($supplierUser, $title, $message);

        return redirect()->to('/admin/orders')->with('toast', Toast::success('Follow-up sent to ' . ($order['company_name'] ?? 'the supplier') . '.'));
    }

    /**
     * The owner cancels an order. Only while the supplier has not shipped it
     * (Order Confirmed or Preparing). The reorder alerts linked to it go back to
     * Active (StockOrderModel::markCancelled). Stock on hand is never touched.
     */
    public function cancel($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order) {
            return redirect()->to('/admin/orders')->with('toast', Toast::error("Couldn't cancel", 'Order not found.'));
        }
        if (! StockOrderModel::canCancel($order)) {
            $why = $order['status'] === 'Shipped'
                ? 'It is already shipped. Contact the supplier to refuse or return it on delivery.'
                : "It is already {$order['status']}.";

            return redirect()->to('/admin/orders')->with('toast', Toast::error("Order {$soId} can't be cancelled", $why));
        }

        $choice = (string) $this->request->getPost('reason');
        if (! in_array($choice, StockOrderModel::CANCEL_REASONS, true)) {
            return redirect()->to('/admin/orders')->with('toast', Toast::error("Couldn't cancel", 'Please choose a reason.'));
        }

        $reason = $choice;
        if ($choice === 'Other') {
            $note = trim((string) $this->request->getPost('note'));
            if (mb_strlen($note) < 5 || mb_strlen($note) > 200) {
                return redirect()->to('/admin/orders')->with('toast', Toast::error("Couldn't cancel", 'Please add a note of 5 to 200 characters.'));
            }
            $reason = 'Other: ' . $note;
        }

        $wasPreparing = $order['status'] === 'Preparing';

        $this->stockOrderModel->markCancelled($soId, [
            'cancel_reason' => $reason,
            'cancelled_by'  => (int) session()->get('user_id'),
            'cancelled_at'  => date('Y-m-d H:i:s'),
        ]);

        $message = "Order {$soId} was cancelled by RfourL. Reason: {$reason}. Please don't prepare or ship it."
            . ($wasPreparing ? " We're sorry for any work already started." : '');
        $supplierUser = $this->supplierUser($order['supplier_id']);

        $this->notificationModel->push(
            'Supplier',
            'Order Status',
            "Order {$soId} was cancelled",
            $message,
            $supplierUser['user_id'] ?? null,
            'order_status',
            '/supplier/completed?range=all'
        );
        $this->emailNotifier->toUser($supplierUser, "Order {$soId} was cancelled", $message);

        SecurityLogModel::log('order_cancelled', null, "{$soId}: {$reason}");

        return redirect()->to('/admin/orders')->with('toast', Toast::success(
            "Order {$soId} cancelled",
            'Its reorder alerts are active again.',
            null,
            ['action' => ['label' => 'Reorder', 'url' => site_url('admin/orders?reorder=' . $soId)]]
        ));
    }

    public function show($soId)
    {
        $order = $this->stockOrderModel->findWithDetails($soId);

        if (! $order) {
            return redirect()->to('/admin/orders')->with('error', 'Order not found.');
        }

        $data = [
            'title'    => 'Order ' . $soId,
            'active'   => 'orders',
            'order'    => $order,
            'items'    => $this->soItemModel->forOrder($soId),
            'statuses' => StockOrderModel::STATUSES,
        ];

        return view('admin/order_detail', $data);
    }

    public function store()
    {
        $supplierId = $this->request->getPost('supplier_id');
        $itemIds    = $this->request->getPost('item_id') ?? [];
        $quantities = $this->request->getPost('quantity') ?? [];
        $prices     = $this->request->getPost('unit_price') ?? [];
        $alertId    = $this->request->getPost('alert_id');
        // The shared purchase order dialog says where to go back to: Inventory and Reorder
        // Alerts (pre-filled, automatic priority) or Orders (empty start, Priority chosen there).
        // Any other return_to is ignored and the order lands on Orders as before.
        $returnTo   = $this->request->getPost('return_to');
        $fromDialog = in_array($returnTo, ['/admin/reorder-alerts', '/admin/inventory'], true);
        $fromOrders = $returnTo === '/admin/orders';
        $fail       = fn (string $why) => ($fromOrders ? redirect()->to('/admin/orders') : redirect()->back())
            ->with('toast', Toast::error("Couldn't create order", $why));

        if (! $supplierId) {
            return $fail('Please select a supplier.');
        }

        $orderSupplier = $this->supplierModel->find($supplierId);
        if (! $orderSupplier || ! $orderSupplier['is_active']) {
            return $fail('This supplier is archived. Restore it before sending an order.');
        }

        $lines = [];
        foreach ($itemIds as $i => $itemId) {
            if (! $itemId) {
                continue;
            }
            $qty   = (int) ($quantities[$i] ?? 0);
            $price = (float) ($prices[$i] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $lines[] = ['item_id' => $itemId, 'qty' => $qty, 'price' => $price];
        }

        $priority = $this->request->getPost('priority') ?: 'Order';

        if ($fromOrders) {
            $error = $this->ordersPageError((string) $supplierId, $itemIds, $quantities, $prices);
            if ($error !== null) {
                return $fail($error);
            }
            if (! in_array($priority, StockOrderModel::PRIORITIES, true)) {
                $priority = 'Order';
            }
        }

        if (empty($lines)) {
            return redirect()->back()->with('toast', Toast::error("Couldn't create order", 'Add at least one item with a quantity greater than zero.'));
        }

        if ($fromDialog) {
            $back = $returnTo === '/admin/inventory' ? $this->inventoryUrl() : '/admin/reorder-alerts';
            // Every size needs a quantity of 1 or more and a unit cost above ₱0 (the dialog checks this too).
            if (count(array_filter($itemIds)) !== count($lines)) {
                return redirect()->to($back)->with('toast', Toast::error("Couldn't create order", 'Enter a quantity of 1 or more for every size.'));
            }
            foreach ($lines as $line) {
                if (! ($line['price'] > 0)) {
                    return redirect()->to($back)->with('toast', Toast::error("Couldn't create order", 'Enter a unit cost above ₱0 for every size.'));
                }
            }
            $ordered = $this->productModel->whereIn('item_id', array_column($lines, 'item_id'))->findAll();
            // A size is only ever ordered from its own supplier ("Order all sizes" groups by supplier).
            foreach ($ordered as $p) {
                if (! empty($p['supplier_id']) && $p['supplier_id'] !== $supplierId) {
                    return redirect()->to($back)->with('toast', Toast::error("Couldn't create order", ProductModel::nameWithSize($p) . ' comes from another supplier.'));
                }
            }
            // The dialog has no Priority field: Urgent when any ordered size has nothing on hand
            // right now (read here, not from the page), otherwise the default. A posted value is ignored.
            $priority = self::dialogPriority($ordered);
        }

        // The alert the order was opened from, read before the pending alerts below become
        // Ordered. Only a pending one is linked: a dismissed (or already ordered) alert stays as it is.
        $alert = $alertId ? $this->reorderAlertModel->find((int) $alertId) : null;
        if ($alert && ! in_array($alert['status'], ReorderAlertModel::PENDING_STATUSES, true)) {
            $alert = null;
        }

        $soId = $this->stockOrderModel->generateNextId();

        $db = db_connect();
        $db->transStart();

        $this->stockOrderModel->insert([
            'so_id'                   => $soId,
            'supplier_id'             => $supplierId,
            'user_id'                 => session()->get('user_id'),
            'priority'                => $priority,
            'status'                  => 'Order Confirmed',
            'order_date'              => date('Y-m-d'),
            'expected_delivery_date'  => $this->request->getPost('expected_delivery_date') ?: null,
            'tracking_no'             => null, // the supplier enters it when shipping (Supplier → Deliveries)
        ]);

        foreach ($lines as $line) {
            $this->soItemModel->insert([
                'so_id'          => $soId,
                'item_id'        => $line['item_id'],
                'order_quantity' => $line['qty'],
                'unit_price'     => $line['price'],
            ]);
        }

        // Any pending alert for an ordered item is now covered by this order,
        // whichever page the order came from; delivery marks it Fulfilled.
        $this->reorderAlertModel
            ->whereIn('item_id', array_column($lines, 'item_id'))
            ->whereIn('status', ReorderAlertModel::PENDING_STATUSES)
            ->set(['status' => 'Ordered', 'so_id' => $soId])
            ->update();
        // Staff "Owner notified" on those items is answered by this order.
        (new ReorderNoticeModel())->clear(array_column($lines, 'item_id'), 'ordered');

        $product = null;
        if ($alert) {
            $product = $this->productModel->find($alert['item_id']);
            $this->reorderAlertModel->update((int) $alert['alert_id'], ['status' => 'Ordered', 'so_id' => $soId]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return redirect()->back()->with('toast', Toast::error("Couldn't create order", 'Something went wrong while saving. Please try again.'));
        }

        if ($alert) {
            // Closes the loop back to whoever flagged this item — staff share
            // a single portal login, so this broadcasts to all Staff rather
            // than one specific account.
            $this->notificationModel->push(
                'Staff',
                'Order Status',
                "Order placed for {$soId}",
                'The reorder you flagged for ' . ($product['item_name'] ?? 'an item') . " has been ordered as {$soId}.",
                null,
                'staff_activity',
                '/staff/reorder-alerts'
            );
        }

        $newOrderSupplierUser = $this->supplierUser($supplierId);

        $this->notificationModel->push(
            'Supplier',
            'Order Status',
            "New order {$soId}",
            "A new stock order {$soId} has been placed. Check New Orders to accept it.",
            $newOrderSupplierUser['user_id'] ?? null,
            'order_status',
            '/supplier/new-orders'
        );

        $this->emailNotifier->toUser(
            $newOrderSupplierUser,
            "New stock order {$soId}",
            "A new stock order {$soId} has been placed. Please log in to the supplier portal to review and accept it."
        );

        if ($fromDialog) {
            $sizes = count($lines);
            // Reorder Alerts: its Ordered tab. Inventory: the same page, filters and search.
            $url = $returnTo === '/admin/inventory' ? $this->inventoryUrl() : '/admin/reorder-alerts?tab=ordered';

            return redirect()->to($url)->with('toast', Toast::success(
                'Order created',
                'was sent to ' . $orderSupplier['company_name'] . ($sizes > 1 ? " ({$sizes} sizes)." : '.'),
                $soId,
                ['action' => ['label' => 'View order', 'url' => site_url('admin/orders/' . $soId)]]
            ));
        }

        if ($fromOrders) {
            return redirect()->to('/admin/orders')->with('toast', Toast::success(
                'Order created',
                'was sent to ' . $orderSupplier['company_name'] . '.',
                $soId,
                ['action' => ['label' => 'View order', 'url' => site_url('admin/orders/' . $soId)]]
            ));
        }

        return redirect()->to('/admin/orders')->with('toast', Toast::success('Order created', 'was sent to the supplier.', $soId));
    }

    /**
     * What's wrong with an order from the Orders page dialog, or null: at least one line,
     * every quantity 1 or more, every unit cost above ₱0, and every item active and from the
     * chosen supplier. (The supplier itself is checked above for every order.)
     */
    private function ordersPageError(string $supplierId, array $itemIds, array $quantities, array $prices): ?string
    {
        $ids = array_values(array_filter(array_map('strval', $itemIds), static fn ($id) => $id !== ''));
        if ($ids === []) {
            return 'Add at least 1 item.';
        }
        foreach ($itemIds as $i => $id) {
            if ((string) $id === '') {
                continue;
            }
            $qty = trim((string) ($quantities[$i] ?? ''));
            if (! ctype_digit($qty) || (int) $qty < 1) {
                return 'Enter a quantity of 1 or more for every item.';
            }
            if (! is_numeric($prices[$i] ?? null) || ! ((float) $prices[$i] > 0)) {
                return 'Enter a unit cost above ₱0 for every item.';
            }
        }
        $products = array_column($this->productModel->whereIn('item_id', $ids)->findAll(), null, 'item_id');
        foreach ($ids as $id) {
            $p = $products[$id] ?? null;
            if (! $p || ! $p['is_active']) {
                return ($p ? ProductModel::nameWithSize($p) : $id) . ' is archived. Remove it from the order.';
            }
            if ((string) $p['supplier_id'] !== $supplierId) {
                return ProductModel::nameWithSize($p) . " isn't from this supplier.";
            }
        }

        return null;
    }

    /**
     * Priority for an order from the purchase order dialog (stock_order.priority is
     * enum('Urgent', 'Order', 'Planned')): 'Urgent' if any ordered size has 0 on hand,
     * otherwise the default 'Order'.
     *
     * @param list<array> $products the ordered sizes' products rows, as stored now
     */
    public static function dialogPriority(array $products): string
    {
        foreach ($products as $p) {
            if ((int) $p['current_stock'] <= 0) {
                return 'Urgent';
            }
        }

        return 'Order';
    }

    /**
     * The Inventory list the order was placed from (category, search, size, status, page,
     * rows per page), rebuilt from the return_query the dialog posts. Only those parameters
     * are kept, as in InventoryController::listUrl().
     */
    private function inventoryUrl(): string
    {
        parse_str(ltrim((string) $this->request->getPost('return_query'), '?'), $params);
        $params = array_filter(array_intersect_key($params, array_flip(['category', 'search', 'size', 'status', 'page', 'per_page'])), static fn ($v) => is_string($v) && $v !== '');

        return '/admin/inventory' . ($params ? '?' . http_build_query($params) : '');
    }

    public function updateStatus($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order) {
            return redirect()->to('/admin/orders')->with('error', 'Order not found.');
        }

        $newStatus = $this->request->getPost('status');

        if (! in_array($newStatus, StockOrderModel::STATUSES, true)) {
            return redirect()->to('/admin/orders')->with('error', 'Invalid status.');
        }

        // Cancelling has its own rules and a reason (see cancel()), so it can't be done from here.
        if ($newStatus === 'Cancelled') {
            return redirect()->to('/admin/orders')->with('error', 'Use Cancel order to cancel an order.');
        }
        // A cancelled order stays cancelled; a new order is a new order.
        if ($order['status'] === 'Cancelled') {
            return redirect()->to('/admin/orders')->with('error', "Order {$soId} was cancelled and can't be changed.");
        }

        if ($newStatus === 'Delivered' && $order['status'] !== 'Delivered') {
            $this->stockOrderModel->markDelivered($soId, session()->get('user_id'));
        } else {
            $this->stockOrderModel->update($soId, ['status' => $newStatus]);
        }

        if ($newStatus !== $order['status']) {
            $statusSupplierUser = $this->supplierUser($order['supplier_id']);

            $this->notificationModel->push(
                'Supplier',
                'Order Status',
                "Order {$soId} updated to {$newStatus}",
                "The owner manually set order {$soId} to {$newStatus}.",
                $statusSupplierUser['user_id'] ?? null,
                'order_status',
                '/supplier/deliveries'
            );

            $this->emailNotifier->toUser(
                $statusSupplierUser,
                "Order {$soId} updated to {$newStatus}",
                "The owner manually set order {$soId} to {$newStatus}. Please log in to the supplier portal for details."
            );
        }

        return redirect()->to('/admin/orders')->with('success', "Order {$soId} marked as {$newStatus}.");
    }
}
