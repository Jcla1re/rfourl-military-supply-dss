<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationModel extends Model
{
    protected $table            = 'notifications';
    protected $primaryKey       = 'notification_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'recipient_role',
        'recipient_id',
        'type',
        'title',
        'message',
        'is_read',
    ];

    protected $useTimestamps = false;
    protected $createdField  = 'created_at';

    protected $validationRules = [
        'recipient_role' => 'required|in_list[Admin,Staff,Supplier]',
        'type'           => 'required',
        'title'          => 'required',
    ];

    public function forRole(string $role, ?int $recipientId = null): array
    {
        $builder = $this->where('recipient_role', $role)->orderBy('created_at', 'DESC');

        if ($recipientId !== null) {
            $builder->groupStart()
                ->where('recipient_id', $recipientId)
                ->orWhere('recipient_id', null)
                ->groupEnd();
        }

        return $builder->findAll();
    }

    public function unreadCount(string $role, ?int $recipientId = null): int
    {
        $builder = $this->where('recipient_role', $role)->where('is_read', 0);

        if ($recipientId !== null) {
            $builder->groupStart()
                ->where('recipient_id', $recipientId)
                ->orWhere('recipient_id', null)
                ->groupEnd();
        }

        return $builder->countAllResults();
    }

    public function push(string $role, string $type, string $title, ?string $message = null, ?int $recipientId = null): void
    {
        $this->insert([
            'recipient_role' => $role,
            'recipient_id'   => $recipientId,
            'type'           => $type,
            'title'          => $title,
            'message'        => $message,
            'is_read'        => 0,
        ]);
    }
}
