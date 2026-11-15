<?php

namespace App\Libraries;

use App\Models\InventoryLogModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\ReorderNoticeModel;
use App\Models\SalesTransactionModel;
use App\Models\SecurityLogModel;

/**
 * Voids a sale that was saved twice (the old double-click bug, fixed by OnceToken), for
 * `php spark sales:void-duplicate`. Command line only; no screen can do this.
 *
 * Nothing is hidden: the duplicate stays in sales_transaction and on every receipt list, marked
 * "Voided duplicate of #XXXX", and is only left out of totals, charts and demand
 * (SalesTransactionModel::notVoided()). Its effect on stock is reversed exactly: every line it took
 * out (PosCheckout: one changeStock(item, -qty) per line) is put back with changeStock(item, +qty),
 * a relative update, each with a SALE_VOID inventory_log entry, plus one security_log entry.
 *
 * check() changes nothing (the dry run). apply() runs check() again inside one transaction, with
 * the two sales and the product rows locked, so a second or simultaneous run changes nothing.
 */
final class DuplicateSaleVoid
{
    /** The duplicate must be saved this many seconds after the original, or fewer. */
    public const WINDOW_SECONDS = 10;

    public const EVENT          = 'sale_voided_duplicate';
    public const DEFAULT_REASON = 'Saved twice by the old double-click bug (fixed)';

    public const VOIDED  = 'voided';
    public const ALREADY = 'already_voided';
    public const REFUSED = 'refused';
    public const FAILED  = 'failed';

    /** "Duplicate sale #1738 voided, duplicate of #1737" (the inventory_log note). */
    public static function logNote(array $duplicate, array $original): string
    {
        return "Duplicate sale #{$duplicate['receipt_no']} voided, duplicate of #{$original['receipt_no']}";
    }

