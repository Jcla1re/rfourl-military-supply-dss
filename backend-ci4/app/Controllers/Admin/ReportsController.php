<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AccountingReports;

/**
 * The owner's Reports: Sales Journal, Purchases Journal and Period Summary for a date range
 * (this month by default), on screen, as a PDF and as a CSV. Read-only. Owner only: the
 * admin route group's roleauth:Admin filter keeps staff and suppliers out.
 */
class ReportsController extends BaseController
{
    /** @return array{type: string, from: string, to: string, report: array} */
    private function load(): array
    {
        $type         = AccountingReports::type($this->request->getGet('type'));
        [$from, $to]  = AccountingReports::range($this->request->getGet('from'), $this->request->getGet('to'));
        $report       = match ($type) {
            'purchases' => AccountingReports::purchasesJournal($from, $to),
            'summary'   => AccountingReports::summary($from, $to),
            default     => AccountingReports::salesJournal($from, $to),
        };

        return ['type' => $type, 'from' => $from, 'to' => $to, 'report' => $report];
    }

    public function index()
    {
        return view('admin/reports', $this->load() + ['title' => 'Reports', 'active' => 'reports']);
    }

    public function pdf()
    {
        $data = $this->load();

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('a4', $data['type'] === 'summary' ? 'portrait' : 'landscape');
        $dompdf->loadHtml(view('reports/pdf', $data + ['generatedAt' => date('M j, Y g:i A')]));
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $this->fileName($data) . '.pdf"')
            ->setBody($dompdf->output());
    }

    public function csv()
    {
        $data = $this->load();

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $this->fileName($data) . '.csv"')
            ->setBody(AccountingReports::csv($data['type'], $data['from'], $data['to']));
    }

    private function fileName(array $data): string
    {
        return "{$data['type']}-{$data['from']}-to-{$data['to']}";
    }
}
