<?php

namespace App\Controllers;

use App\Models\NotificationModel;

/**
 * Lets the Owner/Admin approve or decline a staff access-request straight
 * from the one-click email link, without logging into the admin portal.
 *
 * Deliberately NOT session/role-gated — validated instead by a random
 * per-notification token (see AuthController::notifyStaffForgot()), the
 * same "magic link" pattern as an email confirmation link. The GET step
 * only ever shows a confirmation page; the actual approve/decline happens
 * on the POST from that page's button. This split matters: email security
 * scanners (Gmail, Outlook Safe Links, corporate proxies) pre-fetch every
 * link in an email to scan it, so a GET that mutated data would risk being
 * silently triggered by a scanner bot before a human ever saw it.
 */
class NotificationActionController extends BaseController
{
    private const ACTIONS = ['approve', 'decline'];

    public function confirm($notificationId, string $token, string $action)
    {
        $notification = $this->validRequest($notificationId, $token, $action);

        if (! $notification) {
            return view('notification_action/problem', [
                'message' => 'This link is invalid or has expired.',
            ]);
        }

        if (! empty($notification['status'])) {
            return view('notification_action/problem', [
                'message' => 'This request has already been ' . $notification['status'] . '.',
            ]);
        }

        return view('notification_action/confirm', [
            'notification' => $notification,
            'action'       => $action,
        ]);
    }

    public function submit($notificationId, string $token, string $action)
    {
        $notification = $this->validRequest($notificationId, $token, $action);

        if (! $notification || ! empty($notification['status'])) {
            return view('notification_action/problem', [
                'message' => 'This link is invalid, expired, or was already used.',
            ]);
        }

        $model = new NotificationModel();

        if ($action === 'approve') {
            $model->approveAccessRequest((int) $notificationId);
        } else {
            $model->declineAccessRequest((int) $notificationId);
        }

        return view('notification_action/done', ['action' => $action]);
    }

    private function validRequest($notificationId, string $token, string $action): ?array
    {
        if (! is_numeric($notificationId) || ! in_array($action, self::ACTIONS, true)) {
            return null;
        }

        $notification = (new NotificationModel())->find((int) $notificationId);

        if (! $notification || empty($notification['action_token']) || ! hash_equals($notification['action_token'], $token)) {
            return null;
        }

        return $notification;
    }
}
