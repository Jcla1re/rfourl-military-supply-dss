<?php

namespace App\Libraries;

use App\Models\SecurityLogModel;

/**
 * Runs the DSS engine (`php spark dss:run`: ROP, EOQ, safety stock, ABC
 * classes, reorder alerts) for both triggers:
 *
 *  - Automatic: weekly. When an admin opens the Dashboard (every admin login
 *    lands there) and the last calculation is more than 7 days old, a
 *    separate background process (`php spark dss:auto`) is started so the
 *    page isn't blocked.
 *  - Manual: the "Recalculate now" button in Settings > DSS, also started
 *    in the background (`dss:auto --by <user_id>`).
 *
 * While a run is in progress every admin page polls status()
 * (assets/js/dss-watch.js) and shows a toast when it finishes.
 *
 * Only one run at a time: a lock file is created atomically before a run
 * starts; a second trigger skips while it exists. A lock older than
 * LOCK_TTL is treated as abandoned (e.g. a crashed run) and replaced.
 * If the K-Means service is down, dss:run reuses the last saved
 * classification and still updates ROP/EOQ.
 */
class DssRunner
{
    public const WEEKLY_DAYS = 7;
    private const LOCK_TTL   = 900; // seconds

    private string $lockFile;
    private string $resultFile;

    public function __construct()
    {
        $this->lockFile   = WRITEPATH . 'dss-run.lock';
        $this->resultFile = WRITEPATH . 'dss-last-run.json';
    }

    /** Latest calculation time (pdss_computation), or null if never run. */
    public function lastRunAt(): ?string
    {
        return db_connect()->table('pdss_computation')->selectMax('computed_at', 'mx')->get()->getRow()->mx ?? null;
    }

    /** True when the last calculation is older than WEEKLY_DAYS (or there is none). */
    public function isDue(): bool
    {
        $row = db_connect()->query(
            'SELECT MAX(computed_at) AS mx, MAX(computed_at) < (NOW() - INTERVAL ' . self::WEEKLY_DAYS . ' DAY) AS due FROM pdss_computation'
        )->getRow();

        return $row->mx === null || (int) $row->due === 1;
    }

    public function isRunning(): bool
    {
        clearstatcache(true, $this->lockFile);

        return is_file($this->lockFile) && (time() - filemtime($this->lockFile)) < self::LOCK_TTL;
    }

    /**
     * Takes the run lock. Atomic: of two simultaneous callers only one can
     * create the file. Returns false when a run is already in progress.
     */
    public function acquireLock(string $trigger): bool
    {
        if (is_file($this->lockFile) && ! $this->isRunning()) {
            @unlink($this->lockFile); // abandoned lock from a run that never finished
        }

        $fh = @fopen($this->lockFile, 'x');
        if ($fh === false) {
            return false;
        }
        fwrite($fh, json_encode(['trigger' => $trigger, 'started_at' => date('c'), 'pid' => getmypid()]));
        fclose($fh);

        return true;
    }

    public function releaseLock(): void
    {
        @unlink($this->lockFile);
    }

    /**
     * Weekly check (Dashboard): starts a background run if the last one is
     * more than 7 days old and none is running.
     *
     * @return string 'started' | 'running' | 'idle'
     */
    public function startWeeklyIfDue(): string
    {
        if ($this->isRunning()) {
            return 'running';
        }
        if (! $this->isDue() || ! $this->acquireLock('auto')) {
            return $this->isRunning() ? 'running' : 'idle';
        }
        if (! $this->spawnBackground('dss:auto --locked')) {
            $this->releaseLock();
            log_message('error', 'DSS weekly run: could not start the background process.');

            return 'idle';
        }

        return 'started';
    }

    /**
     * "Recalculate now" (Settings > DSS): starts the run in the background,
     * like the weekly one, so the request returns at once and the admin can
     * keep working; every admin page polls status() and shows a toast.
     *
     * @return string 'started' | 'running' | 'failed'
     */
    public function startManual(int $userId): string
    {
        if (! $this->acquireLock('manual')) {
            return 'running';
        }
        if (! $this->spawnBackground('dss:auto --locked --by ' . $userId)) {
            $this->releaseLock();
            log_message('error', 'DSS manual run: could not start the background process.');

            return 'failed';
        }

        return 'started';
    }

    /** Starts `php spark <command>` detached from this request. */
    public function spawnBackground(string $command): bool
    {
        $php   = $this->phpBinary();
        $spark = ROOTPATH . 'spark';
        if ($php === null || ! is_file($spark)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $handle = @popen('start "" /B "' . $php . '" "' . $spark . '" ' . $command . ' > NUL 2>&1', 'r');
            if ($handle === false) {
                return false;
            }
            pclose($handle);

            return true;
        }

        exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($spark) . ' ' . $command . ' > /dev/null 2>&1 &', $out, $code);

