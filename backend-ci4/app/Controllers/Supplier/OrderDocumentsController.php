<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Libraries\ProcurementDocs;
use App\Models\StockOrderModel;

/** A supplier's PDFs (Purchase Order, Sales Order, Delivery Receipt) for their own orders only. */
class OrderDocumentsController extends BaseController
{
    public function show($soId, $type)
    {
        $order = (new StockOrderModel())->find($soId);

        // Another supplier's order looks exactly like an order that does not exist.
        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/dashboard')->with('error', 'Order not found.');
        }

        return ProcurementDocs::respond($this->response, $order, (string) $type)
            ?? redirect()->to('/supplier/dashboard')->with('error', 'That document is not available for this order yet.');
    }
}
