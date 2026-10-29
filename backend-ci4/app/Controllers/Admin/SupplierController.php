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

        $suppliers = $builder->orderBy('company_name', 'ASC')->findAll();

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
        $avgLeadTime     = $suppliers ? round(array_sum(array_column($suppliers, 'lead_time_days')) / count($suppliers), 1) : 0;

        $data = [
            'title'         => 'Suppliers',
            'active'        => 'suppliers',
            'suppliers'     => $suppliers,
            'totalSuppliers'=> count($suppliers),
            'avgLeadTime'   => $avgLeadTime,
            'pendingOrders' => $pendingOrders,
            'search'        => $search ?? '',
            'status'        => $status,
            'activeCount'   => $this->supplierModel->where('is_active', 1)->countAllResults(),
            'archivedCount' => $this->supplierModel->where('is_active', 0)->countAllResults(),
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
        $username   = $this->request->getPost('username');
        $password   = $this->request->getPost('password');
        $confirm    = $this->request->getPost('confirm_password');

        if ($username || $password || $loginEmail) {
            if (! $this->validate([
                'login_email' => 'permit_empty|valid_email|is_unique[users.email]',
                'username'    => 'required|is_unique[users.username]',
                'password'    => 'required',
            ])) {
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

        return redirect()->to('/admin/suppliers')->with('success', 'Supplier updated successfully.');
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
