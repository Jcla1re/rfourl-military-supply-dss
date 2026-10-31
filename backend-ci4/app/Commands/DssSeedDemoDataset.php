<?php

namespace App\Commands;

use App\Libraries\DemoDb;
use App\Libraries\DemoStockPlanner;
use App\Models\ProductModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;

/**
 * DEMO-ONLY sibling of dss:seed-dataset. Loads a FICTIONAL products + sales dataset (same
 * two-CSV shape as data-cleaning/final/, but no real RfourL names, prices, costs or supplier
 * identities) so the public demo/survey site never has to contain real business data at all.
 *
 * Unlike dss:seed-dataset, this command does NOT check the files against a fixed, pre-agreed
 * set of totals (lines/receipts/amount/etc.) - a made-up dataset has no "correct" totals to
 * match. It still runs every STRUCTURAL check (every sales line's Product_ID+Size exists in
 * the products master, categories are ones the app knows, Supplier_ID is 1-6 and exists in
 * `suppliers`, no duplicate product keys, DB-after-insert matches the file that was loaded) -
 * see fileChecks()/dbChecks() below.
 *
 * Refuses to run at all unless DEMO_MODE = true AND the connected database name contains
 * "demo" (DemoDb::guard(), the same rule demo:setup / demo:snapshot / demo:reset use) - so
 * this can never be pointed at the live database, even by accident or with --db.
 *
 *   php spark dss:seed-demo-dataset                dry run (default): checks the files and the database,
 *                                                  prints what would be deleted and inserted, writes nothing
 *   php spark dss:seed-demo-dataset --commit       does it
 *   options: --dir <folder>          where the CSVs are (default data-cleaning/demo-fictional)
 *            --stock-file <csv>      Product_ID, Size, Current_Stock: a stock count that replaces the
 *                                    demo opening stock (products not listed get 0)
 *            --cost-file <csv>       Product_ID, Size, Unit_Cost: costs that replace the 60% estimate
 *            --user <user_id>        the sale user (default: the first active Admin = the demo owner)
 *            --db <name>             run it against another database (a rehearsal copy) - must still contain "demo"
 *
 * What --commit does, in order (identical to dss:seed-dataset):
 *   1. drops the inventory_log triggers (it is append-only, so it cannot be cleared otherwise)
 *   2. ONE transaction: clears products, sales, stock orders, alerts, computations, clusters, snapshots,
 *      inventory_log and the stock/reorder notifications; inserts the products (stock 0) and the sales;
 *      runs the checks; any failure rolls everything back
 *   3. puts the triggers back (always, even after a failure) and resets AUTO_INCREMENT
 *   4. dss:run (needs the ml-service) -> ROP, EOQ, K-Means class
 *   5. demo opening stock from those numbers (DemoStockPlanner, seed 2026), logged in inventory_log
 *   6. dss:run again, so statuses and reorder alerts match the stock
 * Users, suppliers, dss_parameters, notification_preferences, app_settings and security_log are kept
 * (demo:setup still runs afterwards as usual to (re)create the 3 demo logins).
 */
class DssSeedDemoDataset extends BaseCommand
{
    protected $group       = 'DSS';
    protected $name        = 'dss:seed-demo-dataset';
    protected $description = 'DEMO ONLY: replaces the seeded products and sales with a fictional dataset (dry run unless --commit).';
    protected $usage       = 'dss:seed-demo-dataset [--commit] [--dir folder] [--stock-file csv] [--cost-file csv] [--user id] [--db name]';

    /** Cleared tables, children first. Notifications are cleared only for stock/reorder/order types. */
    private const CLEAR_ORDER = [
        'reorder_notices', 'reorder_alert', 'sales_item', 'sales_transaction', 'so_item', 'inventory_log',
        'stock_order', 'pdss_computation', 'cluster_segments', 'analytics_snapshot', 'notifications', 'products',
    ];
    private const NOTIFICATION_CATEGORIES = ['reorder_alert', 'order_status'];
    private const KEPT_TABLES             = ['users', 'suppliers', 'dss_parameters', 'notification_preferences', 'app_settings', 'security_log'];
    private const COST_ESTIMATE_RATE      = 0.6;
    /** Master column => [products column, width]. */
    private const COLUMN_WIDTHS = [
        'Product_ID' => ['product_code', 10], 'Item_Name' => ['item_name', 100], 'Category' => ['category', 50],
        'Size' => ['size', 10], 'Size_Type' => ['size_type', 10],
    ];
    private const OPENING_NOTE_DEMO       = 'Opening balance (demo)';
    private const OPENING_NOTE_COUNT      = 'Opening balance (stock count)';

    private BaseConnection $db;
    private ?string $dbOption = null;
    private bool $isLive     = true;

    public function run(array $params)
    {
        // Belt-and-suspenders: identical rule to demo:setup/demo:snapshot/demo:reset. This
        // command can NEVER touch a database whose name does not contain "demo", live included.
        if ($reason = DemoDb::guard()) {
            CLI::error($reason);
            DemoDb::log('dss:seed-demo-dataset refused: ' . $reason);

            return;
        }

        $this->dbOption = CLI::getOption('db') ?: null;
        if ($this->dbOption !== null && ! demo_db_ok($this->dbOption)) {
            CLI::error('Refusing to run: --db "' . $this->dbOption . '" does not contain "demo".');

            return;
        }
        $this->db       = $this->connect($this->dbOption);
        $this->isLive   = $this->dbOption === null || $this->dbOption === (string) config('Database')->default['database'];

        $commit = (bool) CLI::getOption('commit');
        $dir    = rtrim((string) (CLI::getOption('dir') ?: ROOTPATH . '../data-cleaning/demo-fictional'), '/\\');

        if (! $this->isLive) {
            // A rehearsal copy: everything in this process (models, dss:run, migrations) must use it.
            config('Database')->default['database'] = $this->dbOption;
            if (db_connect()->getDatabase() !== $this->dbOption) {
                CLI::error('Could not point the default connection at ' . $this->dbOption . '.');

                return;
            }
            if ($commit) {
                CLI::write('Bringing the rehearsal copy up to the current schema (migrations)...');
                \Config\Services::migrations()->latest();
            }
        }

        CLI::write('Database: ' . $this->db->getDatabase() . ' (' . ($this->isLive ? 'the demo database from .env' : 'rehearsal copy') . ')', 'cyan');
        CLI::write($commit ? 'MODE: COMMIT - the database will be changed.' : 'MODE: DRY RUN - nothing is written to the database.', $commit ? 'red' : 'green');

        // ---------- 1. the files ----------
        $data = $this->loadData($dir, CLI::getOption('cost-file') ?: null, CLI::getOption('stock-file') ?: null);
        if ($data['errors']) {
            $this->heading('Problems in the files');
            foreach (array_slice($data['errors'], 0, 40) as $e) {
                CLI::error('  - ' . $e);
            }
            if (count($data['errors']) > 40) {
                CLI::error('  ... and ' . (count($data['errors']) - 40) . ' more');
            }

            return;
        }
        $fileChecks = $this->fileChecks($data);
        $this->heading('Dataset checks (the files)');
        $this->printChecks($fileChecks);
        if ($this->failed($fileChecks)) {
            CLI::error('The files do not match what was agreed. Nothing was done.');

            return;
        }

        // ---------- 2. the database ----------
        $problems = $this->preflight($data, $commit);
        $owner    = $this->ownerUserId($problems);
        $this->report($data, $owner);

        if ($problems) {
            $this->heading('Cannot continue');
            foreach ($problems as $p) {
                CLI::error('  - ' . $p);
            }

            return;
        }

        if (! $commit) {
            CLI::newLine();
            CLI::write('DRY RUN complete. Nothing was written to the database. Add --commit to do it.', 'green');

            return;
        }

        $this->commit($data, (int) $owner);
    }

