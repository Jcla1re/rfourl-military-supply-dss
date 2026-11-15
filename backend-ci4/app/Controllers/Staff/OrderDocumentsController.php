<?php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\ProcurementDocs;
use App\Models\StockOrderModel;

/** The PDFs staff check a delivery against on Restock: orders that are on the way or already received. */
class OrderDocumentsController extends BaseController
{
    public function show($soId, $type)
    {
        $order = (new StockOrderModel())->find($soId);

        if (! $order || ! in_array($order['status'], ['Shipped', 'Delivered'], true)) {
            return redirect()->to('/staff/log-transaction/restock')->with('error', 'Order not found or not ready to confirm.');
        }

        return ProcurementDocs::respond($this->response, $order, (string) $type)
            ?? redirect()->to('/staff/log-transaction/restock')->with('error', 'That document is not available for this order.');
    }
}
