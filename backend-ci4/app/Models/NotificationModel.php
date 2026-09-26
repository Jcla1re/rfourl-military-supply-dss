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
        'category',
        'title',
        'message',
        'link_url',
        'is_read',
        'status',
    ];

    public const CATEGORIES = ['order_status', 'staff_activity', 'access_request'];

    protected $useTimestamps = false;
    protected $createdField  = 'created_at';

    protected $validationRules = [
        'recipient_role' => 'required|in_list[Admin,Staff,Supplier]',
        'type'           => 'required',
        'title'          => 'required',
    ];

    public function forRole(string $role, ?int $recipientId = null, bool $onlyUnread = false): array
    {
        $builder = $this->where('recipient_role', $role)->orderBy('created_at', 'DESC');

        if ($recipientId !== null) {
            $builder->groupStart()
                ->where('recipient_id', $recipientId)
                ->orWhere('recipient_id', null)
                ->groupEnd();
        }

        if ($onlyUnread) {
            $builder->where('is_read', 0);
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

    public function push(
        string $role,
        string $type,
        string $title,
        ?string $message = null,
        ?int $recipientId = null,
        ?string $category = null,
        ?string $linkUrl = null
    ): void {
        $this->insert([
            'recipient_role' => $role,
            'recipient_id'   => $recipientId,
            'type'           => $type,
            'category'       => $category,
            'title'          => $title,
            'message'        => $message,
            'link_url'       => $linkUrl,
            'is_read'        => 0,
        ]);
    }

    /**
     * Resolves an actionable notification (approve/decline a staff access
     * request) — marks it read at the same time so it drops out of the
     * pending inbox like any other handled notification.
     */
    public function resolve(int $notificationId, string $status): void
    {
        $this->update($notificationId, ['status' => $status, 'is_read' => 1]);
    }
}