        return $code === 0;
    }

    /**
     * Runs the engine now. The caller must hold the lock; it's released
     * here. Logs "Automatic" or "Manual (by <user>)" to the security log.
     *
     * @param array|null $actor ['user_id', 'username', 'role'] for manual runs
     *
     * @return array{ok: bool, items: int, note: string, summary: string}
     */
    public function run(string $trigger, ?array $actor = null): array
    {
        $db     = db_connect();
        $label  = $trigger === 'auto' ? 'Automatic' : 'Manual (by ' . ($actor['username'] ?? 'unknown') . ')';
        $logAs  = $trigger === 'auto' ? ['user_id' => null, 'username' => 'system', 'role' => 'System'] : $actor;
        $before = (int) ($db->table('pdss_computation')->selectMax('computation_id', 'mx')->get()->getRow()->mx ?? 0);

        try {
            @set_time_limit(300);
            $summary = trim(preg_replace('/\e\[[0-9;]*m/', '', (string) command('dss:run')));
            $items   = (int) $db->table('pdss_computation')->where('computation_id >', $before)->countAllResults();
            $ok      = $items > 0 && stripos($summary, 'failed') === false && stripos($summary, 'cannot compute') === false;
            $note    = stripos($summary, 'Falling back') !== false || stripos($summary, 'No cached cluster') !== false
                ? 'The K-Means service was unreachable, so the last saved classification was used.'
                : '';
        } catch (\Throwable $e) {
            [$summary, $items, $ok, $note] = [$e->getMessage(), 0, false, ''];
        } finally {
            $this->releaseLock();
        }

        SecurityLogModel::log(
            $ok ? 'dss_run' : 'dss_run_failed',
            $logAs,
            $label . ($ok ? ": {$items} items" : ': ' . mb_substr($summary, 0, 200))
        );

        // Active sizes that now have a reorder point (dss:run saves ROP > 0 only).
        $withRop = (int) $db->table('products')->where('is_active', 1)->where('manual_rop_warning >', 0)->countAllResults();

        $result = [
            'trigger' => $trigger, 'ok' => $ok, 'items' => $items, 'with_rop' => $withRop, 'note' => $note,
            'error'   => $ok ? '' : ($items === 0 && $summary === '' ? 'The engine saved no results.' : mb_substr(strtok($summary, "\r\n") ?: $summary, 0, 160)),
            'summary' => $summary, 'finished_at' => date('c'),
        ];
        @file_put_contents($this->resultFile, json_encode($result));

        return $result;
    }

    /**
     * For the Dashboard poller: whether a run is in progress, and the result
     * of the last run that finished.
     *
     * @param bool $withCalculated also include the "Last calculated" line (one DB query)
     *
     * @return array{running: bool, last: array|null, calculated?: string}
     */
    public function status(bool $withCalculated = true): array
    {
        $last = is_file($this->resultFile) ? json_decode((string) file_get_contents($this->resultFile), true) : null;
        if (is_array($last)) {
            unset($last['summary']); // engine output stays server-side
        }

        $status = ['running' => $this->isRunning(), 'last' => $last];
        if ($withCalculated) {
            $status['calculated'] = $this->lastCalculatedLabel();
        }

        return $status;
    }

    /** "Oct 16, 2026, 3:04 PM · 672 items", or null if never run. */
    public function lastCalculatedLabel(): ?string
    {
        $at = $this->lastRunAt();
        if (! $at) {
            return null;
        }
        $items = (int) db_connect()->table('pdss_computation')->where('computed_at', $at)->countAllResults();

        return date('M j, Y, g:i A', strtotime($at)) . ' · ' . number_format($items) . ' items';
    }

    /**
     * The CLI PHP binary. PHP_BINARY is the web server's under Apache, and
     * PHP_BINDIR is the compile-time path (C:\php on XAMPP builds), so the
     * folder of the loaded php.ini (C:\xampp\php) is also tried.
     */
    private function phpBinary(): ?string
    {
        $exe        = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
        $ini        = php_ini_loaded_file();
        $candidates = [PHP_BINARY, $ini ? dirname($ini) . DIRECTORY_SEPARATOR . $exe : null, PHP_BINDIR . DIRECTORY_SEPARATOR . $exe];
        foreach ($candidates as $c) {
            if ($c && is_file($c) && strcasecmp(basename($c), $exe) === 0) {
                return $c;
            }
        }

        return null;
    }
}
