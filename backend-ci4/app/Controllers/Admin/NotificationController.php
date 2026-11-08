<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DemoGuard;
use App\Libraries\StaffPasswordAlert;
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

    public function clearAll()
    {
        $this->notificationModel->clearForRecipient('Admin');
        return redirect()->to('/admin/notifications')->with('toast', Toast::success('Notifications cleared', 'All notifications were removed.'));
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
     * "Accept" on the alert that follows a Forgot-password reset: the owner says it was really the staff
     * member. Closes the alert (so Lock no longer works) and leaves a note.
     */
    public function accept(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);
        $staff        = StaffPasswordAlert::acceptableStaff($notification);

        if (! $staff) {
            return redirect()->to('/admin/notifications')->with('toast', Toast::error("Couldn't confirm", 'It was already confirmed or locked, or the account no longer exists.'));
        }

        StaffPasswordAlert::accept($notification, $staff, 'password_reset_accepted_via_notification', (string) session()->get('username'));

        return redirect()->to('/admin/notifications')->with('toast', Toast::success('Reset confirmed', "You confirmed {$staff['full_name']}'s password reset."));
    }

    /** "Lock account" on a staff password-change alert, from the logged-in owner. */
    public function lock(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);
        $staff        = StaffPasswordAlert::lockableStaff($notification);

        if (! $staff) {
            return redirect()->to('/admin/notifications')->with('toast', Toast::error("Couldn't lock the account", 'It was already locked, or no longer exists.'));
        }

        if (DemoGuard::locksUser($staff)) {
            return DemoGuard::refuse('/admin/notifications');
        }

        StaffPasswordAlert::lock($notification, $staff, 'account_locked_via_notification', (string) session()->get('username'));

        return redirect()->to('/admin/notifications')->with('toast', Toast::success('Account locked', "{$staff['full_name']}'s account is locked. Reset their password from Manage Accounts to unlock it."));
    }
}
