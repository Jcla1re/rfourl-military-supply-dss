<?php

namespace App\Libraries;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Demo-mode locks. Everything here does nothing unless DEMO_MODE is true, so
 * the live site behaves exactly as before. Controllers call refuse() so the
 * lock holds even if someone posts straight to a route; the UserModel adds a
 * second check as a backstop.
 */
class DemoGuard
{
    public const MESSAGE = 'Disabled in the demo version.';

    /** User columns a demo account may not change (login-time columns like last_login stay writable). */
    public const PROTECTED_FIELDS = ['username', 'email', 'full_name', 'password_hash', 'role', 'is_active', 'deleted_at'];

    private static int $bypass = 0;

    public static function active(): bool
    {
        return demo_mode();
    }

    /** True when this user row is a demo account that must stay as it is. */
    public static function locksUser(?array $user): bool
    {
        return self::active() && ! empty($user['is_demo']);
    }

    /** Runs $fn with the UserModel backstop switched off (used by demo:setup only). */
    public static function withoutLocks(callable $fn)
    {
        self::$bypass++;
        try {
            return $fn();
        } finally {
            self::$bypass--;
        }
    }

    public static function bypassed(): bool
    {
        return self::$bypass > 0;
    }

    /**
     * Redirect back with the demo message. $flash is 'toast' for pages that show the
     * shared toast, or 'error' for pages that show a plain error line.
     */
    public static function refuse(string $to, string $flash = 'toast'): RedirectResponse
    {
        $redirect = redirect()->to($to);

        return $flash === 'toast'
            ? $redirect->with('toast', Toast::error(self::MESSAGE))
            : $redirect->with($flash, self::MESSAGE);
    }

    /** Shows a code or temporary password on the next page, in the clearly labelled demo box. */
    public static function reveal(string $label, string $code): void
    {
        if (! self::active()) {
            return;
        }

        session()->setFlashdata('demo_secret', ['label' => $label, 'code' => $code]);
    }
}
