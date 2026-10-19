<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * System-wide settings (app_settings key/value table). getValue() returns the
 * given default when a key has never been saved, or when the table doesn't
 * exist yet (migration not run), so a missing row never breaks a page.
 */
class AppSettingModel extends Model
{
    protected $table         = 'app_settings';
    protected $primaryKey    = 'setting_key';
    protected $returnType    = 'array';
    protected $useAutoIncrement = false;
    protected $allowedFields = ['setting_key', 'setting_value', 'updated_by', 'updated_at'];

    public function getValue(string $key, ?string $default = null): ?string
    {
        try {
            $row = $this->find($key);
        } catch (\Throwable $e) {
            return $default;
        }

        return $row['setting_value'] ?? $default;
    }

    public function setValue(string $key, ?string $value, ?int $userId = null): void
    {
        $this->db->table($this->table)->replace([
            'setting_key'   => $key,
            'setting_value' => $value,
            'updated_by'    => $userId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
