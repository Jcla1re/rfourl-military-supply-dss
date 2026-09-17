<?php

namespace App\Models;

use CodeIgniter\Model;

class InventoryLogModel extends Model
{
    protected $table            = 'inventory_log';
    protected $primaryKey       = 'log_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'item_id',
        'user_id',
        'log_type',
        'quantity_changed',
        'stock_after_change',
        'reference_so_id',
        'notes',
    ];

    protected $useTimestamps = false;

    public const LOG_TYPES = ['Restock', 'Return', 'Damaged', 'Adjustment'];

    public function record(string $itemId, ?int $userId, string $logType, int $quantityChanged, int $stockAfter, ?string $referenceSoId = null, ?string $notes = null): void
    {
        $this->insert([
            'item_id'             => $itemId,
            'user_id'             => $userId,
            'log_type'            => $logType,
            'quantity_changed'    => $quantityChanged,
            'stock_after_change'  => $stockAfter,
            'reference_so_id'     => $referenceSoId,
            'notes'               => $notes,
        ]);
    }

    public function recentForItem(string $itemId, int $limit = 20): array
    {
        return $this->where('item_id', $itemId)
            ->orderBy('timestamp', 'DESC')
            ->findAll($limit);
    }
}
