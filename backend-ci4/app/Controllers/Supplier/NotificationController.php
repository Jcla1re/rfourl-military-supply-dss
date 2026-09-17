<?php

namespace App\Controllers\Supplier;

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
        $notifications = $this->notificationModel->forRole('Supplier', session()->get('user_id'));
        $unread        = count(array_filter($notifications, fn ($n) => empty($n['is_read'])));

        $data = [
            'title'         => 'Notifications',
            'subtitle'      => $unread . ' unread message' . ($unread === 1 ? '' : 's'),
            'active'        => 'notifications',
            'notifications' => $notifications,
        ];

        return view('supplier/notifications', $data);
    }

    public function markRead($notificationId)
    {
        $this->notificationModel->update((int) $notificationId, ['is_read' => 1]);
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
}
