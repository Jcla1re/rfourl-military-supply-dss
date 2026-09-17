<?php
// app/Controllers/Staff/NotificationController.php

namespace App\Controllers\Staff;

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
        $notifications = $this->notificationModel->forRole('Staff', session()->get('user_id'));

        $data = [
            'title'         => 'Notifications',
            'active'        => 'notifications',
            'notifications' => $notifications,
        ];

        return view('staff/notifications', $data);
    }

    public function markRead($notificationId)
    {
        $this->notificationModel->update((int) $notificationId, ['is_read' => 1]);
        return redirect()->to('/staff/notifications');
    }

    public function markAllRead()
    {
        $this->notificationModel->where('recipient_role', 'Staff')->set(['is_read' => 1])->update();
        return redirect()->to('/staff/notifications');
    }
}