    /**
     * Everything the dry run shows: both sales, every check, and what --apply would change.
     *
     * @return array{
     *     ok: bool, already: bool, checks: list<array{label: string, ok: bool, detail: string}>,
     *     duplicate: ?array, original: ?array, duplicateLines: list<array>, originalLines: list<array>,
     *     owner: ?array, stock: list<array{item_id: string, name: string, before: int, qty: int, after: int, active: bool}>
     * }
     */
    public static function check(int $duplicateId, int $originalId, ?int $ownerId): array
    {
        $db     = db_connect();
        $sale   = static fn (int $id): ?array => $db->table('sales_transaction')->where('sales_id', $id)->get()->getRowArray() ?: null;
        $lines  = static fn (int $id): array => $db->table('sales_item')->where('sales_id', $id)->orderBy('sales_item_id', 'ASC')->get()->getResultArray();
        $dup    = $sale($duplicateId);
        $orig   = $sale($originalId);
        $dLines = $dup ? $lines($duplicateId) : [];
        $oLines = $orig ? $lines($originalId) : [];
        $owner  = $ownerId ? ($db->table('users')->where('user_id', $ownerId)->get()->getRowArray() ?: null) : null;

        $checks = [];
        $add    = static function (string $label, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        $add('Duplicate sale exists', $dup !== null, $dup ? "#{$dup['receipt_no']}" : "no sale with sales_id {$duplicateId}");
        $add('Original sale exists', $orig !== null, $orig ? "#{$orig['receipt_no']}" : "no sale with sales_id {$originalId}");

        $already = $dup !== null && SalesTransactionModel::isVoided($dup)
            && (int) ($dup['duplicate_of_sales_id'] ?? 0) === $originalId;

        if ($dup && $orig) {
            $add('Two different sales, the duplicate saved later', $duplicateId !== $originalId && $duplicateId > $originalId,
                "sales_id {$duplicateId} vs {$originalId}");
            $add('Duplicate not already voided', ! SalesTransactionModel::isVoided($dup),
                SalesTransactionModel::isVoided($dup) ? 'voided at ' . $dup['voided_at'] : '');
            $add('Original still valid (not voided, has items)', ! SalesTransactionModel::isVoided($orig) && $oLines !== [],
                SalesTransactionModel::isVoided($orig) ? 'the original is voided' : ($oLines === [] ? 'the original has no items' : ''));
            $add('Same user', $dup['user_id'] !== null && (string) $dup['user_id'] === (string) $orig['user_id'],
                "user_id {$dup['user_id']} vs {$orig['user_id']}");

            foreach (['payment_method' => 'Same payment method', 'or_number' => 'Same OR number', 'gcash_reference_no' => 'Same GCash reference'] as $field => $label) {
                $a = trim((string) ($dup[$field] ?? ''));
                $b = trim((string) ($orig[$field] ?? ''));
                $add($label, $a === $b, ($a === '' ? '(none)' : $a) . ' vs ' . ($b === '' ? '(none)' : $b));
            }
            $money = static fn ($v): string => number_format((float) $v, 2, '.', '');
            foreach (['cash_amount' => 'Same cash amount', 'gcash_amount' => 'Same GCash amount', 'subtotal' => 'Same subtotal', 'discount' => 'Same discount', 'total_amount' => 'Same total'] as $field => $label) {
                $add($label, $money($dup[$field] ?? 0) === $money($orig[$field] ?? 0), $money($dup[$field] ?? 0) . ' vs ' . $money($orig[$field] ?? 0));
            }

            $lineKey = static fn (array $l): string => $l['item_id'] . ' x' . (int) $l['quantity_sold'] . ' @' . $money($l['selling_price']) . (! empty($l['is_bulk']) ? ' bulk' : '');
            $dKeys   = array_map($lineKey, $dLines);
            $oKeys   = array_map($lineKey, $oLines);
            sort($dKeys);
            sort($oKeys);
            $add('Same items, sizes, quantities and prices (line by line)', $dKeys !== [] && $dKeys === $oKeys,
                implode(', ', $dKeys) . ' vs ' . implode(', ', $oKeys));

            $gap = strtotime((string) $dup['sale_date']) - strtotime((string) $orig['sale_date']);
            $add('Saved after the original, within ' . self::WINDOW_SECONDS . ' seconds', $gap >= 0 && $gap <= self::WINDOW_SECONDS,
                "{$orig['sale_date']} then {$dup['sale_date']} ({$gap} s)");

            $returns = self::returnsAgainst((string) $dup['receipt_no'], array_column($dLines, 'item_id'));
            $add('No customer return logged against the duplicate', $returns === 0,
                $returns > 0 ? "{$returns} return(s) reference #{$dup['receipt_no']}" : '');
        }

        $ownerOk = $owner !== null && ($owner['role'] ?? '') === 'Admin' && (int) ($owner['is_active'] ?? 0) === 1 && empty($owner['deleted_at']);
        $add('Run by an active Owner account (--by)', $ownerOk, $ownerId ? "user_id {$ownerId}" : 'missing --by <owner user_id>');

        $stock = [];
        if ($dLines !== []) {
            $qty = [];
            foreach ($dLines as $l) {
                $qty[$l['item_id']] = ($qty[$l['item_id']] ?? 0) + (int) $l['quantity_sold'];
            }
            $products = array_column($db->table('products')->whereIn('item_id', array_keys($qty))->get()->getResultArray(), null, 'item_id');
            foreach ($qty as $itemId => $q) {
                $p       = $products[$itemId] ?? null;
                $before  = (int) ($p['current_stock'] ?? 0);
                $stock[] = [
                    'item_id' => (string) $itemId,
                    'name'    => $p ? ProductModel::nameWithSize($p) : '(item not found)',
                    'before'  => $before,
                    'qty'     => $q,
                    'after'   => $before + $q,
                    'active'  => $p !== null && (int) ($p['is_active'] ?? 1) === 1,
                ];
            }
            $add('Every item still exists', ! in_array('(item not found)', array_column($stock, 'name'), true));
        }

        $types = self::logTypeValues();
        $add('inventory_log.log_type accepts SALE_VOID (migration AddVoidToSalesTransaction run)', $types === null || in_array('SALE_VOID', $types, true),
            $types === null ? '' : 'ENUM(' . implode(',', $types) . ')');

        return [
            'ok'             => ! in_array(false, array_column($checks, 'ok'), true),
            'already'        => $already,
            'checks'         => $checks,
            'duplicate'      => $dup,
            'original'       => $orig,
            'duplicateLines' => $dLines,
            'originalLines'  => $oLines,
            'owner'          => $owner,
            'stock'          => $stock,
        ];
    }

    /**
     * The allowed values of inventory_log.log_type as the database has them now, or null where the column
     * is not an ENUM (SQLite: any text is kept as given). Our MySQL runs in non-strict mode, where a value
     * missing from the ENUM is saved as '' without an error, so this is checked before anything is written.
     */
    public static function logTypeValues(): ?array
    {
        $db = db_connect();
        if ($db->getPlatform() !== 'MySQLi') {
            return null;
        }
        $row = $db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db->prefixTable('inventory_log'), 'log_type']
        )->getRowArray();

