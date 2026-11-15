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

    private function guard(): string
    {
        return (string) file_get_contents(FCPATH . 'assets/js/session-guard.js');
    }

    /** The body of a JS function declared as "function $name(...) {" up to its closing brace. */
    private function jsFunction(string $js, string $name): string
    {
        $start = strpos($js, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name);
        $open  = strpos($js, '{', $start);
        $depth = 0;
        for ($i = $open; $i < strlen($js); $i++) {
            $depth += $js[$i] === '{' ? 1 : ($js[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                return substr($js, $start, $i - $start + 1);
            }
        }

        return substr($js, $start);
    }

    public function testTheGuardNeverReloadsResubmitsOrKeepsTheSessionAlive(): void
    {
        $js = $this->guard();

        $this->assertStringNotContainsString('location.reload', $js);
        $this->assertStringNotContainsString('.submit()', $js);
        $this->assertStringNotContainsString('requestSubmit', $js);
        $this->assertStringNotContainsString('location.assign', $js, 'Leaving replaces the page (Back never shows it again).');
        $this->assertStringContainsString('location.replace(', $js);

        // Activity is only what reaches the server: no mouse, keyboard or scroll counts.
        foreach (['mousemove', 'mousedown', 'keydown', 'keypress', 'keyup', "'click'", 'scroll', 'touchstart', 'pointer', 'wheel', "'input'"] as $userEvent) {
            $this->assertStringNotContainsString($userEvent, $js, $userEvent);
        }
    }

    public function testOnlyTheStatusCheckRunsOnATimerAndItNeverCountsAsActivity(): void
    {
        $js = $this->guard();

        $this->assertSame(1, substr_count($js, 'setInterval('), 'One timer.');
        $this->assertStringContainsString('setInterval(check, CHECK_EVERY)', $js);
        $this->assertStringContainsString('var CHECK_EVERY = 30000;', $js, 'Every 30 seconds.');
        $this->assertSame(substr_count($js, 'setTimeout('), substr_count($js, 'setTimeout(check,'), 'Any other timer only runs the same check.');

        // check() itself is local; the only request it can make is askServer(), the status check.
        $check = $this->jsFunction($js, 'check');
        $this->assertStringNotContainsString('fetch(', $check);
        $this->assertStringContainsString('askServer()', $check);
        $ask = $this->jsFunction($js, 'askServer');
        $this->assertStringContainsString('fetch(cfg.statusUrl', $ask);
        $this->assertStringContainsString("'X-Background-Poll': '1'", $ask, 'Asking never resets the idle clock.');

        // The only requests in the guard: App.fetch's own, the token after a failed submit, the status check.
        $this->assertSame(3, preg_match_all('/(^|[^.\w])fetch\s*\((?!\))/', $js), '"fetch()" in the comment is not a request.');
        $this->assertStringContainsString('fetch(cfg.csrfUrl', $this->jsFunction($js, 'refreshToken'));

        // Shown again (tab, focus, a laptop waking up): the same check.
        foreach (["addEventListener('visibilitychange'", "addEventListener('focus', check)", "addEventListener('pageshow'"] as $wake) {
            $this->assertStringContainsString($wake, $js);
        }
    }

    public function testLeavingFiresTheEventTellsTheOtherTabsAndHidesThePage(): void
    {
        $leave = $this->jsFunction($this->guard(), 'leave');

        $this->assertStringContainsString('announce(reason)', $leave, '"session:leaving": the POS saves its cart.');
        $this->assertStringContainsString('tell(', $leave);
        $this->assertStringContainsString('hidePage()', $leave);
        $this->assertLessThan(strpos($leave, 'location.replace('), strpos($leave, 'hidePage()'), 'Hidden before the login page loads.');
        $this->assertStringContainsString("'session:leaving'", $this->guard());
        $this->assertStringContainsString("new BroadcastChannel('rfl-session')", $this->guard());
        $this->assertStringContainsString("signal: 'rfl.session.signal'", $this->guard(), 'The localStorage fallback.');

        $url = $this->jsFunction($this->guard(), 'loginUrlFor');
        $this->assertStringContainsString("if (reason === 'idle') params.push('reason=idle')", $url);
        $this->assertStringContainsString("if (reason !== 'logout') params.push('return='", $url, 'Logout: no idle notice, no return page.');
    }

    public function testTheLogoutButtonTellsTheOtherTabsAndDeletesTheSavedCart(): void
    {
        $js    = $this->guard();
        $start = strpos($js, "hasAttribute('data-logout')");
        $this->assertNotFalse($start);
        $handler = substr($js, $start, 600);

        $this->assertStringContainsString('e.defaultPrevented', $handler, 'Only a logout that is really sent.');
        $this->assertStringContainsString('write(KEYS.cart, null)', $handler);
        $this->assertStringContainsString("tell({ type: 'logout' })", $handler);
        $this->assertStringContainsString("cart: 'rfl.pos.cart'", $js);
    }

    public function testEveryLogoutFormIsMarkedForTheGuard(): void
    {
        $found = 0;
        foreach ($this->sources() as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            preg_match_all('/<form method="post" action="<\?= site_url\(\'logout\'\) \?>"[^>]*>/', (string) file_get_contents($path), $forms);
            foreach ($forms[0] as $form) {
                $found++;
                $this->assertStringContainsString('data-logout', $form, basename($path));
            }
        }
        $this->assertGreaterThanOrEqual(5, $found, 'Three layouts, the account menu and the set-password page.');
    }

    // ---------------------------------------------------------------- the POS cart saved in the browser

    private function pos(): string
    {
        return (string) file_get_contents(FCPATH . 'assets/js/pos.js');
    }

    public function testTheSavedCartHoldsItemIdsAndQuantitiesOnly(): void
    {
        $js    = $this->pos();
        $saved = substr($js, (int) strpos($js, '// SAVED CART START'), (int) strpos($js, '// SAVED CART END') - (int) strpos($js, '// SAVED CART START'));

        $this->assertNotSame('', $saved);
        $this->assertStringContainsString('.map(id => ({ id: id, qty: cart[id].qty }))', $saved);
        $this->assertStringContainsString('JSON.stringify({ v: 1, userId: cartUser, savedAt: cartChangedAt || Date.now(), lines: lines })', $saved);
        foreach (['price', 'name', 'size', 'stock', 'payment', 'cash', 'gcash', 'or_number'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $saved, $never);
        }
        $this->assertSame(1, substr_count($js, 'localStorage.setItem('), 'Nothing else is written.');
    }

    public function testTheSavedCartComesBackOnlyWhenTheServerSaysSo(): void
    {
        $js = $this->pos();

        $this->assertStringContainsString("if (form.dataset.restoreSaved === '1') restoreSavedCart(); else forgetSavedCart();", $js, 'Any other load clears it.');
        $restore = $this->jsFunction($js, 'restoreSavedCart');
        $this->assertStringContainsString('String(saved.userId) !== String(cartUser)', $restore, 'Another user\'s cart is thrown away.');
        $this->assertStringContainsString('age <= CART_MAX_AGE', $restore);
        $this->assertStringContainsString('const CART_MAX_AGE = 2 * 60 * 60 * 1000;', $js, '2 hours.');
        $this->assertStringContainsString('addLines(saved.lines)', $restore, 'Rebuilt like the Part 1 cart: prices and stock from the page.');
        $this->assertLessThan(strpos($restore, 'addLines('), strpos($restore, 'forgetSavedCart()'), 'Read once, then gone.');
        $this->assertStringContainsString("title: 'Your cart was restored'", $restore);
        $this->assertStringContainsString('back in your cart. Check them before checkout.', $restore);

        $add = $this->jsFunction($js, 'addLines');
        $this->assertStringContainsString('price: v.selling_price', $add);
        $this->assertStringContainsString('capped: wanted > v.current_stock', $add, 'Capped to stock, with the usual note.');
        $this->assertStringContainsString('if (!v || qty < 1 || v.current_stock < 1) return;', $add, 'Gone or sold out: left out.');
    }

    public function testThePosSavesOnLeavingAndForgetsOnLogout(): void
    {
        $js    = $this->pos();
        $start = strpos($js, "window.addEventListener('session:leaving'");
        $this->assertNotFalse($start);
        $handler = substr($js, $start, 500);

        $this->assertStringContainsString("e.detail.reason === 'logout') { forgetSavedCart(); return; }", $handler);
        $this->assertStringContainsString('saveCart()', $handler);
        $this->assertStringContainsString('cartChanged();', $this->jsFunction($js, 'renderCart'), 'Saved on every change.');
    }
}
