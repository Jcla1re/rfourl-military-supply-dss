<?php

namespace App\Commands;

use App\Libraries\OnceToken;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Deletes one-time form keys (form_submissions, see App\Libraries\OnceToken) older than N days.
 * They only matter for a few seconds after a submit, so this is safe at any time. The weekly
 * DSS run (dss:run) also does it.
 *
 *   php spark forms:purge          (older than 7 days)
 *   php spark forms:purge 30
 */
class PurgeFormSubmissions extends BaseCommand
{
    protected $group       = 'Maintenance';
    protected $name        = 'forms:purge';
    protected $description = 'Deletes one-time form submission keys older than N days (default 7).';
    protected $usage       = 'forms:purge [days]';
    protected $arguments   = ['days' => 'Keep keys newer than this many days (default 7).'];

    public function run(array $params)
    {
        $days = max(1, (int) ($params[0] ?? 7));

        CLI::write('Removed ' . OnceToken::purgeOlderThan($days) . " form key(s) older than {$days} days.", 'green');
    }
}
