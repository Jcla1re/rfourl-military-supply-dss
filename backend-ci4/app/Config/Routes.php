<?php
// app/Config/Routes.php

$routes->get('/', 'AuthController::landing');

$routes->get('/login/admin', 'AuthController::showAdminLogin');
$routes->post('/login/admin', 'AuthController::attemptAdminLogin');

$routes->get('/login/staff', 'AuthController::showStaffLogin');
$routes->post('/login/staff', 'AuthController::attemptStaffLogin');

$routes->get('/login/supplier', 'AuthController::showSupplierLogin');
$routes->post('/login/supplier', 'AuthController::attemptSupplierLogin');

$routes->get('/logout', 'AuthController::logout');