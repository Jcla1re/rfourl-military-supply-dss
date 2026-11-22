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
        'or_number',
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

    // ------------------------------------------------------------------ voided sales
    // A voided sale (only `php spark sales:void-duplicate` voids one; voided_at, voided_by, void_reason
    // and duplicate_of_sales_id are deliberately not in $allowedFields) stays in the table and on the
    // receipt lists, marked, but is left out of every total, count, chart and demand figure.

    /**
     * THE condition for "a real sale, not voided", for every query that adds up or counts sales:
     * `->where(SalesTransactionModel::notVoided('st'), null, false)`, or inside raw SQL.
     * $alias is the table name or alias that sales_transaction has in the query. The plain table name
     * gets the connection's table prefix (none in production; the test database uses one), because a
     * raw condition is not prefixed by the query builder.
     */
    public static function notVoided(string $alias = 'sales_transaction'): string
    {
        if ($alias === 'sales_transaction') {
            $alias = db_connect()->prefixTable($alias);
        }

        return $alias . '.voided_at IS NULL';
    }

    /** Whether a sale row (with its voided_at column) was voided. */
    public static function isVoided(array $sale): bool
    {
        return ! empty($sale['voided_at']);
    }

    /**
     * Joins the original receipt of a voided duplicate, as `duplicate_of_receipt_no`, for the lists that
     * show voided receipts (receipts tab, day PDF, Reports, Old Sales Records).
     *
     * @param \CodeIgniter\Database\BaseBuilder|Model $builder
     */
    public static function withOriginalReceipt($builder, string $alias = 'sales_transaction')
    {
        return $builder->select("{$alias}.voided_at, {$alias}.duplicate_of_sales_id, void_orig.receipt_no AS duplicate_of_receipt_no")
            ->join('sales_transaction void_orig', "void_orig.sales_id = {$alias}.duplicate_of_sales_id", 'left');
    }

    /** "Voided duplicate of #1737" (or "Voided"), or null for a normal sale. */
    public static function voidLabel(array $sale): ?string
    {
        if (! self::isVoided($sale)) {
            return null;
        }
        $of = (string) ($sale['duplicate_of_receipt_no'] ?? '');

        return $of !== '' ? 'Voided duplicate of #' . $of : 'Voided';
    }

    /** The total of a list of sale rows (e.g. a day's receipts), voided duplicates left out. */
    public static function totalOf(array $sales): float
    {
        return (float) array_sum(array_column(array_filter($sales, static fn (array $s): bool => ! self::isVoided($s)), 'total_amount'));
    }

    /** The latest sale date-time (voided sales left out), or null when there are none. */
    public function lastSaleDate(): ?string
    {
        $row = $this->db->table($this->table)->selectMax('sale_date', 'mx')
            ->where(self::notVoided(), null, false)->get()->getRowArray();

        return $row['mx'] ?? null;
    }

    /**
     * The official receipt number typed from the store's BIR booklet: optional, digits only, 1 to 20
     * (kept as text, so leading zeros stay). Sales saved before this rule keep what they have.
     * The system's own receipt_no is not an official receipt.
     */
    public static function validOrNumber(string $value): bool
    {
        return (bool) preg_match('~^[0-9]{1,20}$~', $value);
    }

    /**
     * The latest sale that already carries this OR number (trimmed, upper/lower case ignored), or null.
     * Used for the checkout warning; a repeat is only warned about, never refused.
     */
    public function findByOrNumber(string $orNumber): ?array
    {
        $orNumber = strtolower(trim($orNumber));
        if ($orNumber === '') {
            return null;
        }

        // A voided duplicate carries the same number as its original; it is not a second use of it.
        return $this->select('sales_id, receipt_no, or_number')
            ->where('LOWER(TRIM(or_number)) =', $orNumber)
            ->where(self::notVoided(), null, false)
            ->orderBy('sales_id', 'DESC')
            ->first();
    }

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
     *
     * Voided sales are deliberately counted here: a voided receipt's number stays taken, never reused.
     */
    public function generateReceiptNo(): string
    {
        $table = $this->db->protectIdentifiers($this->table, true);

        if ($this->db->getPlatform() === 'MySQLi') { // DBDriver is the app's own class name; the platform is 'MySQLi'
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
        return $this->totalForDate(date('Y-m-d'));
    }

    /** Sales value of one day (Y-m-d), voided sales left out. */
    public function totalForDate(string $date): float
    {
        return (float) ($this->selectSum('total_amount')
            ->where('DATE(sale_date)', $date)
            ->where(self::notVoided(), null, false)
            ->first()['total_amount'] ?? 0);
    }

    /** Sales value of one month ('Y-m'), voided sales left out. */
    public function totalForMonth(string $month): float
    {
        return (float) ($this->selectSum('total_amount')
            ->where("DATE_FORMAT(sale_date, '%Y-%m')", $month)
            ->where(self::notVoided(), null, false)
            ->first()['total_amount'] ?? 0);
    }

    /** Receipts in one month ('Y-m'), voided sales left out. */
    public function countForMonth(string $month): int
    {
        return $this->where("DATE_FORMAT(sale_date, '%Y-%m')", $month)
            ->where(self::notVoided(), null, false)
            ->countAllResults();
    }

    public function countForToday(): int
    {
        return $this->countForDate(date('Y-m-d'));
    }

    /** Receipts on one day (Y-m-d), voided sales left out. */
    public function countForDate(string $date): int
    {
        return $this->where('DATE(sale_date)', $date)->where(self::notVoided(), null, false)->countAllResults();
    }

    public function countInRange(string $startDate, string $endDate): int
    {
        return $this->where('DATE(sale_date) >=', $startDate)
            ->where('DATE(sale_date) <=', $endDate)
            ->where(self::notVoided(), null, false)
            ->countAllResults();
    }

    /** The latest receipts, voided sales left out. */
    public function recent(int $limit = 20): array
    {
        return $this->where(self::notVoided(), null, false)->orderBy('sale_date', 'DESC')->findAll($limit);
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
            ->where(self::notVoided(), null, false)
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
