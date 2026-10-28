<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Loads the cleaned 2021-2026 sales history (data-cleaning/output/
 * transactions_final.csv) into products / sales_transaction / sales_item.
 *
 *   php spark dss:import-history                 dry run (default) - writes nothing
 *   php spark dss:import-history --commit        import
 *   php spark dss:import-history --rollback      remove what an import added
 *   options: --file <csv>   --user <user_id>   --db <database name>
 *
 * How lines are matched to products:
 *   one product per catalog item + size. An existing product is reused when its
 *   name equals the catalog name (and its size is empty or equal); otherwise a
 *   new product is created INACTIVE, with no supplier and no stock, so it never
 *   shows in POS / inventory or gets a reorder point - it only carries history.
 *
 * Safe to re-run: receipts are skipped when their receipt_no (BIR-YYYYMMDD-NN)
 * already exists. Everything is one transaction. Imported receipts all start with
 * "BIR-", which is what --rollback uses to find them.
 */
class ImportHistory extends BaseCommand
{
    protected $group       = 'DSS';
    protected $name        = 'dss:import-history';
    protected $description = '[DEPRECATED - use dss:seed-dataset] Imports the old cleaned 2021-2026 sales history.';
    protected $usage       = 'dss:import-history [--commit] [--rollback] [--file path] [--user id] [--db name]';

    private const PREFIX = 'BIR-';

    public function run(array $params)
    {
        CLI::error('dss:import-history is deprecated: it loads the OLD sales data and would bring it back.');
        CLI::write('Use `php spark dss:seed-dataset` instead (see the README, "Rebuild the database").', 'yellow');

        return;
    }