    // =====================================================================
    // loading and checking the files
    // =====================================================================

    /** @return list<array<string,string>> */
    private function readCsv(string $file): array
    {
        $fh = fopen($file, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$file}");
        }
        $header = fgetcsv($fh, 0, ',', '"', '');
        $header = array_map(static fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header ?: []);
        $rows   = [];
        while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($line === [null] || count($line) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $line); // every value stays a string (Product_ID keeps its zeros)
        }
        fclose($fh);

        return $rows;
    }

    private static function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', ',');
    }

    private static function key(string $productId, string $size): string
    {
        return $productId . '|' . $size;
    }

    /**
     * @return array{errors: list<string>, products: list<array>, byKey: array<string,int>, receipts: array<int,array>,
     *               lines: list<array>, costFile: ?array, stockFile: ?array, dir: string}
     */
    private function loadData(string $dir, ?string $costFile, ?string $stockFile): array
    {
        $errors = [];
        foreach (['RfourL_Dataset_2021_2026_CLEAN.csv', 'RfourL_Products_Master.csv'] as $f) {
            if (! is_file("{$dir}/{$f}")) {
                $errors[] = "Missing {$dir}/{$f}";
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'products' => [], 'byKey' => [], 'receipts' => [], 'lines' => [], 'costFile' => null, 'stockFile' => null, 'dir' => $dir];
        }

        // ---- products master: one row per Product_ID + Size
        $master = $this->readCsv("{$dir}/RfourL_Products_Master.csv");
        usort($master, static function ($a, $b) {
            return [$a['Product_ID'], $a['Size'] === '' ? 0 : 1] <=> [$b['Product_ID'], $b['Size'] === '' ? 0 : 1]
                ?: strnatcasecmp($a['Size'], $b['Size']);
        });

        $products = [];
        $byKey    = [];
        foreach ($master as $i => $m) {
            $k = self::key($m['Product_ID'], $m['Size']);
            if (isset($byKey[$k])) {
                $errors[] = "Products master: {$k} appears twice";
                continue;
            }
            if (! preg_match('/^\d{4}$/', $m['Product_ID'])) {
                $errors[] = "Products master: Product_ID '{$m['Product_ID']}' is not 4 digits";
            }
            if (! preg_match('/^[1-6]$/', $m['Supplier_ID'])) {
                $errors[] = "Products master: {$k} has Supplier_ID '{$m['Supplier_ID']}' (expected 1-6)";
            }
            if (! is_numeric($m['Latest_Selling_Price'])) {
                $errors[] = "Products master: {$k} has no numeric selling price";
            }
            // This MariaDB is not in strict mode: a value too long for its column would be cut short without a word.
            foreach (self::COLUMN_WIDTHS as $field => [$column, $width]) {
                if (mb_strlen($m[$field]) > $width) {
                    $errors[] = "Products master: {$k} {$field} '{$m[$field]}' is longer than products.{$column} ({$width})";
                }
            }
            if (! in_array($m['Category'], ProductModel::CATEGORIES, true)) {
                $errors[] = "Products master: {$k} has category '{$m['Category']}' (the app knows " . implode(', ', ProductModel::CATEGORIES) . ')';
            }
            $byKey[$k]  = count($products);
            $products[] = [
                'key'         => $k,
                'product_id'  => $m['Product_ID'],
                'size'        => $m['Size'],
                'item_name'   => $m['Item_Name'],
                'category'    => $m['Category'],
                'size_type'   => $m['Size_Type'],
                'supplier_id' => sprintf('SUP-%03d', (int) $m['Supplier_ID']),
                'price'       => $m['Latest_Selling_Price'],
                'file_cost'   => $m['Unit_Cost'],
                'file_stock'  => $m['Current_Stock'],
                'item_id'     => sprintf('%04d', count($products) + 1), // 0001.. in Product_ID, Size order
            ];
        }

        // ---- sales lines, grouped into receipts
        $lines    = [];
        $receipts = [];
        foreach ($this->readCsv("{$dir}/RfourL_Dataset_2021_2026_CLEAN.csv") as $n => $r) {
            $k = self::key($r['Product_ID'], $r['Size']);
            if (! isset($byKey[$k])) {
                $errors[] = 'Dataset line ' . ($n + 2) . ": {$k} ({$r['Item_Name']}) is not in the products master";
                continue;
            }
            $no = (int) $r['Receipt_No'];
            if (! isset($receipts[$no])) {
                $receipts[$no] = ['no' => $no, 'date' => $r['Date'], 'year' => (int) $r['Year'], 'lines' => [], 'cents' => 0];
            } elseif ($receipts[$no]['date'] !== $r['Date']) {
                $errors[] = "Receipt {$no} has two different dates";
            }
            $line = [
                'receipt'  => $no,
                'product'  => $byKey[$k],
                'qty'      => (int) $r['Quantity'],
                'price'    => $r['Unit_Price'],
                'cents'    => self::cents($r['Amount']),
                'bulk'     => strtolower(trim($r['Bulk_Order'])) === 'yes' ? 1 : 0,
            ];
            $receipts[$no]['lines'][] = $line;
            $receipts[$no]['cents']  += $line['cents'];
            $lines[]                  = $line;
        }
        ksort($receipts);

        // ---- optional real costs / stock counts
        $costs = $stocks = null;
        if ($costFile !== null) {
            $costs = $this->keyedFile($costFile, 'Unit_Cost', $byKey, $errors);
            foreach ($costs ?? [] as $k => $v) {
                if (! is_numeric($v) || (float) $v <= 0) {
                    $errors[] = "--cost-file: {$k} has Unit_Cost '{$v}' (must be a number above 0)";
                }
            }
        }
        if ($stockFile !== null) {
            $stocks = $this->keyedFile($stockFile, 'Current_Stock', $byKey, $errors);
            foreach ($stocks ?? [] as $k => $v) {
                if (! preg_match('/^\d+$/', $v)) {
                    $errors[] = "--stock-file: {$k} has Current_Stock '{$v}' (must be a whole number, 0 or more)";
                }
            }
        }

        // ---- unit cost: the file's, else a real cost from --cost-file, else 60% of the selling price (estimated)
        foreach ($products as &$p) {
            $k = $p['key'];
            if (isset($costs[$k])) {
                $p['cost'] = number_format((float) $costs[$k], 2, '.', '');
                $p['cost_source'] = 'cost-file';
            } elseif ($p['file_cost'] !== '' && is_numeric($p['file_cost'])) {
                $p['cost'] = $p['file_cost'];
                $p['cost_source'] = 'master';
            } else {
                $p['cost'] = number_format(round((float) $p['price'] * self::COST_ESTIMATE_RATE, 2), 2, '.', '');
                $p['cost_source'] = 'estimated';
            }
        }
        unset($p);

        return ['errors' => $errors, 'products' => $products, 'byKey' => $byKey, 'receipts' => $receipts, 'lines' => $lines,
            'costFile' => $costs, 'stockFile' => $stocks, 'dir' => $dir];
    }

    /**
     * Reads a Product_ID, Size, <value column> file into [Product_ID|Size => value], reporting unknown products.
     *
     * @param array<string,int> $byKey
     * @param list<string>      $errors
     *
     * @return array<string,string>|null
     */
    private function keyedFile(string $file, string $valueColumn, array $byKey, array &$errors): ?array
    {
        if (! is_file($file)) {
            $errors[] = "File not found: {$file}";

            return null;
        }
        $out = [];
        foreach ($this->readCsv($file) as $n => $r) {
            foreach (['Product_ID', 'Size', $valueColumn] as $c) {
                if (! array_key_exists($c, $r)) {
                    $errors[] = basename($file) . ": column {$c} is missing";

                    return null;
                }
            }
            $k = self::key(trim($r['Product_ID']), trim($r['Size']));
            if (! isset($byKey[$k])) {
                $errors[] = basename($file) . ' line ' . ($n + 2) . ": {$k} is not in the products master";
                continue;
            }
            $out[$k] = trim($r[$valueColumn]);
        }

        return $out;
    }

    /**
     * Unlike dss:seed-dataset, there is no pre-agreed "correct" total for a made-up dataset, so
     * every row here reports the file's own numbers (always "ok") rather than comparing against
     * a fixed constant - this is informational (what you're about to load), not a gate. The real
     * gates are: every line matched a products-master row (checked in loadData(), becomes an
     * 'errors' entry, not a row here), receipt numbers are consecutive, and there are no
     * duplicate product keys / two-codes-for-one-name mistakes.
     *
     * @return list<array{0:string,1:string,2:string,3:bool}> label, expected, actual, ok
     */
    private function fileChecks(array $d): array
    {
        $receipts = $d['receipts'];
        $qty      = array_sum(array_column($d['lines'], 'qty'));
        $cents    = array_sum(array_column($d['lines'], 'cents'));
        $years    = [];
        foreach ($receipts as $r) {
            $years[$r['year']] = ($years[$r['year']] ?? 0) + $r['cents'];
        }
        ksort($years);
        $firstReceipt = array_key_first($receipts);
        $lastReceipt  = array_key_last($receipts);

        $c   = [];
        $add = static function (string $label, $expected, $actual) use (&$c) {
            $c[] = [$label, (string) $expected, (string) $actual, (string) $expected === (string) $actual];
        };
        $add('Sales lines', count($d['lines']), count($d['lines']));
        $add('Receipts', count($receipts), count($receipts));
        $add('First receipt no.', $firstReceipt, $firstReceipt);
        $add('Last receipt no.', $lastReceipt, $lastReceipt);
        $add('Receipts are consecutive', 'yes', $receipts === [] || array_keys($receipts) === range($firstReceipt, $lastReceipt) ? 'yes' : 'no');
        $add('Total quantity', $qty, $qty);
        $add('Total amount', self::money($cents), self::money($cents));
        foreach ($years as $y => $exp) {
            $add("Amount {$y}", self::money($exp), self::money($exp));
        }
        $add('Products', count($d['products']), count($d['products']));
        $names = [];
        foreach ($d['products'] as $p) {
            $names[$p['item_name']][$p['product_id']] = true;
        }
        $add('Product names with two codes', 0, count(array_filter($names, static fn ($codes) => count($codes) > 1)));

        return $c;
    }

    // =====================================================================
    // database: preflight and the report
    // =====================================================================

    private function connect(?string $name): BaseConnection
    {
        return $name
            ? db_connect(array_merge((array) config('Database')->default, ['database' => $name]))
            : db_connect();
    }

    private function count(string $sql, array $binds = []): int
    {
        return (int) ($this->db->query($sql, $binds)->getRow('c') ?? 0);
    }

    private function tableExists(string $t): bool
    {
        return $this->db->tableExists($t);
    }

    /** @return list<string> reasons we cannot go on */
    private function preflight(array $d, bool $commit): array
    {
        $problems = [];

        foreach (array_merge(self::CLEAR_ORDER, self::KEPT_TABLES) as $t) {
            if ($t !== 'reorder_notices' && ! $this->tableExists($t)) {
                $problems[] = "Table {$t} does not exist - run `php spark migrate`.";
            }
        }
        if ($problems) {
            return $problems;
        }

        foreach (['product_code', 'size_type'] as $col) {
            if (! $this->db->fieldExists($col, 'products')) {
                $problems[] = "products.{$col} is missing - run `php spark migrate`.";
            }
        }
        if (! $this->db->fieldExists('is_bulk', 'sales_item')) {
            $problems[] = 'sales_item.is_bulk is missing - run `php spark migrate` (migration AddIsBulkToSalesItem).';
        }

        $have = array_column($this->db->query('SELECT supplier_id FROM suppliers')->getResultArray(), 'supplier_id');
        foreach (array_unique(array_column($d['products'], 'supplier_id')) as $s) {
            if (! in_array($s, $have, true)) {
                $problems[] = "Supplier {$s} (used by the products master) does not exist in suppliers.";
            }
        }

        if ($commit) {
            // The demo database is disposable (demo:snapshot/demo:reset already cover recovery), so a
            // backup is suggested, not required - unlike dss:seed-dataset, which guards the live database.
            if ($this->isLive && $this->latestBackup() === null) {
                CLI::write('No recent backup found in data-cleaning/backup/. Not required for the demo database, but `mysqldump` it first if you want an easy way back.', 'yellow');
            }
            if (! $this->mlReachable()) {
                $problems[] = 'The ml-service is not reachable at ' . $this->mlUrl() . ' - start it first (cd ml-service && python app.py).';
            }
            if ($this->triggerCount() !== 2) {
                CLI::write('The inventory_log triggers are not both present; they will be recreated.', 'yellow');
            }
        }

        return $problems;
    }

    private function ownerUserId(array &$problems): ?int
    {
        $opt = CLI::getOption('user');
        if ($opt) {
            $row = $this->db->query('SELECT user_id FROM users WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL', [(int) $opt])->getRowArray();
        } else {
            $row = $this->db->query("SELECT user_id FROM users WHERE role = 'Admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY user_id LIMIT 1")->getRowArray();
        }
        if (! $row) {
            $problems[] = 'No active Admin user found for the sales (pass --user <id>).';

            return null;
        }

        return (int) $row['user_id'];
    }

    private function report(array $d, ?int $owner): void
    {
        $this->heading('What will be DELETED (rows now in the database)');
        $rows = [];
        foreach (self::CLEAR_ORDER as $t) {
            if (! $this->tableExists($t)) {
                continue;
            }
            if ($t === 'notifications') {
                $in    = "'" . implode("','", self::NOTIFICATION_CATEGORIES) . "'";
                $n     = $this->count("SELECT COUNT(*) c FROM notifications WHERE category IN ({$in})");
                $total = $this->count('SELECT COUNT(*) c FROM notifications');
                $rows[] = [$t, (string) $n, "stock/reorder/order notifications only ({$total} total, " . ($total - $n) . ' kept)'];
            } else {
                $rows[] = [$t, (string) $this->count("SELECT COUNT(*) c FROM `{$t}`"), ''];
            }
        }
        CLI::table($rows, ['Table', 'Rows', 'Note']);

        $this->heading('What is KEPT');
        $rows = [];
        foreach (self::KEPT_TABLES as $t) {
            $rows[] = [$t, (string) $this->count("SELECT COUNT(*) c FROM `{$t}`")];
        }
        CLI::table($rows, ['Table', 'Rows']);

        $cat = $this->db->query("SELECT category, type, COUNT(*) n FROM notifications GROUP BY category, type ORDER BY category")->getResultArray();
        CLI::write('Notifications by category (deleted: ' . implode(', ', self::NOTIFICATION_CATEGORIES) . '):');
        foreach ($cat as $c) {
            $mark = in_array($c['category'], self::NOTIFICATION_CATEGORIES, true) ? 'delete' : 'keep  ';
            CLI::write("  [{$mark}] {$c['category']} / {$c['type']}: {$c['n']}");
        }

        $this->heading('What will be INSERTED');
        $lines = count($d['lines']);
        $units = array_sum(array_column($d['lines'], 'qty'));
        CLI::table([
            ['products', (string) count($d['products']), 'item_id 0001-' . sprintf('%04d', count($d['products'])) . ', stock 0 until the demo opening stock'],
            ['sales_transaction', (string) count($d['receipts']), 'receipts ' . array_key_first($d['receipts']) . '-' . array_key_last($d['receipts']) . ', Cash, 12:00, user_id ' . ($owner ?? '?')],
            ['sales_item', (string) $lines, "{$units} units, total ₱" . self::money(array_sum(array_column($d['lines'], 'cents')))],
        ], ['Table', 'Rows', 'Note']);

        // ---- estimated cost
        $est = array_values(array_filter($d['products'], static fn ($p) => $p['cost_source'] === 'estimated'));
        $this->heading('Unit cost');
        $bySource = array_count_values(array_column($d['products'], 'cost_source'));
        foreach ($bySource as $s => $n) {
            CLI::write(sprintf('  %-10s %d products', $s, $n));
        }
        if ($est) {
            $file = $this->writeEstimatedList($est);
            CLI::write('  ' . count($est) . ' products use an ESTIMATED cost (' . (int) (self::COST_ESTIMATE_RATE * 100) . '% of the selling price). Full list: ' . $file, 'yellow');
            foreach (array_slice($est, 0, 5) as $p) {
                CLI::write("    {$p['product_id']} {$p['item_name']}" . ($p['size'] !== '' ? " ({$p['size']})" : '') . ": price {$p['price']} -> cost {$p['cost']}");
            }
            CLI::write('    ...');
        }

        // ---- bulk
        $this->heading('Bulk orders (reported only; no DSS calculation uses them)');
        $bulk = array_filter($d['lines'], static fn ($l) => $l['bulk'] === 1);
        $bq   = array_sum(array_column($bulk, 'qty'));
        $bc   = array_sum(array_column($bulk, 'cents'));
        $tc   = array_sum(array_column($d['lines'], 'cents'));
        CLI::write(sprintf('  %d of %d lines are bulk; %d of %d units (%.1f%%); ₱%s of ₱%s (%.1f%%)', count($bulk), $lines, $bq, $units, 100 * $bq / max(1, $units), self::money($bc), self::money($tc), 100 * $bc / max(1, $tc)));
        $byItem = [];
        foreach ($bulk as $l) {
            $byItem[$d['products'][$l['product']]['item_name']] = ($byItem[$d['products'][$l['product']]['item_name']] ?? 0) + $l['qty'];
        }
        arsort($byItem);
        foreach (array_slice($byItem, 0, 5, true) as $name => $q) {
            CLI::write("    {$name}: {$q} units");
        }

        // ---- the two lines whose Amount differs from qty x price
        $this->heading('Receipts whose Amount differs from quantity x price (loaded as given)');
        foreach ($this->amountMismatches($d) as $no => $diff) {
            CLI::write("  receipt {$no}: total differs from the sum of qty x price by " . self::money($diff));
        }

        // ---- opening stock plan
        $this->heading('Opening stock');
        if ($d['stockFile'] !== null) {
            CLI::write('  --stock-file given: ' . count($d['stockFile']) . ' products from the file, everything else 0 (logged as "' . self::OPENING_NOTE_COUNT . '").');
        } else {
            CLI::write('  Demo stock (seed ' . DemoStockPlanner::SEED . '), worked out after dss:run from each product\'s ROP, EOQ and class, so it can');
            CLI::write('  only be previewed on a rehearsal copy (--db <copy> --commit). Target mix: ' . implode(', ', array_map(
                static fn ($l, $s) => ((int) round($s * 100)) . "% {$l}",
                array_keys(DemoStockPlanner::MIX),
                DemoStockPlanner::MIX
            )) . '.');
        }
    }

    /** @return array<int,int> receipt no => cents (total minus sum of qty x price) for receipts that differ */
    private function amountMismatches(array $d): array
    {
        $out = [];
        foreach ($d['receipts'] as $no => $r) {
            $sum = 0;
            foreach ($r['lines'] as $l) {
                $sum += (int) round($l['qty'] * (float) $l['price'] * 100);
            }
            if ($sum !== $r['cents']) {
                $out[$no] = $r['cents'] - $sum;
            }
        }

        return $out;
    }

    private function writeEstimatedList(array $est): string
    {
        $dir = WRITEPATH . 'dss-seed-dataset';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'estimated_cost_products.csv';
        $fh   = fopen($file, 'w');
        fputcsv($fh, ['Product_ID', 'Size', 'Item_Name', 'Category', 'Selling_Price', 'Estimated_Unit_Cost'], ',', '"', '');
        foreach ($est as $p) {
            fputcsv($fh, [$p['product_id'], $p['size'], $p['item_name'], $p['category'], $p['price'], $p['cost']], ',', '"', '');
        }
        fclose($fh);

        return $file;
    }

    // =====================================================================
    // --commit
    // =====================================================================

    private function commit(array $d, int $owner): void
    {
        $dbName = $this->db->getDatabase();

        // dss:run and the models use the default connection. Point it at this database (a rehearsal copy
        // needs that) and prove it before anything is changed.
        config('Database')->default['database'] = $dbName;
        $seen = db_connect()->getDatabase();
        if ($seen !== $dbName) {
            CLI::error("The default connection is '{$seen}', not '{$dbName}'. Stopping before anything is changed.");

            return;
        }

        $this->heading('1. Clearing and loading (one transaction)');
        $this->ensureTriggers(false);
        $this->dropTriggers();
        // Strict for this session only: a value that does not fit becomes an error (and a rollback), never a silent cut.
        $this->db->query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
        $ok = false;
        $this->db->transException(true); // inside a transaction CodeIgniter only returns false on a failed query; make it throw
        try {
            $this->db->transBegin();
            $this->clearOldData();
            $this->insertProducts($d);
            $this->insertSales($d, $owner);

            $checks = $this->dbChecks($d);
            $this->printChecks($checks);
            if ($this->failed($checks)) {
                throw new \RuntimeException('A check failed.');
            }
            $this->db->transCommit();
            $ok = true;
            CLI::write('Committed.', 'green');
            DemoDb::log('dss:seed-demo-dataset committed on ' . $dbName . ': ' . count($d['products']) . ' products, ' . count($d['receipts']) . ' receipts.');
        } catch (\Throwable $e) {
            $this->db->transRollback();
            CLI::error('Rolled back (' . rtrim($e->getMessage(), '. ') . '). The database is unchanged.');
        } finally {
            $this->db->transException(false);
            $this->createTriggers();
            CLI::write('inventory_log triggers restored: ' . $this->triggerCount() . ' of 2 present.', $this->triggerCount() === 2 ? 'green' : 'red');
        }
        if (! $ok) {
            return;
        }
        $this->resetAutoIncrement(['reorder_notices', 'reorder_alert', 'sales_item', 'sales_transaction', 'so_item', 'inventory_log', 'pdss_computation', 'analytics_snapshot', 'notifications']);

        // ---------- dss:run, demo stock, dss:run ----------
        $this->heading('2. dss:run (ROP, EOQ, K-Means class)');
        if (! $this->dssRun()) {
            return;
        }

        $this->heading('3. Opening stock');
        $inputs = $this->planInputs(); // read BEFORE the first pass is removed: ROP, EOQ and class come from it
        $this->cleanFirstPass();
        $plan = $this->openingStock($d, $owner, $inputs);
        if ($d['stockFile'] === null) {
            $zero = $this->count('SELECT COUNT(*) c FROM products WHERE current_stock = 0');
            if ($zero > 0) {
                CLI::error("RUN FAILED: {$zero} products have current_stock = 0 after the opening stock. Stopping.");

                return;
            }
        }

        $this->heading('4. dss:run again (statuses and reorder alerts)');
        if (! $this->dssRun()) {
            return;
        }

        $this->finalReport($d, $plan);
    }

    private function clearOldData(): void
    {
        $in = "'" . implode("','", self::NOTIFICATION_CATEGORIES) . "'";
        foreach (self::CLEAR_ORDER as $t) {
            if (! $this->tableExists($t)) {
                continue;
            }
            $this->db->query($t === 'notifications' ? "DELETE FROM notifications WHERE category IN ({$in})" : "DELETE FROM `{$t}`");
            CLI::write(sprintf('  deleted %-20s %d rows', $t, $this->db->affectedRows()));
        }
    }

    private function insertProducts(array $d): void
    {
        $batch = [];
        foreach ($d['products'] as $p) {
            $batch[] = [
                'item_id'       => $p['item_id'],
                'product_code'  => $p['product_id'],
                'supplier_id'   => $p['supplier_id'],
                'item_name'     => $p['item_name'],
                'category'      => $p['category'],
                'size'          => $p['size'] === '' ? null : $p['size'],
                'size_type'     => $p['size_type'] === '' ? null : $p['size_type'],
                'unit_cost'     => $p['cost'],
                'selling_price' => $p['price'],
                'current_stock' => 0,
                'is_active'     => 1,
            ];
        }
        foreach (array_chunk($batch, 200) as $chunk) {
            $this->db->table('products')->insertBatch($chunk);
        }
        CLI::write('  inserted products              ' . count($batch));
    }

    private function insertSales(array $d, int $owner): void
    {
        $tx = $items = [];
        $salesId = 0;
        $itemId  = 0;
        foreach ($d['receipts'] as $r) {
            $salesId++;
            $total = self::money($r['cents']);
            $total = str_replace(',', '', $total);
            $tx[]  = [
                'sales_id'           => $salesId,
                'receipt_no'         => (string) $r['no'],
                'user_id'            => $owner,
                'sale_date'          => $r['date'] . ' 12:00:00',
                'payment_method'     => 'Cash',
                'cash_amount'        => $total,
                'gcash_amount'       => '0.00',
                'gcash_reference_no' => null,
                'subtotal'           => $total,
                'discount'           => '0.00',
                'total_amount'       => $total,
            ];
            foreach ($r['lines'] as $l) {
                $items[] = [
                    'sales_item_id' => ++$itemId,
                    'sales_id'      => $salesId,
                    'item_id'       => $d['products'][$l['product']]['item_id'],
                    'quantity_sold' => $l['qty'],
                    'selling_price' => $l['price'],
                    'is_bulk'       => $l['bulk'],
                ];
            }
        }
        foreach (array_chunk($tx, 200) as $chunk) {
            $this->db->table('sales_transaction')->insertBatch($chunk);
        }
        foreach (array_chunk($items, 500) as $chunk) {
            $this->db->table('sales_item')->insertBatch($chunk);
        }
        CLI::write('  inserted sales_transaction     ' . count($tx));
        CLI::write('  inserted sales_item            ' . count($items));
    }

    /**
     * Checks the database after insert against what the FILE said to load (not a fixed constant -
     * see fileChecks()), plus the same structural integrity checks dss:seed-dataset runs.
     *
     * @return list<array{0:string,1:string,2:string,3:bool}>
     */
    private function dbChecks(array $d, bool $stockSet = false): array
    {
        $fileQty   = array_sum(array_column($d['lines'], 'qty'));
        $fileCents = array_sum(array_column($d['lines'], 'cents'));
        $fileYears = [];
        foreach ($d['receipts'] as $r) {
            $fileYears[$r['year']] = ($fileYears[$r['year']] ?? 0) + $r['cents'];
        }
        ksort($fileYears);

        $c = [];
        $add = static function (string $label, $expected, $actual) use (&$c) {
            $c[] = [$label, (string) $expected, (string) $actual, (string) $expected === (string) $actual];
        };

        $add('sales_item rows', count($d['lines']), $this->count('SELECT COUNT(*) c FROM sales_item'));
        $add('sales_transaction rows', count($d['receipts']), $this->count('SELECT COUNT(*) c FROM sales_transaction'));
        $add('Lowest receipt no.', array_key_first($d['receipts']), $this->count('SELECT MIN(CAST(receipt_no AS UNSIGNED)) c FROM sales_transaction'));
        $add('Highest receipt no.', array_key_last($d['receipts']), $this->count('SELECT MAX(CAST(receipt_no AS UNSIGNED)) c FROM sales_transaction'));
        $add('Total quantity', $fileQty, $this->count('SELECT SUM(quantity_sold) c FROM sales_item'));
        $add('Total amount', self::money($fileCents), self::money($this->count('SELECT ROUND(SUM(total_amount) * 100) c FROM sales_transaction')));
        $years = [];
        foreach ($this->db->query('SELECT YEAR(sale_date) y, ROUND(SUM(total_amount) * 100) c FROM sales_transaction GROUP BY y')->getResultArray() as $r) {
            $years[(int) $r['y']] = (int) $r['c'];
        }
        foreach ($fileYears as $y => $exp) {
            $add("Amount {$y}", self::money($exp), self::money($years[$y] ?? 0));
        }
        $add('Products', count($d['products']), $this->count('SELECT COUNT(*) c FROM products'));
        $add('Product + size pairs', count($d['products']), $this->count("SELECT COUNT(*) c FROM (SELECT 1 FROM products GROUP BY product_code, COALESCE(size, '')) t"));
        $add('Pairs with more than one item_id', 0, $this->count("SELECT COUNT(*) c FROM (SELECT 1 FROM products GROUP BY product_code, COALESCE(size, '') HAVING COUNT(DISTINCT item_id) <> 1) t"));
        $add('Product names with two codes', 0, $this->count('SELECT COUNT(*) c FROM (SELECT 1 FROM products GROUP BY item_name HAVING COUNT(DISTINCT product_code) > 1) t'));

        // every receipt total equals the sum of that receipt's Amounts in the file
        $bad = 0;
        foreach ($this->db->query('SELECT receipt_no, ROUND(total_amount * 100) c, ROUND(subtotal * 100) s FROM sales_transaction')->getResultArray() as $r) {
            $exp = $d['receipts'][(int) $r['receipt_no']]['cents'] ?? null;
            if ($exp === null || (int) $r['c'] !== $exp || (int) $r['s'] !== $exp) {
                $bad++;
            }
        }
        $add('Receipts whose total differs from the file', 0, $bad);

        // sum of qty x price per receipt: only the receipts that differ in the file may differ here
        $dbMismatch = [];
        $rows = $this->db->query('SELECT st.receipt_no, ROUND(st.total_amount * 100) - ROUND(SUM(si.quantity_sold * si.selling_price) * 100) diff FROM sales_transaction st JOIN sales_item si ON si.sales_id = st.sales_id GROUP BY st.sales_id, st.receipt_no HAVING diff <> 0')->getResultArray();
        foreach ($rows as $r) {
            $dbMismatch[(int) $r['receipt_no']] = (int) $r['diff'];
        }
        ksort($dbMismatch);
        $fileMismatch = $this->amountMismatches($d);
        ksort($fileMismatch);
        $add('Receipts where qty x price differs from the total (as in the file)', json_encode($fileMismatch), json_encode($dbMismatch));

        $bulk = array_filter($d['lines'], static fn ($l) => $l['bulk'] === 1);
        $add('Bulk lines', count($bulk), $this->count('SELECT COUNT(*) c FROM sales_item WHERE is_bulk = 1'));
        $add('Bulk units', array_sum(array_column($bulk, 'qty')), $this->count('SELECT SUM(quantity_sold) c FROM sales_item WHERE is_bulk = 1'));
        if ($stockSet) {
            $add('Products with current_stock = 0', 0, $this->count('SELECT COUNT(*) c FROM products WHERE current_stock = 0'));
        }

        return $c;
    }

    // ---------- triggers / auto increment ----------

    private const TRIGGERS = [
        'inventory_log_no_update' => ['UPDATE', 'entries cannot be edited'],
        'inventory_log_no_delete' => ['DELETE', 'entries cannot be deleted'],
    ];

    private function triggerCount(): int
    {
        return $this->count('SELECT COUNT(*) c FROM information_schema.triggers WHERE trigger_schema = ? AND event_object_table = ?', [$this->db->getDatabase(), 'inventory_log']);
    }

    private function dropTriggers(): void
    {
        foreach (array_keys(self::TRIGGERS) as $name) {
            $this->db->query("DROP TRIGGER IF EXISTS {$name}");
        }
        CLI::write('  inventory_log triggers dropped (it is append-only, so it cannot be cleared with them).');
    }

    /** Same definitions as the MakeInventoryLogAppendOnly migration. */
    private function createTriggers(): void
    {
        foreach (self::TRIGGERS as $name => [$event, $text]) {
            $this->db->query("DROP TRIGGER IF EXISTS {$name}");
            $this->db->query("CREATE TRIGGER {$name} BEFORE {$event} ON inventory_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_log is append-only: {$text}'");
        }
    }

    private function ensureTriggers(bool $quiet): void
    {
        if ($this->triggerCount() !== 2) {
            $this->createTriggers();
            if (! $quiet) {
                CLI::write('  inventory_log triggers were missing; recreated.', 'yellow');
            }
        }
    }

    /** @param list<string> $tables */
    private function resetAutoIncrement(array $tables): void
    {
        $done = [];
        foreach ($tables as $t) {
            if (! $this->tableExists($t)) {
                continue;
            }
            $has = $this->count("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND extra LIKE '%auto_increment%'", [$this->db->getDatabase(), $t]);
            if ($has) {
                $this->db->query("ALTER TABLE `{$t}` AUTO_INCREMENT = 1"); // MariaDB keeps it above the highest id
                $done[] = $t;
            }
        }
        CLI::write('  AUTO_INCREMENT reset on: ' . implode(', ', $done));
    }

    // ---------- backup / ml-service / child processes ----------

    private function latestBackup(): ?string
    {
        $newest = null;
        foreach (glob(ROOTPATH . '../data-cleaning/backup/rfourl_dss_before_reseed_*.sql') ?: [] as $f) {
            if (filemtime($f) >= time() - 86400 && filesize($f) > 100000 && str_contains((string) file_get_contents($f, false, null, max(0, filesize($f) - 200)), 'Dump completed')) {
                $newest = $f;
            }
        }

        return $newest;
    }

    private function mlUrl(): string
    {
        return rtrim((string) env('ML_SERVICE_URL', 'http://127.0.0.1:5001'), '/');
    }

    private function mlReachable(): bool
    {
        $ch = curl_init($this->mlUrl() . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200 && is_string($body) && str_contains($body, 'ok');
    }

    /**
     * Runs dss:run in this process (so it uses the same default connection as everything else here)
     * and checks from the database that it really used the live K-Means service.
     */
    private function dssRun(): bool
    {
        $before = $this->count('SELECT COUNT(*) c FROM pdss_computation');
        $this->call('dss:run');

        $products  = $this->count('SELECT COUNT(*) c FROM products WHERE is_active = 1');
        $added     = $this->count('SELECT COUNT(*) c FROM pdss_computation') - $before;
        $clusters  = $this->count('SELECT COUNT(*) c FROM cluster_segments');
        $snapshot  = $this->db->query("SELECT raw_data FROM analytics_snapshot WHERE metric_type = 'abc_distribution' ORDER BY snapshot_id DESC LIMIT 1")->getRow('raw_data');
        $live      = is_string($snapshot) && (json_decode($snapshot, true)['source'] ?? null) === 'live_cluster';

        if ($added !== $products || $clusters !== $products || ! $live) {
            CLI::error("dss:run did not finish properly with the live K-Means service ({$added} computations and {$clusters} clusters for {$products} products, live clusters: " . ($live ? 'yes' : 'no') . '). Stopping.');

            return false;
        }
        CLI::write("  dss:run ok: {$added} computations, {$clusters} K-Means clusters (live service).", 'green');

        return true;
    }

    // ---------- demo opening stock ----------

    /** The first pass only existed to get ROP / EOQ / class; its alerts and history are removed so the second pass is the only record. */
    private function cleanFirstPass(): void
    {
        $this->db->query('DELETE FROM reorder_alert');
        $this->db->query('DELETE FROM analytics_snapshot');
        $this->db->query('DELETE FROM pdss_computation');
        $this->resetAutoIncrement(['reorder_alert', 'analytics_snapshot', 'pdss_computation']);
        CLI::write('  removed the first pass\'s alerts, snapshots and computations (the second pass recreates them).');
    }

    /**
     * Each active product's reorder point, EOQ and K-Means class from the latest dss:run.
     *
     * @return list<array<string,mixed>>
     */
    private function planInputs(): array
    {
        $rows = $this->db->query(
            'SELECT p.item_id, p.product_code, p.size, COALESCE(p.manual_rop_warning, 0) rop, p.abc_category abc,
                    (SELECT c.eoq_value FROM pdss_computation c WHERE c.item_id = p.item_id ORDER BY c.computation_id DESC LIMIT 1) eoq
             FROM products p WHERE p.is_active = 1'
        )->getResultArray();

        $missing = count(array_filter($rows, static fn ($r) => $r['eoq'] === null));
        if ($missing > 0) {
            throw new \RuntimeException("{$missing} products have no EOQ from dss:run; the opening stock cannot be planned.");
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows from planInputs()
     *
     * @return array<string,mixed>|null the plan, or null when a stock file was used
     */
    private function openingStock(array $d, int $owner, array $rows): ?array
    {

        $plan = null;
        if ($d['stockFile'] !== null) {
            $note   = self::OPENING_NOTE_COUNT;
            $stock  = [];
            foreach ($rows as $r) {
                $stock[$r['item_id']] = (int) ($d['stockFile'][self::key($r['product_code'], (string) $r['size'])] ?? 0);
            }
            CLI::write('  stock from --stock-file: ' . count($d['stockFile']) . ' listed products, the rest set to 0.');
        } else {
            $note = self::OPENING_NOTE_DEMO;
            $plan = (new DemoStockPlanner())->plan(array_map(static fn ($r) => [
                'item_id' => (string) $r['item_id'],
                'rop'     => (int) $r['rop'],
                'eoq'     => (int) $r['eoq'],
                'abc'     => $r['abc'],
            ], $rows));
            $stock = $plan['stock'];
            foreach ($plan['notes'] as $n) {
                CLI::write('  note: ' . $n, 'yellow');
            }
        }

        $this->db->transException(true);
        $this->db->transBegin();
        try {
            $log = [];
            $at  = date('Y-m-d H:i:s');
            foreach ($stock as $itemId => $qty) {
                $this->db->query('UPDATE products SET current_stock = ? WHERE item_id = ?', [$qty, (string) $itemId]);
                if ($qty > 0) {
                    $log[] = ['item_id' => (string) $itemId, 'user_id' => $owner, 'log_type' => 'MANUAL_ADJUSTMENT', 'quantity_changed' => $qty,
                        'stock_after_change' => $qty, 'reference_so_id' => null, 'notes' => $note, 'timestamp' => $at];
                }
            }
            foreach (array_chunk($log, 200) as $chunk) {
                $this->db->table('inventory_log')->insertBatch($chunk);
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        } finally {
            $this->db->transException(false);
        }
        CLI::write('  stock set on ' . count($stock) . ' products; ' . count($log) . " inventory_log entries ('{$note}', user {$owner}).");

        return $plan;
    }

    // ---------- final report ----------

    private function finalReport(array $d, ?array $plan): void
    {
        $model = new ProductModel();

        $this->heading('Checks after everything (the database)');
        $demo   = $d['stockFile'] === null;
        $checks = $this->dbChecks($d, $demo);
        $this->printChecks($checks);
        if ($this->failed($checks)) {
            CLI::error('RUN FAILED: a final check did not pass (see above).');
        }

        $products = $this->db->query('SELECT p.*, (SELECT COUNT(*) FROM reorder_alert a WHERE a.item_id = p.item_id AND a.status IN (\'Active\',\'Acknowledged\',\'Ordered\')) open_alerts FROM products p')->getResultArray();

        $this->heading('K-Means classes (abc_category)');
        $cls = array_count_values(array_map(static fn ($p) => $p['abc_category'] ?? 'none', $products));
        ksort($cls);
        foreach ($cls as $k => $n) {
            CLI::write("  Class {$k}: {$n}");
        }

        $this->heading('Stock status BY ITEM (every size is its own row, the app\'s own rule)');
        $counts = array_fill_keys(ProductModel::STATUSES, 0);
        $classA = ['Reorder Now' => [], 'Low Stock' => []];
        foreach ($products as $p) {
            $label = $model->getStatus($p)['label'];
            $counts[$label]++;
            if (($p['abc_category'] ?? null) === 'A' && isset($classA[$label])) {
                $classA[$label][] = sprintf('%s %s%s (stock %d, ROP %d)', $p['item_id'], $p['item_name'], $p['size'] ? " {$p['size']}" : '', $p['current_stock'], (int) $p['manual_rop_warning']);
            }
        }
        $n = count($products);
        $rows = [];
        foreach ($counts as $label => $c) {
            $rows[] = [$label, (string) $c, sprintf('%.1f%%', 100 * $c / max(1, $n)), $plan ? (string) $plan['targets'][$label] : '-'];
        }
        CLI::table($rows, ['Status', 'Items', 'Share', 'Target']);

        // The Inventory page counts PRODUCTS: sizes of one name + category grouped, shown at the worst size's status.
        $byProduct = $model->statusCounts(array_map(static function ($p) {
            unset($p['open_alerts']);

            return $p;
        }, $products));
        $groups = array_sum($byProduct);
        $this->heading('Stock status BY PRODUCT (as the Inventory page counts: sizes grouped, worst size wins)');
        $rows = [];
        foreach ($byProduct as $label => $c) {
            $rows[] = [$label, (string) $c, sprintf('%.1f%%', 100 * $c / max(1, $groups))];
        }
        CLI::table($rows, ['Status', 'Products', 'Share']);
        CLI::write("  {$groups} products from {$n} items.");
        if (! $demo) {
            CLI::write('  Items with stock 0 (from the stock file): ' . $this->count('SELECT COUNT(*) c FROM products WHERE current_stock = 0'));
        }

        foreach ($classA as $label => $list) {
            CLI::write("Class A items in {$label}: " . count($list), 'yellow');
            foreach ($list as $line) {
                CLI::write('  ' . $line);
            }
        }

        $units = $this->count('SELECT SUM(current_stock) c FROM products');
        $open  = $this->count("SELECT COUNT(*) c FROM reorder_alert WHERE status IN ('Active','Acknowledged','Ordered')");
        CLI::newLine();
        CLI::write("Total units on hand: {$units}");
        CLI::write("Open reorder alerts: {$open} (Reorder Now products: {$counts['Reorder Now']})");
        CLI::write('Estimated unit costs: ' . count(array_filter($d['products'], static fn ($p) => $p['cost_source'] === 'estimated')) . ' (list: ' . WRITEPATH . 'dss-seed-dataset/estimated_cost_products.csv)');
        CLI::write('inventory_log entries: ' . $this->count('SELECT COUNT(*) c FROM inventory_log') . '; triggers present: ' . $this->triggerCount() . ' of 2');
        CLI::write('Next POS receipt number: ' . (new \App\Models\SalesTransactionModel())->generateReceiptNo());
    }

    // ---------- output helpers ----------

    private function heading(string $t): void
    {
        CLI::newLine();
        CLI::write("== {$t} ==", 'yellow');
    }

    /** @param list<array{0:string,1:string,2:string,3:bool}> $checks */
    private function printChecks(array $checks): void
    {
        foreach ($checks as [$label, $expected, $actual, $ok]) {
            CLI::write(sprintf('  [%s] %-70s expected %s, got %s', $ok ? 'ok' : 'FAIL', $label, $expected, $actual), $ok ? 'green' : 'red');
        }
    }

    /** @param list<array{0:string,1:string,2:string,3:bool}> $checks */
    private function failed(array $checks): bool
    {
        return array_filter($checks, static fn ($c) => ! $c[3]) !== [];
    }
}
