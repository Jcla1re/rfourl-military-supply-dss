<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Background requests go through App.fetch (public/assets/js/session-guard.js), which turns a
 * signed-out answer into a trip to the login page and an expired token into a fresh one, instead of
 * pasting the login page into the page or sending a sale anyway. These guard the wiring.
 *
 * @internal
 */
final class AppFetchGuardTest extends CIUnitTestCase
{
    /** @return list<string> every view and script (not the vendor ones) */
    private function sources(): array
    {
        $files = [];
        foreach ([APPPATH . 'Views', FCPATH . 'assets/js'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
                if (in_array($f->getExtension(), ['php', 'js'], true) && ! str_ends_with($f->getFilename(), '.min.js')) {
                    $files[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }

        return $files;
    }

    public function testNoPageMakesARawFetch(): void
    {
        $found = [];
        foreach ($this->sources() as $path) {
            if (str_ends_with($path, 'assets/js/session-guard.js')) {
                continue; // App.fetch itself
            }
            foreach (file($path) as $n => $line) {
                if (preg_match('/(^|[^.\w])fetch\s*\(|XMLHttpRequest\s*\(|\$\.(ajax|get|post|getJSON)\s*\(/', $line)) {
                    $found[] = basename($path) . ':' . ($n + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $found, "Use App.fetch(...) instead:\n" . implode("\n", $found));
    }

    public function testTheSevenBackgroundRequestsUseAppFetch(): void
    {
        $count = static fn (string $file): int => substr_count((string) file_get_contents($file), 'App.fetch(');

        $this->assertSame(2, $count(APPPATH . 'Views/admin/inventory.php'), 'Search and Add/Edit item.');
        $this->assertSame(1, $count(APPPATH . 'Views/staff/inventory.php'));
        $this->assertSame(1, $count(APPPATH . 'Views/admin/archive.php'));
        $this->assertSame(1, $count(FCPATH . 'assets/js/pos-or-check.js'));
        $this->assertSame(1, $count(FCPATH . 'assets/js/dss-watch.js'));
        $this->assertStringContainsString("{ background: true }", file_get_contents(FCPATH . 'assets/js/dss-watch.js'), 'Only the DSS poll is a background poll.');
    }

    public function testOnlyTheDssPollIsMarkedAsBackground(): void
    {
        foreach ($this->sources() as $path) {
            if (str_ends_with($path, 'dss-watch.js') || str_ends_with($path, 'session-guard.js')) {
                continue;
            }
            $this->assertStringNotContainsString('background: true', (string) file_get_contents($path), basename($path));
            $this->assertStringNotContainsString('X-Background-Poll', (string) file_get_contents($path), basename($path));
        }
    }

    public function testEveryLayoutLoadsTheGuardInTheHead(): void
    {
        foreach (['admin', 'staff', 'supplier'] as $role) {
            $layout = (string) file_get_contents(APPPATH . "Views/layouts/{$role}_layout.php");
            $head   = substr($layout, 0, (int) strpos($layout, '</head>'));
            $this->assertStringContainsString('/assets/js/session-guard.js', $head, $role);
            $this->assertStringContainsString('window.APP_SESSION', $head, $role);
        }
    }

    public function testTheGuardNeverRetriesAnything(): void
    {
        $js = (string) file_get_contents(FCPATH . 'assets/js/session-guard.js');

        $this->assertStringNotContainsString('location.reload', $js);
        $this->assertStringNotContainsString('.submit()', $js);
        $this->assertStringNotContainsString('requestSubmit', $js);
        $this->assertStringNotContainsString('setInterval', $js, 'No background token refresh (Part 1).');
    }
}
