<?php

/**
 * Demo mode helpers. Demo mode is a second, public copy of the system with its
 * own database; it is OFF unless DEMO_MODE = true in .env. Values are read on
 * every call (never cached) so tests can switch the mode on and off.
 */

if (! function_exists('demo_mode')) {
    function demo_mode(): bool
    {
        return filter_var(env('DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }
}

if (! function_exists('demo_setting')) {
    /** Any DEMO_* setting from .env, e.g. demo_setting('MAIL_TO'). */
    function demo_setting(string $key, ?string $default = null): ?string
    {
        $value = env('DEMO_' . $key);

        return ($value === null || $value === '') ? $default : (string) $value;
    }
}

if (! function_exists('demo_db_ok')) {
    /** The demo commands only run on a database whose name contains "demo". */
    function demo_db_ok(?string $database = null): bool
    {
        $database ??= (string) config('Database')->default['database'];

        return stripos($database, 'demo') !== false;
    }
}

if (! function_exists('demo_show_credentials')) {
    function demo_show_credentials(): bool
    {
        return demo_mode() && filter_var(env('DEMO_SHOW_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN);
    }
}

if (! function_exists('demo_lock_attr')) {
    /** Attributes for a button that is disabled in the demo: use inside the tag, e.g. <button <?= demo_lock_attr($account) ?>>. */
    function demo_lock_attr(?array $user): string
    {
        return \App\Libraries\DemoGuard::locksUser($user)
            ? ' disabled title="Disabled in the demo version" data-demo-locked="1" style="opacity:.55;cursor:not-allowed;"'
            : '';
    }
}
