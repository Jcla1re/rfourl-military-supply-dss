<?php

namespace App\Controllers\Staff;

use App\Libraries\Toast;
use App\Models\ReorderNoticeModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Shared by the three staff "Notify Owner" buttons (Inventory, Dashboard,
 * Reorder Alerts), so they behave the same: one notice per size
 * (ReorderNoticeModel::notifyOwner), and an honest message, never
 * "Owner notified", when reorder notifications are turned off.
 */
trait NotifiesOwner
{
    /** @param array $product products row (one size) */
    protected function notifyOwnerAbout(array $product, RedirectResponse $redirect): RedirectResponse
    {
        $outcome = (new ReorderNoticeModel())->notifyOwner(
            $product,
            (int) session()->get('user_id') ?: null,
            session()->get('full_name')
        );

        if ($outcome['result'] === ReorderNoticeModel::OFF) {
            return $redirect->with('toast', Toast::info('Owner not notified', $outcome['message'], ['sticky' => true]));
        }

        return $redirect->with('success', $outcome['message']);
    }
}
