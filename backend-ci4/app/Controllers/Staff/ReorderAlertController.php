<?php
// app/Controllers/Staff/ReorderAlertController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Models\ClusterSegmentModel;
use App\Models\DssParameterModel;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\PdssComputationModel;
use App\Models\ReorderAlertModel;
use App\Models\SupplierModel;

class ReorderAlertController extends BaseController
{
    protected ReorderAlertModel $reorderAlertModel;
    protected SupplierModel $supplierModel;
    protected PdssComputationModel $pdssComputationModel;
    protected DssParameterModel $dssParameterModel;
    protected ClusterSegmentModel $clusterSegmentModel;

    public function __construct()
    {
        $this->reorderAlertModel    = new ReorderAlertModel();
        $this->supplierModel        = new SupplierModel();
        $this->pdssComputationModel = new PdssComputationModel();
        $this->dssParameterModel    = new DssParameterModel();
        $this->clusterSegmentModel  = new ClusterSegmentModel();
    }

    public function index()
    {
        // Same live-inventory reconciliation as the admin page, so staff
        // and admin always see the identical set of critical items.
        $this->reorderAlertModel->syncFromLiveInventory();

        $alerts = $this->reorderAlertModel->openAlerts();
        $dss    = $this->dssParameterModel->current();

        foreach ($alerts as &$alert) {
            $computation = $alert['computation_id']
                ? $this->pdssComputationModel->find($alert['computation_id'])
                : $this->pdssComputationModel->latestFor($alert['item_id']);

            $supplier = $alert['supplier_id'] ? $this->supplierModel->find($alert['supplier_id']) : null;
            $cluster  = $this->clusterSegmentModel->find($alert['item_id']);

            $alert['computation']    = $computation;
            $alert['supplier_name']  = $supplier['company_name'] ?? 'Unassigned';
            $alert['lead_time_days'] = $computation['lead_time_days'] ?? ($supplier['lead_time_days'] ?? null);
            $alert['service_level']  = $cluster['service_level'] ?? ($dss['service_level_target'] ?? null);
            $alert['abc_class']      = $cluster['abc_class'] ?? null;
        }
        unset($alert);

        $critical = count(array_filter($alerts, fn ($a) => (int) $a['stock_at_trigger'] <= 0));
        $lowStock = count($alerts) - $critical;

        $data = [
            'title'         => 'Reorder Alerts',
            'active'        => 'reorder_alerts',
            'alerts'        => $alerts,
            'dss'           => $dss,
            'criticalCount' => $critical,
            'lowStockCount' => $lowStock,
            'receivedCount' => $this->reorderAlertModel->resolvedThisWeek(),
            'lastUpdated'   => 'just now',
            'success'       => session()->getFlashdata('success'),
        ];

        return view('staff/reorder_alerts', $data);
    }

    public function notifyAdmin(string $alertId)
    {
        $alert = $this->reorderAlertModel->find((int) $alertId);

        if ((new NotificationPreferenceModel())->isEnabled('rop_alerts')) {
            (new NotificationModel())->push(
                'Admin',
                'Reorder Point Notice',
                'Staff flagged ' . ($alert['item_name'] ?? "alert #{$alertId}") . ' as needing reorder attention.',
                'Reason: ' . ($alert['trigger_reason'] ?? '—'),
                null,
                'staff_activity'
            );
        }

        return redirect()->to('/staff/reorder-alerts')->with('success', 'Notify Sent! Reorder Point Notice');
    }
}
