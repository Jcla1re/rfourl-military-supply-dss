<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Idle sign-out. After $idleSeconds without a request that reaches the server (RoleAuthFilter),
 * the user is signed out. This is separate from Config\Session::$expiration (how long the browser
 * keeps the session cookie and the server keeps the session file), which is longer on purpose: the
 * server can then still tell "signed out for inactivity" from "never signed in".
 *
 * TEST MODE (localhost only): put `auth.idleTestSeconds = 60` in .env to be signed out after 60
 * seconds. It is used only when CI_ENVIRONMENT is development; anywhere else (production on the live
 * server, testing) it is ignored and a warning is logged. .env is never committed.
 */
class Auth extends BaseConfig
{
    /** 30 minutes. */
    public int $idleSeconds = 1800;

    /** Test mode: a shorter limit, from .env `auth.idleTestSeconds`; 0 = off. Development only. */
    public int $idleTestSeconds = 0;

    /**
     * Forgot password, where PHP can't send the email after the response (mod_php: see
     * App\Libraries\AfterResponse): every answer of the forgot form and of Resend takes at least this
     * long, so a found account and an unknown one take the same time. It should be longer than a
     * normal email send. The tests set it to 0 (phpunit.dist.xml).
     */
    public float $forgotMinSeconds = 3.0;

    /**
     * Sent only by background polls that are not the user doing something (the DSS status poll, the
     * session status check). A request with it is still checked for the idle limit, but does not
     * count as activity. Search, saving and every other request still count.
     */
    public const BACKGROUND_HEADER = 'X-Background-Poll';

    /** The idle limit in seconds: $idleSeconds, or the test-mode value on a development machine. */
    public function idleLimit(?string $environment = null): int
    {
        return $this->testModeActive($environment) ? $this->idleTestSeconds : $this->idleSeconds;
    }

    public function testModeActive(?string $environment = null): bool
    {
        if ($this->idleTestSeconds <= 0) {
            return false;
        }
        if (($environment ?? ENVIRONMENT) !== 'development') {
            static $warned = false;
            if (! $warned) {
                $warned = true;
                log_message('warning', 'auth.idleTestSeconds is set but ignored outside development: the idle limit stays ' . $this->idleSeconds . ' seconds.');
            }

            return false;
        }

        return true;
    }
}
