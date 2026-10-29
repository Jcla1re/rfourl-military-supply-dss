<?php

namespace App\Commands;

use App\Libraries\DemoDb;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Saves the current demo database to writable/demo/snapshot.sql, which demo:reset
 * restores every night. Refuses unless DEMO_MODE is true AND the database name contains "demo".
 */
class DemoSnapshot extends BaseCommand
{
    protected $group       = 'Demo';
    protected $name        = 'demo:snapshot';
    protected $description = 'Saves the demo database to writable/demo/snapshot.sql (the state demo:reset goes back to).';

    public function run(array $params)
    {
        if ($reason = DemoDb::guard()) {
            CLI::error($reason);
            DemoDb::log('demo:snapshot refused: ' . $reason);

            return EXIT_ERROR;
        }

        try {
            DemoDb::dump(DemoDb::snapshotPath());
        } catch (\Throwable $e) {
            CLI::error('demo:snapshot failed: ' . $e->getMessage());
            DemoDb::log('demo:snapshot failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $size = number_format(filesize(DemoDb::snapshotPath()) / 1024, 0);
        DemoDb::log("demo:snapshot saved {$size} KB from " . DemoDb::database());
        CLI::write('Snapshot saved to ' . DemoDb::snapshotPath() . " ({$size} KB).", 'green');

        return EXIT_SUCCESS;
    }
}
