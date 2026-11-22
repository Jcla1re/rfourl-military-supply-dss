<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DemoGuard;
use App\Libraries\PasswordPolicy;
use App\Libraries\Toast;
use App\Models\DssParameterModel;
use App\Models\SecurityLogModel;
use App\Models\ProductModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;
use App\Models\UserModel;

class SupplierController extends BaseController
{
    protected $supplierModel;
    protected $productModel;
    protected $userModel;

    public function __construct()
    {
        $this->supplierModel = new SupplierModel();
        $this->productModel  = new ProductModel();
        $this->userModel     = new UserModel();
    }

    public function index()
    {
        $search = $this->request->getGet('search');
        $status = $this->request->getGet('status') === 'archived' ? 'archived' : 'active';

        $builder = $this->supplierModel->where('is_active', $status === 'archived' ? 0 : 1);

        if ($search) {
            $builder = $builder->like('company_name', $search);
        }

        // Real suppliers first, then the sample ones (each by name).
        $suppliers = $builder->orderBy('is_sample', 'ASC')->orderBy('company_name', 'ASC')->findAll();

        $stockOrderModel = new StockOrderModel();

        foreach ($suppliers as &$s) {
            $s['product_count'] = $this->productModel
                ->where('supplier_id', $s['supplier_id'])
                ->where('is_active', 1)
                ->countAllResults();
            $portal = $this->userModel->where('supplier_id', $s['supplier_id'])->first();
            $s['has_portal']      = (bool) $portal;
            $s['portal_username'] = $portal['username'] ?? null;
            $s['open_orders']     = $stockOrderModel->where('supplier_id', $s['supplier_id'])
                ->whereNotIn('status', ['Delivered', 'Cancelled'])
                ->countAllResults();
        }
        unset($s);

        $pendingOrders   = $stockOrderModel->whereNotIn('status', ['Delivered', 'Cancelled'])->countAllResults();

        // Summary cards: the same on both tabs and with any search (not the listed suppliers).
        $activeCount   = $this->supplierModel->real()->where('is_active', 1)->countAllResults();
        $archivedCount = $this->supplierModel->real()->where('is_active', 0)->countAllResults();
        // The tabs also say how many sample suppliers they list, so their counts match the cards below them.
        $activeSampleCount   = $this->supplierModel->where('is_sample', 1)->where('is_active', 1)->countAllResults();
        $archivedSampleCount = $this->supplierModel->where('is_sample', 1)->where('is_active', 0)->countAllResults();
        $avgRow        = $this->supplierModel->real()->selectAvg('lead_time_days', 'avg_lead')->where('is_active', 1)->get()->getRowArray();
        $avgLeadTime   = $activeCount > 0 ? round((float) ($avgRow['avg_lead'] ?? 0), 1) : 0;

        $data = [
            'title'          => 'Suppliers',
            'active'         => 'suppliers',
            'suppliers'      => $suppliers,
            'totalSuppliers' => $activeCount + $archivedCount,
            'activeSuppliers'=> $activeCount,
            'avgLeadTime'    => $avgLeadTime,
            'pendingOrders'  => $pendingOrders,
            'search'         => $search ?? '',
            'status'         => $status,
            'activeCount'    => $activeCount,
            'archivedCount'  => $archivedCount,
            'activeSampleCount'   => $activeSampleCount,
            'archivedSampleCount' => $archivedSampleCount,
            'success'       => session()->getFlashdata('success'),
            'error'         => session()->getFlashdata('error'),
        ];

        return view('admin/suppliers', $data);
    }

    public function create()
    {
        $data = [
            'title'     => 'Add Supplier',
            'active'    => 'suppliers',
            'supplier'  => null,
            'dss'       => (new DssParameterModel())->current(),
            'error'     => session()->getFlashdata('error'),
        ];

        return view('admin/supplier_form', $data);
    }

    public function editForm($supplierId)
    {
        $supplier = $this->supplierModel->find($supplierId);

        if (! $supplier) {
            return redirect()->to('/admin/suppliers')->with('error', 'Supplier not found.');
        }

        $data = [
            'title'    => 'Edit Supplier',
            'active'   => 'suppliers',
            'supplier' => $supplier,
            'dss'      => (new DssParameterModel())->current(),
            'error'    => session()->getFlashdata('error'),
        ];

        return view('admin/supplier_form', $data);
    }

