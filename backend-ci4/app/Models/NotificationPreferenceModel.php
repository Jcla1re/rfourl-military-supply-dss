<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationPreferenceModel extends Model
{
    protected $table            = 'notification_preferences';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'rop_alerts',
        'low_stock_warnings',
        'daily_sales_summary',
        'procurement_reminders',
        'weekly_trend_report',
        'updated_by',
    ];

    public const KEYS = [
        'rop_alerts',
        'low_stock_warnings',
        'daily_sales_summary',
        'procurement_reminders',
        'weekly_trend_report',
    ];

    /**
     * There is only ever one row (id=1). Returns it, or sensible defaults
     * if the table is somehow empty.
     */
    public function current(): array
    {
        return $this->first() ?? [
            'id'                     => null,
            'rop_alerts'             => 1,
            'low_stock_warnings'     => 1,
            'daily_sales_summary'    => 1,
            'procurement_reminders'  => 1,
            'weekly_trend_report'    => 0,
        ];
    }

    public function isEnabled(string $key): bool
    {
        return (bool) ($this->current()[$key] ?? true);
    }

    public function setEnabled(string $key, bool $enabled, ?int $updatedBy = null): void
    {
        if (! in_array($key, self::KEYS, true)) {
            return;
        }

        $current = $this->current();
        $payload = [$key => $enabled ? 1 : 0, 'updated_by' => $updatedBy];

        if (! empty($current['id'])) {
            $this->update($current['id'], $payload);
        } else {
            $this->insert($payload + array_fill_keys(self::KEYS, 1));
        }
    }
}