    /** The original command, kept for reference; no longer reachable. */
    private function legacyRun(array $params)
    {
        $dbName = CLI::getOption('db');
        $db     = $dbName ? db_connect(array_merge((array) config('Database')->default, ['database' => $dbName])) : db_connect();
        CLI::write('Database: ' . $db->getDatabase(), 'cyan');
        $file = $params['file'] ?? CLI::getOption('file') ?? ROOTPATH . '../data-cleaning/output/transactions_final.csv';

        if (CLI::getOption('rollback')) {
            return $this->rollback($db);
        }

        $commit = CLI::getOption('commit') !== null;

        if (! is_file($file)) {
            CLI::error("File not found: {$file}");
            return;
        }

        foreach (['product_code', 'size_type'] as $col) {
            if (! $db->fieldExists($col, 'products')) {
                CLI::error("products.{$col} is missing - run `php spark migrate` first.");
                return;
            }
        }

        $userId = (int) (CLI::getOption('user') ?: ($db->table('users')->where('role', 'Admin')->orderBy('user_id')->get()->getRow('user_id') ?? 0));
        if (! $userId) {
            CLI::error('No Admin user found; pass --user <id>.');
            return;
        }

        $rows = $this->readCsv($file);
        CLI::write(count($rows) . ' sale lines read from ' . basename($file));

        // ---- the shop's existing products, by normalised name (they are the real item list)
        $norm = static fn (string $s): string => preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
        $byName = $sellById = [];
        foreach ($db->table('products')->select('item_id, item_name, selling_price')->get()->getResultArray() as $p) {
            $byName[$norm($p['item_name'])] ??= $p['item_id'];
            $sellById[$p['item_id']] = (float) $p['selling_price'];
        }
        $nextId  = (int) ($db->table('products')->select('MAX(CAST(item_id AS UNSIGNED)) AS mx')->get()->getRow('mx') ?? 0);
        $aliases = $this->loadAliases(dirname($file) . '/catalog_clean.csv');

        // ---- match every distinct (catalog id, item detail, size) to a product:
        //      1) exact name  2) a catalog alias of the same item  3) new inactive product
        $match = $tierOf = $fallback = $unmatched = [];
        foreach ($rows as $r) {
            $code = $r['product_id'];
            $size = $r['size_clean'];
            $d    = $r['item_detail'];
            $k    = $code . '|' . $d . '|' . $size;
            if (isset($match[$k])) {
                continue;
            }
            $canon = $r['product_name_canonical'];
            $found = null;
            $tier  = 'exact';
            foreach (($size !== '' ? [$d . ' ' . $size, $d, $canon . ' ' . $size, $canon] : [$d, $canon]) as $c) {
                if (isset($byName[$norm($c)])) {
                    $found = $byName[$norm($c)];
                    break;
                }
            }
            if ($found === null) {
                $tier = 'alias';
                $al   = $aliases[$code] ?? [];
                if ($size !== '') {
                    foreach ($al as $a) {
                        if (isset($byName[$norm($a . ' ' . $size)])) {
                            $found = $byName[$norm($a . ' ' . $size)];
                            break;
                        }
                    }
                }
                if ($found === null) {
                    foreach ($al as $a) {
                        if (isset($byName[$norm($a)])) {
                            $found = $byName[$norm($a)];
                            break;
                        }
                    }
                }
            }
            if ($found === null) {
                $tier = 'new';
                $fk   = $code . '|' . $size;
                if (! isset($fallback[$fk])) {
                    $fallback[$fk] = sprintf('%04d', ++$nextId);
                }
                $found       = $fallback[$fk];
                $unmatched[] = [$code, $canon, $d, $size];
            }
            $match[$k]  = $found;
            $tierOf[$k] = $tier;
        }

        // ---- typical price per product, for lines whose source price is unusable
        $prices = [];
        foreach ($rows as $r) {
            if ($r['price_ok'] === 'yes' && (float) $r['unit_price'] > 0) {
                $prices[$match[$r['product_id'] . '|' . $r['item_detail'] . '|' . $r['size_clean']]][] = (float) $r['unit_price'];
                $prices['code:' . $r['product_id']][] = (float) $r['unit_price'];
            }
        }
        $median = static function (array $a): float {
            sort($a);
            $n = count($a);
            return $n ? (float) ($n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2) : 0.0;
        };
        $priceFor = function (string $itemId, string $code) use ($prices, $median, $sellById): float {
            if (! empty($prices[$itemId])) {
                return $median($prices[$itemId]);
            }
            return ($sellById[$itemId] ?? 0) > 0 ? $sellById[$itemId] : $median($prices['code:' . $code] ?? []);
        };

        // ---- products the import has to create (only for lines nothing matched)
        $newProducts = [];
        foreach ($rows as $r) {
            $k = $r['product_id'] . '|' . $r['item_detail'] . '|' . $r['size_clean'];
            $id = $match[$k];
            if ($tierOf[$k] === 'new' && ! isset($newProducts[$id])) {
                $newProducts[$id] = [
                    'item_id'       => $id,
                    'product_code'  => $r['product_id'],
                    'supplier_id'   => null,
                    'item_name'     => $r['product_name_canonical'],
                    'category'      => $r['category'] === 'Metal / Insignia' ? 'Metal' : $r['category'],
                    'size'          => $r['size_clean'] !== '' ? $r['size_clean'] : null,
                    'size_type'     => $r['size_type'] !== '' ? $r['size_type'] : null,
                    'unit_cost'     => 0,
                    'selling_price' => $priceFor($id, $r['product_id']),
                    'current_stock' => 0,
                    'is_active'     => 0,
                ];
            }
        }

        // ---- group lines into receipts
        $receipts = [];
        $estimated = 0;
        $lineTier = ['exact' => 0, 'alias' => 0, 'new' => 0];
        foreach ($rows as $r) {
            $k  = $r['product_id'] . '|' . $r['item_detail'] . '|' . $r['size_clean'];
            $id = $match[$k];
            $lineTier[$tierOf[$k]]++;
            $price = ($r['price_ok'] === 'yes' && $r['unit_price'] !== '') ? (float) $r['unit_price'] : $priceFor($id, $r['product_id']);
            if ($r['price_ok'] !== 'yes') {
                $estimated++;
            }
            $no = self::PREFIX . substr($r['transaction_id'], 1);
            $receipts[$no]['date']    = $r['date'];
            $receipts[$no]['lines'][] = ['item_id' => $id, 'qty' => (int) $r['quantity'], 'price' => $price];
        }

        if ($unmatched) {
            $out = fopen(dirname($file) . '/unmatched_products.csv', 'w');
            fputcsv($out, ['product_id', 'product_name_canonical', 'item_detail', 'size']);
            foreach (array_unique($unmatched, SORT_REGULAR) as $u) {
                fputcsv($out, $u);
            }
            fclose($out);
        }

        $existing = array_column($db->table('sales_transaction')->select('receipt_no')->like('receipt_no', self::PREFIX, 'after')->get()->getResultArray(), 'receipt_no');
        $existing = array_flip($existing);
        $todo     = array_diff_key($receipts, $existing);
        $lines    = array_sum(array_map(fn ($t) => count($t['lines']), $todo));

        CLI::newLine();
        CLI::write('Lines matched to an existing product by exact name: ' . $lineTier['exact']);
        CLI::write('Lines matched through a catalog alias:             ' . $lineTier['alias']);
        CLI::write('Lines with no match (new inactive product):        ' . $lineTier['new'] . '  -> unmatched_products.csv');
        CLI::write('Products to create (inactive):      ' . count($newProducts));
        CLI::write('Receipts to import:                 ' . count($todo) . ' (' . count($existing) . ' already imported, skipped)');
        CLI::write('Sale lines to import:               ' . $lines);
        CLI::write('Lines priced from the product\'s typical price (source price unusable): ' . $estimated);
        if ($todo) {
            $dates = array_column($todo, 'date');
            CLI::write('Date range:                         ' . min($dates) . ' to ' . max($dates));
        }

        if (! $commit) {
            CLI::newLine();
            CLI::write('Dry run - nothing written. Re-run with --commit to import.', 'yellow');
            return;
        }

        $db->transStart();
        if ($newProducts) {
            foreach (array_chunk(array_values($newProducts), 200) as $chunk) {
                $db->table('products')->insertBatch($chunk);
            }
        }
        foreach ($todo as $no => $t) {
            $subtotal = 0.0;
            foreach ($t['lines'] as $l) {
                $subtotal += $l['qty'] * $l['price'];
            }
            $db->table('sales_transaction')->insert([
                'receipt_no'     => $no,
                'user_id'        => $userId,
                'sale_date'      => $t['date'] . ' 12:00:00',
                'payment_method' => 'Cash',
                'cash_amount'    => round($subtotal, 2),
                'subtotal'       => round($subtotal, 2),
                'discount'       => 0,
                'total_amount'   => round($subtotal, 2),
            ]);
            $salesId = $db->insertID();
            $batch   = [];
            foreach ($t['lines'] as $l) {
                $batch[] = ['sales_id' => $salesId, 'item_id' => $l['item_id'], 'quantity_sold' => $l['qty'], 'selling_price' => $l['price']];
            }
            $db->table('sales_item')->insertBatch($batch);
        }
        $db->transComplete();

        if (! $db->transStatus()) {
            CLI::error('Import failed and was rolled back.');
            return;
        }
        CLI::write('Imported. Remove it again with: php spark dss:import-history --rollback', 'green');
    }

