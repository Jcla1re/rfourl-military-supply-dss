<?php

namespace App\Controllers;

use App\Libraries\DemoGuard;
use App\Libraries\StaffPasswordAlert;
use App\Models\NotificationModel;

/**
 * Lets the Owner/Admin Accept or Lock a staff member after a Forgot-password reset (or just Lock after
 * a changed email) straight from the email link, without logging into the admin portal.
 *
 * Deliberately NOT session/role-gated: validated instead by a random per-notification token (only its
 * hash is stored), the same "magic link" pattern as an email confirmation link. The GET step only ever
 * shows a confirmation page; the actual Accept/Lock happens on the POST from that page's button. This
 * split matters: email security scanners (Gmail, Outlook Safe Links, corporate proxies) pre-fetch
 * every link in an email to scan it, so a GET that mutated data would risk being silently triggered by
 * a scanner bot before a human ever saw it.
 *
 * Only one of Accept / Lock can be used per alert. The old Approve / Decline links (the owner no longer
 * approves resets) only explain that staff now reset their password themselves.
 */
class NotificationActionController extends BaseController
{
    private const RETIRED = ['approve', 'decline'];

    public const RETIRED_MESSAGE = 'This link is no longer used. Staff now reset their password themselves.';

    public function confirm($notificationId, string $token, string $action)
    {
        if (in_array($action, self::RETIRED, true)) {
            return $this->retired();
        }

        $notification = $this->notification($notificationId);

        if (! in_array($action, ['lock', 'accept'], true) || ! StaffPasswordAlert::validToken($notification, $token)) {
            return $this->noLongerValid();
        }

        if ($action === 'accept' ? StaffPasswordAlert::acceptableStaff($notification) === null : StaffPasswordAlert::lockableStaff($notification) === null) {
            return $this->alreadyHandled($notification);
        }

        return view('notification_action/confirm', ['notification' => $notification, 'action' => $action]);
    }

    public function submit($notificationId, string $token, string $action)
    {
        if (in_array($action, self::RETIRED, true)) {
            return $this->retired();
        }

        $notification = $this->notification($notificationId);

        if (! in_array($action, ['lock', 'accept'], true) || ! StaffPasswordAlert::validToken($notification, $token)) {
            return $this->noLongerValid();
        }

        if ($action === 'accept') {
            $staff = StaffPasswordAlert::acceptableStaff($notification);
            if ($staff === null) {
                return $this->alreadyHandled($notification);
            }

            StaffPasswordAlert::accept($notification, $staff, 'password_reset_accepted_via_email', 'owner (email link)');

            return view('notification_action/done', ['action' => 'accept', 'staffName' => $staff['full_name']]);
        }

        $staff = StaffPasswordAlert::lockableStaff($notification);
        if ($staff === null) {
            return $this->alreadyHandled($notification);
        }
        if (DemoGuard::locksUser($staff)) {
            return view('notification_action/problem', ['message' => DemoGuard::MESSAGE]);
        }

        StaffPasswordAlert::lock($notification, $staff, 'account_locked_via_email', 'owner (email link)');

        return view('notification_action/done', ['action' => 'lock', 'staffName' => $staff['full_name']]);
    }

    private function notification($notificationId): ?array
    {
        return is_numeric($notificationId) ? (new NotificationModel())->find((int) $notificationId) : null;
    }

    private function retired()
    {
        return view('notification_action/problem', ['heading' => 'Link no longer used', 'message' => self::RETIRED_MESSAGE]);
    }

    /** The other button was already used (the alert is closed), or the account no longer exists. */
    private function alreadyHandled(array $notification)
    {
        $message = match ($notification['status'] ?? '') {
            StaffPasswordAlert::STATUS_CONFIRMED => 'Already confirmed. This request can no longer be changed from this link.',
            StaffPasswordAlert::STATUS_LOCKED    => 'This account was already locked. Reset their password in Settings > Manage Accounts to unlock it.',
            default                              => 'This link is no longer valid.',
        };

        return view('notification_action/problem', ['heading' => 'Already handled', 'message' => $message]);
    }

    private function noLongerValid()
    {
        return view('notification_action/problem', ['message' => 'This link is no longer valid.']);
    }
}
