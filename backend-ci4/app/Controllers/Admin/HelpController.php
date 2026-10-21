<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\HelpGuides;

/** Short step-by-step guides for owner tasks. Read-only page. */
class HelpController extends BaseController
{
    public function index()
    {
        return view('admin/help', [
            'title'  => 'Help',
            'active' => 'help',
            'guides' => HelpGuides::for('owner'),
        ]);
    }
}