    /** catalog_clean.csv -> [product_id => [alias names]] */
    private function loadAliases(string $file): array
    {
        $out = [];
        if (! is_file($file)) {
            return $out;
        }
        foreach ($this->readCsv($file) as $c) {
            $names = [$c['Product_Name_Canonical']];
            foreach (explode(',', (string) $c['Common_Raw_Variants']) as $v) {
                if (trim($v) !== '') {
                    $names[] = trim($v);
                }
            }
            $names[] = trim(explode(' / ', $c['Product_Name_Canonical'])[0]);
            $names[] = trim(preg_replace('/\s*\(.*\)/', '', $c['Product_Name_Canonical']));
            $out[$c['Product_ID']] = array_values(array_unique($names));
        }
        return $out;
    }

    private function rollback($db): void
    {
        $n = $db->table('sales_transaction')->like('receipt_no', self::PREFIX, 'after')->countAllResults();
        CLI::write("Removing {$n} imported receipts (their lines go with them)...");
        $db->transStart();
        $db->table('sales_transaction')->like('receipt_no', self::PREFIX, 'after')->delete();
        // only products the import created: has a product_code, inactive, no stock, no sales left
        $db->query('DELETE p FROM products p
                    LEFT JOIN sales_item si ON si.item_id = p.item_id
                    WHERE p.product_code IS NOT NULL AND p.is_active = 0 AND p.current_stock = 0 AND si.item_id IS NULL');
        $db->transComplete();
        CLI::write($db->transStatus() ? 'Done.' : 'Failed.', $db->transStatus() ? 'green' : 'red');
    }

    private function readCsv(string $file): array
    {
        $h = fopen($file, 'r');
        $header = fgetcsv($h);
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        $out = [];
        while (($r = fgetcsv($h)) !== false) {
            if (count($r) === count($header)) {
                $out[] = array_combine($header, $r);
            }
        }
        fclose($h);
        return $out;
    }
}
