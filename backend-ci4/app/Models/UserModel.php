<?php
// app/Models/UserModel.php

namespace App\Models;

use App\Exceptions\DemoLockedException;
use App\Libraries\DemoGuard;
use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'supplier_id',
        'username',
        'email',
        'phone',
        'password_hash',
        'role',
        'full_name',
        'is_active',
        'last_login',
        'reset_otp_hash',
        'reset_otp_expires',
        'must_change_password',
        'temp_password_kind',
        'temp_password_expires_at',
        'deleted_at',
        'is_demo',
    ];

    // Demo-mode backstop: see DemoGuard. Does nothing unless DEMO_MODE is true.
    protected $beforeInsert = ['demoRefuseNewAdmin'];
    protected $beforeUpdate = ['demoRefuseLockedChange'];
    protected $beforeDelete = ['demoRefuseLockedDelete'];

    protected $useTimestamps = false; // schema only has created_at, no updated_at
    protected $createdField  = 'created_at';

    protected $validationRules = [
        'user_id'   => 'permit_empty|integer',
        'username'  => 'required|is_unique[users.username,user_id,{user_id}]',
        'full_name' => 'required',
        'role'      => 'required|in_list[Admin,Staff,Supplier]',
    ];

    // Helper: find a user by username, used in login
    /** Demo mode: no new Admin/Owner accounts (staff and suppliers are fine). */
    protected function demoRefuseNewAdmin(array $data): array
    {
        if (demo_mode() && ! DemoGuard::bypassed() && ($data['data']['role'] ?? null) === 'Admin') {
            throw new DemoLockedException();
        }

        return $data;
    }

    /** Demo mode: a locked demo account's name, email, username, password, role and active flag can't change. */
    protected function demoRefuseLockedChange(array $data): array
    {
        if (! demo_mode() || DemoGuard::bypassed() || empty($data['id'])) {
            return $data;
        }

        if (array_intersect(array_keys($data['data'] ?? []), DemoGuard::PROTECTED_FIELDS) !== [] && $this->anyDemoAccount((array) $data['id'])) {
            throw new DemoLockedException();
        }

        return $data;
    }

    /** Demo mode: a demo account can't be deleted. */
    protected function demoRefuseLockedDelete(array $data): array
    {
        if (demo_mode() && ! DemoGuard::bypassed() && ! empty($data['id']) && $this->anyDemoAccount((array) $data['id'])) {
            throw new DemoLockedException();
        }

        return $data;
    }

    private function anyDemoAccount(array $ids): bool
    {
        return db_connect()->table('users')->whereIn('user_id', $ids)->where('is_demo', 1)->countAllResults() > 0;
    }

    public function findByUsername(string $username)
    {
        return $this->where('username', $username)->first();
    }

    /**
     * Staff accounts that have not been removed. Removal sets deleted_at
     * rather than deleting the row (see AddDeletedAtToUsers), so every list or
     * picker of staff must go through here; report joins must not.
     */
    public function activeStaff(): self
    {
        return $this->where('role', 'Staff')->where('deleted_at', null);
    }

    /**
     * Staff login is password-only, so a password must identify exactly one
     * person. Returns the non-removed Staff accounts whose password matches
     * (bcrypt hashes can't be compared to each other, only verified against a
     * plaintext). $activeOnly = false also checks deactivated accounts, which
     * could be reactivated later.
     *
     * @return list<array>
     */
    public function staffMatchingPassword(string $password, bool $activeOnly = true, ?int $exceptUserId = null): array
    {
        $builder = $this->activeStaff();
        if ($activeOnly) {
            $builder->where('is_active', 1);
        }

        $matches = [];
        foreach ($builder->findAll() as $staff) {
            if ($exceptUserId !== null && (int) $staff['user_id'] === $exceptUserId) {
                continue;
            }
            if (password_verify($password, $staff['password_hash'])) {
                $matches[] = $staff;
            }
        }

        return $matches;
    }

    /** True if another non-removed Staff account (active or not) already uses this password. */
    public function staffPasswordInUse(string $password, ?int $exceptUserId = null): bool
    {
        return $this->staffMatchingPassword($password, false, $exceptUserId) !== [];
    }
}
