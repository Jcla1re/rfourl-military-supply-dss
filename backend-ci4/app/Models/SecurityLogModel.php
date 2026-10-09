<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Audit trail. Use SecurityLogModel::log('event_name', $user, 'detail').
 * Never records passwords, OTP codes or tokens, and never lets a logging
 * problem (e.g. table not migrated yet) break the action being logged.
 */
class SecurityLogModel extends Model
{
    protected $table         = 'security_log';
    protected $primaryKey    = 'log_id';
    protected $returnType    = 'array';
    protected $allowedFields = ['user_id', 'username', 'role', 'event', 'detail', 'ip_address', 'created_at'];

    /**
     * @param array|null $actor user row (or any array with user_id/username/role);
     *                          defaults to whoever is in the current session.
     */
    public static function log(string $event, ?array $actor = null, ?string $detail = null): void
    {
        try {
            $session = session();

            (new self())->insert([
                'user_id'    => $actor['user_id'] ?? $session->get('user_id'),
                'username'   => $actor['username'] ?? $session->get('username'),
                'role'       => $actor['role'] ?? $session->get('role'),
                'event'      => $event,
                'detail'     => $detail !== null ? mb_substr($detail, 0, 255) : null,
                'ip_address' => service('request')->getIPAddress(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'SecurityLog failed: ' . $e->getMessage());
        }
    }
}
