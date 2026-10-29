<?php

namespace App\Commands;

use App\Libraries\DemoDb;
use App\Libraries\DemoSetup as DemoAccounts;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Turns a copy of the live database into the public demo: demo logins, team-only
 * emails, real logins switched off and real names replaced. Refuses unless
 * DEMO_MODE is true AND the database name contains "demo".
 */
class DemoSetup extends BaseCommand
{
    protected $group       = 'Demo';
    protected $name        = 'demo:setup';
    protected $description = 'Creates the demo accounts and removes real people from the demo database (needs DEMO_MODE = true and a "demo" database).';

    public function run(array $params)
    {
        if ($reason = DemoDb::guard()) {
            CLI::error($reason);
            DemoDb::log('demo:setup refused: ' . $reason);

            return EXIT_ERROR;
        }

        try {
            $logins = (new DemoAccounts())->run();
        } catch (\Throwable $e) {
            CLI::error('demo:setup failed: ' . $e->getMessage());
            DemoDb::log('demo:setup failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        DemoDb::log('demo:setup done on ' . DemoDb::database());
        CLI::write('Demo accounts are ready on database "' . DemoDb::database() . '".', 'green');
        CLI::write('Every email in this database now points at ' . demo_setting('MAIL_TO') . ' (+aliases); every other login is switched off.');
        CLI::newLine();

        foreach ($logins as $l) {
            CLI::write(sprintf('  %-9s username: %-14s password: %s', $l['label'], $l['username'], $l['password']));
        }

        return EXIT_SUCCESS;
    }
}
