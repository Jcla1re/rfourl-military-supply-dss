<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * The logic behind `php spark sample-suppliers load|remove` (App\Commands\SampleSuppliers).
 *
 * Sample suppliers are made-up suppliers (suppliers.is_sample = 1) with price-list rows (supplier_prices,
 * is_sample = 1) so the owner can compare offers. They have no portal user, no orders and no products; they
 * are never an item's main supplier. Nothing here touches pdss_computation, products, stock orders or users.
 *
 * Suppliers and price factors come in as arrays (the command keeps them at its top, so they can be adjusted
 * without touching this logic):
 *
 *   $suppliers  id => [name, category, lead_time_days, min_order_qty (per item, null = none), payment_terms,
 *                      contact_person, contact_email, contact_number]
 *   $priceList  supplier id => [factor, items => [item name, ...]]   price = round(unit_cost x factor), whole pesos
 */
class SampleSuppliers
{
    /**
     * Creates the sample suppliers and their price-list rows. Safe to run again (it updates what it made).
     * Item names are matched exactly (upper / lower case ignored) against active products, all sizes; if any
     * name matches nothing, NOTHING is written and the unmatched names are returned.
     *
     * @return array{ok: bool, errors: list<string>, unmatched: list<string>, suppliers_created: int, suppliers_existing: int,
     *               rows: list<array{supplier: string, item: string, size: string, main_cost: float, price: int, status: string}>}
     */
    public static function load(array $suppliers, array $priceList, ?BaseConnection $db = null): array
    {
        $db ??= db_connect();
        $out = ['ok' => false, 'errors' => [], 'unmatched' => [], 'suppliers_created' => 0, 'suppliers_existing' => 0, 'rows' => []];

        foreach (['suppliers' => 'is_sample', 'supplier_prices' => 'is_sample'] as $table => $column) {
            if (! $db->tableExists($table) || ! $db->fieldExists($column, $table)) {
                $out['errors'][] = "The database is not ready ({$table}.{$column} is missing). Run: php spark migrate";

                return $out;
            }
        }

        // 1. Every item name must match, before anything is written.
        $matches = [];
        foreach ($priceList as $entry) {
            foreach ($entry['items'] as $name) {
                if (isset($matches[$name])) {
                    continue;
                }
                $matches[$name] = $db->table('products')->where('is_active', 1)->where('LOWER(item_name)', mb_strtolower($name))->orderBy('item_id')->get()->getResultArray();
                if ($matches[$name] === []) {
                    $out['unmatched'][] = $name;
                }
            }
        }
        if ($out['unmatched'] !== []) {
            $out['errors'][] = 'These item names match no active product, so nothing was loaded: ' . implode(', ', $out['unmatched']) . '. Fix the names (or the products) and run it again.';

            return $out;
        }

        // 2. A real supplier must never be overwritten.
        foreach ($suppliers as $id => $s) {
            $existing = $db->table('suppliers')->where('supplier_id', $id)->get()->getRowArray();
            if ($existing && (int) $existing['is_sample'] !== 1) {
                $out['errors'][] = "Supplier ID {$id} already belongs to a real supplier ({$existing['company_name']}). Nothing was loaded.";

                return $out;
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->transStart();

        foreach ($suppliers as $id => $s) {
            $row = [
                'company_name'   => $s['name'],
                'category'       => $s['category'],
                'lead_time_days' => (int) $s['lead_time_days'],
                'payment_terms'  => $s['payment_terms'] ?? null,
                'contact_person' => $s['contact_person'],
                'contact_email'  => $s['contact_email'],
                'contact_number' => $s['contact_number'],
                'is_active'      => 1,
                'is_sample'      => 1,
                'updated_at'     => $now,
            ];
            if ($db->table('suppliers')->where('supplier_id', $id)->countAllResults() > 0) {
                $db->table('suppliers')->where('supplier_id', $id)->update($row);
                $out['suppliers_existing']++;
            } else {
                $db->table('suppliers')->insert($row + ['supplier_id' => $id, 'created_at' => $now]);
                $out['suppliers_created']++;
            }
        }

        foreach ($priceList as $supplierId => $entry) {
            $minimum = $suppliers[$supplierId]['min_order_qty'] ?? null;
            foreach ($entry['items'] as $name) {
                foreach ($matches[$name] as $product) {
                    $price    = (int) round((float) $product['unit_cost'] * (float) $entry['factor']); // whole pesos
                    $existing = $db->table('supplier_prices')->where('supplier_id', $supplierId)->where('item_id', $product['item_id'])->get()->getRowArray();
                    $values   = ['unit_price' => $price, 'lead_time_days' => null, 'min_order_qty' => $minimum, 'is_sample' => 1, 'updated_at' => $now];

                    if ($existing && (int) $existing['is_sample'] !== 1) {
                        $status = 'kept (real price)'; // never overwrite a price somebody entered
                    } elseif ($existing) {
                        $db->table('supplier_prices')->where('id', $existing['id'])->update($values);
                        $status = 'updated';
                    } else {
                        $db->table('supplier_prices')->insert($values + ['supplier_id' => $supplierId, 'item_id' => $product['item_id'], 'created_at' => $now]);
                        $status = 'new';
                    }
                    $out['rows'][] = [
                        'supplier'  => $suppliers[$supplierId]['name'],
                        'item'      => (string) $product['item_name'],
                        'size'      => (string) ($product['size'] ?? ''),
                        'main_cost' => (float) $product['unit_cost'],
                        'price'     => $price,
                        'status'    => $status,
                    ];
                }
            }
        }

        $db->transComplete();
        $out['ok'] = $db->transStatus() !== false;
        if (! $out['ok']) {
            $out['errors'][] = 'Something went wrong while saving, so nothing was loaded.';
        }

        return $out;
    }

    /**
     * Deletes every sample price-list row and every sample supplier, and nothing else. It refuses (and deletes
     * nothing) if anything other than sample price rows points at a sample supplier: a user, an item, an order
     * or a real price row.
     *
     * @return array{ok: bool, errors: list<string>, prices: int, suppliers: int}
     */
    public static function remove(?BaseConnection $db = null): array
    {
        $db ??= db_connect();
        $out = ['ok' => false, 'errors' => [], 'prices' => 0, 'suppliers' => 0];

        if (! $db->tableExists('supplier_prices') || ! $db->fieldExists('is_sample', 'suppliers')) {
            $out['errors'][] = 'The database is not ready (suppliers.is_sample / supplier_prices are missing). Run: php spark migrate';

            return $out;
        }

        $ids = array_column($db->table('suppliers')->select('supplier_id')->where('is_sample', 1)->get()->getResultArray(), 'supplier_id');
        if ($ids !== []) {
            $blockers = [
                'a portal user'     => $db->table('users')->whereIn('supplier_id', $ids)->countAllResults(),
                'an item'           => $db->table('products')->whereIn('supplier_id', $ids)->countAllResults(),
                'a stock order'     => $db->table('stock_order')->whereIn('supplier_id', $ids)->countAllResults(),
                'a real price row'  => $db->table('supplier_prices')->whereIn('supplier_id', $ids)->where('is_sample', 0)->countAllResults(),
            ];
            foreach ($blockers as $what => $count) {
                if ($count > 0) {
                    $out['errors'][] = "A sample supplier has {$what} ({$count}), so nothing was removed. Sample data never has these; check it by hand.";
                }
            }
            if ($out['errors'] !== []) {
                return $out;
            }
        }

        $db->transStart();
        $db->table('supplier_prices')->where('is_sample', 1)->delete();
        $out['prices'] = $db->affectedRows();
        if ($ids !== []) {
            $db->table('suppliers')->where('is_sample', 1)->delete();
            $out['suppliers'] = $db->affectedRows();
        }
        $db->transComplete();

        $out['ok'] = $db->transStatus() !== false;

        return $out;
    }
}
