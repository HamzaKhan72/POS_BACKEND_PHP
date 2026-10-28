<?php
class SupplierController {
    public static function list() {
        $rows = Database::getInstance()->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
        Response::success('Suppliers', $rows);
    }

    private static function body() {
        $in = Request::getBody();
        $name = trim($in['name'] ?? '');
        if ($name === '') Response::error('Supplier ka naam likhein');
        return [$name, trim($in['phone'] ?? '') ?: null, trim($in['address'] ?? '') ?: null];
    }

    public static function create() {
        $b = self::body();
        $db = Database::getInstance();
        $db->prepare("INSERT INTO suppliers (name, phone, address) VALUES (?, ?, ?)")->execute($b);
        Response::success('Supplier add ho gaya', ['id' => (int)$db->lastInsertId()], 201);
    }

    public static function update($id) {
        $b = self::body();
        $b[] = (int)$id;
        $st = Database::getInstance()->prepare("UPDATE suppliers SET name = ?, phone = ?, address = ? WHERE id = ? AND is_active = 1");
        $st->execute($b);
        Response::success('Supplier update ho gaya');
    }

    // POST /suppliers/{id}/payment { amount, method, note } : supplier ko raqam ada
    public static function payment($id) {
        $in = Request::getBody();
        $amount = (float)($in['amount'] ?? 0);
        if ($amount <= 0) Response::error('Raqam 0 se zyada likhein');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT balance FROM suppliers WHERE id = ? AND is_active = 1 FOR UPDATE");
            $st->execute([(int)$id]);
            $s = $st->fetch();
            if (!$s) throw new Exception('Supplier nahi mila');
            if ($amount > (float)$s['balance'] + 0.001) throw new Exception('Raqam baqi rakam (' . $s['balance'] . ') se zyada nahi ho sakti');

            $db->prepare("INSERT INTO supplier_payments (supplier_id, amount, method, note, user_id) VALUES (?, ?, ?, ?, ?)")
               ->execute([(int)$id, $amount, substr($in['method'] ?? 'cash', 0, 20), $in['note'] ?? null, $GLOBALS['auth_user']['id'] ?? null]);
            $db->prepare("UPDATE suppliers SET balance = balance - ? WHERE id = ?")->execute([$amount, (int)$id]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error($e->getMessage());
        }
        Response::success('Payment save ho gayi');
    }
}
