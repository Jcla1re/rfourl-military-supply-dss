<?php

namespace App\Controllers\Staff;

use App\Libraries\Toast;
use App\Models\ReorderAlertModel;
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
        // The Owner dismissed this size's alert (decided not to reorder it for now): no notice,
        // e.g. from a page that was open before the dismissal.
        if ((new ReorderAlertModel())->dismissedItemIds([$product['item_id']]) !== []) {
            return $redirect->with('toast', Toast::info('No notice sent', 'The owner dismissed this alert, so no notice was sent.'));
        }

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
