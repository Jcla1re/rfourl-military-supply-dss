<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\SalesArchive;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * Old Sales Records (opened from Settings > Data & Records or the bell): sales
 * older than the retention period. The Owner can save a copy (PDF or print)
 * and then remove them with their password.
 */
class ArchiveController extends BaseController
{
    public function index()
    {
        $summary = SalesArchive::summary();

        return view('admin/archive', [
            'title'     => 'Old Sales Records',
            'active'    => 'settings',
            'summary'   => $summary,
            'years'     => SalesArchive::years(),
            'savedCopy' => $this->savedCopy($summary),
            'success'   => session()->getFlashdata('success'),
            'error'     => session()->getFlashdata('error'),
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

        $file = 'sales-before-' . $summary['cutoff'] . '.pdf';
        // Step 1 shows a check mark and this file name while the same records are listed.
        session()->set('archive_copy', ['file' => $file, 'cutoff' => $summary['cutoff'], 'receipts' => $summary['receipts']]);
        SecurityLogModel::log('archive_pdf_saved', null, "sales before {$summary['cutoff']}: {$summary['receipts']} receipts");

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file . '"')
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

        if (! $user || ! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            SecurityLogModel::log('archive_purge_failed', null, 'wrong current password');

            return redirect()->to('/admin/archive')->with('error', 'Current password is incorrect.');
        }

        $years   = SalesArchive::years();
        $cutoff  = SalesArchive::cutoff();
        $removed = SalesArchive::purge();
        SecurityLogModel::log('archive_purged', null, "removed {$removed} receipts dated before {$cutoff}");
        session()->remove('archive_copy');
        SalesArchive::syncNotification(); // the bell notice goes away once nothing is past the period

        return redirect()->to('/admin/archive')->with('success', "Removed {$removed} sales records older than {$years} years.");
    }

    /** The PDF saved this session for exactly these records, or null. */
    private function savedCopy(?array $summary): ?array
    {
        $copy = session()->get('archive_copy');

        return $summary && is_array($copy) && $copy['cutoff'] === $summary['cutoff'] && (int) $copy['receipts'] === (int) $summary['receipts']
            ? $copy
            : null;
    }
}
