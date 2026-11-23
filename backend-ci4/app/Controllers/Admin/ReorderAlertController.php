<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\BestSupplier;
use App\Libraries\DssRunner;
use App\Libraries\ProcurementComparison;
use App\Libraries\ProcurementReport;
use App\Libraries\ReorderBoard;
use App\Libraries\SupplierPo;
use App\Libraries\SupplierScorecard;
use App\Libraries\SupplierProcurement;
use App\Libraries\Toast;
use App\Models\DssParameterModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\SupplierModel;

class ReorderAlertController extends BaseController
{
    /** Procurement Report rows-per-page choices (same as Inventory); first is the default. */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

    protected ReorderAlertModel $reorderAlertModel;
    protected ProductModel $productModel;
    protected SupplierModel $supplierModel;
    protected DssParameterModel $dssParameterModel;

    public function __construct()
    {
        $this->reorderAlertModel = new ReorderAlertModel();
        $this->productModel      = new ProductModel();
        $this->supplierModel     = new SupplierModel();
        $this->dssParameterModel = new DssParameterModel();
    }

    public function index()
    {
        // The board (tabs, cards, counts, filters) is shared with the Staff page: App\Libraries\ReorderBoard.
        $data = ReorderBoard::build($this->request->getGet() ?? [], session()->get('previous_login') ?: null) + [
            'title'     => 'Reorder Alerts',
            'active'    => 'reorder_alerts',
            'role'      => 'admin',
            'suppliers' => $this->supplierModel->real()->where('is_active', 1)->findAll(), // the order dialog: no sample suppliers
            'success'   => session()->getFlashdata('success'),
            'error'     => session()->getFlashdata('error'),
        ];

        return view('admin/reorder_alerts', $data);
    }

    /**
     * Procurement Report: read-only DSS results per size (App\Libraries\ProcurementReport).
     * Search, Class / Status filters and paging are GET parameters worked out on the server
     * over every active size; the live search fetches just the table (isAJAX). Ordering
     * stays on Reorder Alerts and Inventory. Nothing here writes, not even the alert sync.
     */
    public function report()
    {
        $filters = ProcurementReport::filters($this->request->getGet() ?? []);
        $dss     = $this->dssParameterModel->current();

        $tab = $this->pageTab();
        if ($tab === 'comparison') {
            return $this->comparison($filters, $dss);
        }
        if ($tab === 'suppliers') {
            return $this->suppliersTab($dss);
        }
        if ($tab === 'best') {
            return $this->bestTab($filters, $dss);
        }
        $rows    = ProcurementReport::rows($filters, $dss);

        $perPage = (int) ($this->request->getGet('per_page') ?? self::PER_PAGE_OPTIONS[0]);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }
        $totalItems = count($rows);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page       = min(max(1, (int) ($this->request->getGet('page') ?? 1)), $totalPages);

        $data = $filters + [
            'title'          => 'Procurement Report',
            'active'         => 'procurement_report',
            'tab'            => 'table',
            'rows'           => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'totalItems'     => $totalItems,
            'currentPage'    => $page,
            'totalPages'     => $totalPages,
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'minimumOrder'   => (int) ($dss['minimum_order_qty'] ?? 0),
            'lastRunAt'      => (new DssRunner())->lastRunAt(),
            'activeFilters'  => ProcurementReport::activeFilterCount($filters),
            'exportQuery'    => ProcurementReport::query($filters),
        ];

        if ($this->request->isAJAX()) {
            return view('admin/_procurement_table', $data);
        }

