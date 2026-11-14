<?php

use App\Libraries\ProcurementDocs;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Procurement documents: PO / SO / DR numbers, received_by / received_at, the three PDFs and who may
 * open them, the order timeline, the "Documents" links, and the optional OR number at POS checkout.
 * Status rules and stock logic are not touched here. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class ProcurementDocsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private int $supplierUserId;
    private int $otherSupplierUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $when = ['type' => 'DATETIME', 'null' => true];
        $make = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $make('sales_transaction', ['sales_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => $when, 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real], 'sales_id');
        $make('sales_item', ['sales_item_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real, 'is_bulk' => ['type' => 'INTEGER', 'default' => 0]], 'sales_item_id');
        $make('inventory_log', ['log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => $when], 'log_id');
        $make('app_settings', ['key' => $str, 'value' => $str], 'key');
        $forge->addColumn('suppliers', ['preferred_courier' => $str]);

        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['contact_person' => 'Mario Reyes', 'contact_email' => 'sales@tgi.test', 'contact_number' => '0917 555 0101', 'preferred_courier' => 'LBC Express']);
        // The receipts tab filters by month with MySQL's DATE_FORMAT; SQLite needs a stand-in.
        $this->db->connID->createFunction('DATE_FORMAT', static fn ($date, $format) => $format === '%Y-%m' ? substr((string) $date, 0, 7) : (string) $date);
        $this->supplierUserId = (int) $this->db->table('users')->where('username', 'tgi')->get()->getRow()->user_id;
        $this->db->table('users')->insert(['username' => 'qpc', 'full_name' => 'Quiapo Patches', 'role' => 'Supplier', 'supplier_id' => 'SUP-002', 'email' => 'qpc@example.test', 'password_hash' => 'x']);
        $this->otherSupplierUserId = (int) $this->db->insertID();

        $this->addSize('0290', 'Tactical Pants', 'S', 5, 2, ['selling_price' => 350]);
        $this->addSize('0291', 'Combat Shirt', 'M', 9, 2, ['selling_price' => 400]);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** A stock order (supplier SUP-001, two lines) in the given status, numbered the way that status would be. */
    private function order(string $soId, string $status = 'Order Confirmed', array $extra = [], string $supplier = 'SUP-001'): void
    {
        $this->db->table('stock_order')->insert($extra + [
            'so_id' => $soId, 'supplier_id' => $supplier, 'user_id' => $this->ownerId, 'priority' => 'Order', 'status' => $status,
            'order_date' => date('Y-m-d'), 'expected_delivery_date' => '2026-12-05', 'tracking_no' => null,
            'po_number' => ProcurementDocs::nextNumber('po', null, $this->db),
        ]);
        $this->db->table('so_item')->insert(['so_id' => $soId, 'item_id' => '0290', 'order_quantity' => 12, 'unit_price' => 52.5, 'shipped_quantity' => in_array($status, ['Shipped', 'Delivered'], true) ? 10 : null]);
        $this->db->table('so_item')->insert(['so_id' => $soId, 'item_id' => '0291', 'order_quantity' => 4, 'unit_price' => 80, 'shipped_quantity' => in_array($status, ['Shipped', 'Delivered'], true) ? 4 : null]);
        ProcurementDocs::ensureNumbers($soId);
    }

    private function row(string $soId): array
    {
        return $this->db->table('stock_order')->where('so_id', $soId)->get()->getRowArray();
    }

    private function asSupplier(bool $other = false): self
    {
        return $this->withSession([
            'isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => $other ? $this->otherSupplierUserId : $this->supplierUserId,
            'username' => $other ? 'qpc' : 'tgi', 'full_name' => $other ? 'Quiapo Patches' : 'Tactical Gear', 'supplier_id' => $other ? 'SUP-002' : 'SUP-001', 'last_activity' => time(),
        ]);
    }

    /** @return array<string,string> the order's own columns, for the first order created */
    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function assertPdf($result): void
    {
        $result->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', $result->response()->getBody());
    }

    // ---------------------------------------------------------------- numbers

    public function testAnOrderGetsAPoNumberWhenItIsPlaced(): void
    {
        $year = date('Y');
        foreach ([1, 2] as $n) {
            $this->asOwner()->post('admin/orders/store', ['supplier_id' => 'SUP-001', 'item_id' => ['0290'], 'quantity' => ['10'], 'unit_price' => ['50']]);
        }

        $numbers = array_column($this->db->table('stock_order')->orderBy('so_id')->get()->getResultArray(), 'po_number');

        $this->assertSame(["PO-{$year}-0001", "PO-{$year}-0002"], $numbers, 'PO-YYYY-#### and each one different');
        $this->assertNull($this->row('SO-0001')['so_number'], 'No SO or DR number yet.');
        $this->assertNull($this->row('SO-0001')['dr_number']);
    }

    public function testTheSupplierAcceptingGivesTheSoNumberAndShippingGivesTheDrNumber(): void
    {
        $year = date('Y');
        $this->order('SO-0001');
        $this->order('SO-0002');

        $this->asSupplier()->post('supplier/new-orders/accept/SO-0001');
        $this->asSupplier()->post('supplier/new-orders/accept/SO-0002');

        $one = $this->row('SO-0001');
        $two = $this->row('SO-0002');
        $this->assertSame('Preparing', $one['status']);
        $this->assertSame("SO-{$year}-0001", $one['so_number']);
        $this->assertSame("SO-{$year}-0002", $two['so_number'], 'Unique, counting up.');
        $this->assertNotNull($one['accepted_at']);
        $this->assertNull($one['dr_number'], 'Not shipped yet.');

        $this->asSupplier()->post('supplier/deliveries/ship/SO-0001', ['tracking_no' => 'LBC-998', 'shipped_qty' => []]);

        $shipped = $this->row('SO-0001');
        $this->assertSame('Shipped', $shipped['status']);
        $this->assertSame("DR-{$year}-0001", $shipped['dr_number']);
        $this->assertNotNull($shipped['shipped_at']);
        $this->assertSame($one['so_number'], $shipped['so_number'], 'A number never changes once given.');
        $this->assertNull($this->row('SO-0002')['dr_number']);
    }

    public function testNumbersCountUpPerKindAndPerYear(): void
    {
        $this->assertSame('PO-2026-0001', ProcurementDocs::nextNumber('po', 2026, $this->db));
        $this->db->table('stock_order')->insert(['so_id' => 'SO-0001', 'supplier_id' => 'SUP-001', 'status' => 'Order Confirmed', 'po_number' => 'PO-2026-0009', 'so_number' => 'SO-2026-0003']);

        $this->assertSame('PO-2026-0010', ProcurementDocs::nextNumber('po', 2026, $this->db));
        $this->assertSame('PO-2027-0001', ProcurementDocs::nextNumber('po', 2027, $this->db), 'A new year starts again at 0001.');
        $this->assertSame('SO-2026-0004', ProcurementDocs::nextNumber('so', 2026, $this->db));
        $this->assertSame('DR-2026-0001', ProcurementDocs::nextNumber('dr', 2026, $this->db));
    }

    public function testTheOwnerMovingAnOrderForwardByHandStillNumbersIt(): void
    {
        $this->order('SO-0001', 'Order Confirmed');

        $this->asOwner()->post('admin/orders/update-status/SO-0001', ['status' => 'Shipped']);

        $row = $this->row('SO-0001');
        $this->assertSame('Shipped', $row['status']);
        $this->assertMatchesRegularExpression('/^SO-\d{4}-\d{4}$/', (string) $row['so_number']);
        $this->assertMatchesRegularExpression('/^DR-\d{4}-\d{4}$/', (string) $row['dr_number']);
    }

    public function testStaffRestockConfirmRecordsWhoReceivedItAndWhen(): void
    {
        $this->order('SO-0001', 'Shipped');

        $this->asStaff()->post('staff/log-transaction/restock-confirm/SO-0001');

        $row = $this->row('SO-0001');
        $this->assertSame('Delivered', $row['status']);
        $this->assertSame($this->staffId, (int) $row['received_by']);
        $this->assertNotEmpty($row['received_at']);
        $this->assertSame(15, (int) $this->db->table('products')->where('item_id', '0290')->get()->getRow()->current_stock, 'Stock logic unchanged: 5 on hand + 10 shipped.');
    }

    public function testTheOwnersMarkAsDoneRecordsWhoReceivedItAndWhen(): void
    {
        $this->order('SO-0001', 'Shipped');

        $this->asOwner()->post('admin/orders/update-status/SO-0001', ['status' => 'Delivered']);

        $row = $this->row('SO-0001');
        $this->assertSame('Delivered', $row['status']);
        $this->assertSame($this->ownerId, (int) $row['received_by']);
        $this->assertNotEmpty($row['received_at']);
    }

    public function testTheBackfillNumbersExistingOrdersFromTheirStatus(): void
    {
        $insert = fn (string $id, string $status, string $date) => $this->db->table('stock_order')->insert(['so_id' => $id, 'supplier_id' => 'SUP-001', 'status' => $status, 'order_date' => $date]);
        $insert('SO-0001', 'Delivered', '2025-12-30');
        $insert('SO-0002', 'Order Confirmed', '2026-01-05');
        $insert('SO-0003', 'Shipped', '2026-02-01');
        $insert('SO-0004', 'Preparing', '2026-03-01');
        $insert('SO-0005', 'Cancelled', '2026-04-01');

        ProcurementDocs::backfill($this->db);

        $by = array_column($this->db->table('stock_order')->get()->getResultArray(), null, 'so_id');
        $this->assertSame('PO-2025-0001', $by['SO-0001']['po_number']);
        $this->assertSame('SO-2025-0001', $by['SO-0001']['so_number']);
        $this->assertSame('DR-2025-0001', $by['SO-0001']['dr_number']);
        $this->assertSame(['PO-2026-0001', null, null], [$by['SO-0002']['po_number'], $by['SO-0002']['so_number'], $by['SO-0002']['dr_number']], 'Only confirmed: a PO and nothing more.');
        $this->assertSame(['PO-2026-0002', 'SO-2026-0001', 'DR-2026-0001'], [$by['SO-0003']['po_number'], $by['SO-0003']['so_number'], $by['SO-0003']['dr_number']]);
        $this->assertSame(['PO-2026-0003', 'SO-2026-0002', null], [$by['SO-0004']['po_number'], $by['SO-0004']['so_number'], $by['SO-0004']['dr_number']], 'Preparing: PO and SO.');
        $this->assertSame(['PO-2026-0004', null, null], [$by['SO-0005']['po_number'], $by['SO-0005']['so_number'], $by['SO-0005']['dr_number']], 'Cancelled: how far it got is unknown, so a PO only.');

        $before = $by;
        ProcurementDocs::backfill($this->db);
        $this->assertSame($before, array_column($this->db->table('stock_order')->get()->getResultArray(), null, 'so_id'), 'Running it again changes nothing.');

        foreach (['po_number', 'so_number', 'dr_number'] as $column) {
            $values = array_filter(array_column($by, $column));
            $this->assertSame(count($values), count(array_unique($values)), "{$column} is unique");
        }
    }

    // ---------------------------------------------------------------- the PDFs

    public function testEachPdfRendersWithTheOrdersData(): void
    {
        $this->order('SO-0001', 'Delivered', ['tracking_no' => 'LBC-998', 'received_by' => $this->staffId, 'received_at' => '2026-12-04 14:30:00', 'accepted_at' => '2026-11-14 09:00:00', 'shipped_at' => '2026-12-01 10:15:00']);
        $order = $this->row('SO-0001');

        $po = view('procurement/po', ProcurementDocs::documentData($order, 'po'));
        foreach ([$order['po_number'], 'PURCHASE ORDER', 'RFOURL MILITARY SUPPLY', 'Tactical Gear Inc', 'Mario Reyes', 'Tactical Pants', 'Combat Shirt', '₱52.50', '₱630.00', '₱320.00', '₱950.00', 'Dec 5, 2026', 'Owner One', 'Prepared by'] as $text) {
            $this->assertStringContainsString($text, $po, "PO shows {$text}");
        }
        $this->assertStringContainsString('>S<', preg_replace('/\s+/', '', $po) ?: '', 'The size column shows the size.');

        $so = view('procurement/so', ProcurementDocs::documentData($order, 'so'));
        foreach ([$order['so_number'], 'SALES ORDER', $order['po_number'], 'Tactical Pants', '12', 'Dec 5, 2026', 'Nov 14, 2026 9:00 AM', 'Tactical Gear Inc'] as $text) {
            $this->assertStringContainsString($text, $so, "SO shows {$text}");
        }

        $dr = view('procurement/dr', ProcurementDocs::documentData($order, 'dr'));
        foreach ([$order['dr_number'], 'DELIVERY RECEIPT', $order['po_number'], $order['so_number'], 'LBC Express', 'LBC-998', 'Qty shipped', 'Qty received'] as $text) {
            $this->assertStringContainsString($text, $dr, "DR shows {$text}");
        }
        $this->assertStringContainsString('Ana Cruz', $dr, 'Received by');
        $this->assertStringContainsString('Dec 4, 2026 2:30 PM', $dr, 'Received date and time');
        $this->assertStringContainsString('signature', $dr, 'A signature line');

        foreach (['po', 'so', 'dr'] as $type) {
            $this->assertPdf($this->asOwner()->get("admin/orders/SO-0001/document/{$type}"));
        }
    }

    public function testTheDeliveryReceiptHasNoReceivedColumnBeforeReceipt(): void
    {
        $this->order('SO-0001', 'Shipped');

        $dr = view('procurement/dr', ProcurementDocs::documentData($this->row('SO-0001'), 'dr'));

        $this->assertStringContainsString('Qty shipped', $dr);
        $this->assertStringNotContainsString('Qty received', $dr);
        $this->assertStringContainsString('signature', $dr, 'The signature line is there to be signed on arrival.');
    }

    public function testADocumentTheOrderHasNotReachedIsNotAvailable(): void
    {
        $this->order('SO-0001', 'Order Confirmed');

        $so = $this->asOwner()->get('admin/orders/SO-0001/document/so');
        $dr = $this->asOwner()->get('admin/orders/SO-0001/document/dr');
        $po = $this->asOwner()->get('admin/orders/SO-0001/document/po');
        $nonsense = $this->asOwner()->get('admin/orders/SO-0001/document/xx');

        $so->assertRedirect();
        $dr->assertRedirect();
        $nonsense->assertRedirect();
        $this->assertPdf($po);
        $this->assertSame(['po'], ProcurementDocs::available($this->row('SO-0001')));
    }

    // ---------------------------------------------------------------- who may open them

    public function testASupplierOpensTheirOwnDocumentsOnly(): void
    {
        $this->order('SO-0001', 'Preparing');
        $this->order('SO-0002', 'Preparing', [], 'SUP-002');

        foreach (['po', 'so'] as $type) {
            $this->assertPdf($this->asSupplier()->get("supplier/orders/SO-0001/document/{$type}"));
        }

        foreach (['po', 'so'] as $type) {
            $theirs = $this->asSupplier()->get("supplier/orders/SO-0002/document/{$type}");
            $theirs->assertRedirectTo(site_url('supplier/dashboard'));
            $this->assertStringNotContainsString('%PDF', (string) $theirs->response()->getBody());
        }
        $this->assertSame('Order not found.', $_SESSION['error'] ?? null);
        // the other supplier does see it
        $this->assertPdf($this->asSupplier(true)->get('supplier/orders/SO-0002/document/po'));
    }

    public function testStaffOpenTheDocumentsOfOrdersOnRestockOnly(): void
    {
        $this->order('SO-0001', 'Shipped');
        $this->order('SO-0002', 'Order Confirmed');

        foreach (['po', 'so', 'dr'] as $type) {
            $this->assertPdf($this->asStaff()->get("staff/orders/SO-0001/document/{$type}"));
        }
        $this->asStaff()->get('staff/orders/SO-0002/document/po')->assertRedirectTo(site_url('staff/log-transaction/restock'));
    }

    public function testTheOwnerOpensAnyOrdersDocumentsAndEveryoneElseIsKeptOut(): void
    {
        $this->order('SO-0001', 'Shipped');
        $this->order('SO-0002', 'Shipped', [], 'SUP-002');

        $this->assertPdf($this->asOwner()->get('admin/orders/SO-0002/document/dr'));

        // staff and suppliers cannot use the owner's route; nobody signed in is sent to log in
        $this->asStaff()->get('admin/orders/SO-0001/document/po')->assertRedirectTo(site_url('login/admin'));
        $this->asSupplier()->get('admin/orders/SO-0001/document/po')->assertRedirect();
        $this->asOwner()->get('supplier/orders/SO-0001/document/po')->assertRedirect();
        $this->withSession([])->get('admin/orders/SO-0001/document/po')->assertRedirectTo(site_url('login/admin'));
        $this->withSession([])->get('supplier/orders/SO-0001/document/po')->assertRedirect();
    }

    // ---------------------------------------------------------------- the timeline and the links

    private function steps(string $soId): array
    {
        $html = (string) $this->asOwner()->get('admin/orders/' . $soId)->response()->getBody();
        $x    = $this->html($html);
        $out  = [];
        foreach ($x->query('//li[contains(@class,"proc-step")]') as $li) {
            $out[$li->getAttribute('data-step')] = [
                'state' => $li->getAttribute('data-state'),
                'text'  => trim(preg_replace('/\s+/', ' ', $li->textContent)),
                'pdf'   => $x->query('.//a[contains(@href,"/document/")]', $li)->length ? $x->query('.//a[contains(@href,"/document/")]', $li)->item(0)->getAttribute('href') : null,
            ];
        }

        return $out;
    }

    public function testTheTimelineShowsAReceivedOrderAsFourDoneSteps(): void
    {
        $this->order('SO-0001', 'Delivered', ['received_by' => $this->staffId, 'received_at' => '2026-12-04 14:30:00', 'accepted_at' => '2026-11-14 09:00:00', 'shipped_at' => '2026-12-01 10:15:00']);
        $row   = $this->row('SO-0001');
        $steps = $this->steps('SO-0001');

        $this->assertSame(['po', 'so', 'dr', 'received'], array_keys($steps), 'PO -> SO -> DR -> Received');
        foreach ($steps as $s) {
            $this->assertSame('done', $s['state']);
            $this->assertStringContainsString('View PDF', $s['text']);
        }
        $this->assertStringContainsString($row['po_number'], $steps['po']['text']);
        $this->assertStringContainsString('Prepared by Owner One', $steps['po']['text']);
        $this->assertStringContainsString('Accepted by Tactical Gear Inc', $steps['so']['text']);
        $this->assertStringContainsString('Nov 14, 2026 9:00 AM', $steps['so']['text']);
        $this->assertStringContainsString('Dec 1, 2026 10:15 AM', $steps['dr']['text']);
        $this->assertStringContainsString($row['dr_number'], $steps['dr']['text']);
        $this->assertStringContainsString('Received by Ana Cruz', $steps['received']['text']);
        $this->assertStringContainsString('Dec 4, 2026 2:30 PM', $steps['received']['text']);
        $this->assertStringEndsWith('/admin/orders/SO-0001/document/po', $steps['po']['pdf']);
        $this->assertStringEndsWith('/admin/orders/SO-0001/document/dr', $steps['received']['pdf'], 'The Received step opens the delivery receipt with the receipt details.');
    }

    public function testStepsNotReachedAreGreyAndHaveNoPdf(): void
    {
        $this->order('SO-0001', 'Preparing');
        $steps = $this->steps('SO-0001');

        $this->assertSame(['done', 'done', 'pending', 'pending'], array_column($steps, 'state'));
        $this->assertNull($steps['dr']['pdf']);
        $this->assertNull($steps['received']['pdf']);
        $this->assertStringNotContainsString('View PDF', $steps['dr']['text']);
        $this->assertStringNotContainsString('DR-', $steps['dr']['text']);
    }

    public function testACancelledOrderShowsWhereItStoppedAndWhy(): void
    {
        $this->order('SO-0001', 'Cancelled', ['cancelled_by' => $this->ownerId, 'cancelled_at' => '2026-11-15 08:00:00', 'cancel_reason' => 'Ordered by mistake']);
        $this->order('SO-0002', 'Preparing');
        $this->db->table('stock_order')->where('so_id', 'SO-0002')->update(['status' => 'Cancelled', 'cancelled_by' => $this->ownerId, 'cancelled_at' => '2026-11-15 08:00:00', 'cancel_reason' => 'No longer needed']);

        $before = $this->steps('SO-0001');
        $this->assertSame(['done', 'stopped', 'pending', 'pending'], array_column($before, 'state'), 'Cancelled before the supplier accepted: stops at the Sales Order.');
        $this->assertStringContainsString('Stopped here', $before['so']['text']);
        $this->assertStringContainsString('Ordered by mistake', $before['so']['text']);
        $this->assertNull($before['so']['pdf']);

        $after = $this->steps('SO-0002');
        $this->assertSame(['done', 'done', 'stopped', 'pending'], array_column($after, 'state'), 'Accepted, then cancelled: stops at the Delivery Receipt.');
        $this->assertStringContainsString('No longer needed', $after['dr']['text']);
    }

    public function testDocumentsLinksOnOrderCardsSupplierRowsAndRestock(): void
    {
        $this->order('SO-0001', 'Preparing');
        $this->order('SO-0002', 'Shipped');

        $orders = (string) $this->asOwner()->get('admin/orders')->response()->getBody();
        $this->assertStringContainsString(site_url('admin/orders/SO-0001') . '#documents', $orders);
        $this->assertStringContainsString(site_url('admin/orders/SO-0002') . '#documents', $orders);

        $deliveries = (string) $this->asSupplier()->get('supplier/deliveries')->response()->getBody();
        foreach (['po', 'so'] as $type) {
            $this->assertStringContainsString(site_url("supplier/orders/SO-0001/document/{$type}"), $deliveries);
        }
        $this->assertStringNotContainsString('supplier/orders/SO-0001/document/dr', $deliveries, 'Not shipped yet: no DR link.');
        $this->assertStringContainsString(site_url('supplier/orders/SO-0002/document/dr'), $deliveries);

        $restock = (string) $this->asStaff()->get('staff/log-transaction/restock')->response()->getBody();
        foreach (['po', 'so', 'dr'] as $type) {
            $this->assertStringContainsString(site_url("staff/orders/SO-0002/document/{$type}"), $restock);
        }
    }

    // ---------------------------------------------------------------- the PO number is the order's name

    public function testThePoNumberIsTheMainLabelOnEveryScreen(): void
    {
        $this->order('SO-0001', 'Shipped');
        $po = $this->row('SO-0001')['po_number'];

        $detail = $this->html((string) $this->asOwner()->get('admin/orders/SO-0001')->response()->getBody());
        $this->assertSame($po, trim($detail->query('//h2')->item(0)->textContent), 'Order detail title');

        $orders = (string) $this->asOwner()->get('admin/orders')->response()->getBody();
        $this->assertStringContainsString($po . ' &middot; Tactical Gear Inc', $orders, 'Order card');
        $this->assertStringNotContainsString('#SO-0001', $orders);
        $this->assertStringContainsString('Confirm Delivery &mdash; ' . $po, $orders);

        $restock = (string) $this->asStaff()->get('staff/log-transaction/restock')->response()->getBody();
        $this->assertStringContainsString($po . ' &middot;', $restock);
        $this->assertStringNotContainsString('#SO-0001', $restock);

        $deliveries = (string) $this->asSupplier()->get('supplier/deliveries')->response()->getBody();
        $this->assertStringContainsString('>' . $po . '<', $deliveries);
        $this->assertStringNotContainsString('#SO-0001', $deliveries);
    }

    public function testAnOrderWithoutAPoNumberStillShowsItsOldCode(): void
    {
        $this->order('SO-0001', 'Order Confirmed');
        $this->db->table('stock_order')->where('so_id', 'SO-0001')->update(['po_number' => null]);

        $detail = $this->html((string) $this->asOwner()->get('admin/orders/SO-0001')->response()->getBody());

        $this->assertSame('SO-0001', trim($detail->query('//h2')->item(0)->textContent));
    }

    public function testMessagesNameTheOrderByItsPoNumberButLinkByItsCode(): void
    {
        $this->order('SO-0001', 'Order Confirmed');
        $po = $this->row('SO-0001')['po_number'];

        $this->asSupplier()->post('supplier/new-orders/accept/SO-0001');

        $note = $this->db->table('notifications')->where('recipient_role', 'Admin')->get()->getRowArray();
        $this->assertStringContainsString($po, $note['title']);
        $this->assertStringContainsString($po, $note['message']);
        $this->assertStringNotContainsString('SO-0001', $note['title'] . $note['message']);
        $this->assertStringContainsString('/admin/orders/SO-0001', $note['link_url'], 'The link still uses the internal code.');
        $this->assertStringContainsString($po, (string) ($_SESSION['success'] ?? ''));
    }

    public function testASearchFindsAnOrderByPoNumberOrOldCode(): void
    {
        $this->order('SO-0001', 'Order Confirmed');
        $po = $this->row('SO-0001')['po_number'];

        foreach ([$po, 'so-0001'] as $term) {
            $html = (string) $this->asSupplier()->get('supplier/new-orders?search=' . urlencode($term))->response()->getBody();
            $this->assertStringContainsString('>' . $po . '<', $html, "Found by {$term}");
        }
    }

    public function testThePdfDetailsTablesGiveTheValuesAWideColumnThatDoesNotWrap(): void
    {
        $this->order('SO-0001', 'Delivered', ['received_by' => $this->staffId, 'received_at' => '2026-12-04 14:30:00']);
        $order = $this->row('SO-0001');

        foreach (['po', 'so', 'dr'] as $type) {
            $html = view('procurement/' . $type, ProcurementDocs::documentData($order, $type));
            $x    = $this->html($html);

            $this->assertStringContainsString('.kv td.v { width: 70%; white-space: nowrap; }', $html);
            $this->assertStringContainsString('.kv td.k { color: #555; width: 30%; white-space: nowrap; }', $html);
            foreach ($x->query('//table[contains(@class,"kv")]//tr') as $tr) {
                $this->assertSame(2, $x->query('./td', $tr)->length, 'One label and one value per row.');
                $this->assertSame('v', $x->query('./td', $tr)->item(1)->getAttribute('class'));
            }
        }
        $dr = $this->html(view('procurement/dr', ProcurementDocs::documentData($order, 'dr')));
        $this->assertSame(['DR no.', 'PO reference', 'SO reference', 'Courier', 'Tracking no.', 'Received by', 'Received on'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($dr->query('//td[@class="k"]'))));
    }

    // ---------------------------------------------------------------- POS: official receipt / invoice number

    private function checkout(string $role, array $extra = []): \CodeIgniter\Test\TestResponse
    {
        $session = $role === 'admin' ? $this->asOwner() : $this->asStaff();

        return $session->post($role . '/sales/checkout', $extra + ['item_id' => ['0290'], 'quantity' => ['1'], 'payment_method' => 'Cash', 'cash_received' => '5000']);
    }

    /** @return array<string, array{0:string}> */
    public static function roles(): array
    {
        return ['owner' => ['admin'], 'staff' => ['staff']];
    }

    /** @dataProvider roles */
    public function testTheCheckoutFormHasTheOptionalOrField(string $role): void
    {
        $html = (string) ($role === 'admin' ? $this->asOwner() : $this->asStaff())->get($role . '/sales')->response()->getBody();
        $x    = $this->html($html);

        $input = $x->query('//form[@id="cartForm"]//input[@name="or_number"]');
        $this->assertSame(1, $input->length);
        $this->assertFalse($input->item(0)->hasAttribute('required'), 'Optional');
        $this->assertStringContainsString("Official receipt / invoice no.", $x->query('//label[@for="orNumber"]')->item(0)->textContent);
        $this->assertStringContainsString('BIR booklet', $x->query('//label[@for="orNumber"]')->item(0)->textContent);
    }

    /** @dataProvider roles */
    public function testTheOrNumberIsSavedWithTheSale(string $role): void
    {
        $result = $this->checkout($role, ['or_number' => '  A-001234 ']);

        $result->assertRedirectTo(site_url($role . '/sales'));
        $sale = $this->db->table('sales_transaction')->get()->getRowArray();
        $this->assertSame('A-001234', $sale['or_number'], 'Trimmed and saved.');
        $this->assertSame('1001', $sale['receipt_no'], "The system's own receipt number is separate.");
        $this->assertSame('A-001234', $_SESSION['receipt']['or_number'] ?? null);
    }

    /** @dataProvider roles */
    public function testNoOrNumberIsFineAndABadOneIsRefused(string $role): void
    {
        $this->checkout($role);
        $this->assertNull($this->db->table('sales_transaction')->get()->getRowArray()['or_number']);

        $this->checkout($role, ['or_number' => '<script>alert(1)</script>']);
        $this->assertStringContainsString('Official receipt / invoice no.', (string) ($_SESSION['error'] ?? ''));
        $this->checkout($role, ['or_number' => str_repeat('1', 31)]);
        $this->assertStringContainsString('up to 30 characters', (string) ($_SESSION['error'] ?? ''));
        $this->assertSame(1, $this->db->table('sales_transaction')->countAllResults(), 'Nothing was sold by the refused checkouts.');
    }

    private function receiptPage(string $role, array $receipt): string
    {
        $session = ['isLoggedIn' => true, 'last_activity' => time(), 'receipt' => $receipt, '__ci_vars' => ['receipt' => 'new']]
            + ($role === 'admin'
                ? ['role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One']
                : ['role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz']);

        return (string) $this->withSession($session)->get($role . '/sales')->response()->getBody();
    }

    private function receiptData(?string $or): array
    {
        return [
            'receipt_no' => '1001', 'or_number' => $or, 'sale_date' => '2026-11-14 10:00:00', 'lines' => [['item_name' => 'Tactical Pants', 'qty' => 1, 'unit_price' => 350.0, 'total' => 350.0, 'discount' => 0, 'badge' => null]],
            'subtotal' => 350.0, 'discount' => 0.0, 'total' => 350.0, 'cash_received' => 500.0, 'change' => 150.0, 'payment_method' => 'Cash', 'cash_amount' => 350.0, 'gcash_amount' => 0.0, 'gcash_reference_no' => null, 'staff_name' => 'Ana Cruz',
        ];
    }

    /** @dataProvider roles */
    public function testThePrintedReceiptIsASalesReceiptWithTheOrNumber(string $role): void
    {
        $html = $this->receiptPage($role, $this->receiptData('A-001234'));
        $x    = $this->html($html);

        $this->assertSame('SALES RECEIPT', trim($x->query('//div[@id="receiptModal"]//div[@class="inv-heading"]')->item(0)->textContent));
        $this->assertStringNotContainsString('SALES INVOICE', $html);
        $this->assertStringContainsString('This is not an official receipt.', $x->query('//div[@id="receiptModal"]')->item(0)->textContent);
        $this->assertStringContainsString('OR / Invoice no.: A-001234', preg_replace('/\s+/', ' ', $x->query('//div[@id="receiptModal"]')->item(0)->textContent));
    }

    /** @dataProvider roles */
    public function testAReceiptWithoutAnOrNumberShowsNoOrLine(string $role): void
    {
        $x = $this->html($this->receiptPage($role, $this->receiptData(null)));

        $this->assertSame(0, $x->query('//div[@id="receiptModal"]//div[contains(@class,"inv-or-no")]')->length);
        $this->assertStringContainsString('This is not an official receipt.', $x->query('//div[@id="receiptModal"]')->item(0)->textContent);
    }

    /** @dataProvider roles */
    public function testTheReceiptsTabCarriesTheOrNumberForReprinting(string $role): void
    {
        $this->checkout($role, ['or_number' => 'A-001234']);
        $html = (string) ($role === 'admin' ? $this->asOwner() : $this->asStaff())->get($role . '/sales?tab=receipts')->response()->getBody();
        $x    = $this->html($html);

        $link = $x->query('//a[contains(@class,"view-receipt-link")]')->item(0);
        $this->assertSame('A-001234', json_decode($link->getAttribute('data-receipt'), true)['or_number']);
        $this->assertNotNull($x->query('//*[@id="hrOrNo"]')->item(0), 'The reprint dialog has the OR line.');
        $this->assertStringContainsString('SALES RECEIPT', $html);
    }
}
