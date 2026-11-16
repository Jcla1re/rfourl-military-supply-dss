<?php

namespace App\Libraries;

use App\Models\UserModel;

/**
 * Brute-force throttle for the sign-in form. Counted in the server-side cache (see AttemptStore), so
 * clearing cookies or opening a fresh session does not reset a counter. Two locks, both 60 seconds:
 *
 *  - per account: 3 wrong tries in a row for one typed username from one IP address. The username
 *    is compared as UserModel::normalise() does (no spaces at the ends, any letter case), so
 *    "Admin1 " and "admin1" share one counter. It doesn't matter whether that username exists.
 *  - per IP address (backstop): 30 wrong tries in 15 minutes from one address, whatever usernames
 *    were typed, so trying many usernames doesn't get around the first lock. The whole store shares
 *    one IP, hence the high number.
 */
class LoginLockout
{
    public const MAX_ATTEMPTS    = 3;
    public const LOCKOUT_SECONDS = 60;
    private const WINDOW_SECONDS = 900;

    public const IP_MAX_FAILURES   = 30;
    public const IP_WINDOW_SECONDS = 900;

    private function ip(): string
    {
        return (string) service('request')->getIPAddress();
    }

    private function key(string $what, ?string $username): string
    {
        return "login_{$what}_" . md5($this->ip() . '|' . UserModel::normalise($username));
    }

    private function ipKey(string $what): string
    {
        return "login_ip_{$what}_" . md5($this->ip());
    }

    /** Seconds before this username may be tried again from this IP (either lock); 0 when free. */
    public function secondsRemaining(?string $username): int
    {
        return max(AttemptStore::lockedFor($this->key('locked', $username)), AttemptStore::lockedFor($this->ipKey('locked')));
    }

    /** True when the account lock (3 in a row) is what's holding this username, not only the IP backstop. */
    public function accountLocked(?string $username): bool
    {
        return AttemptStore::lockedFor($this->key('locked', $username)) > 0;
    }

    /**
     * Records one wrong try for this username and for this IP. A lock starts when either count
     * reaches its limit; that count then starts again from zero.
     */
    public function registerFailure(?string $username): void
    {
        $attemptsKey = $this->key('attempts', $username);
        if (AttemptStore::hit($attemptsKey, self::WINDOW_SECONDS) >= self::MAX_ATTEMPTS) {
            AttemptStore::lock($this->key('locked', $username), self::LOCKOUT_SECONDS);
            AttemptStore::forget($attemptsKey);
        }

        $ipAttempts = $this->ipKey('attempts');
        if (AttemptStore::hit($ipAttempts, self::IP_WINDOW_SECONDS) >= self::IP_MAX_FAILURES) {
            AttemptStore::lock($this->ipKey('locked'), self::LOCKOUT_SECONDS);
            AttemptStore::forget($ipAttempts);
        }
    }

    /** Wrong tries still allowed for this username before its lock starts (3 at first, then 2, then 1). */
    public function triesLeft(?string $username): int
    {
        return max(0, self::MAX_ATTEMPTS - AttemptStore::count($this->key('attempts', $username)));
    }

    /** After a correct sign-in (or a password reset): this username's count and lock start again. The IP count is kept. */
    public function reset(?string $username): void
    {
        AttemptStore::forget($this->key('attempts', $username));
        AttemptStore::forget($this->key('locked', $username));
    }

    /** Clears the IP backstop for this address (tests, and nothing else). */
    public function resetIp(): void
    {
        AttemptStore::forget($this->ipKey('attempts'));
        AttemptStore::forget($this->ipKey('locked'));
    }
}
