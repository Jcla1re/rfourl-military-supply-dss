<?php

namespace App\Commands;

use App\Database\Seeds\LegacyDataSeeder;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lists the non-removed Staff accounts still on the one password every seeded user starts with
 * (LegacyDataSeeder::DEFAULT_PASSWORD, from the 02_users.csv import). Anyone who knows that default
 * and a username could sign in as them, so the owner should reset these (Settings > Manage Accounts
 * > Reset Password). Stored bcrypt hashes can't be compared to each other, so only that known
 * password can be checked.
 */
class StaffCheckPasswords extends BaseCommand
{
    protected $group       = 'Accounts';
    protected $name        = 'staff:check-passwords';
    protected $description = 'Lists staff accounts still on the seeded default password (the owner should reset them).';

    public function run(array $params)
    {
        $staff = (new UserModel())->activeStaff()->orderBy('user_id')->findAll();

        $onDefault = array_values(array_filter(
            $staff,
            static fn ($s) => password_verify(LegacyDataSeeder::DEFAULT_PASSWORD, $s['password_hash'])
        ));

        CLI::write('Staff accounts checked: ' . count($staff));

        if ($onDefault === []) {
            CLI::write('No staff account is still on the seeded default password.', 'green');

            return;
        }

        CLI::write(count($onDefault) . ' still on the seeded default password:', 'yellow');
        foreach ($onDefault as $s) {
            CLI::write(sprintf('  #%d  @%-12s %-24s %s', $s['user_id'], $s['username'], $s['full_name'], $s['is_active'] ? 'active' : 'inactive'));
        }

        CLI::write('Reset them in Settings > Manage Accounts > Reset Password.', 'red');
    }
}
