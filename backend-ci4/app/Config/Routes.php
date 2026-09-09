<?php
// app/Config/Routes.php  (add inside the existing file, don't replace it)

$routes->get('/login', 'AuthController::login');
$routes->post('/login', 'AuthController::attemptLogin');
$routes->get('/logout', 'AuthController::logout');

// Placeholder dashboard routes — just to confirm redirects work for now
$routes->get('/admin/dashboard', 'Admin\DashboardController::index');
$routes->get('/staff/dashboard', static function () {
    return 'Staff dashboard placeholder — logged in as: ' . session()->get('full_name');
});
$routes->get('/supplier/dashboard', static function () {
    return 'Supplier dashboard placeholder — logged in as: ' . session()->get('full_name');
});