    public function store()
    {
        $rules = [
            'payment_terms' => 'permit_empty|in_list[' . implode(',', \App\Models\SupplierModel::PAYMENT_TERMS) . ']',
            'company_name'   => 'required|max_length[100]',
            'lead_time_days' => 'required|integer|greater_than_equal_to[1]',
            'contact_email'  => 'permit_empty|valid_email',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
        }

        // Validate the optional portal login BEFORE creating the supplier, so a
        // bad password or email does not leave a supplier with no account behind.
        $loginEmail = $this->request->getPost('login_email');
        $username   = trim((string) $this->request->getPost('username'));
        $password   = $this->request->getPost('password');
        $confirm    = $this->request->getPost('confirm_password');

        if ($username !== '' || $password || $loginEmail) {
            // The username is what they sign in with: trimmed, 4-50 letters, digits, _ or -, not taken.
            $data = (array) $this->request->getPost();
            $data['username'] = $username;
            if (! $this->validateData($data, [
                'login_email' => 'permit_empty|valid_email|is_unique[users.email]',
                'username'    => 'required|min_length[4]|max_length[50]|alpha_dash|is_unique[users.username]',
                'password'    => 'required',
            ], ['username' => ['is_unique' => 'That username is already taken.']])) {
                return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
            }
            if ($error = PasswordPolicy::check($password)) {
                return redirect()->back()->withInput()->with('error', $error);
            }
            if ($password !== $confirm) {
                return redirect()->back()->withInput()->with('error', 'Passwords do not match.');
            }
        }

        $newId = $this->supplierModel->generateNextId();
        $status = $this->request->getPost('supplier_status') ?: 'Active';

        $this->supplierModel->insert([
            'supplier_id'    => $newId,
            'company_name'   => $this->request->getPost('company_name'),
            'category'       => $this->request->getPost('category') ?: null,
            'lead_time_days'     => (int) $this->request->getPost('lead_time_days'),
            'preferred_courier'  => $this->request->getPost('preferred_courier') ?: null,
            'contact_person'     => $this->request->getPost('contact_person') ?: null,
            'contact_email'      => $this->request->getPost('contact_email') ?: null,
            'contact_number'     => $this->request->getPost('contact_number') ?: null,
            'is_active'          => $status === 'Inactive' ? 0 : 1,
        ]);

        $this->savePaymentTerms($newId);

        if ($username && $password) {
            $this->userModel->insert([
                'supplier_id'   => $newId,
                'username'      => $username,
                'email'         => $loginEmail ?: null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role'          => 'Supplier',
                'full_name'     => $this->request->getPost('company_name'),
                'is_active'     => 1,
            ]);
            SecurityLogModel::log('account_created', null, "supplier portal: {$username} ({$newId})");
        }

        return redirect()->to('/admin/suppliers')->with('success', "Supplier {$newId} added successfully.");
    }

    public function update($supplierId)
    {
        $supplier = $this->supplierModel->find($supplierId);

        if (! $supplier) {
            return redirect()->to('/admin/suppliers')->with('error', 'Supplier not found.');
        }

        $rules = [
            'payment_terms' => 'permit_empty|in_list[' . implode(',', \App\Models\SupplierModel::PAYMENT_TERMS) . ']',
            'company_name'   => 'required|max_length[100]',
            'lead_time_days' => 'required|integer|greater_than_equal_to[1]',
            'contact_email'  => 'permit_empty|valid_email',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
        }

        $status = $this->request->getPost('supplier_status') ?: 'Active';

        // Demo mode: the demo supplier can't be archived, renamed, or have its contact email changed.
        if ($this->isDemoSupplier((string) $supplierId)
            && ($status === 'Inactive'
                || trim((string) $this->request->getPost('company_name')) !== (string) $supplier['company_name']
                || trim((string) $this->request->getPost('contact_email')) !== trim((string) ($supplier['contact_email'] ?? '')))) {
            return DemoGuard::refuse('/admin/suppliers');
        }

        $this->supplierModel->update($supplierId, [
            'company_name'   => $this->request->getPost('company_name'),
            'category'       => $this->request->getPost('category') ?: null,
            'lead_time_days'     => (int) $this->request->getPost('lead_time_days'),
            'preferred_courier'  => $this->request->getPost('preferred_courier') ?: null,
            'contact_person'     => $this->request->getPost('contact_person') ?: null,
            'contact_email'      => $this->request->getPost('contact_email') ?: null,
            'contact_number'     => $this->request->getPost('contact_number') ?: null,
            'is_active'          => $status === 'Inactive' ? 0 : 1,
        ]);

        $this->savePaymentTerms((string) $supplierId);

        return redirect()->to('/admin/suppliers')->with('success', 'Supplier updated successfully.');
    }

    /** The optional Payment terms (Cash / Check / Other); a form that does not send the field leaves them as they are. */
    private function savePaymentTerms(string $supplierId): void
    {
        $posted = $this->request->getPost('payment_terms');
        if ($posted === null) {
            return;
        }
        $terms = in_array($posted, \App\Models\SupplierModel::PAYMENT_TERMS, true) ? $posted : null;
        $this->supplierModel->update($supplierId, ['payment_terms' => $terms]);
    }

    public function delete($supplierId)
    {
        $supplier = $this->supplierModel->find($supplierId);

        if (! $supplier) {
            return redirect()->to('/admin/suppliers')->with('error', 'Supplier not found.');
        }

        if ($this->isDemoSupplier((string) $supplierId)) {
            return DemoGuard::refuse('/admin/suppliers');
        }

        $this->supplierModel->update($supplierId, ['is_active' => 0]);
        SecurityLogModel::log('supplier_archived', null, $supplierId . ' ' . $supplier['company_name']);

        // The supplier's portal user is left as it is: RoleAuthFilter and the login both
        // check the supplier's own flag, so the account is locked (and any open session
        // ended) until the supplier is restored.
        return redirect()->to('/admin/suppliers')->with('toast', Toast::success(
            'Supplier archived',
            'moved to Archived. Their portal account is locked until you restore it.',
            $supplier['company_name'],
            ['undo' => site_url('admin/suppliers/restore/' . rawurlencode((string) $supplierId))]
        ));
    }

    /** Demo mode: does this supplier have a demo portal account? Always false outside demo mode. */
    private function isDemoSupplier(string $supplierId): bool
    {
        return demo_mode()
            && $this->userModel->where('supplier_id', $supplierId)->where('is_demo', 1)->countAllResults() > 0;
    }

    public function restore($supplierId)
    {
        $supplier = $this->supplierModel->find($supplierId);

        if (! $supplier) {
            return redirect()->to('/admin/suppliers?status=archived')->with('error', 'Supplier not found.');
        }

        $this->supplierModel->update($supplierId, ['is_active' => 1]);
        SecurityLogModel::log('supplier_restored', null, $supplierId . ' ' . $supplier['company_name']);

        return redirect()->to('/admin/suppliers')->with('toast', Toast::success(
            'Supplier restored',
            'is active again. Their portal account can sign in.',
            $supplier['company_name']
        ));
    }
}
