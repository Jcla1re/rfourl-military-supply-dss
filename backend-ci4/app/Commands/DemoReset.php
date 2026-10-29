<?php

namespace App\Commands;

use App\Libraries\DemoDb;
use App\Libraries\DemoSetup as DemoAccounts;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The nightly reset: restores writable/demo/snapshot.sql into the demo database, re-runs
 * the demo account setup, and logs everyone out. Refuses unless DEMO_MODE is true AND the
 * database name contains "demo".
 */
class DemoReset extends BaseCommand
{
    protected $group       = 'Demo';
    protected $name        = 'demo:reset';
    protected $description = 'Restores the demo snapshot, re-runs demo:setup and logs everyone out (run nightly by cron).';

    public function run(array $params)
    {
        if ($reason = DemoDb::guard()) {
            CLI::error($reason);
            DemoDb::log('demo:reset refused: ' . $reason);

            return EXIT_ERROR;
        }

        try {
            DemoDb::restore(DemoDb::snapshotPath());
            // The restore replaced every table, so reconnect before using the models again.
            db_connect()->reconnect();
            // A snapshot taken before a deploy may lack newer columns (e.g. users.is_demo): bring it up to date.
            service('migrations')->latest();
            (new DemoAccounts())->run();
            DemoDb::clearSessions();
        } catch (\Throwable $e) {
            CLI::error('demo:reset failed: ' . $e->getMessage());
            DemoDb::log('demo:reset failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        DemoDb::log('demo:reset done on ' . DemoDb::database());
        CLI::write('Demo database restored from the snapshot, accounts set up, everyone logged out.', 'green');

        return EXIT_SUCCESS;
    }
}
