<?php

namespace App\Libraries;

/**
 * Builds the flash payload for the shared toast component
 * (app/Views/partials/_toast.php, assets/js/toast-timer.js).
 *
 *   return redirect()->to($url)->with('toast', Toast::success(
 *       'Item archived', 'moved to Archived items.', $item['item_name'],
 *       ['undo' => site_url('admin/inventory/restore/' . $id)]
 *   ));
 *
 * Renders as: [icon] Title / "<name>" message   [Undo] [×]
 * Success and info toasts hide themselves after a few seconds; error
 * toasts stay until dismissed. Extra options: 'key' (a later toast with the
 * same key replaces this one in place), 'sticky' (no auto-hide) and 'busy'
 * (in progress: spinner instead of the icon, no auto-hide).
 */
class Toast
{
    /**
     * @param string      $title   Specific action title, e.g. "Changes saved"
     * @param string|null $message Text after the name, e.g. "was updated."
     * @param string|null $name    Shown first, in bold quotes (item name, order no.)
     * @param array       $extra   Optional: 'undo' => URL to POST to for Undo
     */
    public static function success(string $title, ?string $message = null, ?string $name = null, array $extra = []): array
    {
        return ['type' => 'success', 'title' => $title, 'message' => $message, 'name' => $name] + $extra;
    }

    /**
     * Neutral notice, not an error (Deep Olive icon), e.g. "A recalculation
     * is already running".
     *
     * @param array $extra Optional: 'key', 'sticky', 'busy'
     */
    public static function info(string $title, ?string $message = null, array $extra = []): array
    {
        return ['type' => 'info', 'title' => $title, 'message' => $message, 'name' => null] + $extra;
    }

    /**
     * @param string      $title  What failed, e.g. "Couldn't save changes"
     * @param string|null $reason Why, shown on the message line
     */
    public static function error(string $title, ?string $reason = null, ?string $name = null): array
    {
        return ['type' => 'error', 'title' => $title, 'message' => $reason, 'name' => $name];
    }
}
