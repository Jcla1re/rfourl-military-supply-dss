<?php

namespace App\Libraries;

/**
 * The category icons of the Point of Sale (Owner and Staff, partials/_pos_tab.php): the same
 * line icon on a category chip and on that category's item cards. Inline SVG, 24×24 viewBox,
 * stroked with currentColor (colors in assets/css/pos.css).
 */
class PosIcon
{
    /** ProductModel::CATEGORIES => SVG path data. */
    public const PATHS = [
        'Clothes'     => 'M8 3 3 6l2 4 2-1v12h10V9l2 1 2-4-5-3c-.5 1.7-2 2.8-4 2.8S8.5 4.7 8 3z',
        'Shoes'       => 'M5 3h5v7l6 2.5c2.5 1 4 2.6 4 5V19H3v-5.5c0-1.3.6-2.2 2-2.5zM3 16h17M8 6h2M8 8.5h2',
        'Equipment'   => 'M9 6V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V6M6 21V10a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v11zM9 14h6v4H9zM9 11h6',
        'Patches'     => 'M12 3 5 6v5.5c0 4.5 3 7.5 7 9.5 4-2 7-5 7-9.5V6zM12 6.5 8 8.2v3.3c0 2.8 1.6 4.8 4 6.1 2.4-1.3 4-3.3 4-6.1V8.2z',
        'Metal'       => 'M8 2l4 6 4-6M12 22a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM12 13l.9 1.9 2.1.3-1.5 1.5.4 2.1-1.9-1-1.9 1 .4-2.1-1.5-1.5 2.1-.3z',
        'Accessories' => 'M3 16c0-5.5 4-9.5 9-9.5s8 3.5 8.5 8.5M3 16h11l7 1.5M12 6.5V4',
    ];

    /** The category's icon as inline SVG ('' for an unknown category). */
    public static function svg(?string $category, int $size = 24): string
    {
        $d = self::PATHS[$category ?? ''] ?? null;
        if ($d === null) {
            return '';
        }

        return '<svg class="pos-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $d . '"/></svg>';
    }
}
