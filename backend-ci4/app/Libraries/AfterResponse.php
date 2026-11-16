<?php

namespace App\Libraries;

/**
 * Work (sending an email) that must not change how long a page takes, so the timing never tells
 * whether an account exists (Forgot password).
 *
 * Two ways, picked by how PHP runs:
 *
 *  - DEFERRED (PHP-FPM / FastCGI: fastcgi_finish_request() exists). defer() only queues the job.
 *    After CodeIgniter has sent the response, a shutdown function:
 *      1. saves and closes the session (session_write_close), so the next page always finds what
 *         this request stored (the pending reset);
 *      2. finishes the response (fastcgi_finish_request): the browser has the whole page now;
 *      3. runs the queued jobs.
 *  - PADDED (mod_php, the built-in server, the command line). defer() runs the job at once, and
 *    the controller calls padTo() before answering, so every answer of that form takes at least
 *    Config\Auth::$forgotMinSeconds. An email slower than that still shows; the log says so.
 *
 * A job never throws to the caller: a failure is written to the log.
 */
final class AfterResponse
{
    /** @var list<callable> */
    private static array $jobs = [];

    private static bool $registered = false;

    /** Tests only: true/false forces DEFERRED/PADDED; null decides from the PHP setup. */
    public static ?bool $forceDeferred = null;

    /** Tests only: replace the three steps of the shutdown function (each gets no arguments). */
    public static $closeSession = null;
    public static $finishRequest = null;

    /** Tests only: replaces usleep() in padTo(); gets the microseconds. */
    public static $sleeper = null;

    public static function deferred(): bool
    {
        if (self::$forceDeferred !== null) {
            return self::$forceDeferred;
        }

        return function_exists('fastcgi_finish_request') && ! is_cli();
    }

    /** "deferred" or "padded": which one this server uses (shown by nothing; for logs and tests). */
    public static function mode(): string
    {
        return self::deferred() ? 'deferred' : 'padded';
    }

    public static function defer(callable $job): void
    {
        if (! self::deferred()) {
            self::run($job);

            return;
        }

        self::$jobs[] = $job;
        if (! self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'runQueued']);
        }
    }

    /** Jobs waiting for the end of the request. */
    public static function pending(): int
    {
        return count(self::$jobs);
    }

    /**
     * The shutdown function (see the class comment). Public so tests can run it the way PHP does
     * at the end of a request.
     */
    public static function runQueued(): void
    {
        if (self::$jobs === []) {
            return;
        }

        try {
            if (is_callable(self::$closeSession)) {
                (self::$closeSession)();
            } elseif (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        } catch (\Throwable $e) {
            log_message('error', 'AfterResponse: the session could not be saved first: ' . $e->getMessage());
        }

        try {
            if (is_callable(self::$finishRequest)) {
                (self::$finishRequest)();
            } else {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'AfterResponse: the response could not be finished first: ' . $e->getMessage());
        }

        $jobs       = self::$jobs;
        self::$jobs = [];
        foreach ($jobs as $job) {
            self::run($job);
        }
    }

    /**
     * PADDED only: waits until $minSeconds have passed since $startedAt (microtime(true)). Does
     * nothing in DEFERRED mode, where the email is not part of the answer's time.
     */
    public static function padTo(float $startedAt, float $minSeconds): void
    {
        if (self::deferred() || $minSeconds <= 0) {
            return;
        }

        $elapsed = microtime(true) - $startedAt;
        if ($elapsed > $minSeconds) {
            log_message('warning', sprintf('Forgot password took %.2f s, longer than the %.2f s it is padded to: the time may differ between accounts.', $elapsed, $minSeconds));

            return;
        }

        $micro = (int) round(($minSeconds - $elapsed) * 1_000_000);
        is_callable(self::$sleeper) ? (self::$sleeper)($micro) : usleep($micro);
    }

    /** Tests only: back to the start. */
    public static function resetForTests(): void
    {
        self::$jobs          = [];
        self::$forceDeferred = null;
        self::$closeSession  = null;
        self::$finishRequest = null;
        self::$sleeper       = null;
    }

    private static function run(callable $job): void
    {
        try {
            $job();
        } catch (\Throwable $e) {
            log_message('error', 'AfterResponse job failed: ' . $e->getMessage());
        }
    }
}
