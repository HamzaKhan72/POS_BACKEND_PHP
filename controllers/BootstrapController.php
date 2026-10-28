<?php
// Ek hi call me app ko shuru karne ka sara data (POSContext aur SalesInvoices isi ko bulate hain)
class BootstrapController {
    public static function index() {
        $db = Database::getInstance();

        $products = $db->query("SELECT p.*, c.name AS category_name
                                FROM products p LEFT JOIN categories c ON p.category_id = c.id
                                WHERE p.is_active = 1 ORDER BY p.name ASC")->fetchAll();
        $categories = $db->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
        $customers = $db->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();
        $suppliers = $db->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
        $sales = $db->query("SELECT s.*, c.name AS customer_name
                             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
                             ORDER BY s.id DESC LIMIT 500")->fetchAll();

        Response::success('Bootstrap data', [
            'products' => $products,
            'categories' => $categories,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'sales' => $sales,
            'settings' => SettingsController::all(),
        ]);
    }
}
