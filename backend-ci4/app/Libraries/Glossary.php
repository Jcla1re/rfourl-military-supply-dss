<?php

namespace App\Libraries;

/**
 * The plain-language names the screens use for DSS terms, so every page says
 * the same thing: "Reorder At" instead of ROP and "Order Qty" instead of
 * EOQ, each with an info icon explaining the formal term.
 *
 * Only visible text. Database columns, variables and functions keep their
 * ROP / EOQ names (manual_rop_warning, eoq_value, ...).
 */
class Glossary
{
    public const TERMS = [
        'reorder_at' => [
            'label' => 'Reorder At',
            'tip'   => "Reorder Point (ROP): when on hand reaches this number, it's time to order.",
        ],
        'order_qty' => [
            'label' => 'Order Qty',
            'tip'   => 'Economic Order Quantity (EOQ): the best number of units to order at a time.',
        ],
    ];

    public static function label(string $term): string
    {
        return self::TERMS[$term]['label'];
    }

    /**
     * The small info icon with the term's tooltip (assets/js/info-tip.js).
     * A real button, so it can be reached and opened with the keyboard.
     */
    public static function tip(string $term): string
    {
        $t = self::TERMS[$term];

        return '<button type="button" class="info-tip" data-tip="' . esc($t['tip'], 'attr') . '" aria-label="' . esc('About ' . $t['label'], 'attr') . '">'
            . '<i class="bi bi-info-circle" aria-hidden="true"></i></button>';
    }

    /**
     * Label followed by its info icon, e.g. for a table header. The last word
     * and the icon never separate, so a narrow header breaks as
     * "Reorder / At (i)" rather than leaving the icon on a line of its own.
     */
    public static function labelWithTip(string $term): string
    {
        $words = explode(' ', self::label($term));
        $last  = array_pop($words);

        return ($words ? esc(implode(' ', $words)) . ' ' : '')
            . '<span class="term-tail">' . esc($last) . self::tip($term) . '</span>';
    }
}