        return view('admin/procurement_report', $data);
    }

    private function isComparisonTab(): bool
    {
        return $this->request->getGet('tab') === 'comparison';
    }

    private function isBestTab(): bool
    {
        return $this->request->getGet('tab') === 'best';
    }

    private function isSuppliersTab(): bool
    {
        return $this->request->getGet('tab') === 'suppliers';
    }

    /**
     * Which tab the page opens: ?tab=suppliers | items | comparison | best. With no tab it is Suppliers, unless the URL
     * carries Items parameters (search, class, status, page, per_page): an old Items link still opens Items.
     * (The PDF and CSV exports are separate routes: with no tab they export Items, as they always did.)
     */
    private function pageTab(): string
    {
        $tab = (string) $this->request->getGet('tab');
        if (in_array($tab, ['suppliers', 'items', 'comparison', 'best'], true)) {
            return $tab;
        }
        foreach (['search', 'class', 'status', 'page', 'per_page'] as $itemsParam) {
            if ($this->request->getGet($itemsParam) !== null) {
                return 'items';
            }
        }

        return 'suppliers';
    }

    /**
     * The Suppliers tab: one row per active supplier, with a supplier's detail (?supplier=ID, ?needs=1 for "needs
     * action only") and the weekly / monthly report of real orders (?period=weekly|monthly&date=YYYY-MM-DD).
     */
    private function suppliersTab(array $dss)
    {
        $period     = SupplierProcurement::period($this->request->getGet('period'), $this->request->getGet('date'));
        $sizes      = ProcurementReport::rows(ProcurementReport::filters([]), $dss);
        $supplierId = (string) $this->request->getGet('supplier');
        $detail     = $supplierId !== '' ? SupplierProcurement::detail($supplierId, $this->request->getGet('needs') === '1', $dss, $sizes) : null;
        $filters    = ProcurementReport::filters([]);

        return view('admin/procurement_report', $filters + [
            'title'         => 'Procurement Report',
            'active'        => 'procurement_report',
            'tab'           => 'suppliers',
            'detail'        => $detail,
            'supplierRows'  => $detail === null ? SupplierProcurement::suppliers($dss, $sizes) : [],
            'poCounts'      => SupplierPo::countsBySupplier($sizes), // for "Create PO for low items"
            // The supplier scorecard (read-only, from real orders only): ?score=3|6|12|all, 12 by default.
            'scorePeriod'   => SupplierScorecard::period($this->request->getGet('score')),
            'scores'        => SupplierScorecard::forSuppliers($this->request->getGet('score')),
            'period'        => $period,
            'periodReport'  => $detail === null ? SupplierProcurement::report($period) : null,
            'lastRunAt'     => (new DssRunner())->lastRunAt(),
            'activeFilters' => 0,
            // The exports on this tab are the weekly / monthly supplier report.
            'exportQuery'   => ['tab' => 'suppliers', 'period' => $period['type'], 'date' => $period['anchor']],
        ]);
    }

    /**
     * The Best supplier tab (a read-only comparison, see App\Libraries\BestSupplier): the list of sizes with each tag's
     * supplier, and one size's full comparison (?item=ID). ?show=other (default) | warn | all, plus search and class.
     */
    private function bestTab(array $filters, array $dss)
    {
        $filters['status'] = ''; // the Items status filter does not apply here
        $show              = (string) $this->request->getGet('show');
        $show              = array_key_exists($show, BestSupplier::SHOW) ? $show : 'other';
        $base              = $filters + [
            'title'         => 'Procurement Report',
            'active'        => 'procurement_report',
            'tab'           => 'best',
            'lastRunAt'     => (new DssRunner())->lastRunAt(),
            'activeFilters' => 0,
            'detail'        => null,
            'bestShow'      => $show,
            'bestCounts'    => [],
            'rows'          => [],
            'exportQuery'   => ['tab' => 'best'] + ProcurementReport::query($filters) + ($show !== 'other' ? ['show' => $show] : []),
        ];

        $itemId = (string) $this->request->getGet('item');
        if ($itemId !== '') {
            $size = array_values(array_filter(ProcurementReport::rows(ProcurementReport::filters([]), $dss), static fn ($r) => $r['item_id'] === $itemId));
            if ($size !== []) {
                return view('admin/procurement_report', ['detail' => BestSupplier::forItems($size, $dss)[$itemId], 'exportQuery' => ['tab' => 'best']] + $base);
            }
        }

        $results = BestSupplier::forItems(ProcurementReport::rows($filters, $dss), $dss);
        $counts  = [];
        foreach (array_keys(BestSupplier::SHOW) as $key) {
            $counts[$key] = count(BestSupplier::filter($results, $key));
        }
        $shown = array_values(BestSupplier::filter($results, $show));
        if ($show === 'other') {
            usort($shown, static fn ($a, $b) => ($b['saving_vs_main'] ?? -1) <=> ($a['saving_vs_main'] ?? -1)); // the biggest yearly saving first
        }

        $perPage = (int) ($this->request->getGet('per_page') ?? self::PER_PAGE_OPTIONS[0]);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }
        $totalPages = max(1, (int) ceil(count($shown) / $perPage));
        $page       = min(max(1, (int) ($this->request->getGet('page') ?? 1)), $totalPages);

        return view('admin/procurement_report', [
            'rows'           => array_slice($shown, ($page - 1) * $perPage, $perPage),
            'bestCounts'     => $counts,
            'totalItems'     => count($shown),
            'currentPage'    => $page,
            'totalPages'     => $totalPages,
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'activeFilters'  => ProcurementReport::activeFilterCount($filters),
        ] + $base);
    }

    /** The Cost comparison tab: the owner's usual order quantity against the system's Order Qty, per size with sales. */
    private function comparison(array $filters, array $dss)
    {
        $rows = ProcurementComparison::rows($filters, $dss);

        $data = $filters + [
            'title'         => 'Procurement Report',
            'active'        => 'procurement_report',
            'tab'           => 'comparison',
            'rows'          => $rows,
            'totals'        => ProcurementComparison::totals($rows),
            'ownerQty'      => ProcurementComparison::ownerQty($dss),
            'orderingCost'  => (float) ($dss['ordering_cost'] ?? 0),
            'holdingCost'   => (float) ($dss['holding_cost_per_unit'] ?? 0),
            'lastRunAt'     => (new DssRunner())->lastRunAt(),
            'activeFilters' => ProcurementReport::activeFilterCount($filters),
            'exportQuery'   => ['tab' => 'comparison'] + ProcurementReport::query($filters),
        ];

        return $this->request->isAJAX() ? view('admin/_procurement_comparison', $data) : view('admin/procurement_report', $data);
    }

    /**
     * Export PDF: every size matching the current search / filters (not only the page shown). Read-only.
     * Dompdf keeps the whole document in memory: all ~890 sizes need ~170MB, over the live
     * server's 128M, so this request alone gets more (as Old Sales Records' PDF does).
     */
    public function reportPdf()
    {
        @ini_set('memory_limit', '512M');
        // Web requests only: under the CLI (PHPUnit) set_time_limit() would cap the rest of the whole test run.
        if (! is_cli()) {
            @set_time_limit(120);
        }

        if ($this->isBestTab()) {
            return redirect()->to('/admin/procurement-report?tab=best')->with('toast', Toast::info('No PDF for this tab', 'Use Export CSV.'));
        }

        $filters   = ProcurementReport::filters($this->request->getGet() ?? []);
        $dss       = $this->dssParameterModel->current();
        $lastRunAt = (new DssRunner())->lastRunAt();

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('a4', 'landscape');

        if ($this->isSuppliersTab()) {
            $period = SupplierProcurement::period($this->request->getGet('period'), $this->request->getGet('date'));
            $dompdf->loadHtml(view('reports/procurement_suppliers_pdf', [
                'period'      => $period,
                'report'      => SupplierProcurement::report($period),
                'generatedAt' => date('M j, Y g:i A'),
            ]));
            $dompdf->render();

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="supplier-report-' . $period['type'] . '-' . $period['from'] . '.pdf"')
                ->setBody($dompdf->output());
        }

        if ($this->isComparisonTab()) {
            $rows = ProcurementComparison::rows($filters, $dss);
            $dompdf->loadHtml(view('reports/procurement_comparison_pdf', [
                'rows'        => $rows,
                'totals'      => ProcurementComparison::totals($rows),
                'ownerQty'    => ProcurementComparison::ownerQty($dss),
                'basis'       => ProcurementComparison::basis($lastRunAt, $dss),
                'filterLabel' => ProcurementReport::filterLabel($filters),
                'generatedAt' => date('M j, Y g:i A'),
            ]));
            $dompdf->render();

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="cost-comparison-' . date('Y-m-d') . '.pdf"')
                ->setBody($dompdf->output());
        }

        $dompdf->loadHtml(view('reports/procurement_pdf', [
            'rows'        => ProcurementReport::rows($filters, $dss),
            'basis'       => ProcurementReport::basis($lastRunAt, $dss),
            'filterLabel' => ProcurementReport::filterLabel($filters),
            'generatedAt' => date('M j, Y g:i A'),
        ]));
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="procurement-report-' . date('Y-m-d') . '.pdf"')
            ->setBody($dompdf->output());
    }

    /** Export CSV: same rows as the PDF, "Item ID" first, then the Basis note. Read-only. */
    public function reportCsv()
    {
        $filters = ProcurementReport::filters($this->request->getGet() ?? []);
        $dss     = $this->dssParameterModel->current();

        if ($this->isSuppliersTab()) {
            $period = SupplierProcurement::period($this->request->getGet('period'), $this->request->getGet('date'));

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="supplier-report-' . $period['type'] . '-' . $period['from'] . '.csv"')
                ->setBody(SupplierProcurement::csv($period, SupplierProcurement::report($period)));
        }

        if ($this->isBestTab()) {
            // One line per item and supplier, for what the list shows (same search, class and "show" filters).
            $filters['status'] = '';
            $show              = (string) $this->request->getGet('show');
            $show              = array_key_exists($show, BestSupplier::SHOW) ? $show : 'other';
            $results           = BestSupplier::filter(BestSupplier::forItems(ProcurementReport::rows($filters, $dss), $dss), $show);

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="best-supplier-' . date('Y-m-d') . '.csv"')
                ->setBody(BestSupplier::csv($results));
        }

        if ($this->isComparisonTab()) {
            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="cost-comparison-' . date('Y-m-d') . '.csv"')
                ->setBody(ProcurementComparison::csv(ProcurementComparison::rows($filters, $dss), ProcurementComparison::basis((new DssRunner())->lastRunAt(), $dss)));
        }

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="procurement-report-' . date('Y-m-d') . '.csv"')
            ->setBody(ProcurementReport::csv(
                ProcurementReport::rows($filters, $dss),
                ProcurementReport::basis((new DssRunner())->lastRunAt(), $dss)
            ));
    }

    /**
     * Closes a single alert without ordering (false positive, stock
     * corrected manually, etc.) — distinct from Fulfilled, which only
     * happens when a linked stock order is actually delivered. It stays
     * in the Dismissed tab until its size recovers. No confirmation:
     * the toast offers Undo (which restores it).
     */
    public function dismiss(int|string $alertId)
    {
        $alert = $this->reorderAlertModel->find((int) $alertId);
        if (! $alert || ! $this->reorderAlertModel->dismissPending((int) $alertId)) {
            return redirect()->to($this->boardUrl())->with('toast', Toast::error("Couldn't dismiss this alert", 'It is no longer waiting to be ordered.'));
        }

        return redirect()->to($this->boardUrl())->with('toast', Toast::success(
            'Alert dismissed', 'moved to Dismissed.', $this->sizeName($alert['item_id']),
            ['undo' => site_url('admin/reorder-alerts/restore/' . $alert['alert_id'])]
        ));
    }

    /** Undoes a dismissal: the size is back in "To order". The toast's Undo dismisses it again. */
    public function restore(int|string $alertId)
    {
        $alert = $this->reorderAlertModel->find((int) $alertId);
        if (! $alert || ! $this->reorderAlertModel->restoreDismissed((int) $alertId)) {
            return redirect()->to($this->boardUrl())->with('toast', Toast::error("Couldn't restore this alert", 'It is no longer dismissed.'));
        }

        return redirect()->to($this->boardUrl())->with('toast', Toast::success(
            'Alert restored', 'is back in To order.', $this->sizeName($alert['item_id']),
            ['undo' => site_url('admin/reorder-alerts/dismiss/' . $alert['alert_id'])]
        ));
    }

    /** "Tactical Pants · Size L" for a toast. */
    private function sizeName(string $itemId): string
    {
        return ProductModel::nameWithSize($this->productModel->find($itemId) ?? ['item_id' => $itemId]);
    }

    /**
     * Back to the tab and filters the Owner was on: the page (or the toast's Undo)
     * posts them as return_query. Only this page's own parameters are kept.
     */
    private function boardUrl(): string
    {
        parse_str(ltrim((string) $this->request->getPost('return_query'), '?'), $params);
        $params = array_filter(array_intersect_key($params, array_flip(['tab', 'q', 'cat', 'sup', 'only'])), static fn ($v) => is_string($v) && $v !== '');

        return '/admin/reorder-alerts' . ($params ? '?' . http_build_query($params) : '');
    }

    /**
     * Kept for backward compatibility with the old inline order modal;
     * new orders are created through StockOrderController::store().
     */
    public function createOrder()
    {
        session()->setFlashdata('toast', Toast::success('Order created', 'The stock order was created.'));
        return redirect()->to('/admin/reorder-alerts');
    }
}
