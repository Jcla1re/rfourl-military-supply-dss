<?php

namespace App\Commands;

use App\Libraries\DssRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * A DSS run in the background. Started by the admin Dashboard
 * (DssRunner::startWeeklyIfDue) when the last calculation is more than 7 days
 * old, and by "Recalculate now" in Settings > DSS (DssRunner::startManual,
 * with --by <user_id>). Can also be run by hand or from a scheduler.
 *
 * Uses the same run lock for every trigger, so two runs never overlap.
 * Logged to the security log as "Automatic" or "Manual (by <user>)".
 */
class DssAuto extends BaseCommand
{
    protected $group       = 'DSS';
    protected $name        = 'dss:auto';
    protected $description = 'Background DSS run: weekly automatic, or manual with --by <user_id> (skips if another run is in progress).';
    protected $options     = [
        '--locked' => 'The run lock was already taken by the caller.',
        '--by'     => 'User ID of the admin who clicked "Recalculate now" (logged as a manual run).',
    ];

    public function run(array $params)
    {
        $runner = new DssRunner();

        // Started from the app (--locked) the lock was taken for us before
        // this process was spawned; run directly, it's taken here, and the
        // run is skipped if another one already holds it.
        $preLocked = array_key_exists('locked', $params) || CLI::getOption('locked') !== null || in_array('--locked', $_SERVER['argv'] ?? [], true);
        if (! $preLocked && ! $runner->acquireLock('auto')) {
            CLI::write('Another DSS run is in progress - skipped.', 'yellow');

            return;
        }

        $byId  = (int) ($params['by'] ?? CLI::getOption('by') ?? 0);
        $actor = null;
        if ($byId > 0) {
            $user  = db_connect()->table('users')->select('user_id, username, role')->where('user_id', $byId)->get()->getRowArray();
            $actor = $user ?: ['user_id' => $byId, 'username' => 'user #' . $byId, 'role' => null];
        }

        $result = $actor ? $runner->run('manual', $actor) : $runner->run('auto');

        CLI::write($result['ok']
            ? "DSS run complete: {$result['items']} items." . ($result['note'] ? ' ' . $result['note'] : '')
            : 'DSS run failed: ' . $result['summary'], $result['ok'] ? 'green' : 'red');
    }
}
