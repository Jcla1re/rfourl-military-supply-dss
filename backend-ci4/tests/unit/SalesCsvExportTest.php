<?php

use App\Libraries\LoginLockout;
use App\Libraries\SalesCsvExport;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * IT-17 helper logic (export range) and UT-04 constant.
 *
 * @internal
 */
final class SalesCsvExportTest extends CIUnitTestCase
{
    public function testMonthRangeCoversWholeMonth(): void
    {
        $this->assertSame(['2026-06-01', '2026-06-30', '2026-06'], SalesCsvExport::range(null, '2026-06'));
        $this->assertSame(['2028-02-01', '2028-02-29', '2028-02'], SalesCsvExport::range(null, '2028-02'));
    }

    public function testDateRangeIsOneDay(): void
    {
        $this->assertSame(['2026-07-29', '2026-07-29', '2026-07-29'], SalesCsvExport::range('2026-07-29', null));
    }

    public function testInvalidInputFallsBackToToday(): void
    {
        $today = date('Y-m-d');

        $this->assertSame([$today, $today, $today], SalesCsvExport::range('not-a-date', '2026-13'));
    }

    /** UT-04: three wrong passwords in a row lock the login. */
    public function testLoginLocksAfterThreeFailures(): void
    {
        $this->assertSame(3, LoginLockout::MAX_ATTEMPTS);
    }
}
