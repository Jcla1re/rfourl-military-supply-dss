<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\StaffPasswordAlert;
use App\Libraries\StaffPasswordReset;
use App\Libraries\Toast;
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
            'title'       => 'Notifications',
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
     * Approving a staff password-reset request emails the matched staff member a
     * temporary password (the owner never sees it). The owner must tick that they
     * checked with the staff member first.
     */
    public function approve(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);

        if (! $notification || ! empty($notification['status'])) {
            return redirect()->to('/admin/notifications');
        }
        if (! $this->request->getPost('confirmed')) {
            return redirect()->to('/admin/notifications')->with('toast', Toast::error('Not approved', 'Tick "I confirmed with them" first.'));
        }

        $result = (new StaffPasswordReset())->approveRequest($notification, (string) session()->get('username'));

        if (! $result['ok']) {
            return redirect()->to('/admin/notifications')->with('toast', Toast::error("Couldn't approve", $result['message']));
        }

        return redirect()->to('/admin/notifications')->with('toast', Toast::success(
            'Reset approved',
            'A temporary password was emailed to ' . StaffPasswordReset::maskEmail((string) $result['staff']['email']) . '.'
        ));
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

    /** "Lock account" on a staff password-change alert, from the logged-in owner. */
    public function lock(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);
        $staff        = StaffPasswordAlert::lockableStaff($notification);

        if (! $staff) {
            return redirect()->to('/admin/notifications')->with('toast', Toast::error("Couldn't lock the account", 'It was already locked, or no longer exists.'));
        }

        StaffPasswordAlert::lock($notification, $staff, 'account_locked_via_notification', (string) session()->get('username'));

        return redirect()->to('/admin/notifications')->with('toast', Toast::success('Account locked', "{$staff['full_name']}'s account is locked. Reset their password from Manage Accounts to unlock it."));
    }
}
