<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeds what a fresh database needs before the real data goes in: the suppliers, the user accounts
 * and the DSS parameters. It no longer seeds products, sales, stock orders or the inventory log:
 * those come from the cleaned dataset (`php spark dss:seed-dataset --commit`, see the README,
 * "Rebuild the database"). The old product and sales seed files are in data-cleaning/archive/.
 */
class LegacyDataSeeder extends Seeder
{
    /** Every seeded user starts on this password; staff:check-passwords looks for accounts still using it. */
    public const DEFAULT_PASSWORD = 'Passw0rd!';

    private string $dataDir;

    /** @var array<int,string> */
    private array $supplierMap = [];

    public function run()
    {
        $this->dataDir = APPPATH . 'Database/Seeds/data/';

        $this->db->transStart();

        $this->seedSuppliers();
        $this->seedUsers();
        $this->seedDssParameters();

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            echo "SEED FAILED - transaction rolled back\n";
            return;
        }

        echo "Seed complete (suppliers, users, DSS parameters).\n";
        echo 'Suppliers: ' . count($this->supplierMap) . "\n";
        echo "Next: php spark dss:seed-dataset --commit  (products and sales)\n";
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
        $hash = password_hash(self::DEFAULT_PASSWORD, PASSWORD_DEFAULT);
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

    /**
     * The demand window is 730 days (2 years), the same as the live system, so a rebuilt database
     * gives the same reorder points and statuses as the one it replaces.
     */
    private function seedDssParameters(): void
    {
        $this->db->table('dss_parameters')->insert([
            'ordering_cost'         => 500.00,
            'holding_cost_per_unit' => 60.48,
            'service_level_target'  => 95.00,
            'z_score'               => 1.645,
            'demand_lookback_days'  => 730,
            'minimum_order_qty'     => 10,
            'updated_by'            => 1,
        ]);
    }
}
