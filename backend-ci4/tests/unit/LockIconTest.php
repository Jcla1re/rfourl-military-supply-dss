<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The shared padlock (app/Views/partials/_lock_icon.php, approved PosLockV1 board) and where it is used
 * instead of Bootstrap's bi-lock / bi-lock-fill font icons.
 *
 * @internal
 */
final class LockIconTest extends CIUnitTestCase
{
    private function icon(array $data = []): string
    {
        return view('partials/_lock_icon', $data, ['saveData' => false]);
    }

    public function testTheDefaultIsAClosed16pxOutlineLockInTheTextColour(): void
    {
        $svg = $this->icon();
        foreach ([
            '<svg class="lock-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">',
            '<rect x="4" y="10" width="16" height="11" rx="2"/>',
            '<path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        ] as $part) {
            $this->assertStringContainsString($part, $svg);
        }
    }

    public function testSizeOpenShackleAndLineWeight(): void
    {
        $svg = $this->icon(['lockIconSize' => 20, 'lockIconOpen' => true, 'lockIconStroke' => 1.5]);
        $this->assertStringContainsString('width="20" height="20"', $svg);
        $this->assertStringContainsString('stroke-width="1.5"', $svg);
        $this->assertStringContainsString('<path d="M8 10V7a4 4 0 0 1 7.5-2"/>', $svg, 'The shackle open on one side.');
        $this->assertStringNotContainsString('0 1 8 0v3', $svg);
    }

    public function testBadOrMissingValuesFallBackToTheDefaults(): void
    {
        // A parent view's own 'size' (Inventory's size filter, often '') never reaches the icon.
        $svg = view('partials/_lock_icon', ['size' => '', 'lockIconSize' => 0, 'lockIconStroke' => 'x'], ['saveData' => false]);
        $this->assertStringContainsString('width="16" height="16"', $svg);
        $this->assertStringContainsString('stroke-width="1.8"', $svg);
    }

    public function testTheNamesAreTheIconsOwn(): void
    {
        // Like $penIconSize: only the partial and its callers use these names.
        foreach (['lockIconSize', 'lockIconOpen', 'lockIconStroke'] as $name) {
            foreach ($this->views() as $file) {
                if (str_contains(file_get_contents($file), $name)) {
                    $this->assertMatchesRegularExpression("/'{$name}' =>|_lock_icon\\.php/", file_get_contents($file), "{$file} uses {$name} only to pass it to the icon");
                }
            }
        }
    }

    public function testThePasswordBoxesUseTheLighterLineToMatchThePersonIcon(): void
    {
        $call = "<i aria-hidden=\"true\"><?= view('partials/_lock_icon', ['lockIconSize' => 16, 'lockIconStroke' => 1.5], ['saveData' => false]) ?></i>";
        $this->assertSame(1, substr_count(file_get_contents(APPPATH . 'Views/auth/login.php'), $call), 'Sign-in: the Password box.');
        $this->assertSame(2, substr_count(file_get_contents(APPPATH . 'Views/auth/_password_fields.php'), $call), 'New and Confirm password.');

        $html = view('auth/_password_fields', ['serverRule' => 'x'], ['saveData' => false]);
        $this->assertSame(2, preg_match_all('#<div class="input-icon-wrap">\s*<i aria-hidden="true">\s*(<!--.*?-->\s*)*<svg class="lock-icon" width="16" height="16"[^>]*stroke-width="1\.5"#s', $html), 'Still the first thing in the box (same position and #999 colour from .input-icon-wrap > i).');
        $this->assertStringContainsString('.input-icon-wrap > i > svg { display: block; }', file_get_contents(FCPATH . 'assets/css/auth-theme.css'));
    }

    public function testNoBootstrapPadlockIsLeftInTheViews(): void
    {
        foreach ($this->views() as $file) {
            // admin/suppliers.php (the archived supplier's "Portal locked" badge) is in Developer A's active
            // area: it gets the shared padlock in a follow-up, after their work is merged.
            if (str_ends_with(str_replace('\\', '/', $file), 'Views/admin/suppliers.php')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/\bbi-(un)?lock(-fill)?\b/', file_get_contents($file), $file);
        }
    }

    /** @return list<string> every view file */
    private function views(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH . 'Views', FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
