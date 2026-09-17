<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\DssParameterModel;
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

        $builder = $this->supplierModel->where('is_active', 1);

        if ($search) {
            $builder = $builder->like('company_name', $search);
        }

        $suppliers = $builder->orderBy('company_name', 'ASC')->findAll();

        foreach ($suppliers as &$s) {
            $s['product_count'] = $this->productModel
                ->where('supplier_id', $s['supplier_id'])
                ->where('is_active', 1)
                ->countAllResults();
            $s['has_portal'] = (bool) $this->userModel->where('supplier_id', $s['supplier_id'])->first();
        }
        unset($s);

        $stockOrderModel = new StockOrderModel();
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
            'lead_time_days' => 'required|integer|greater_than_equal_to[0]',
            'contact_email'  => 'permit_empty|valid_email',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
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

        $loginEmail = $this->request->getPost('login_email');
        $username   = $this->request->getPost('username');
        $password   = $this->request->getPost('password');
        $confirm    = $this->request->getPost('confirm_password');

        if ($username && $password) {
            if ($password !== $confirm) {
                return redirect()->to('/admin/suppliers')->with('error', "Supplier {$newId} created, but the portal account password did not match — add the account manually in Settings.");
            }

            $this->userModel->insert([
                'supplier_id'   => $newId,
                'username'      => $username,
                'email'         => $loginEmail ?: null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role'          => 'Supplier',
                'full_name'     => $this->request->getPost('company_name'),
                'is_active'     => 1,
            ]);
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
            'lead_time_days' => 'required|integer|greater_than_equal_to[0]',
            'contact_email'  => 'permit_empty|valid_email',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
        }

        $status = $this->request->getPost('supplier_status') ?: 'Active';

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

        $this->supplierModel->update($supplierId, ['is_active' => 0]);

        return redirect()->to('/admin/suppliers')->with('success', 'Supplier archived.');
    }
}
