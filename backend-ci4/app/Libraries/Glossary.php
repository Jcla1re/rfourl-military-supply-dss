<?php

namespace App\Libraries;

/**
 * The plain-language names the screens use for DSS terms, so every page says
 * the same thing: "Reorder At" instead of ROP and "Order Qty" instead of
 * EOQ, each with an (i) button that opens a short explanation (help_tip()).
 *
 * Only visible text. Database columns, variables and functions keep their
 * ROP / EOQ names (manual_rop_warning, eoq_value, ...).
 */
class Glossary
{
    public const TERMS = [
        'reorder_at' => [
            'label' => 'Reorder At',
            'tip'   => "When stock falls to this number, it's time to order more.\n\nAlso called Reorder Point (ROP).",
        ],
        'order_qty' => [
            'label' => 'Order Qty',
            'tip'   => "How many units to order each time.\n\nAlso called Economic Order Quantity (EOQ).",
        ],
        'safety_stock' => [
            'label' => 'Safety Stock',
            'tip'   => "Extra units kept on hand for busy days, so you don't run out when sales are higher than usual.",
        ],
        'dss' => [
            'label' => 'DSS',
            'tip'   => "DSS means Decision Support System. It is the part of the app that works out when to reorder and how much, using your past sales.\n\nIt only gives suggestions. You decide what to order.",
        ],
        'availability' => [
            'label' => 'Availability target',
            'tip'   => 'How often you want an item to be in stock. 95% means in stock about 19 days out of 20.',
        ],
        'delivery_time' => [
            'label' => 'Delivery time',
            'tip'   => 'How many days a supplier usually takes to deliver after you place an order.',
        ],
        'abc' => [
            'label' => 'ABC classification',
            'tip'   => 'The system groups items using K-Means clustering. Class A items get the most safety stock so they rarely run out.',
        ],
    ];

    public static function label(string $term): string
    {
        return self::TERMS[$term]['label'];
    }

    /**
     * The (i) button for a term. A real button, so it can be reached and
     * opened with the keyboard as well as by click or tap.
     * $text replaces the standard explanation where a screen needs more detail.
     */
    public static function tip(string $term, ?string $text = null): string
    {
        $t = self::TERMS[$term];

        return help_tip($t['label'], $text ?? $t['tip']);
    }

    /**
     * Label followed by its (i) button, e.g. for a table header. The last word
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
