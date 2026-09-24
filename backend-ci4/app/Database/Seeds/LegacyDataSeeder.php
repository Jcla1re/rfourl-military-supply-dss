<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class LegacyDataSeeder extends Seeder
{
    private string $dataDir;

    /** @var array<int,string> */
    private array $supplierMap = [];
    /** @var array<int,string> */
    private array $itemMap = [];
    /** @var array<int,string> */
    private array $soMap = [];

    private const PRIORITY_MAP = [
        'Normal' => 'Order',
        'Low'    => 'Planned',
        'High'   => 'Urgent',
    ];

    private const STATUS_MAP = [
        'Received'  => 'Delivered',
        'Pending'   => 'Order Confirmed',
        'Cancelled' => 'Cancelled',
    ];

    public function run()
    {
        $this->dataDir = APPPATH . 'Database/Seeds/data/';

        $this->db->transStart();

        $this->seedSuppliers();
        $this->seedUsers();
        $this->seedProducts();
        $this->seedSalesTransactions();
        $this->seedSalesItems();
        $this->seedStockOrders();
        $this->seedSoItems();
        $this->seedInventoryLog();
        $this->seedDssParameters();

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            echo "SEED FAILED - transaction rolled back\n";
            return;
        }

        echo "Legacy data seed complete.\n";
        echo 'Suppliers: ' . count($this->supplierMap) . "\n";
        echo 'Products: ' . count($this->itemMap) . "\n";
        echo 'Stock orders: ' . count($this->soMap) . "\n";
    }

    private function readCsv(string $filename): array
    {
        $rows = [];
        $fh   = fopen($this->dataDir . $filename, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$filename}");
        }
        $header = fgetcsv($fh);
        while (($line = fgetcsv($fh)) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }
            $rows[] = array_combine($header, $line);
        }
        fclose($fh);
        return $rows;
    }

    private static function nullIfEmpty(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : $v;
    }

    private static function intFromFloatString(?string $v): ?int
    {
        $v = self::nullIfEmpty($v);
        return $v === null ? null : (int) round((float) $v);
    }

    private function seedSuppliers(): void
    {
        $rows = $this->readCsv('01_supplier.csv');
        $data = [];
        foreach ($rows as $r) {
            $id = 'SUP-' . str_pad((string) $r['supplier_id'], 3, '0', STR_PAD_LEFT);
            $this->supplierMap[(int) $r['supplier_id']] = $id;
            $data[] = [
                'supplier_id'       => $id,
                'company_name'      => $r['company_name'],
                'category'          => self::nullIfEmpty($r['category']),
                'lead_time_days'    => (int) $r['lead_time_days'],
                'preferred_courier' => null,
                'contact_person'    => self::nullIfEmpty($r['contact_person']),
                'contact_email'     => self::nullIfEmpty($r['contact_email']),
                'contact_number'    => self::nullIfEmpty($r['contact_number']),
                'is_active'         => (int) $r['is_active'],
                'created_at'        => $r['created_at'],
                'updated_at'        => $r['updated_at'],
            ];
        }
        $this->db->table('suppliers')->insertBatch($data);
    }

    private function seedUsers(): void
    {
        $rows = $this->readCsv('02_users.csv');
        $hash = password_hash('Passw0rd!', PASSWORD_DEFAULT);
        $data = [];
        foreach ($rows as $r) {
            $supId = self::intFromFloatString($r['supplier_id']);
            $data[] = [
                'user_id'       => (int) $r['user_id'],
                'supplier_id'   => $supId !== null ? ($this->supplierMap[$supId] ?? null) : null,
                'username'      => $r['username'],
                'email'         => self::nullIfEmpty($r['email']),
                'password_hash' => $hash,
                'role'          => $r['role'],
                'full_name'     => $r['full_name'],
                'is_active'     => (int) $r['is_active'],
                'last_login'    => self::nullIfEmpty($r['last_login']),
                'created_at'    => $r['created_at'],
            ];
        }
        $this->db->table('users')->insertBatch($data);
    }

    private function seedProducts(): void
    {
        $rows  = $this->readCsv('03_products.csv');
        $chunk = [];
        foreach ($rows as $r) {
            $id = 'ITM-' . str_pad((string) $r['item_id'], 4, '0', STR_PAD_LEFT);
            $this->itemMap[(int) $r['item_id']] = $id;
            $supId = self::intFromFloatString($r['supplier_id']);
            $chunk[] = [
                'item_id'            => $id,
                'supplier_id'        => $supId !== null ? ($this->supplierMap[$supId] ?? null) : null,
                'item_name'          => $r['item_name'],
                'category'           => $r['category'],
                'size'               => self::nullIfEmpty($r['size']),
                'unit_cost'          => $r['unit_cost'],
                'selling_price'      => $r['selling_price'],
                'current_stock'      => (int) $r['current_stock'],
                'manual_rop_warning' => null,
                'abc_category'       => null,
                'is_active'          => (int) $r['is_active'],
                'created_at'         => $r['created_at'],
                'updated_at'         => $r['updated_at'],
            ];
            if (count($chunk) >= 200) {
                $this->db->table('products')->insertBatch($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->db->table('products')->insertBatch($chunk);
        }
    }

    private function seedSalesTransactions(): void
    {
        $rows = $this->readCsv('04_sales_transaction.csv');
        usort($rows, static fn ($a, $b) => strcmp($a['sale_date'], $b['sale_date']) <=> 0 ? strcmp($a['sale_date'], $b['sale_date']) : 0);

        $dayCounters = [];
        $chunk       = [];
        foreach ($rows as $r) {
            $day = substr($r['sale_date'], 0, 10);
            $ymd = str_replace('-', '', $day);
            $dayCounters[$ymd] = ($dayCounters[$ymd] ?? 0) + 1;
            $receipt = 'RCPT-' . $ymd . '-' . str_pad((string) $dayCounters[$ymd], 4, '0', STR_PAD_LEFT);

            $chunk[] = [
                'sales_id'       => (int) $r['sales_id'],
                'receipt_no'     => $receipt,
                'user_id'        => (int) $r['user_id'],
                'sale_date'      => $r['sale_date'],
                'payment_method' => $r['payment_method'],
                'subtotal'       => $r['subtotal'],
                'discount'       => $r['discount'],
                'total_amount'   => $r['total_amount'],
            ];
            if (count($chunk) >= 200) {
                $this->db->table('sales_transaction')->insertBatch($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->db->table('sales_transaction')->insertBatch($chunk);
        }
    }

    private function seedSalesItems(): void
    {
        $rows  = $this->readCsv('05_sales_item.csv');
        $chunk = [];
        foreach ($rows as $r) {
            $chunk[] = [
                'sales_item_id' => (int) $r['sales_item_id'],
                'sales_id'      => (int) $r['sales_id'],
                'item_id'       => $this->itemMap[(int) $r['item_id']] ?? null,
                'quantity_sold' => (int) $r['quantity_sold'],
                'selling_price' => $r['selling_price'],
            ];
            if (count($chunk) >= 200) {
                $this->db->table('sales_item')->insertBatch($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->db->table('sales_item')->insertBatch($chunk);
        }
    }

    private function seedStockOrders(): void
    {
        $rows  = $this->readCsv('06_stock_order.csv');
        $chunk = [];
        foreach ($rows as $r) {
            $id = 'SO-' . str_pad((string) $r['so_id'], 4, '0', STR_PAD_LEFT);
            $this->soMap[(int) $r['so_id']] = $id;
            $supId = (int) $r['supplier_id'];
            $chunk[] = [
                'so_id'                  => $id,
                'supplier_id'            => $this->supplierMap[$supId] ?? null,
                'user_id'                => (int) $r['user_id'],
                'priority'               => self::PRIORITY_MAP[$r['priority']] ?? 'Order',
                'status'                 => self::STATUS_MAP[$r['status']] ?? 'Order Confirmed',
                'order_date'             => $r['order_date'],
                'expected_delivery_date' => self::nullIfEmpty($r['expected_delivery_date']),
                'actual_delivery_date'   => self::nullIfEmpty($r['actual_delivery_date']),
                'tracking_no'            => self::nullIfEmpty($r['tracking_no']),
            ];
            if (count($chunk) >= 200) {
                $this->db->table('stock_order')->insertBatch($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->db->table('stock_order')->insertBatch($chunk);
        }
    }

    private function seedSoItems(): void
    {
        $rows  = $this->readCsv('07_so_item.csv');
        $chunk = [];
        foreach ($rows as $r) {
            $chunk[] = [
                'so_item_id'     => (int) $r['so_item_id'],
                'so_id'          => $this->soMap[(int) $r['so_id']] ?? null,
                'item_id'        => $this->itemMap[(int) $r['item_id']] ?? null,
                'order_quantity' => (int) $r['order_quantity'],
                'unit_price'     => $r['unit_price'],
            ];
            if (count($chunk) >= 200) {
                $this->db->table('so_item')->insertBatch($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->db->table('so_item')->insertBatch($chunk);
        }
    }

    private function seedInventoryLog(): void
    {
        $rows  = $this->readCsv('08_inventory_log.csv');
        $chunk = [];
        foreach ($rows as $r) {
            $refSo = self::intFromFloatString($r['reference_so_id']);
            $chunk[] = [
                'log_id'              => (int) $r['log_id'],
                'item_id'             => $this->itemMap[(int) $r['item_id']] ?? null,
                'user_id'             => (int) $r['user_id'],
                'log_type'            => $r['log_type'],
                'quantity_changed'    => (int) $r['quantity_changed'],
                'stock_after_change'  => (int) $r['stock_after_change'],
                'reference_so_id'     => $refSo !== null ? ($this->soMap[$refSo] ?? null) : null,
                'notes'               => self::nullIfEmpty($r['notes']),
                'timestamp'           => $r['timestamp'],
            ];
            if (count($chunk) >= 300) {
                $this->db->table('inventory_log')->insertBatch($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->db->table('inventory_log')->insertBatch($chunk);
        }
    }

    private function seedDssParameters(): void
    {
        $this->db->table('dss_parameters')->insert([
            'ordering_cost'         => 500.00,
            'holding_cost_per_unit' => 60.48,
            'service_level_target'  => 95.00,
            'z_score'               => 1.645,
            'demand_lookback_days'  => 90,
            'minimum_order_qty'     => 5,
            'updated_by'            => 1,
        ]);
    }
}
