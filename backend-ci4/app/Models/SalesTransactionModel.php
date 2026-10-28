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
        'cash_amount',
        'gcash_amount',
        'gcash_reference_no',
        'subtotal',
        'discount',
        'total_amount',
    ];

    protected $useTimestamps = false;

    /** The first receipt number the shop's invoice booklets started from. */
    public const FIRST_RECEIPT_NO = 1001;

    /**
     * Plain sequential invoice number (e.g. "1001", "1002", ...) matching
     * the numbering style pre-printed on the shop's real Sales Invoice
     * booklets, rather than a system-style "RCPT-YYYYMMDD-####" code.
     *
     * It is the highest numeric receipt_no plus one (never fewer than 1001), not a
     * row count, so a deleted or archived receipt never makes it hand out a number
     * that is already in use. Call it inside the same transaction as the insert:
     * on MySQL/MariaDB the read locks the highest receipt, so two cashiers checking
     * out together cannot both be given the same number (receipt_no is also UNIQUE).
     * Receipt numbers that are not plain numbers are ignored.
     */
    public function generateReceiptNo(): string
    {
        $table = $this->db->protectIdentifiers($this->table, true);

        if ($this->db->DBDriver === 'MySQLi') {
            $row     = $this->db->query("SELECT MAX(CAST(receipt_no AS UNSIGNED)) AS mx FROM {$table} WHERE receipt_no REGEXP '^[0-9]+\$' FOR UPDATE")->getRowArray();
            $highest = (int) ($row['mx'] ?? 0);
        } else {
            $highest = 0;
            foreach ($this->db->query("SELECT receipt_no FROM {$table}")->getResultArray() as $r) {
                if (ctype_digit((string) $r['receipt_no'])) {
                    $highest = max($highest, (int) $r['receipt_no']);
                }
            }
        }

        return (string) (max($highest, self::FIRST_RECEIPT_NO - 1) + 1);
    }

    public function totalForToday(): float
    {
        return (float) ($this->selectSum('total_amount')
            ->where('DATE(sale_date)', date('Y-m-d'))
            ->first()['total_amount'] ?? 0);
    }

    public function countForToday(): int
    {
        return $this->where('DATE(sale_date)', date('Y-m-d'))->countAllResults();
    }

    public function countInRange(string $startDate, string $endDate): int
    {
        return $this->where('DATE(sale_date) >=', $startDate)
            ->where('DATE(sale_date) <=', $endDate)
            ->countAllResults();
    }

    public function recent(int $limit = 20): array
    {
        return $this->orderBy('sale_date', 'DESC')->findAll($limit);
    }

    public function weeklyTotals(): array
    {
        return $this->dailyTotalsRange(6, 0);
    }

    /**
     * Same 7-day shape as weeklyTotals(), but for the 7 days before that —
     * feeds the "Last Week" comparison line on the dashboard's weekly chart
     * (previously hardcoded to zeros and never actually computed).
     */
    public function lastWeekTotals(): array
    {
        return $this->dailyTotalsRange(13, 7);
    }

    /**
     * Daily revenue totals for the range [today - $fromDaysAgo, today - $toDaysAgo],
     * inclusive, oldest first. Uses DateTime arithmetic throughout (rather
     * than concatenating possibly-negative numbers into strtotime()
     * strings) so the upper bound can't silently land on the wrong day.
     */
    private function dailyTotalsRange(int $fromDaysAgo, int $toDaysAgo): array
    {
        $rangeStart = (new \DateTime('today'))->modify("-{$fromDaysAgo} days");
        $rangeEnd   = (new \DateTime('today'))->modify("-{$toDaysAgo} days");
        $upperBound = (clone $rangeEnd)->modify('+1 day');

        $rows = $this->select("DATE(sale_date) as d, SUM(total_amount) as total")
            ->where('sale_date >=', $rangeStart->format('Y-m-d'))
            ->where('sale_date <', $upperBound->format('Y-m-d'))
            ->groupBy('d')
            ->orderBy('d', 'ASC')
            ->findAll();

        $totals = [];
        foreach ($rows as $r) {
            $totals[$r['d']] = (float) $r['total'];
        }

        $labels = [];
        $data   = [];
        $cursor = clone $rangeStart;
        while ($cursor <= $rangeEnd) {
            $date = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('D');
            $data[]   = $totals[$date] ?? 0;
            $cursor->modify('+1 day');
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
