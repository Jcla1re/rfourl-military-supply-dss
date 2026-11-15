<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The app's MySQLi connection class is App\Database\MySQLi (Config\Database sets it for every MySQLi
 * group), so $db->DBDriver is 'App\Database\MySQLi', never 'MySQLi'. A check like
 * `DBDriver === 'MySQLi'` is therefore always false on the real database and silently skips the
 * MySQL-only code (FOR UPDATE locks, ENUM changes). The platform must be checked with getPlatform().
 *
 * @internal
 */
final class PlatformCheckTest extends CIUnitTestCase
{
    public function testNoCodeComparesDbDriverWithAName(): void
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || str_ends_with($path, 'Config/Database.php')) {
                continue; // Config\Database is where the driver class is set
            }
            foreach (file($path) as $n => $line) {
                if (preg_match('/DBDriver\'?\]?\s*(===|!==|==|!=)\s*[\'"]|[\'"]\s*(===|!==|==|!=)\s*\$[\w>\-]*DBDriver/', $line)) {
                    $found[] = substr($path, strlen(str_replace('\\', '/', APPPATH))) . ':' . ($n + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $found, "Use \$db->getPlatform() === 'MySQLi' (DBDriver is the app's own class name):\n" . implode("\n", $found));
    }

    public function testTheGuardCatchesTheOldCheck(): void
    {
        $pattern = '/DBDriver\'?\]?\s*(===|!==|==|!=)\s*[\'"]|[\'"]\s*(===|!==|==|!=)\s*\$[\w>\-]*DBDriver/';

        $this->assertSame(1, preg_match($pattern, "if (\$this->db->DBDriver === 'MySQLi') {"));
        $this->assertSame(1, preg_match($pattern, "if (\$db->DBDriver !== 'SQLite3') {"));
        $this->assertSame(1, preg_match($pattern, "if ('MySQLi' === \$db->DBDriver) {"));
        $this->assertSame(0, preg_match($pattern, "if (\$db->getPlatform() === 'MySQLi') {"));
    }

    public function testTheAppsMysqlConnectionReportsTheMysqliPlatform(): void
    {
        $db = new \App\Database\MySQLi\Connection(['DBDriver' => 'App\Database\MySQLi', 'hostname' => 'localhost', 'database' => 'x', 'username' => 'x', 'password' => '']);

        $this->assertSame('MySQLi', $db->getPlatform(), 'What the code checks.');
        $this->assertNotSame('MySQLi', $db->DBDriver, 'Why DBDriver must not be checked.');
    }
}
