<?php

namespace App\Libraries;

/**
 * The icon and color of a notification card, the same on the Owner, Staff and Supplier
 * Notifications pages (styles in public/assets/css/notifications.css):
 *
 *   damaged / lost (by type)  bi-exclamation         red
 *   access_request            bi-exclamation         amber
 *   security                  bi-shield-exclamation  amber
 *   order_status              bi-clipboard-check     green
 *   sales_records             bi-archive             green
 *   anything else             bi-bell                green
 */
class NotificationIcon
{
    /**
     * @param array $n a notifications row ('type', 'category')
     *
     * @return array{icon: string, color: string}
     */
    public static function for(array $n): array
    {
        $type     = strtolower((string) ($n['type'] ?? ''));
        $category = $n['category'] ?? null;

        if (str_contains($type, 'damaged') || str_contains($type, 'lost')) {
            return ['icon' => 'bi-exclamation', 'color' => 'red'];
        }

        return match ($category) {
            'access_request' => ['icon' => 'bi-exclamation', 'color' => 'amber'],
            'security'       => ['icon' => 'bi-shield-exclamation', 'color' => 'amber'],
            'order_status'   => ['icon' => 'bi-clipboard-check', 'color' => 'green'],
            'sales_records'  => ['icon' => 'bi-archive', 'color' => 'green'],
            default          => ['icon' => 'bi-bell', 'color' => 'green'],
        };
    }
}
