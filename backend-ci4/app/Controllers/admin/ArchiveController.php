<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\SalesArchive;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * The Owner's choice for sales that are 3+ years old: save a copy (PDF or
 * print) and remove them, remove without a copy, or keep them for now.
 */
class ArchiveController extends BaseController
{
    public function index()
    {
        return view('admin/archive', [
            'title'   => 'Old Sales Records',
            'active'  => 'trend_analysis',
            'summary' => SalesArchive::summary(),
            'success' => session()->getFlashdata('success'),
            'error'   => session()->getFlashdata('error'),
        ]);
    }

    public function pdf()
    {
        $summary = SalesArchive::summary();
        if (! $summary) {
            return redirect()->to('/admin/archive');
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $html = view('admin/archive_report', ['summary' => $summary, 'report' => SalesArchive::report(), 'mode' => 'pdf']);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml($html);
        $dompdf->render();

        SecurityLogModel::log('archive_pdf_saved', null, "sales before {$summary['cutoff']}: {$summary['receipts']} receipts");

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="sales-before-' . $summary['cutoff'] . '.pdf"')
            ->setBody($dompdf->output());
    }

    public function print()
    {
        $summary = SalesArchive::summary();
        if (! $summary) {
            return redirect()->to('/admin/archive');
        }

        SecurityLogModel::log('archive_printed', null, "sales before {$summary['cutoff']}: {$summary['receipts']} receipts");

        return view('admin/archive_report', ['summary' => $summary, 'report' => SalesArchive::report(), 'mode' => 'print']);
    }

    public function purge()
    {
        $user = (new UserModel())->find((int) session()->get('user_id'));

        if (trim((string) $this->request->getPost('confirm_text')) !== 'REMOVE') {
            return redirect()->to('/admin/archive')->with('error', 'Type REMOVE to confirm.');
        }

        if (! $user || ! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            SecurityLogModel::log('archive_purge_failed', null, 'wrong current password');
            return redirect()->to('/admin/archive')->with('error', 'Current password is incorrect.');
        }

        $summary = SalesArchive::summary();
        $removed = SalesArchive::purge();
        SecurityLogModel::log('archive_purged', null, "removed {$removed} receipts dated before " . ($summary['cutoff'] ?? SalesArchive::cutoff()));

        return redirect()->to('/admin/archive')->with('success', "Removed {$removed} sales records older than " . SalesArchive::YEARS . ' years.');
    }

    /** "Remind me later": hides the notice for the rest of today. */
    public function later()
    {
        session()->set('archive_snoozed_on', date('Y-m-d'));

        return redirect()->back();
    }
}
