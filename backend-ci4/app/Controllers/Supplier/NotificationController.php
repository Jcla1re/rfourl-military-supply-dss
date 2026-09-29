<?php

namespace App\Controllers\Supplier;

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
        $all    = $this->notificationModel->forRole('Supplier', session()->get('user_id'));

        $allCount    = count($all);
        $unreadCount = count(array_filter($all, fn ($n) => empty($n['is_read'])));

        $notifications = $filter === 'unread'
            ? array_values(array_filter($all, fn ($n) => empty($n['is_read'])))
            : $all;

        $data = [
            'title'       => 'Notifications',
            'subtitle'    => $unreadCount . ' unread message' . ($unreadCount === 1 ? '' : 's'),
            'active'      => 'notifications',
            'groups'      => NotificationModel::groupByRecency($notifications),
            'filter'      => $filter,
            'allCount'    => $allCount,
            'unreadCount' => $unreadCount,
        ];

        return view('supplier/notifications', $data);
    }

    public function markRead(int $notificationId)
    {
        $this->notificationModel->update($notificationId, ['is_read' => 1]);
        return redirect()->to('/supplier/notifications');
    }

    public function markAllRead()
    {
        $this->notificationModel
            ->where('recipient_role', 'Supplier')
            ->groupStart()->where('recipient_id', session()->get('user_id'))->orWhere('recipient_id', null)->groupEnd()
            ->set(['is_read' => 1])
            ->update();

        return redirect()->to('/supplier/notifications');
    }

    /**
     * Cards with a link_url (e.g. "new order placed", "please follow up")
     * mark themselves read and forward straight to the relevant page in
     * one click, the same way order-status cards already work for the admin.
     */
    public function open(int $notificationId)
    {
        $notification = $this->notificationModel->find($notificationId);

        if (! $notification) {
            return redirect()->to('/supplier/notifications');
        }

        $this->notificationModel->update($notificationId, ['is_read' => 1]);

        return redirect()->to($notification['link_url'] ?: '/supplier/notifications');
    }
}
