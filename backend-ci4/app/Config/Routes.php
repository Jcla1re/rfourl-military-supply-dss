<?php

$routes->get('/', 'AuthController::landing');

$routes->get('login/admin', 'AuthController::showAdminLogin');
$routes->post('login/admin', 'AuthController::attemptAdminLogin');

$routes->get('login/staff', 'AuthController::showStaffLogin');
$routes->post('login/staff', 'AuthController::attemptStaffLogin');

$routes->get('login/supplier', 'AuthController::showSupplierLogin');
$routes->post('login/supplier', 'AuthController::attemptSupplierLogin');

$routes->get('logout', 'AuthController::logout');

$routes->group('admin', ['namespace' => 'App\Controllers\Admin'], static function ($routes) {
    $routes->get('dashboard', 'DashboardController::index');
    $routes->get('inventory', 'InventoryController::index');
    $routes->get('reorder-alerts', 'ReorderAlertController::index');
    $routes->get('procurement-report', 'ReorderAlertController::report');

    $routes->post('reorder-alerts/resolve-all', 'ReorderAlertController::resolveAll');
    $routes->post('reorder-alerts/order', 'ReorderAlertController::createOrder');
});