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
        'action_token',
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

    /**
     * @return int|string|false The new notification's ID (needed by callers
     *                          that build an action link from $actionToken).
     */
    public function push(
        string $role,
        string $type,
        string $title,
        ?string $message = null,
        ?int $recipientId = null,
        ?string $category = null,
        ?string $linkUrl = null,
        ?string $actionToken = null
    ) {
        return $this->insert([
            'recipient_role' => $role,
            'recipient_id'   => $recipientId,
            'type'           => $type,
            'category'       => $category,
            'title'          => $title,
            'message'        => $message,
            'link_url'       => $linkUrl,
            'is_read'        => 0,
            'action_token'   => $actionToken,
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

    /**
     * Shared by the admin portal's Approve button and the one-click email
     * action link, so both paths have exactly one definition of what
     * "approving" a staff access request does.
     */
    public function approveAccessRequest(int $notificationId): void
    {
        $this->resolve($notificationId, 'approved');
        $this->push(
            'Staff',
            'Access Request',
            'Your password reset request was approved',
            'The admin will give you your new password directly.',
            null,
            'staff_activity'
        );
    }

    public function declineAccessRequest(int $notificationId): void
    {
        $this->resolve($notificationId, 'declined');
        $this->push(
            'Staff',
            'Access Request',
            'Your password reset request was declined',
            'Please contact the admin directly if you still need access.',
            null,
            'staff_activity'
        );
    }

    /**
     * Notifications older than $days are permanently deleted. Called from
     * each role's notifications index() so the inbox self-cleans without
     * needing a cron/scheduled task, which this app doesn't have.
     */
    public function pruneExpired(int $days = 14): void
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $this->where('created_at <', $cutoff)->delete();
    }

    /**
     * Buckets a list of notifications (already sorted newest-first) into
     * Today / Yesterday / Earlier, dropping empty buckets so the view only
     * ever renders the date-group headers that actually have content.
     */
    public static function groupByRecency(array $notifications): array
    {
        $groups    = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        foreach ($notifications as $n) {
            $date = date('Y-m-d', strtotime($n['created_at']));

            if ($date === $today) {
                $groups['Today'][] = $n;
            } elseif ($date === $yesterday) {
                $groups['Yesterday'][] = $n;
            } else {
                $groups['Earlier'][] = $n;
            }
        }

        return array_filter($groups, fn ($g) => ! empty($g));
    }
}
