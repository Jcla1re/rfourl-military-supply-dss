<?php

namespace App\Commands;

use App\Database\Seeds\LegacyDataSeeder;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Staff sign in with their password alone, so two staff on the same password
 * can't be told apart. Stored bcrypt hashes can't be compared to each other,
 * so this checks every non-removed Staff account against the one password
 * every seeded user starts with (LegacyDataSeeder::DEFAULT_PASSWORD, from the
 * 02_users.csv import) and lists the accounts still using it. It cannot find
 * two accounts that share some other password; the login itself refuses that
 * case at sign-in time.
 */
class StaffCheckPasswords extends BaseCommand
{
    protected $group       = 'Accounts';
    protected $name        = 'staff:check-passwords';
    protected $description = 'Lists staff accounts still on the seeded default password (they cannot be told apart at the password-only staff login).';

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

        $activeCount = count(array_filter($onDefault, static fn ($s) => $s['is_active']));
        if ($activeCount > 1) {
            CLI::write("{$activeCount} ACTIVE accounts share it, so staff login is blocked for all of them until the owner resets their passwords (Settings > Manage Accounts > Reset Password).", 'red');
        }
    }
}
