<?php

namespace App\Libraries;

use CodeIgniter\Cache\Handlers\DummyHandler;

/**
 * Small counter/lock store behind the login lockout, OTP attempt limit and
 * request rate limits. Uses the server-side cache so clearing cookies can't
 * reset a counter. If the cache is unavailable (CI4 silently swaps in a
 * "dummy" cache that stores nothing when writable/cache/ isn't writable), it
 * falls back to the session instead — weaker, but never a silent no-op.
 */
class AttemptStore
{
    private static function usingCache(): bool
    {
        return ! (cache() instanceof DummyHandler);
    }

    /** @return array{n:int,exp:int}|null  A live entry, or null if absent/expired. */
    private static function read(string $key): ?array
    {
        $entry = self::usingCache() ? cache($key) : session()->get('attempt_' . $key);

        return is_array($entry) && $entry['exp'] > time() ? $entry : null;
    }

    private static function write(string $key, array $entry): void
    {
        if (self::usingCache()) {
            cache()->save($key, $entry, max(1, $entry['exp'] - time()));
        } else {
            session()->set('attempt_' . $key, $entry);
        }
    }

    /** Counts one event in a fixed window that starts at the first hit; returns the new count. */
    public static function hit(string $key, int $windowSeconds): int
    {
        $entry = self::read($key) ?? ['n' => 0, 'exp' => time() + $windowSeconds];
        $entry['n']++;
        self::write($key, $entry);

        return $entry['n'];
    }

    public static function count(string $key): int
    {
        return self::read($key)['n'] ?? 0;
    }

    public static function forget(string $key): void
    {
        self::usingCache() ? cache()->delete($key) : session()->remove('attempt_' . $key);
    }

    public static function lock(string $key, int $seconds): void
    {
        self::write($key, ['n' => 1, 'exp' => time() + $seconds]);
    }

    public static function lockedFor(string $key): int
    {
        $entry = self::read($key);

        return $entry ? max(0, $entry['exp'] - time()) : 0;
    }
}
