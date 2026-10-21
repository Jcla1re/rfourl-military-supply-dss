<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Staff "Notify Admin" per item (staff Inventory). One open notice per item:
 * further clicks, from any staff account, reuse it instead of notifying the
 * admin again. A notice closes when the item is ordered (StockOrderController)
 * or stops needing a reorder (ProductModel's after-update hook, and the DSS run
 * for reorder-point changes); the next dip below the reorder point starts fresh.
 */
class ReorderNoticeModel extends Model
{
    protected $table         = 'reorder_notices';
    protected $primaryKey    = 'notice_id';
    protected $returnType    = 'array';
    protected $allowedFields = ['item_id', 'notified_by', 'notification_id', 'created_at', 'cleared_at', 'cleared_reason'];

    /**
     * Open notices for these items, with the notifier's name.
     *
     * @param list<string>|null $itemIds null = every item
     *
     * @return array<string, array> item_id => notice row + 'notified_by_name'
     */
    public function openFor(?array $itemIds = null): array
    {
        if ($itemIds === []) {
            return [];
        }
        $builder = $this->db->table('reorder_notices n')
            ->select('n.*, u.full_name AS notified_by_name')
            ->join('users u', 'u.user_id = n.notified_by', 'left')
            ->where('n.cleared_at', null)
            ->orderBy('n.notice_id', 'ASC');
        if ($itemIds !== null) {
            $builder->whereIn('n.item_id', array_map('strval', $itemIds));
        }
        $open = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $open[$row['item_id']] ??= $row;
        }

        return $open;
    }

    /**
     * Hover / focus text for "Admin notified", on two lines:
     * "Notified by Ana Cruz" / "Oct 21, 2026 · 2:05 PM" (no break inside the date or time).
     */
    public static function tooltip(array $notice): string
    {
        $at = strtotime($notice['created_at']);

        return 'Notified by ' . ($notice['notified_by_name'] ?: 'staff') . "\n"
            . str_replace(' ', "\u{00A0}", date('M j, Y', $at)) . ' · ' . str_replace(' ', "\u{00A0}", date('g:i A', $at));
    }

    /** One-line version for the toast: "Notified by Ana Cruz · Oct 21, 2026, 2:05 PM". */
    public static function summary(array $notice): string
    {
        return 'Notified by ' . ($notice['notified_by_name'] ?: 'staff')
            . ' · ' . date('M j, Y, g:i A', strtotime($notice['created_at']));
    }

    /** @param list<string> $itemIds */
    public function clear(array $itemIds, string $reason): void
    {
        if ($itemIds === []) {
            return;
        }
        $this->builder()
            ->whereIn('item_id', array_map('strval', $itemIds))
            ->where('cleared_at', null)
            ->update(['cleared_at' => date('Y-m-d H:i:s'), 'cleared_reason' => $reason]);
    }

    /**
     * Closes open notices whose item no longer needs reordering (stock back
     * above the reorder point, or reorder point lowered) or is already on order.
     *
     * @param list<string>|null $itemIds null = check every open notice
     */
    public function clearResolved(?array $itemIds = null): void
    {
        $open = $this->openFor($itemIds);
        if ($open === []) {
            return;
        }
        $products   = new ProductModel();
        $openOrders = $products->openOrders();
        $ordered = $resolved = [];
        foreach ($products->whereIn('item_id', array_keys($open))->findAll() as $p) {
            if ($products->getStatus($p)['label'] !== 'Reorder Now') {
                $resolved[] = $p['item_id'];
            } elseif (isset($openOrders[$p['item_id']])) {
                $ordered[] = $p['item_id'];
            }
        }
        $this->clear($resolved, 'stock_ok');
        $this->clear($ordered, 'ordered');
    }
}
