<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Libraries\HelpGuides;

/** Short step-by-step guides for supplier tasks. Read-only page. */
class HelpController extends BaseController
{
    public function index()
    {
        return view('supplier/help', [
            'title'  => 'Help',
            'active' => 'help',
            'guides' => HelpGuides::for('supplier'),
        ]);
    }
}
