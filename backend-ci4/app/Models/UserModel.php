<?php
// app/Models/UserModel.php

namespace App\Models;

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
        'deleted_at',
    ];

    protected $useTimestamps = false; // schema only has created_at, no updated_at
    protected $createdField  = 'created_at';

    protected $validationRules = [
        'user_id'   => 'permit_empty|integer',
        'username'  => 'required|is_unique[users.username,user_id,{user_id}]',
        'full_name' => 'required',
        'role'      => 'required|in_list[Admin,Staff,Supplier]',
    ];

    // Helper: find a user by username, used in login
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
}
