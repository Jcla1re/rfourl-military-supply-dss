<?php

namespace App\Libraries;

/**
 * Session-based brute-force throttle for the login forms. Tracks failed
 * attempts per role (admin/staff/supplier) rather than per account, since
 * the Staff login has no username to key on and this only needs to slow
 * down repeated guessing at the form itself, not survive across devices.
 */
class LoginLockout
{
    public const MAX_ATTEMPTS    = 5;
    public const LOCKOUT_SECONDS = 60;

    public function secondsRemaining(string $role): int
    {
        $until = session()->get("login_locked_until_{$role}");

        if (! $until) {
            return 0;
        }

        $remaining = $until - time();

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Records one failed attempt; once MAX_ATTEMPTS is reached, starts the
     * lockout window and resets the counter so the next window starts fresh.
     */
    public function registerFailure(string $role): void
    {
        $key      = "login_attempts_{$role}";
        $attempts = (int) session()->get($key) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            session()->set("login_locked_until_{$role}", time() + self::LOCKOUT_SECONDS);
            session()->remove($key);
            return;
        }

        session()->set($key, $attempts);
    }

    public function reset(string $role): void
    {
        session()->remove(["login_attempts_{$role}", "login_locked_until_{$role}"]);
    }
}
