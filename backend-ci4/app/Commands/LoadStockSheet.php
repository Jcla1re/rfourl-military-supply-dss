<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Loads the filled stock-count sheet: one active product per item + size.
 *
 *   php spark dss:load-stock-sheet                 dry run (default) - writes nothing
 *   php spark dss:load-stock-sheet --commit        load it
 *   php spark dss:load-stock-sheet --rollback      undo the last load (uses the manifest it wrote)
 *   options: --file <csv>  --sales <csv>  --db <name>
 *
 * For every sheet row with carry_this_size = Y and a stock_count:
 *   - the size product is created (or the inactive one the history import made is reused), named like the
 *     item already in the system so POS and Inventory group the sizes together;
 *   - the item's old size-less product is switched off and its stock set to 0 (its total was split across
 *     the sizes), so stock is not counted twice;
 *   - past sale lines whose size was recorded are pointed at the matching size product, so each size has
 *     its own demand history for the DSS.
 * Sale lines with no recorded size stay on the old (now inactive) product.
 *
 * Everything is one transaction, and the old values are kept in a manifest so --rollback restores them.
 */
class LoadStockSheet extends BaseCommand
{
    protected $group       = 'DSS';
    protected $name        = 'dss:load-stock-sheet';
    protected $description = 'Loads the filled stock-count sheet as active size products (dry run unless --commit).';
    protected $usage       = 'dss:load-stock-sheet [--commit] [--rollback] [--file path] [--sales path] [--db name]';

