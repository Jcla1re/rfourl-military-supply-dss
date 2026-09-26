<?php

$routes->get('/', 'AuthController::landing');

$routes->get('login/admin', 'AuthController::showAdminLogin');
$routes->post('login/admin', 'AuthController::attemptAdminLogin');

$routes->get('login/staff', 'AuthController::showStaffLogin');
$routes->post('login/staff', 'AuthController::attemptStaffLogin');

$routes->get('login/supplier', 'AuthController::showSupplierLogin');
$routes->post('login/supplier', 'AuthController::attemptSupplierLogin');

$routes->get('logout', 'AuthController::logout');

// Staff has no self-service reset — it notifies the Admin instead (see Figma "Can't access your account?").
// Registered before the generic (:segment) routes below so it takes precedence for the "staff" segment.
$routes->get('login/staff/forgot', 'AuthController::showStaffForgot');
$routes->post('login/staff/forgot', 'AuthController::notifyStaffForgot');

$routes->get('login/(:segment)/forgot', 'AuthController::showForgotPassword/$1');
$routes->post('login/(:segment)/forgot', 'AuthController::sendOtp/$1');
$routes->get('login/(:segment)/otp', 'AuthController::showOtpVerify/$1');
$routes->post('login/(:segment)/otp', 'AuthController::verifyOtp/$1');
$routes->post('login/(:segment)/otp/resend', 'AuthController::resendOtp/$1');
$routes->get('login/(:segment)/reset-password', 'AuthController::showResetPassword/$1');
$routes->post('login/(:segment)/reset-password', 'AuthController::resetPassword/$1');

$routes->group('admin', ['namespace' => 'App\Controllers\Admin', 'filter' => 'roleauth:Admin'], static function ($routes) {
    $routes->get('dashboard', 'DashboardController::index');

    $routes->get('inventory', 'InventoryController::index');
    $routes->post('inventory/store', 'InventoryController::store');
    $routes->post('inventory/update/(:segment)', 'InventoryController::update/$1');
    $routes->post('inventory/delete/(:segment)', 'InventoryController::delete/$1');

    $routes->get('suppliers', 'SupplierController::index');
    $routes->get('suppliers/create', 'SupplierController::create');
    $routes->get('suppliers/edit/(:segment)', 'SupplierController::editForm/$1');
    $routes->post('suppliers/store', 'SupplierController::store');
    $routes->post('suppliers/update/(:segment)', 'SupplierController::update/$1');
    $routes->post('suppliers/delete/(:segment)', 'SupplierController::delete/$1');

    $routes->get('orders', 'StockOrderController::index');
    $routes->get('orders/(:segment)', 'StockOrderController::show/$1');
    $routes->post('orders/store', 'StockOrderController::store');
    $routes->post('orders/update-status/(:segment)', 'StockOrderController::updateStatus/$1');
    $routes->post('orders/flag-delayed/(:segment)', 'StockOrderController::flagDelayed/$1');

    $routes->get('reorder-alerts', 'ReorderAlertController::index');
    $routes->get('procurement-report', 'ReorderAlertController::report');
    $routes->post('reorder-alerts/resolve-all', 'ReorderAlertController::resolveAll');
    $routes->post('reorder-alerts/order', 'ReorderAlertController::createOrder');
    $routes->post('reorder-alerts/acknowledge/(:segment)', 'ReorderAlertController::acknowledge/$1');
    $routes->post('reorder-alerts/dismiss/(:segment)', 'ReorderAlertController::dismiss/$1');

    $routes->get('trend-analysis', 'TrendAnalysisController::index');

    $routes->get('sales', 'SalesController::index');
    $routes->post('sales/checkout', 'SalesController::checkout');
    $routes->get('sales/export-pdf', 'SalesController::exportPdf');

    $routes->get('settings', 'SettingsController::index');
    $routes->post('settings/dss-parameters', 'SettingsController::updateDssParameters');
    $routes->post('settings/notification-preference', 'SettingsController::updateNotificationPreference');
    $routes->post('settings/account', 'SettingsController::updateAccount');
    $routes->post('settings/owner-password', 'SettingsController::changeOwnerPassword');
    $routes->post('settings/staff-password', 'SettingsController::changeStaffPassword');
    $routes->post('settings/staff-accounts', 'SettingsController::addStaffAccount');
    $routes->post('settings/staff-accounts/toggle/(:segment)', 'SettingsController::deactivateStaff/$1');

    $routes->get('notifications', 'NotificationController::index');
    $routes->post('notifications/mark-read/(:segment)', 'NotificationController::markRead/$1');
    $routes->post('notifications/mark-all-read', 'NotificationController::markAllRead');
    $routes->post('notifications/open/(:segment)', 'NotificationController::open/$1');
    $routes->post('notifications/approve/(:segment)', 'NotificationController::approve/$1');
    $routes->post('notifications/decline/(:segment)', 'NotificationController::decline/$1');
});

$routes->group('staff', ['namespace' => 'App\Controllers\Staff', 'filter' => 'roleauth:Staff'], static function ($routes) {
    $routes->get('dashboard', 'DashboardController::index');
    $routes->post('dashboard/notify', 'DashboardController::notifyAdmin');

    $routes->get('inventory', 'InventoryController::index');
    $routes->post('inventory/notify/(:segment)', 'InventoryController::notifyAdmin/$1');

    $routes->get('reorder-alerts', 'ReorderAlertController::index');
    $routes->post('reorder-alerts/notify/(:segment)', 'ReorderAlertController::notifyAdmin/$1');

    $routes->get('log-transaction', 'LogTransactionController::index');
    $routes->get('log-transaction/(:segment)', 'LogTransactionController::form/$1');
    $routes->post('log-transaction/store', 'LogTransactionController::store');

    $routes->get('sales', 'SalesController::index');
    $routes->post('sales/checkout', 'SalesController::checkout');
    $routes->get('sales/export-pdf', 'SalesController::exportPdf');

    $routes->get('notifications', 'NotificationController::index');
    $routes->post('notifications/mark-read/(:segment)', 'NotificationController::markRead/$1');
    $routes->post('notifications/mark-all-read', 'NotificationController::markAllRead');
});

$routes->group('supplier', ['namespace' => 'App\Controllers\Supplier', 'filter' => 'roleauth:Supplier'], static function ($routes) {
    $routes->get('dashboard', 'DashboardController::index');

    $routes->get('new-orders', 'NewOrdersController::index');
    $routes->post('new-orders/accept/(:segment)', 'NewOrdersController::accept/$1');
    $routes->post('new-orders/decline/(:segment)', 'NewOrdersController::decline/$1');

    $routes->get('deliveries', 'DeliveriesController::index');
    $routes->post('deliveries/ship/(:segment)', 'DeliveriesController::ship/$1');
    $routes->post('deliveries/deliver/(:segment)', 'DeliveriesController::deliver/$1');

    $routes->get('completed', 'CompletedController::index');

    $routes->get('notifications', 'NotificationController::index');
    $routes->post('notifications/mark-read/(:segment)', 'NotificationController::markRead/$1');
    $routes->post('notifications/mark-all-read', 'NotificationController::markAllRead');

    $routes->get('profile', 'ProfileController::index');
    $routes->post('profile/update', 'ProfileController::update');
});