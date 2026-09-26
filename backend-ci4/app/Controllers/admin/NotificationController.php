<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\NotificationModel;

class NotificationController extends BaseController
{
    protected $notificationModel;

    public function __construct()
    {
        $this->notificationModel = new NotificationModel();
    }

    /**
     * Only ever lists pending (unread) notifications — once a card is acted
     * on (opened, marked done, approved/declined) it drops out of this
     * inbox instead of lingering with a stale "read" state.
     */
    public function index()
    {
        $filter = $this->request->getGet('filter') ?: 'all';
        $notifications = $this->notificationModel->forRole('Admin', null, true);

        // Legacy rows created before the `category` column existed fall
        // back to the old substring heuristic so they still group sanely.
        foreach ($notifications as &$n) {
            $n['category'] = $n['category'] ?? (str_contains(strtolower($n['type']), 'order') ? 'order_status' : 'staff_activity');
        }
        unset($n);

        $orderCount = count(array_filter($notifications, fn ($n) => $n['category'] === 'order_status'));
        $staffCount = count($notifications) - $orderCount;

        if ($filter !== 'all') {
            $notifications = array_values(array_filter($notifications, fn ($n) => $filter === 'orders' ? $n['category'] === 'order_status' : $n['category'] !== 'order_status'));
        }

        $groups = [];
        foreach ($notifications as $n) {
            $date = date('Y-m-d', strtotime($n['created_at']));
            $label = $date === date('Y-m-d') ? 'Today' : ($date === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('F j, Y', strtotime($date)));
            $groups[$label][] = $n;
        }

        $data = [
            'title'      => 'Notification',
            'active'     => 'notifications',
            'groups'     => $groups,
            'filter'     => $filter,
            'allCount'   => $orderCount + $staffCount,
            'orderCount' => $orderCount,
            'staffCount' => $staffCount,
        ];

        return view('admin/notifications', $data);
    }

    public function markRead($notificationId)
    {
        $this->notificationModel->update((int) $notificationId, ['is_read' => 1]);
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
    public function open($notificationId)
    {
        $notification = $this->notificationModel->find((int) $notificationId);

        if (! $notification) {
            return redirect()->to('/admin/notifications');
        }

        $this->notificationModel->update((int) $notificationId, ['is_read' => 1]);

        return redirect()->to($notification['link_url'] ?: '/admin/notifications');
    }

    /**
     * Approving a staff password-reset request doesn't set a password here
     * — it hands off to the existing "Change Staff Password" form under
     * Settings > Security so the admin picks the new password themselves,
     * the same way any other staff password change happens.
     */
    public function approve($notificationId)
    {
        $notification = $this->notificationModel->find((int) $notificationId);

        if (! $notification) {
            return redirect()->to('/admin/notifications');
        }

        $this->notificationModel->resolve((int) $notificationId, 'approved');
        $this->notificationModel->push('Staff', 'Access Request', 'Your password reset request was approved', 'The admin will give you your new password directly.', null, 'staff_activity');

        return redirect()->to('/admin/settings?tab=security')
            ->with('success', 'Request approved. Set a new password for the staff account below.');
    }

    public function decline($notificationId)
    {
        $notification = $this->notificationModel->find((int) $notificationId);

        if (! $notification) {
            return redirect()->to('/admin/notifications');
        }

        $this->notificationModel->resolve((int) $notificationId, 'declined');
        $this->notificationModel->push('Staff', 'Access Request', 'Your password reset request was declined', 'Please contact the admin directly if you still need access.', null, 'staff_activity');

        return redirect()->to('/admin/notifications')->with('success', 'Request declined.');
    }
}
