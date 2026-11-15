<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * ST-16: a session idle for longer than the configured limit (30 minutes) is
 * refused on the server, even when the session ID is still presented.
 * The check runs before any database lookup, so no DB is needed.
 *
 * @internal
 */
final class IdleTimeoutTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testIdleSessionIsRefused(): void
    {
        // The idle limit (Config\Auth), not the session cookie's life (Config\Session, 8 hours).
        $limit = config(\Config\Auth::class)->idleLimit();
        $this->assertSame(1800, $limit);
        $this->assertSame(28800, (int) config('Session')->expiration);

        $result = $this->withSession([
            'isLoggedIn'    => true,
            'role'          => 'Staff',
            'user_id'       => 1,
            'last_activity' => time() - $limit - 60,
        ])->get('/staff/dashboard');

        $result->assertRedirectTo(site_url('login/staff'));
    }
}
