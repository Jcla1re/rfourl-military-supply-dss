<?php

use App\Models\SalesTransactionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Receipt numbers are the highest numeric receipt_no + 1 (never below 1001), not a row
 * count, so deleting or archiving a receipt can never make the next sale reuse a number.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class ReceiptNumberTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private SalesTransactionModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();
        $forge->dropTable('sales_transaction', true);
        $forge->addField([
            'sales_id'       => ['type' => 'INTEGER', 'auto_increment' => true],
            'receipt_no'     => ['type' => 'VARCHAR', 'constraint' => 20],
            'sale_date'      => ['type' => 'DATETIME'],
            'payment_method' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'Cash'],
            'subtotal'       => ['type' => 'REAL', 'default' => 0],
            'total_amount'   => ['type' => 'REAL', 'default' => 0],
        ]);
        $forge->addKey('sales_id', true);
        $forge->createTable('sales_transaction');

        $this->model = new SalesTransactionModel();
    }

    private function receipt(string $no): void
    {
        $this->db->table('sales_transaction')->insert(['receipt_no' => $no, 'sale_date' => '2026-10-28 12:00:00']);
    }

    public function testFirstReceiptIs1001(): void
    {
        $this->assertSame('1001', $this->model->generateReceiptNo());
    }

    public function testNextIsHighestPlusOne(): void
    {
        foreach (['1001', '1002', '1732'] as $no) {
            $this->receipt($no);
        }

        $this->assertSame('1733', $this->model->generateReceiptNo());
    }

    public function testDeletingAReceiptNeverReusesANumber(): void
    {
        foreach (['1001', '1002', '1003'] as $no) {
            $this->receipt($no);
        }
        // The old "1000 + row count + 1" gave 1003 here, which is still in use.
        $this->db->table('sales_transaction')->where('receipt_no', '1002')->delete();
        $this->assertSame('1004', $this->model->generateReceiptNo());

        // Deleting the newest one is fine: its number is simply free again.
        $this->db->table('sales_transaction')->where('receipt_no', '1003')->delete();
        $this->assertSame('1002', $this->model->generateReceiptNo());
    }

    public function testNumbersBelowTheBookletStartAreNotUsed(): void
    {
        $this->receipt('7');

        $this->assertSame('1001', $this->model->generateReceiptNo());
    }

    public function testReceiptsThatAreNotPlainNumbersAreIgnored(): void
    {
        $this->receipt('BIR-20210127-01');
        $this->receipt('1005');

        $this->assertSame('1006', $this->model->generateReceiptNo());
    }

    public function testConsecutiveSalesGetConsecutiveNumbers(): void
    {
        $this->receipt('1732');

        $first = $this->model->generateReceiptNo();
        $this->receipt($first);
        $second = $this->model->generateReceiptNo();

        $this->assertSame('1733', $first);
        $this->assertSame('1734', $second);
    }
}
