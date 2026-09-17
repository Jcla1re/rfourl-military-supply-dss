<?php

namespace App\Models;

use CodeIgniter\Model;

class SalesTransactionModel extends Model
{
    protected $table            = 'sales_transaction';
    protected $primaryKey       = 'sales_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'receipt_no',
        'user_id',
        'sale_date',
        'payment_method',
        'subtotal',
        'discount',
        'total_amount',
    ];

    protected $useTimestamps = false;

    public function generateReceiptNo(): string
    {
        return 'RCPT-' . date('Ymd') . '-' . str_pad((string) ($this->countAll() + 1), 4, '0', STR_PAD_LEFT);
    }

    public function totalForToday(): float
    {
        return (float) ($this->selectSum('total_amount')
            ->where('DATE(sale_date)', date('Y-m-d'))
            ->first()['total_amount'] ?? 0);
    }

    public function recent(int $limit = 20): array
    {
        return $this->orderBy('sale_date', 'DESC')->findAll($limit);
    }

    public function weeklyTotals(): array
    {
        $rows = $this->select("DATE(sale_date) as d, SUM(total_amount) as total")
            ->where('sale_date >=', date('Y-m-d', strtotime('-6 days')))
            ->groupBy('d')
            ->orderBy('d', 'ASC')
            ->findAll();

        $totals = [];
        foreach ($rows as $r) {
            $totals[$r['d']] = (float) $r['total'];
        }

        $labels = [];
        $data   = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('D', strtotime($date));
            $data[]   = $totals[$date] ?? 0;
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