    public function run(array $params)
    {
        $dbName = CLI::getOption('db');
        $db     = $dbName ? db_connect(array_merge((array) config('Database')->default, ['database' => $dbName])) : db_connect();
        CLI::write('Database: ' . $db->getDatabase(), 'cyan');

        $manifestPath = WRITEPATH . 'stock_sheet_load_' . $db->getDatabase() . '.json';

        if (CLI::getOption('rollback')) {
            return $this->rollback($db, $manifestPath);
        }

        $commit = CLI::getOption('commit') !== null;
        $base   = ROOTPATH . '../data-cleaning/output/';
        $sheet  = CLI::getOption('file') ?: $base . 'Stock_Count_Sheet_prefilled.csv';
        $sales  = CLI::getOption('sales') ?: $base . 'transactions_final.csv';

        foreach ([$sheet, $sales] as $f) {
            if (! is_file($f)) {
                CLI::error("File not found: {$f}");
                return;
            }
        }
        if (is_file($manifestPath)) {
            CLI::error('A previous load is still on record (' . basename($manifestPath) . '). Run --rollback first, or delete that file if you are sure.');
            return;
        }

        // ---- sheet rows to load
        $todo = [];
        foreach ($this->readCsv($sheet) as $r) {
            if (strtoupper(trim($r['carry_this_size (Y/N)'])) !== 'Y' || trim($r['stock_count']) === '' || trim($r['system_item_id']) === '') {
                continue;
            }
            $todo[] = $r;
        }
        if (! $todo) {
            CLI::write('Nothing marked Y with a stock count - nothing to do.', 'yellow');
            return;
        }

        $parents = [];
        foreach ($db->table('products')->get()->getResultArray() as $p) {
            $parents[$p['item_id']] = $p;
        }
        $existingSized = [];   // product_code|size => item_id (rows the history import made)
        foreach ($parents as $p) {
            if ($p['product_code'] !== null && $p['size'] !== null) {
                $existingSized[$p['product_code'] . '|' . $p['size']] = $p['item_id'];
            }
        }
        $next = (int) max(array_map('intval', array_keys($parents)));

        $create = $update = $target = [];
        $parentIds = [];
        foreach ($todo as $r) {
            $pid = $r['system_item_id'];
            if (! isset($parents[$pid])) {
                CLI::write("  skip {$r['product_code']} {$r['size']}: system item {$pid} no longer exists", 'yellow');
                continue;
            }
            $parent = $parents[$pid];
            $k      = $r['product_code'] . '|' . $r['size'];
            $fields = [
                'item_name'     => $parent['item_name'],
                'category'      => $parent['category'],
                'size'          => $r['size'],
                'size_type'     => $r['size_type'],
                'product_code'  => $r['product_code'],
                'supplier_id'   => trim($r['supplier_id']) !== '' ? trim($r['supplier_id']) : $parent['supplier_id'],
                'unit_cost'     => $r['unit_cost'] !== '' ? (float) $r['unit_cost'] : (float) $parent['unit_cost'],
                'selling_price' => $r['selling_price'] !== '' ? (float) $r['selling_price'] : (float) $parent['selling_price'],
                'current_stock' => (int) $r['stock_count'],
                'is_active'     => 1,
            ];
            if (isset($existingSized[$k])) {
                $id            = $existingSized[$k];
                $update[$id]   = $fields;
            } else {
                $id            = sprintf('%04d', ++$next);
                $create[$id]   = ['item_id' => $id] + $fields;
            }
            $target[$k]        = $id;
            $parentIds[$pid]   = true;
        }

        // ---- relink past sales that recorded a size
        $dbLines = [];
        $q = $db->query("SELECT t.receipt_no, i.sales_item_id, i.item_id, i.quantity_sold
                         FROM sales_transaction t JOIN sales_item i ON i.sales_id = t.sales_id
                         WHERE t.receipt_no LIKE 'BIR-%' ORDER BY t.sales_id, i.sales_item_id");
        foreach ($q->getResultArray() as $l) {
            $dbLines[$l['receipt_no']][] = $l;
        }
        $csvLines = [];
        foreach ($this->readCsv($sales) as $l) {
            $csvLines['BIR-' . substr($l['transaction_id'], 1)][] = $l;
        }

        $relink = [];
        $problems = 0;
        foreach ($csvLines as $receipt => $lines) {
            $have = $dbLines[$receipt] ?? [];
            if (count($have) !== count($lines)) {
                $problems++;
                continue;
            }
            foreach ($lines as $i => $l) {
                if ((int) $have[$i]['quantity_sold'] !== (int) $l['quantity']) {
                    $problems++;
                    continue 2;
                }
            }
            foreach ($lines as $i => $l) {
                if ($l['size_clean'] === '') {
                    continue;
                }
                $k = $l['product_id'] . '|' . $l['size_clean'];
                if (! isset($target[$k])) {
                    continue;
                }
                $cur = $have[$i]['item_id'];
                if ($cur !== $target[$k]) {
                    $relink[$have[$i]['sales_item_id']] = ['from' => $cur, 'to' => $target[$k]];
                }
            }
        }

        $units = 0;
        CLI::newLine();
        CLI::write('Size products to create:                ' . count($create));
        CLI::write('Existing size products to update/activate: ' . count($update));
        CLI::write('Old size-less products to switch off:    ' . count($parentIds));
        CLI::write('Past sale lines to point at a size:      ' . count($relink));
        CLI::write('Stock placed on size products:           ' . array_sum(array_column($create + $update, 'current_stock')));
        if ($problems) {
            CLI::write("Receipts skipped because database and sheet disagree: {$problems}", 'yellow');
        }

        if (! $commit) {
            CLI::newLine();
            CLI::write('Dry run - nothing written. Re-run with --commit to load.', 'yellow');
            return;
        }

        // ---- write, remembering how to undo
        $manifest = ['created' => array_keys($create), 'updated' => [], 'parents' => [], 'relinked' => [], 'at' => date('c')];
        $cols = ['item_name', 'category', 'size', 'size_type', 'product_code', 'supplier_id', 'unit_cost', 'selling_price', 'current_stock', 'is_active'];

        $db->transStart();
        foreach ($update as $id => $f) {
            $manifest['updated'][$id] = array_intersect_key($parents[$id], array_flip($cols));
            $db->table('products')->where('item_id', $id)->update($f);
        }
        foreach (array_chunk(array_values($create), 100) as $chunk) {
            $db->table('products')->insertBatch($chunk);
        }
        foreach (array_keys($parentIds) as $pid) {
            $manifest['parents'][$pid] = ['current_stock' => $parents[$pid]['current_stock'], 'is_active' => $parents[$pid]['is_active']];
            $db->table('products')->where('item_id', $pid)->update(['current_stock' => 0, 'is_active' => 0]);
        }
        foreach ($relink as $sid => $m) {
            $manifest['relinked'][$sid] = $m['from'];
            $db->table('sales_item')->where('sales_item_id', $sid)->update(['item_id' => $m['to']]);
        }
        $db->transComplete();

        if (! $db->transStatus()) {
            CLI::error('Load failed and was rolled back.');
            return;
        }
        file_put_contents($manifestPath, json_encode($manifest));
        CLI::write('Loaded. Undo with: php spark dss:load-stock-sheet --rollback', 'green');
    }

    private function rollback($db, string $manifestPath): void
    {
        if (! is_file($manifestPath)) {
            CLI::error('No load on record for this database - nothing to undo.');
            return;
        }
        $m = json_decode(file_get_contents($manifestPath), true);

        $db->transStart();
        foreach ($m['relinked'] as $sid => $from) {
            $db->table('sales_item')->where('sales_item_id', $sid)->update(['item_id' => $from]);
        }
        foreach ($m['parents'] as $pid => $old) {
            $db->table('products')->where('item_id', $pid)->update($old);
        }
        foreach ($m['updated'] as $id => $old) {
            $db->table('products')->where('item_id', $id)->update($old);
        }
        foreach ($m['created'] as $id) {
            $db->table('products')->where('item_id', $id)->delete();
        }
        $db->transComplete();

        if ($db->transStatus()) {
            unlink($manifestPath);
            CLI::write('Undone: ' . count($m['created']) . ' size products removed, ' . count($m['parents']) . ' items restored, ' . count($m['relinked']) . ' sale lines moved back.', 'green');
        } else {
            CLI::error('Rollback failed.');
        }
    }

    private function readCsv(string $file): array
    {
        $h      = fopen($file, 'r');
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
