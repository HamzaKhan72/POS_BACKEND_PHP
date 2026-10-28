<?php
// ---- CORS + safe error output (sabse pehle, taake error par bhi CORS header jaye) ----
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
ini_set('display_errors', '0');
set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'message' => 'PHP fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']]);
    }
});

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Mailer.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

foreach (['Auth', 'Bootstrap', 'Settings', 'Category', 'Product', 'Sale', 'Customer', 'Supplier', 'Purchase', 'Expense', 'Inventory', 'Report', 'Register', 'User'] as $c) {
    require_once __DIR__ . "/controllers/{$c}Controller.php";
}

$router = new Router();
$U = [['AuthMiddleware', 'handle']]; // koi bhi logged-in user
$A = [['AuthMiddleware', 'admin']];  // sirf admin

// ---------- Public ----------
$router->add('POST', '/api/auth/login', ['AuthController', 'login']);
$router->add('POST', '/api/auth/register', ['AuthController', 'register']);
$router->add('POST', '/api/auth/forgot-password', ['AuthController', 'forgotPassword']);
$router->add('POST', '/api/auth/reset-password', ['AuthController', 'resetPassword']);

// ---------- Logged-in user ----------
$router->add('PUT',  '/api/auth/profile', ['AuthController', 'updateProfile'], $U);
$router->add('POST', '/api/auth/change-password', ['AuthController', 'changePassword'], $U);

$router->add('GET',  '/api/bootstrap', ['BootstrapController', 'index'], $U);
$router->add('GET',  '/api/products', ['ProductController', 'list'], $U);
$router->add('GET',  '/api/customers', ['CustomerController', 'list'], $U);
$router->add('POST', '/api/customers', ['CustomerController', 'create'], $U);
$router->add('POST', '/api/sales/create', ['SaleController', 'checkout'], $U);
$router->add('GET',  '/api/sales/deleted', ['SaleController', 'listDeleted'], $A); // {id} se pehle hona zaroori hai
$router->add('GET',  '/api/sales/{id}', ['SaleController', 'show'], $U);

// ---------- Admin ----------
$router->add('PUT',  '/api/settings', ['SettingsController', 'update'], $A);

$router->add('GET',  '/api/categories', ['CategoryController', 'list'], $U);
$router->add('POST', '/api/categories', ['CategoryController', 'create'], $A);

$router->add('POST',   '/api/products/import', ['ProductController', 'import'], $A);
$router->add('POST',   '/api/products', ['ProductController', 'create'], $A);
$router->add('PUT',    '/api/products/{id}', ['ProductController', 'update'], $A);
$router->add('DELETE', '/api/products/{id}', ['ProductController', 'delete'], $A);

$router->add('PUT',  '/api/customers/{id}', ['CustomerController', 'update'], $A);
$router->add('POST', '/api/customers/{id}/payment', ['CustomerController', 'payment'], $A);
$router->add('POST', '/api/customer-payments', ['CustomerController', 'recordPayment'], $A); // purana route

$router->add('GET',  '/api/sales', ['SaleController', 'list'], $A);
$router->add('PUT',  '/api/sales/{id}', ['SaleController', 'update'], $A);
$router->add('POST', '/api/sales/{id}/return', ['SaleController', 'returnItems'], $A);
$router->add('DELETE', '/api/sales/{id}', ['SaleController', 'delete'], $A);

$router->add('GET',  '/api/suppliers', ['SupplierController', 'list'], $A);
$router->add('POST', '/api/suppliers', ['SupplierController', 'create'], $A);
$router->add('PUT',  '/api/suppliers/{id}', ['SupplierController', 'update'], $A);
$router->add('POST', '/api/suppliers/{id}/payment', ['SupplierController', 'payment'], $A);
$router->add('POST', '/api/purchases', ['PurchaseController', 'create'], $A);

$router->add('GET',    '/api/expenses', ['ExpenseController', 'list'], $A);
$router->add('POST',   '/api/expenses', ['ExpenseController', 'create'], $A);
$router->add('DELETE', '/api/expenses/{id}', ['ExpenseController', 'delete'], $A);

$router->add('GET',  '/api/inventory/movements', ['InventoryController', 'movements'], $A);
$router->add('POST', '/api/inventory/adjust', ['InventoryController', 'adjust'], $A);

$router->add('GET', '/api/reports/dashboard', ['ReportController', 'dashboard'], $A);
$router->add('GET', '/api/reports/summary', ['ReportController', 'summary'], $A);

$router->add('GET',  '/api/register/current', ['RegisterController', 'status'], $A);
$router->add('GET',  '/api/register/sessions', ['RegisterController', 'sessions'], $A);
$router->add('POST', '/api/register/open', ['RegisterController', 'open'], $A);
$router->add('POST', '/api/register/movement', ['RegisterController', 'movement'], $A);
$router->add('POST', '/api/register/close', ['RegisterController', 'close'], $A);

$router->add('GET',    '/api/users', ['UserController', 'list'], $A);
$router->add('POST',   '/api/users', ['UserController', 'create'], $A);
$router->add('PUT',    '/api/users/{id}', ['UserController', 'update'], $A);
$router->add('PUT',    '/api/users/{id}/password', ['UserController', 'resetPassword'], $A);
$router->add('DELETE', '/api/users/{id}', ['UserController', 'delete'], $A);

// Dispatch Request
$router->dispatch(Request::getMethod(), Request::getUri());