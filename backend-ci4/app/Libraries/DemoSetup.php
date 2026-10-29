<?php

namespace App\Libraries;

use App\Models\UserModel;

/**
 * Prepares a demo database (a copy of live): creates the three demo logins, points every
 * email at the team's inbox, switches off every real login, and replaces real people's
 * names. Run through `php spark demo:setup` (which does the DEMO_MODE and database-name
 * checks first) and again by `demo:reset`.
 */
class DemoSetup
{
    public const SUPPLIER_ID = 'SUP-001';

    /** [username, role, label, env key, full name, email tag] */
    private const ACCOUNTS = [
        ['demo_owner',    'Admin',    'Owner',    'OWNER_PASSWORD',    'Demo Owner',    'owner'],
        ['demo_staff',    'Staff',    'Staff',    'STAFF_PASSWORD',    'Demo Staff',    'staff'],
        ['demo_supplier', 'Supplier', 'Supplier', 'SUPPLIER_PASSWORD', 'Demo Supplier', 'supplier'],
    ];

    /**
     * @return list<array{label: string, username: string, password: string}>
     *
     * @throws \RuntimeException when a password is missing or the staff password is not unique.
     */
    public function run(): array
    {
        $mailTo = $this->mailTo();
        $users  = new UserModel();
        $db     = db_connect();

        $passwords = [];
        foreach (self::ACCOUNTS as [, , , $key]) {
            $value = demo_setting($key);
            if ($value === null) {
                throw new \RuntimeException("DEMO_{$key} is not set in .env.");
            }
            $passwords[$key] = $value;
        }

        if (! $db->table('suppliers')->where('supplier_id', self::SUPPLIER_ID)->countAllResults()) {
            throw new \RuntimeException('Supplier ' . self::SUPPLIER_ID . ' does not exist in this database.');
        }

        return DemoGuard::withoutLocks(function () use ($users, $db, $mailTo, $passwords) {
            $db->transStart();

            $demoIds = $this->upsertDemoAccounts($users, $mailTo, $passwords);
            $this->anonymiseOthers($db, $demoIds, $mailTo);
            $this->anonymiseSuppliers($db, $mailTo);
            $this->clearPersonalLogs($db);

            // Staff sign in by password alone: the demo staff password must be unique.
            if ($users->staffPasswordInUse($passwords['STAFF_PASSWORD'], $demoIds['demo_staff'])) {
                $db->transRollback();

                throw new \RuntimeException("DEMO_STAFF_PASSWORD matches another staff account's password. Choose a different one.");
            }

            $db->transComplete();
            if (! $db->transStatus()) {
                throw new \RuntimeException('Setup failed and was rolled back.');
            }

            $logins = [];
            foreach (self::ACCOUNTS as [$username, , $label, $key]) {
                $logins[] = ['label' => $label, 'username' => $username, 'password' => $passwords[$key]];
            }

            return $logins;
        });
    }

    /** DEMO_MAIL_TO, as [local part, domain]. */
    public function mailTo(): array
    {
        $to = demo_setting('MAIL_TO');
        if ($to === null || ! preg_match('/^([^@\s+]+)(?:\+[^@\s]*)?@([^@\s]+)$/', $to, $m)) {
            throw new \RuntimeException('DEMO_MAIL_TO must be a plain email address such as ourteam@gmail.com.');
        }

        return [$m[1], $m[2]];
    }

    private function alias(array $mailTo, string $tag): string
    {
        return $mailTo[0] . '+' . $tag . '@' . $mailTo[1];
    }

