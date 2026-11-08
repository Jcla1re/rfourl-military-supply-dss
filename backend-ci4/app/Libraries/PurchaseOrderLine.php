<?php

namespace App\Libraries;

use App\Models\ProductModel;

/**
 * One size's data for the shared "New purchase order" dialog
 * (app/Views/partials/_purchase_order_dialog.php, assets/js/purchase-order-dialog.js),
 * built the same way for Inventory's Order button and Reorder Alerts' Order buttons.
 *
 * Reads a products row plus, when present, the latest DSS numbers on it:
 * 'eoq_value' or 'recommended_eoq', 'safety_stock' (or 'computation'), 'lead_time_days'.
 */
class PurchaseOrderLine
{
    /**
     * @param array<string, array> $suppliers supplier_id => suppliers row (all, archived too)
     */
    public static function build(array $p, array $suppliers): array
    {
        static $productModel = null;
        $productModel ??= new ProductModel();

        $computation = is_array($p['computation'] ?? null) ? $p['computation'] : [];
        $supplier    = $suppliers[$p['supplier_id'] ?? ''] ?? null;
        // Inventory's status label (Reorder Alerts rows carry the alert's status in 'status').
        $status = is_array($p['status'] ?? null) ? $p['status']['label'] : $productModel->getStatus($p)['label'];
        $eoq    = $p['recommended_eoq'] ?? $p['eoq_value'] ?? $computation['eoq_value'] ?? null;
        $safety = $p['safety_stock'] ?? $computation['safety_stock'] ?? null;
        $lead   = $p['lead_time_days'] ?? $computation['lead_time_days'] ?? $supplier['lead_time_days'] ?? null;
        $size   = trim((string) ($p['size'] ?? ''));

        return [
            'item_id'      => (string) $p['item_id'],
            'name'         => ProductModel::nameWithSize($p),
            'size'         => $size,
            'category'     => (string) ($p['category'] ?? ''),
            'status'       => $status,
            'status_class' => ProductModel::statusColor($status),
            'on_hand'      => (int) ($p['current_stock'] ?? 0),
            'rop'          => (int) ($p['manual_rop_warning'] ?? 0),
            'safety'       => $safety !== null ? (int) $safety : null,
            'eoq'          => $eoq ? (int) $eoq : null,
            'cost'         => round((float) ($p['unit_cost'] ?? 0), 2),
            'supplier_id'  => $supplier ? (string) $supplier['supplier_id'] : '',
            'supplier'     => $supplier['company_name'] ?? '',
            'lead'         => $lead ? (int) $lead : null,
        ];
    }
}
