<?php

namespace App\Libraries;

/**
 * Brute-force throttle for the login forms. Failed attempts are counted per
 * role AND per client IP address in the server-side cache (see AttemptStore),
 * so clearing cookies or opening a fresh session does not reset the counter.
 * Tracked per role rather than per account because the Staff login has no
 * username to key on.
 */
class LoginLockout
{
    public const MAX_ATTEMPTS    = 5;
    public const LOCKOUT_SECONDS = 60;
    private const WINDOW_SECONDS = 900;

    private function key(string $what, string $role): string
    {
        return "login_{$what}_{$role}_" . md5(service('request')->getIPAddress());
    }

    public function secondsRemaining(string $role): int
    {
        return AttemptStore::lockedFor($this->key('locked', $role));
    }

    /**
     * Records one failed attempt; once MAX_ATTEMPTS is reached, starts the
     * lockout window and resets the counter so the next window starts fresh.
     */
    public function registerFailure(string $role): void
    {
        $attemptsKey = $this->key('attempts', $role);

        if (AttemptStore::hit($attemptsKey, self::WINDOW_SECONDS) >= self::MAX_ATTEMPTS) {
            AttemptStore::lock($this->key('locked', $role), self::LOCKOUT_SECONDS);
            AttemptStore::forget($attemptsKey);
        }
    }

    public function reset(string $role): void
    {
        AttemptStore::forget($this->key('attempts', $role));
        AttemptStore::forget($this->key('locked', $role));
    }
}
