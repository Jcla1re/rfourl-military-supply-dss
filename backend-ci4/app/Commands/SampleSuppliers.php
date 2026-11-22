<?php

namespace App\Commands;

use App\Libraries\SampleSuppliers as Sample;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Sample suppliers and their price lists, so offers from suppliers other than an item's main supplier can be
 * compared. Everything made here is marked "Sample" (is_sample = 1): no portal account, no orders, never a main
 * supplier. dss:run still uses each item's main supplier only.
 *
 *   php spark sample-suppliers load     creates them (safe to run again; stops if an item name matches nothing)
 *   php spark sample-suppliers remove   deletes the sample suppliers and their price rows, nothing else
 *
 * To change the suppliers, the items or the price factors, edit the two arrays below; the logic is in
 * App\Libraries\SampleSuppliers.
 */
class SampleSuppliers extends BaseCommand
{
    // ---------------------------------------------------------------- the data to adjust

    /**
     * Sample suppliers, by ID (SMP-###, so they never clash with real SUP-### numbers).
     * min_order_qty is per item (null = no minimum). Contact details are made up on purpose.
     */
    public const SUPPLIERS = [
        'SMP-001' => ['name' => 'Tondo Stitch & Patch Works',   'category' => 'Patches',   'lead_time_days' => 7,  'min_order_qty' => null, 'payment_terms' => 'Cash',          'contact_person' => 'Sample Contact (not real)', 'contact_email' => 'sample-001@example.invalid', 'contact_number' => '0000 000 0001'],
        'SMP-002' => ['name' => 'Caloocan Embroidery Hub',      'category' => 'Patches',   'lead_time_days' => 3,  'min_order_qty' => 100,  'payment_terms' => 'Check',         'contact_person' => 'Sample Contact (not real)', 'contact_email' => 'sample-002@example.invalid', 'contact_number' => '0000 000 0002'],
        'SMP-003' => ['name' => 'Bulacan Brass & Pin Makers',   'category' => 'Metal',     'lead_time_days' => 10, 'min_order_qty' => null, 'payment_terms' => 'Cash',          'contact_person' => 'Sample Contact (not real)', 'contact_email' => 'sample-003@example.invalid', 'contact_number' => '0000 000 0003'],
        'SMP-004' => ['name' => 'Pasig Tactical Supply Depot',  'category' => 'Equipment', 'lead_time_days' => 5,  'min_order_qty' => null, 'payment_terms' => 'Cash or Check', 'contact_person' => 'Sample Contact (not real)', 'contact_email' => 'sample-004@example.invalid', 'contact_number' => '0000 000 0004'],
    ];

    /**
     * Each sample supplier's offers: price = round(the item's unit cost x factor), in whole pesos. Items are
     * matched by name (upper / lower case ignored), every size of the item.
     */
    public const PRICE_LIST = [
        'SMP-001' => ['factor' => 0.92, 'items' => ['1ID Security Patch', 'Name Cloth', 'Logo', 'Patch']],
        'SMP-002' => ['factor' => 0.85, 'items' => ['1ID Security Patch', 'Name Cloth', 'Logo', 'Patch']],
        'SMP-003' => ['factor' => 0.90, 'items' => ['KALASAG Pin', 'Papuri Medal', 'Badge']],
        'SMP-004' => ['factor' => 1.05, 'items' => ['Handcuffs', 'Pepper Spray', 'Cloth']],
    ];

    // ---------------------------------------------------------------- the command

    protected $group       = 'Maintenance';
    protected $name        = 'sample-suppliers';
    protected $description = 'Loads or removes the sample suppliers and their price lists (for comparing offers).';
    protected $usage       = 'sample-suppliers <load|remove>';
    protected $arguments   = ['load' => 'Create the sample suppliers and price-list rows (safe to run again).', 'remove' => 'Delete the sample suppliers and their price-list rows, nothing else.'];

    public function run(array $params)
    {
        $action = $params[0] ?? '';

        if ($action === 'load') {
            return $this->load();
        }
        if ($action === 'remove') {
            return $this->remove();
        }

        CLI::error('Say what to do: php spark sample-suppliers load   or   php spark sample-suppliers remove');

        return EXIT_ERROR;
    }

    private function load(): int
    {
        $result = Sample::load(self::SUPPLIERS, self::PRICE_LIST);

        if (! $result['ok']) {
            foreach ($result['errors'] as $error) {
                CLI::error($error);
            }

            return EXIT_ERROR;
        }

        CLI::write("Sample suppliers: {$result['suppliers_created']} created, {$result['suppliers_existing']} already there (all marked Sample, no portal accounts).", 'green');
        $counts = array_count_values(array_column($result['rows'], 'status'));
        CLI::write('Price-list rows: ' . implode(', ', array_map(static fn ($k, $v) => "{$v} {$k}", array_keys($counts), $counts)) . '.', 'green');
        CLI::newLine();

        $table = [];
        foreach ($result['rows'] as $r) {
            $table[] = [$r['supplier'], $r['item'], $r['size'] !== '' ? $r['size'] : '-', '₱' . number_format($r['main_cost']), '₱' . number_format($r['price']), $r['status']];
        }
        CLI::table($table, ['Supplier', 'Item', 'Size', 'Main cost', 'Sample price', 'Row']);

        return EXIT_SUCCESS;
    }

    private function remove(): int
    {
        $result = Sample::remove();

        if (! $result['ok']) {
            foreach ($result['errors'] as $error) {
                CLI::error($error);
            }

            return EXIT_ERROR;
        }

        CLI::write("Removed {$result['prices']} sample price-list row(s) and {$result['suppliers']} sample supplier(s). Nothing else was touched.", 'green');

        return EXIT_SUCCESS;
    }
}
