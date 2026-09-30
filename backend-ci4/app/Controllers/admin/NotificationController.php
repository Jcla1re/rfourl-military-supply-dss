<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\NotificationModel;

class NotificationController extends BaseController
{
    protected NotificationModel $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new NotificationModel();
    }

    /**
     * "All" shows the full history (up to the 14-day retention window);
     * "Unread" narrows to pending ones. Either way, results are bucketed
     * into Today/Yesterday/Earlier for scanning at a glance.
     */
    public function index()
    {
        $this->notificationModel->pruneExpired();

        $filter = $this->request->getGet('filter') === 'unread' ? 'unread' : 'all';
        $all    = $this->notificationModel->forRole('Admin', null, false);

        // Legacy rows created before the `category` column existed fall
        // back to the old substring heuristic so they still render sanely.
        foreach ($all as &$n) {
            $n['category'] = $n['category'] ?? (str_contains(strtolower($n['type']), 'order') ? 'order_status' : 'staff_activity');
        }
        unset($n);

        $allCount    = count($all);
        $unreadCount = count(array_filter($all, fn ($n) => empty($n['is_read'])));

        $notifications = $filter === 'unread'
            ? array_values(array_filter($all, fn ($n) => empty($n['is_read'])))
            : $all;

        $data = [
            'title'       => 'Notification',
            'active'      => 'notifications',
            'groups'      => NotificationModel::groupByRecency($notifications),
            'filter'      => $filter,
            'allCount'    => $allCount,
            'unreadCount' => $unreadCount,
        ];

        return view('admin/notifications', $data);
    }

    public function markRead(int $notificationId)
    {
        $this->notificationModel->update($notificationId, ['is_read' => 1]);
        return redirect()->to('/admin/notifications');
    }

    public function markAllRead()
    {
        $this->notificationModel->where('recipient_role', 'Admin')->set(['is_read' => 1])->update();
        return redirect()->to('/admin/notifications');
    }

    /**
     * Order-status cards navigate straight to the order they're about —
     * this marks the card done and forwards to it in one step.
     */
    public function open(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);

        if (! $notification) {
            return redirect()->to('/admin/notifications');
        }

        $this->notificationModel->update($notificationId, ['is_read' => 1]);

        return redirect()->to($notification['link_url'] ?: '/admin/notifications');
    }

    /**
     * Approving a staff password-reset request doesn't set a password here
     * — it hands off to the existing "Change Staff Password" form under
     * Settings > Security so the admin picks the new password themselves,
     * the same way any other staff password change happens.
     */
    public function approve(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);

        if (! $notification) {
            return redirect()->to('/admin/notifications');
        }

        $this->notificationModel->approveAccessRequest($notificationId);

        return redirect()->to('/admin/settings?tab=security')
            ->with('success', 'Request approved. Set a new password for the staff account below.');
    }

    public function decline(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);

        if (! $notification) {
            return redirect()->to('/admin/notifications');
        }

        $this->notificationModel->declineAccessRequest($notificationId);

        return redirect()->to('/admin/notifications')->with('success', 'Request declined.');
    }
}