        return $row ? self::enumValues((string) $row['COLUMN_TYPE']) : [];
    }

    /** "enum('A','B','it''s')" => ['A', 'B', "it's"]; anything that is not an ENUM => []. */
    public static function enumValues(string $columnType): array
    {
        if (! preg_match('/^enum\((.*)\)$/is', trim($columnType), $m)) {
            return [];
        }
        preg_match_all("/'((?:[^']|'')*)'/", $m[1], $values);

        return array_map(static fn (string $v): string => str_replace("''", "'", $v), $values[1]);
    }

    /**
     * Customer Returns logged against a receipt. The return form saves "... . Ref: <receipt>" at the
     * end of the note (LogTransactionController::store()).
     */
    private static function returnsAgainst(string $receiptNo, array $itemIds): int
    {
        if ($itemIds === []) {
            return 0;
        }
        $n = 0;
        foreach (db_connect()->table('inventory_log')->where('log_type', 'RETURN')->whereIn('item_id', array_unique($itemIds))->like('notes', 'Ref:')->get()->getResultArray() as $log) {
            if (preg_match('/Ref:\s*#?\s*(.+?)\s*$/', (string) $log['notes'], $m) && strcasecmp($m[1], $receiptNo) === 0) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Voids the duplicate, in one transaction: the sale marked (guarded: only while voided_at IS NULL),
     * the stock given back line by line (relative), one SALE_VOID inventory_log entry per line and one
     * security_log entry. Afterwards the reorder alerts / notices are synced as after a sale.
     *
     * @return array{status: string, message: string, check: array}
     */
    public static function apply(int $duplicateId, int $originalId, int $ownerId, ?string $reason = null): array
    {
        $reason = trim((string) $reason) !== '' ? trim((string) $reason) : self::DEFAULT_REASON;
        $db     = db_connect();
        $result = static fn (string $status, string $message, array $check): array => ['status' => $status, 'message' => $message, 'check' => $check];

        // A failed transaction earlier on this connection leaves its status "failed"; start clean.
        $db->resetTransStatus();
        $db->transStart();

        try {
            // Lock both sales first, then check again: two runs at once can't both void it.
            if ($db->getPlatform() === 'MySQLi') {
                $db->query('SELECT sales_id FROM ' . $db->prefixTable('sales_transaction') . ' WHERE sales_id IN (?, ?) FOR UPDATE', [$duplicateId, $originalId]);
            }
            $check = self::check($duplicateId, $originalId, $ownerId);

            if ($check['already']) {
                $db->transRollback();

                return $result(self::ALREADY, "Sale #{$check['duplicate']['receipt_no']} is already voided as a duplicate of #{$check['original']['receipt_no']}. Nothing was changed.", $check);
            }
            if (! $check['ok']) {
                $db->transRollback();

                return $result(self::REFUSED, 'Refused: not every check passed. Nothing was changed.', $check);
            }

            $dup  = $check['duplicate'];
            $orig = $check['original'];
            $now  = date('Y-m-d H:i:s');

            $db->table('sales_transaction')
                ->where('sales_id', $duplicateId)
                ->where(SalesTransactionModel::notVoided(), null, false)
                ->update([
                    'voided_at'             => $now,
                    'voided_by'             => $ownerId,
                    'void_reason'           => mb_substr($reason, 0, 255),
                    'duplicate_of_sales_id' => $originalId,
                ]);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();

                return $result(self::ALREADY, 'The sale was voided by another run just now. Nothing was changed.', $check);
            }

            $products = new ProductModel();
            $log      = new InventoryLogModel();
            $note     = self::logNote($dup, $orig);
            $products->lockRows(array_column($check['duplicateLines'], 'item_id'));

            $returned = [];
            foreach ($check['duplicateLines'] as $line) {
                $qty      = (int) $line['quantity_sold'];
                $newStock = $products->changeStock((string) $line['item_id'], $qty);
                if ($newStock === null) {
                    $db->transRollback();

                    return $result(self::FAILED, "Could not give stock back to item {$line['item_id']}. Nothing was changed.", $check);
                }
                $log->record((string) $line['item_id'], $ownerId, 'SALE_VOID', $qty, $newStock, null, $note);

                // Read it back: a non-strict MySQL saves a value it does not accept as '' without an error.
                $saved = $db->table('inventory_log')->select('log_type')->where('log_id', (int) $log->getInsertID())->get()->getRowArray();
                if (($saved['log_type'] ?? null) !== 'SALE_VOID') {
                    $db->transRollback();

                    return $result(self::FAILED, "The inventory_log entry for item {$line['item_id']} was saved with log_type '" . ($saved['log_type'] ?? '(missing)')
                        . "' instead of SALE_VOID (run php spark migrate). Everything was rolled back; nothing was changed.", $check);
                }
                $returned[] = "{$line['item_id']} +{$qty}";
            }

            $owner = $check['owner'];
            $saved = (new SecurityLogModel())->insert([
                'user_id'    => $ownerId,
                'username'   => $owner['username'] ?? null,
                'role'       => $owner['role'] ?? null,
                'event'      => self::EVENT,
                'detail'     => mb_substr("Voided sale #{$dup['receipt_no']} (sales_id {$duplicateId}) as a duplicate of #{$orig['receipt_no']} (sales_id {$originalId}); stock returned: "
                    . implode(', ', $returned) . "; reason: {$reason}", 0, 255),
                'ip_address' => null,
                'created_at' => $now,
            ]);
            if ($saved === false) {
                $db->transRollback();

                return $result(self::FAILED, 'Could not write the security log entry. Nothing was changed.', $check);
            }

            $db->transComplete();
        } catch (\Throwable $e) {
            $db->transRollback();

            return $result(self::FAILED, 'Failed and rolled back, nothing was changed: ' . $e->getMessage(), $check ?? []);
        }

        if ($db->transStatus() === false) {
            return $result(self::FAILED, 'Failed and rolled back. Nothing was changed.', $check);
        }

        // As after a sale (PosCheckout): reorder notices and alerts follow the new stock.
        $itemIds = array_values(array_unique(array_column($check['duplicateLines'], 'item_id')));
        (new ReorderNoticeModel())->clearResolved($itemIds);
        (new ReorderAlertModel())->syncFromLiveInventory();

        return $result(self::VOIDED, "Voided sale #{$dup['receipt_no']} as a duplicate of #{$orig['receipt_no']}; stock given back: " . implode(', ', $returned) . '.', $check);
    }
}
