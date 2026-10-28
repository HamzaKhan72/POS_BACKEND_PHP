<?php
class InventoryController {
    // GET /inventory/movements : aakhri 300
    public static function movements() {
        $rows = Database::getInstance()->query(
            "SELECT m.id, m.product_id, p.name AS product_name, m.type, m.qty,
                    COALESCE(NULLIF(m.reason, ''), m.note) AS reason, m.note,
                    u.name AS user_name, m.created_at
             FROM stock_movements m
             LEFT JOIN products p ON p.id = m.product_id
             LEFT JOIN users u ON u.id = m.user_id
             ORDER BY m.id DESC LIMIT 300")->fetchAll();
        Response::success('Stock movements', $rows);
    }

    // POST /inventory/adjust { product_id, type: in|adjust, qty (farq), new_stock, reason, note }
    public static function adjust() {
        $in = Request::getBody();
        $pid = (int)($in['product_id'] ?? 0);
        $type = $in['type'] ?? '';
        $reason = trim($in['reason'] ?? '') ?: ($type === 'in' ? 'Stock in' : 'Adjustment');
        $note = trim($in['note'] ?? '') ?: null;
        if ($pid <= 0 || !in_array($type, ['in', 'adjust'], true)) Response::error('Product aur type sahi likhein');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT name, stock FROM products WHERE id = ? AND is_active = 1 FOR UPDATE");
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p) throw new Exception('Product nahi mila');
            $current = (float)$p['stock'];

            if ($type === 'in') {
                $delta = (float)($in['qty'] ?? 0);
                if ($delta <= 0) throw new Exception('Stock In me quantity 0 se zyada honi chahiye');
                $new = $current + $delta;
            } else {
                if (!isset($in['new_stock']) || !is_numeric($in['new_stock']) || (float)$in['new_stock'] < 0) throw new Exception('Asal ginti sahi likhein');
                $new = (float)$in['new_stock'];
                $delta = $new - $current; // hamesha server ke stock se hisaab
                if (abs($delta) < 0.0001) throw new Exception('Ginti pehle jaisi hi hai, koi badlav nahi');
            }

            $db->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$new, $pid]);
            $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, reason, source, user_id) VALUES (?, ?, ?, ?, ?, 'manual', ?)")
               ->execute([$pid, $type, $delta, $note, $reason, $GLOBALS['auth_user']['id'] ?? null]);
            $db->commit();
            Response::success('Stock update ho gaya', ['stock' => $new]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error($e->getMessage());
        }
    }
}
