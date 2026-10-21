<?php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\HelpGuides;

/** Short step-by-step guides for staff tasks. Read-only page. */
class HelpController extends BaseController
{
    public function index()
    {
        return view('staff/help', [
            'title'  => 'Help',
            'active' => 'help',
            'guides' => HelpGuides::for('staff'),
        ]);
    }
}
