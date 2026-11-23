<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\EmailNotifier;
use App\Libraries\OnceToken;
use App\Libraries\SupplierPo;
use App\Libraries\Toast;
use App\Models\DssParameterModel;
use App\Models\NotificationModel;
use App\Models\StockOrderModel;
use App\Models\UserModel;

/**
 * "Create PO for low items": the review of everything one supplier's items are low on, and the one purchase
 * order created from it (App\Libraries\SupplierPo does the checking and the saving). Owner only (the admin
 * route group); CSRF is the global filter; the form carries a one-time key against a double submit.
 */
class SupplierPoController extends BaseController
{
    private const BACK = '/admin/procurement-report?tab=suppliers';

    /** GET admin/procurement-report/po/{supplier}: the review. */
    public function review(string $supplierId)
    {
        $supplier = SupplierPo::supplier($supplierId, $why);
        if ($supplier === null) {
            return redirect()->to(self::BACK)->with('toast', Toast::error("Can't create a purchase order", SupplierPo::supplierMessage((string) $why)));
        }

        return $this->render($supplier, [], null);
    }

    /** POST admin/procurement-report/po/{supplier}: creates the one order, or shows the review again saying what changed. */
    public function store(string $supplierId)
    {
        $supplier = SupplierPo::supplier($supplierId, $why);
        if ($supplier === null) {
            return redirect()->to(self::BACK)->with('toast', Toast::error("Couldn't create order", SupplierPo::supplierMessage((string) $why)));
        }

        $detailUrl = self::BACK . '&supplier=' . rawurlencode($supplierId);

        // The same form again (double click, resubmit): no second order.
        $onceKey = OnceToken::key($this->request);
        if ($earlier = OnceToken::used($onceKey)) {
            return redirect()->to($detailUrl)->with('toast', Toast::info('Already saved.', ! empty($earlier['result_id']) ? 'Order ' . StockOrderModel::labelFor((string) $earlier['result_id']) . ' was already created.' : null));
        }

        $pick     = $this->request->getPost('pick');
        $qty      = $this->request->getPost('qty');
        $expected = trim((string) $this->request->getPost('expected_delivery_date'));
        $posted   = ['pick' => is_array($pick) ? array_map('strval', array_filter($pick, 'is_scalar')) : [], 'qty' => is_array($qty) ? $qty : [], 'expected' => $expected];

        $dss    = (new DssParameterModel())->current();
        $errors = [];
        if ($expected !== '' && ! $this->isDateFromToday($expected)) {
            $errors[] = 'Expected delivery must be a real date, today or later.';
        }
        $check = SupplierPo::check($supplierId, $pick, $qty, $dss);
        $errors = array_merge($errors, $check['errors']);
        if ($errors !== []) {
            return $this->render($supplier, $errors, $posted);
        }

        $result = SupplierPo::create($supplierId, $check['lines'], $expected !== '' ? $expected : null, $onceKey, (int) session()->get('user_id'), $dss);

        if ($result['status'] === 'duplicate') {
            $earlier = $result['earlier'];

            return redirect()->to($detailUrl)->with('toast', Toast::info('Already saved.', ! empty($earlier['result_id']) ? 'Order ' . StockOrderModel::labelFor((string) $earlier['result_id']) . ' was already created.' : null));
        }
        if ($result['status'] === 'refused') {
            return $this->render($supplier, $result['errors'], $posted);
        }
        if ($result['status'] !== 'created') {
            return $this->render($supplier, ['Something went wrong while saving. Nothing was ordered. Please try again.'], $posted);
        }

        // The supplier is told, like for any new order (the same words as StockOrderController::store()).
        $po           = $result['po_number'];
        $supplierUser = (new UserModel())->where('supplier_id', $supplierId)->where('role', 'Supplier')->first();
        (new NotificationModel())->push(
            'Supplier',
            'Order Status',
            "New order {$po}",
            "A new stock order {$po} has been placed. Check New Orders to accept it.",
            $supplierUser['user_id'] ?? null,
            'order_status',
            '/supplier/new-orders'
        );
        (new EmailNotifier())->toUser(
            $supplierUser,
            "New stock order {$po}",
            "A new stock order {$po} has been placed. Please log in to the supplier portal to review and accept it."
        );

        $n = $result['lines'];

        return redirect()->to($detailUrl)->with('toast', Toast::success(
            'Purchase order created',
            'was sent to ' . $supplier['company_name'] . " ({$n} " . ($n === 1 ? 'line' : 'lines') . ').',
            $po,
            ['action' => ['label' => 'View order', 'url' => site_url('admin/orders/' . $result['so_id'])]]
        ));
    }

    private function render(array $supplier, array $errors, ?array $posted)
    {
        $dss   = (new DssParameterModel())->current();
        $lines = SupplierPo::lines((string) $supplier['supplier_id'], $dss);
        $lead  = (int) ($supplier['lead_time_days'] ?? 0);

        $html = view('admin/supplier_po_review', [
            'title'           => 'Create PO for low items',
            'active'          => 'procurement_report',
            'supplier'        => $supplier,
            'now'             => array_values(array_filter($lines, static fn ($l) => $l['section'] === 'now')),
            'low'             => array_values(array_filter($lines, static fn ($l) => $l['section'] === 'low')),
            'orderingCost'    => (float) ($dss['ordering_cost'] ?? 0),
            'defaultExpected' => $lead > 0 ? date('Y-m-d', strtotime("+{$lead} days")) : '',
            'errors'          => $errors,
            'posted'          => $posted,
            'backUrl'         => site_url('admin/procurement-report') . '?tab=suppliers&supplier=' . rawurlencode((string) $supplier['supplier_id']),
        ]);

        // A refused order is shown with 422 so nothing downstream mistakes it for a saved one.
        return $errors !== [] ? $this->response->setStatusCode(422)->setBody($html) : $html;
    }

    private function isDateFromToday(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && date('Y-m-d', (int) strtotime($date)) === $date
            && $date >= date('Y-m-d');
    }
}
