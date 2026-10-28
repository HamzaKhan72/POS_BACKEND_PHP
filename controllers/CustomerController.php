<?php
class CustomerController {
    const WALK_IN_ID = 1;

    public static function list() {
        $stmt = Database::getInstance()->query("SELECT * FROM customers ORDER BY name ASC");
        Response::success('Customers retrieved', $stmt->fetchAll());
    }

    public static function create() {
        $input = Request::getBody();
        $name = trim($input['name'] ?? '');
        if ($name === '') Response::error('Customer name is required');
        $db = Database::getInstance();
        $db->prepare("INSERT INTO customers (name, phone, address) VALUES (?, ?, ?)")
           ->execute([$name, trim($input['phone'] ?? '') ?: null, trim($input['address'] ?? '') ?: null]);
        Response::success('Customer created', ['id' => (int)$db->lastInsertId()], 201);
    }

    public static function update($id) {
        $id = (int)$id;
        if ($id === self::WALK_IN_ID) Response::error('Walk-in customer edit nahi ho sakta');
        $input = Request::getBody();
        $name = trim($input['name'] ?? '');
        if ($name === '') Response::error('Customer name is required');
        $st = Database::getInstance()->prepare("UPDATE customers SET name = ?, phone = ?, address = ? WHERE id = ?");
        $st->execute([$name, trim($input['phone'] ?? '') ?: null, trim($input['address'] ?? '') ?: null, $id]);
        Response::success('Customer update ho gaya');
    }

    // POST /customers/{id}/payment : udhaar wasool
    public static function payment($id) {
        $input = Request::getBody();
        self::receive((int)$id, (float)($input['amount'] ?? 0), $input);
    }

    // Purana route /customer-payments (body me customer_id) - index.php badalne tak chalta rahe
    public static function recordPayment() {
        $input = Request::getBody();
        self::receive((int)($input['customer_id'] ?? 0), (float)($input['amount'] ?? 0), $input);
    }

    private static function receive($customerId, $amount, $input) {
        if ($customerId <= 0 || $customerId === self::WALK_IN_ID) Response::error('Sahi customer chunein');
        if ($amount <= 0) Response::error('Raqam 0 se zyada likhein');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT balance FROM customers WHERE id = ? FOR UPDATE");
            $st->execute([$customerId]);
            $c = $st->fetch();
            if (!$c) throw new Exception('Customer nahi mila');
            if ($amount > (float)$c['balance'] + 0.001) throw new Exception('Itni raqam nahi ho sakti, udhaar sirf ' . $c['balance'] . ' hai');

            $db->prepare("INSERT INTO customer_payments (customer_id, amount, method, note) VALUES (?, ?, ?, ?)")
               ->execute([$customerId, $amount, substr($input['method'] ?? 'cash', 0, 20), $input['note'] ?? 'Credit Received']);
            $db->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")->execute([$amount, $customerId]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            Response::error($e->getMessage(), 400);
        }
        Response::success('Payment recorded and balance updated');
    }
}
