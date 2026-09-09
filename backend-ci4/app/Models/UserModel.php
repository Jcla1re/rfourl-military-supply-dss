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
        'password_hash',
        'role',
        'full_name',
        'is_active',
        'last_login',
    ];

    protected $useTimestamps = false; // schema only has created_at, no updated_at
    protected $createdField  = 'created_at';

    protected $validationRules = [
        'username'  => 'required|is_unique[users.username,user_id,{user_id}]',
        'full_name' => 'required',
        'role'      => 'required|in_list[Admin,Staff,Supplier]',
    ];

    // Helper: find a user by username, used in login
    public function findByUsername(string $username)
    {
        return $this->where('username', $username)->first();
    }
}