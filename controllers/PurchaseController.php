<?php
class PurchaseController {
    // POST /purchases { supplier_id, items:[{product_id, qty, cost}], paid_amount, payment_method, note }
    public static function create() {
        $in = Request::getBody();
        $supplierId = (int)($in['supplier_id'] ?? 0);
        $rawItems = $in['items'] ?? [];
        if ($supplierId <= 0) Response::error('Supplier chunein');
        if (!is_array($rawItems) || !$rawItems) Response::error('Kam az kam aik product likhein');

        $items = [];
        $total = 0.0;
        foreach ($rawItems as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            $cost = (float)($it['cost'] ?? -1);
            if ($pid <= 0 || $qty <= 0 || $cost < 0) Response::error('Har row me product, quantity aur cost sahi likhein');
            if (isset($items[$pid])) Response::error('Aik product do baar aa gaya, rows jama kar dein');
            $items[$pid] = ['product_id' => $pid, 'qty' => $qty, 'cost' => $cost];
            $total += $qty * $cost;
        }
        ksort($items);
        $total = round($total, 2);
        $paid = isset($in['paid_amount']) ? (float)$in['paid_amount'] : $total;
        if ($paid < 0 || $paid > $total + 0.001) Response::error('Ada ki gayi raqam bill se zyada nahi ho sakti');
        $due = round($total - $paid, 2);
        $method = substr(trim($in['payment_method'] ?? 'cash') ?: 'cash', 0, 20);
        $userId = $GLOBALS['auth_user']['id'] ?? null;

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $s = $db->prepare("SELECT id FROM suppliers WHERE id = ? AND is_active = 1");
            $s->execute([$supplierId]);
            if (!$s->fetch()) throw new Exception('Supplier nahi mila');

            $db->prepare("INSERT INTO purchases (supplier_id, total, paid_amount, payment_method, note, user_id) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$supplierId, $total, $paid, $method, trim($in['note'] ?? '') ?: null, $userId]);
            $purchaseId = (int)$db->lastInsertId();

            $pi = $db->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, cost) VALUES (?, ?, ?, ?)");
            $chk = $db->prepare("SELECT id FROM products WHERE id = ? AND is_active = 1 FOR UPDATE");
            $upd = $db->prepare("UPDATE products SET stock = stock + ?, cost = ? WHERE id = ?");
            $mv = $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, reason, source, ref_id, user_id) VALUES (?, 'purchase', ?, ?, 'Purchase', 'purchase', ?, ?)");
            foreach ($items as $it) {
                $chk->execute([$it['product_id']]);
                if (!$chk->fetch()) throw new Exception('Product #' . $it['product_id'] . ' nahi mila');
                $pi->execute([$purchaseId, $it['product_id'], $it['qty'], $it['cost']]);
                $upd->execute([$it['qty'], $it['cost'], $it['product_id']]); // naya cost latest purchase se
                $mv->execute([$it['product_id'], $it['qty'], 'Purchase #' . $purchaseId, $purchaseId, $userId]);
            }

            if ($due > 0) {
                $db->prepare("UPDATE suppliers SET balance = balance + ? WHERE id = ?")->execute([$due, $supplierId]);
            }
            $db->commit();
            Response::success('Purchase save ho gayi', ['id' => $purchaseId, 'total' => $total, 'due' => $due], 201);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error($e->getMessage());
        }
    }
}