    /** @return array<string, int> username => user_id */
    private function upsertDemoAccounts(UserModel $users, array $mailTo, array $passwords): array
    {
        $ids = [];

        foreach (self::ACCOUNTS as [$username, $role, , $key, $fullName, $tag]) {
            $row = [
                'username'                 => $username,
                'role'                     => $role,
                'full_name'                => $fullName,
                'email'                    => $this->alias($mailTo, $tag),
                'phone'                    => null,
                'password_hash'            => password_hash($passwords[$key], PASSWORD_DEFAULT),
                'supplier_id'              => $role === 'Supplier' ? self::SUPPLIER_ID : null,
                'is_active'                => 1,
                'is_demo'                  => 1,
                'deleted_at'               => null,
                'must_change_password'     => 0,
                'temp_password_kind'       => null,
                'temp_password_expires_at' => null,
                'reset_otp_hash'           => null,
                'reset_otp_expires'        => null,
            ];

            // The data is ours, so the form rules (e.g. is_unique on the username being updated) are skipped,
            // and a failed write must stop setup rather than leave an account half-prepared.
            $users->skipValidation(true);
            $existing = $users->findByUsername($username);
            if ($existing) {
                if (! $users->update((int) $existing['user_id'], $row)) {
                    throw new \RuntimeException("Could not update the {$username} account.");
                }
                $ids[$username] = (int) $existing['user_id'];
            } else {
                $ids[$username] = (int) $users->insert($row, true);
                if ($ids[$username] === 0) {
                    throw new \RuntimeException("Could not create the {$username} account.");
                }
            }
        }

        // The demo supplier must be able to sign in.
        db_connect()->table('suppliers')->where('supplier_id', self::SUPPLIER_ID)->update(['is_active' => 1]);

        return $ids;
    }

    /**
     * Every other login: switched off, renamed, given a team alias and an unusable password,
     * so no real person can sign in, be emailed, or show up by name.
     */
    private function anonymiseOthers($db, array $demoIds, array $mailTo): void
    {
        $others    = $db->table('users')->whereNotIn('user_id', array_values($demoIds))->orderBy('user_id')->get()->getResultArray();
        $companies = array_column($db->table('suppliers')->select('supplier_id, company_name')->get()->getResultArray(), 'company_name', 'supplier_id');
        $n         = ['Admin' => 0, 'Staff' => 0, 'Supplier' => 0];

        foreach ($others as $u) {
            $role = $u['role'];
            $n[$role]++;
            $id = (int) $u['user_id'];

            if ($role === 'Admin') {
                $name  = $n[$role] === 1 ? 'Former Owner' : 'Former Owner ' . $n[$role];
                $login = 'former_owner_' . $id;
                $tag   = 'formerowner' . $id;
            } elseif ($role === 'Staff') {
                $name  = 'Cashier ' . $n[$role];
                $login = 'cashier_' . $id;
                $tag   = 'staff' . $id;
            } else {
                $sid   = (string) ($u['supplier_id'] ?? '');
                $name  = $companies[$sid] ?? ('Supplier Contact ' . $n[$role]);
                $login = 'supplier_user_' . $id;
                // One alias per login, so two logins on the same supplier never share an address.
                $tag   = strtolower(str_replace('-', '', $sid !== '' ? $sid : 'sup')) . 'u' . $id;
            }

            $db->table('users')->where('user_id', $id)->update([
                'username'                 => $login,
                'full_name'                => $name,
                'email'                    => $u['email'] === null ? null : $this->alias($mailTo, $tag),
                'phone'                    => null,
                'password_hash'            => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'is_active'                => 0,
                'must_change_password'     => 0,
                'temp_password_kind'       => null,
                'temp_password_expires_at' => null,
                'reset_otp_hash'           => null,
                'reset_otp_expires'        => null,
            ]);
        }
    }

    /** Supplier contact emails go to the team; contact people and numbers are replaced. Company names stay. */
    private function anonymiseSuppliers($db, array $mailTo): void
    {
        $i = 0;
        foreach ($db->table('suppliers')->orderBy('supplier_id')->get()->getResultArray() as $s) {
            $i++;
            $set = ['contact_person' => $s['contact_person'] === null ? null : 'Supplier Contact ' . $i];
            if ($s['contact_email'] !== null) {
                $set['contact_email'] = $this->alias($mailTo, strtolower(str_replace('-', '', (string) $s['supplier_id'])));
            }
            if ($s['contact_number'] !== null) {
                $set['contact_number'] = '09000000000';
            }
            $db->table('suppliers')->where('supplier_id', $s['supplier_id'])->update($set);
        }
    }

    /** Old notifications and the security log quote real names and usernames; a fresh demo starts without them. */
    private function clearPersonalLogs($db): void
    {
        $db->table('notifications')->emptyTable();
        $db->table('security_log')->emptyTable();
    }
}
