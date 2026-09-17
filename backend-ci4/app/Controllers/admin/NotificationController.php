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

    public function index()
    {
        $filter = $this->request->getGet('filter') ?: 'all';
        $notifications = $this->notificationModel->forRole('Admin');

        if ($filter !== 'all') {
            $notifications = array_values(array_filter($notifications, function ($n) use ($filter) {
                $isOrder = str_contains(strtolower($n['type']), 'order');
                return $filter === 'orders' ? $isOrder : ! $isOrder;
            }));
        }

        $orderCount = count(array_filter($this->notificationModel->forRole('Admin'), fn ($n) => str_contains(strtolower($n['type']), 'order')));
        $staffCount = count($this->notificationModel->forRole('Admin')) - $orderCount;

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
            'allCount'   => count($this->notificationModel->forRole('Admin')),
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
}
