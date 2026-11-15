<?php

namespace App\Commands;

use App\Libraries\DuplicateSaleVoid;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark sales:void-duplicate <duplicate_sales_id> <original_sales_id> --by <owner_user_id> [--apply] [--reason "..."]
 *
 * Voids ONE sale that was saved twice by the old double-click bug. A dry run by default: it prints
 * both sales, every check and everything --apply would change, and changes nothing. See
 * App\Libraries\DuplicateSaleVoid for what --apply does (one transaction).
 */
class SalesVoidDuplicate extends BaseCommand
{
    protected $group       = 'Sales';
    protected $name        = 'sales:void-duplicate';
    protected $description = 'Voids one duplicate sale (double-click bug): dry run unless --apply.';
    protected $usage       = 'sales:void-duplicate <duplicate_sales_id> <original_sales_id> --by <owner_user_id> [--apply] [--reason "..."]';
    protected $arguments   = [
        'duplicate_sales_id' => 'The sale saved by mistake (the later one).',
        'original_sales_id'  => 'The real sale it duplicates.',
    ];
    protected $options = [
        '--by'     => 'The Owner account (user_id) running this; recorded in the logs.',
        '--apply'  => 'Make the changes. Without it nothing is changed (dry run).',
        '--reason' => 'Why (default: "' . DuplicateSaleVoid::DEFAULT_REASON . '").',
    ];

    public function run(array $params)
    {
        // --by 1 and --by=1 both work.
        foreach ($params as $k => $v) {
            if (is_string($k) && str_contains($k, '=')) {
                [$name, $value]  = explode('=', $k, 2);
                $params[$name] ??= $value;
            }
        }
        $option = static fn (string $name) => array_key_exists($name, $params) ? ($params[$name] ?? true) : CLI::getOption($name);

        $duplicateId = (int) ($params[0] ?? 0);
        $originalId  = (int) ($params[1] ?? 0);
        $by          = $option('by');
        $ownerId     = is_numeric($by) ? (int) $by : null;
        $apply       = $option('apply') !== null;
        $reason      = is_string($option('reason')) ? (string) $option('reason') : null;

        if ($duplicateId < 1 || $originalId < 1) {
            CLI::error('Usage: php spark ' . $this->usage);

            return EXIT_ERROR;
        }

        $check = DuplicateSaleVoid::check($duplicateId, $originalId, $ownerId);
        $this->printSales($check);
        $this->printChecks($check);

        if ($check['already']) {
            CLI::write("Already done: sale #{$check['duplicate']['receipt_no']} is voided as a duplicate of #{$check['original']['receipt_no']} (at {$check['duplicate']['voided_at']}). Nothing was changed.", 'yellow');

            return EXIT_SUCCESS;
        }
        if (! $check['ok']) {
            CLI::error('REFUSED: not every check passed. Nothing was changed.');

            return EXIT_ERROR;
        }

        $this->printPlan($check, $ownerId, $reason);

        if (! $apply) {
            CLI::newLine();
            CLI::write('DRY RUN: nothing was changed. Run the same command with --apply to make these changes.', 'yellow');

            return EXIT_SUCCESS;
        }

        $done = DuplicateSaleVoid::apply($duplicateId, $originalId, (int) $ownerId, $reason);
        CLI::newLine();
        if ($done['status'] === DuplicateSaleVoid::VOIDED) {
            CLI::write('APPLIED: ' . $done['message'], 'green');

            return EXIT_SUCCESS;
        }
        if ($done['status'] === DuplicateSaleVoid::ALREADY) {
            CLI::write($done['message'], 'yellow');

            return EXIT_SUCCESS;
        }
        CLI::error(strtoupper($done['status']) . ': ' . $done['message']);

        return EXIT_ERROR;
    }

    private function printSales(array $check): void
    {
        $d = $check['duplicate'] ?? [];
        $o = $check['original'] ?? [];
        $lines = static fn (array $ls): string => $ls === [] ? '(none)' : implode('; ', array_map(
            static fn ($l) => $l['item_id'] . ' x' . (int) $l['quantity_sold'] . ' @ ' . number_format((float) $l['selling_price'], 2) . (! empty($l['is_bulk']) ? ' (bulk)' : ''),
            $ls
        ));
        $v = static fn (array $s, string $k): string => array_key_exists($k, $s) && $s[$k] !== null && $s[$k] !== '' ? (string) $s[$k] : '-';

        CLI::write('Sales (original | duplicate)', 'yellow');
        $rows = [];
        foreach ([
            'sales_id' => 'sales_id', 'receipt_no' => 'Receipt no.', 'or_number' => 'OR no.', 'user_id' => 'User (user_id)', 'sale_date' => 'Saved at',
            'payment_method' => 'Payment', 'cash_amount' => 'Cash', 'gcash_amount' => 'GCash', 'gcash_reference_no' => 'GCash ref.',
            'subtotal' => 'Subtotal', 'discount' => 'Discount', 'total_amount' => 'Total', 'voided_at' => 'Voided at',
        ] as $k => $label) {
            $rows[] = [$label, $v($o, $k), $v($d, $k)];
        }
        $rows[] = ['Items', $lines($check['originalLines']), $lines($check['duplicateLines'])];
        CLI::table($rows, ['', 'Original', 'Duplicate']);
    }

    private function printChecks(array $check): void
    {
        CLI::write('Checks', 'yellow');
        foreach ($check['checks'] as $c) {
            CLI::write(sprintf('  [%s] %s%s', $c['ok'] ? ' OK ' : 'FAIL', $c['label'], $c['detail'] !== '' ? ' - ' . $c['detail'] : ''), $c['ok'] ? null : 'red');
        }
        CLI::newLine();
    }

    private function printPlan(array $check, ?int $ownerId, ?string $reason): void
    {
        $d    = $check['duplicate'];
        $o    = $check['original'];
        $note = DuplicateSaleVoid::logNote($d, $o);

        CLI::write('With --apply, in ONE transaction:', 'yellow');
        CLI::write("  1. sales_transaction sales_id {$d['sales_id']} (#{$d['receipt_no']}): voided_at = now, voided_by = {$ownerId}, duplicate_of_sales_id = {$o['sales_id']},");
        CLI::write('     void_reason = "' . (trim((string) $reason) !== '' ? trim((string) $reason) : DuplicateSaleVoid::DEFAULT_REASON) . '"');
        CLI::write("     (only if it is still not voided). The row and its items stay; it is shown as \"Voided duplicate of #{$o['receipt_no']}\" and left out of every total.");
        CLI::write('  2. Stock given back (relative: current_stock = current_stock + qty):');
        foreach ($check['stock'] as $s) {
            CLI::write("       {$s['item_id']} {$s['name']}: {$s['before']} -> {$s['after']} (+{$s['qty']})" . ($s['active'] ? '' : ' [archived item]'));
        }
        CLI::write('  3. inventory_log, one SALE_VOID row per line: +qty, "' . $note . '"');
        CLI::write('  4. security_log: event ' . DuplicateSaleVoid::EVENT . ", user_id {$ownerId}, both receipts, the stock returned and the reason");
        CLI::write('  5. After the commit: reorder notices and alerts synced for the new stock (as after a sale).');
    }
}
