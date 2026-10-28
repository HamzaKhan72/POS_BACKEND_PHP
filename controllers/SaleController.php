<?php
class SaleController {
    const WALK_IN_ID = 1;

    // Invoice number: INV-<aaj ki date>-<counter>, jaise INV-20261026-0072.
    // Counter din ke hisab se reset nahi hota, ek hi lagataar counter hai jo INVOICE_START (72) se shuru hota hai.
    // Counter app_settings me 'invoice_next' key me rehta hai (transaction ke andar lock ke saath).
    const INVOICE_START = 72;

    private static function nextInvoiceNo($db) {
        $st = $db->prepare("SELECT v FROM app_settings WHERE k = 'invoice_next' FOR UPDATE");
        $st->execute();
        $cur = $st->fetchColumn();
        $n = max(self::INVOICE_START, (int)$cur);

        // Agar ye number pehle se kisi bill ka hai (purani setting wagera) to aagay barh jao
        $chk = $db->prepare("SELECT 1 FROM sales WHERE invoice_no LIKE ? LIMIT 1");
        do {
            $no = 'INV-' . date('Ymd') . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
            $chk->execute(['INV-%-' . str_pad($n, 4, '0', STR_PAD_LEFT)]);
            $exists = (bool)$chk->fetchColumn();
            if ($exists) $n++;
        } while ($exists);

        $db->prepare("INSERT INTO app_settings (k, v) VALUES ('invoice_next', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
           ->execute([$n + 1]);
        return $no;
    }

    // 'retail' ya 'wholesale'. Baaqi sab kuch retail maana jata hai.
    private static function priceType($input) {
        return (($input['price_type'] ?? 'retail') === 'wholesale') ? 'wholesale' : 'retail';
    }

    // Discount lagne ke baad har item ki asal (net) price kitni reh jati hai: net = price * factor.
    // Taake discount ke zariye bhi minimum price se neechay bechna mumkin na ho.
    private static function discountFactor(array $items, $discount) {
        $sub = 0.0;
        foreach ($items as $it) $sub += $it['price'] * $it['qty'];
        if ($sub <= 0) return 1.0;
        $d = min(max((float)$discount, 0), $sub);
        return ($sub - $d) / $sub;
    }

    // Product ki minimum price (retail ya wholesale wali) se kam price par bill nahi banega.
    private static function assertMinPrice(array $prod, $unitPrice, $factor, $priceType) {
        $min = (float)($priceType === 'wholesale' ? ($prod['min_wholesale_price'] ?? 0) : ($prod['min_retail_price'] ?? 0));
        if ($min <= 0) return;
        $net = round($unitPrice * $factor, 2);
        if ($net < $min - 0.005) {
            $label = $priceType === 'wholesale' ? 'wholesale' : 'retail';
            $fmt = function ($n) { return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); };
            $extra = abs($factor - 1) > 0.00001 ? ' (discount ke baad)' : '';
            throw new Exception("{$prod['name']}: {$label} minimum price {$fmt($min)} hai, bill me {$fmt($net)} ban rahi hai{$extra}. Is se kam par bill nahi ban sakta");
        }
    }

    public static function checkout() {
        $input = Request::getBody();
        $rawItems = $input['items'] ?? [];
        if (!is_array($rawItems) || empty($rawItems)) Response::error('Cart is empty');

        // Aik product do baar aaye to jama kar do
        $items = [];
        foreach ($rawItems as $it) {
            $pid = (int)($it['id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            $price = (float)($it['price'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || $price < 0) Response::error('Cart me ghalat item hai');
            if (isset($items[$pid])) { $items[$pid]['qty'] += $qty; }
            else { $items[$pid] = ['id' => $pid, 'qty' => $qty, 'price' => $price]; }
        }
        ksort($items); // hamesha ek hi tarteeb se lock, taake deadlock na ho

        $subtotal = (float)($input['subtotal'] ?? 0);
        $discount = (float)($input['discount'] ?? 0);
        $tax = (float)($input['tax'] ?? 0);
        $total = (float)($input['total'] ?? 0);
        $paid = (float)($input['paid'] ?? 0);
        if ($total < 0 || $paid < 0 || $discount < 0 || $tax < 0) Response::error('Raqam sahi likhein');
        $due = max(0, round($total - $paid, 2));
        $method = substr(trim($input['payment_method'] ?? 'cash') ?: 'cash', 0, 20);
        $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : self::WALK_IN_ID;
        $userId = $GLOBALS['auth_user']['id'] ?? 1;
        $allowNeg = !empty($input['allow_negative_stock']); // sirf invoice page bhejta hai
        $priceType = self::priceType($input);
        $factor = self::discountFactor($items, $discount);

        if ($due > 0 && $customerId === self::WALK_IN_ID) {
            Response::error('Udhaar ya kam payment ke liye customer chunein');
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $c = $db->prepare("SELECT id FROM customers WHERE id = ?");
            $c->execute([$customerId]);
            if (!$c->fetch()) throw new Exception('Customer nahi mila');

            $invoiceNo = self::nextInvoiceNo($db);
            $db->prepare("INSERT INTO sales (invoice_no, customer_id, user_id, subtotal, discount, tax, total, paid, payment_method, price_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$invoiceNo, $customerId, $userId, $subtotal, $discount, $tax, $total, $paid, $method, $priceType]);
            $saleId = (int)$db->lastInsertId();

            $stmtItem = $db->prepare("INSERT INTO sale_items (sale_id, product_id, name, qty, price, cost) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtStock = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $stmtMove = $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, source, ref_id, user_id) VALUES (?, 'sale', ?, ?, 'sale', ?, ?)");
            $chk = $db->prepare("SELECT name, cost, stock, min_retail_price, min_wholesale_price FROM products WHERE id = ? AND is_active = 1 FOR UPDATE");

            foreach ($items as $it) {
                $chk->execute([$it['id']]);
                $prod = $chk->fetch();
                if (!$prod) throw new Exception("Product #{$it['id']} nahi mila");
                self::assertMinPrice($prod, $it['price'], $factor, $priceType);
                if (!$allowNeg && (float)$prod['stock'] < $it['qty']) throw new Exception("{$prod['name']} ka stock kam hai (maujood: {$prod['stock']})");

                // cost hamesha DB se (browser se nahi), taake profit sahi rahe
                $stmtItem->execute([$saleId, $it['id'], $prod['name'], $it['qty'], $it['price'], $prod['cost']]);
                $stmtStock->execute([$it['qty'], $it['id']]);
                $stmtMove->execute([$it['id'], $it['qty'], 'Invoice ' . $invoiceNo, $saleId, $userId]);
            }

            if ($due > 0) {
                $db->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")->execute([$due, $customerId]);
            }

            $db->commit();
            Response::success('Checkout completed successfully', [
                'sale_id' => $saleId, 'invoice_no' => $invoiceNo, 'total' => $total, 'paid' => $paid, 'due' => $due,
            ], 201);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error('Checkout failed: ' . $e->getMessage(), 400);
        }
    }

    // GET /sales?limit=1000 (default 200, max 5000). Export ke liye bhi yahi.
    public static function list() {
        $limit = max(1, min(5000, (int)Request::getQuery('limit', 200)));
        $db = Database::getInstance();
        $sales = $db->query("SELECT s.*, c.name AS customer_name
                             FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
                             ORDER BY s.id DESC LIMIT $limit")->fetchAll();
        if ($sales) {
            $ids = array_column($sales, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT si.sale_id, si.product_id, si.name, si.qty, si.price, si.cost, si.returned_qty, p.sku
                                FROM sale_items si LEFT JOIN products p ON p.id = si.product_id
                                WHERE si.sale_id IN ($in)");
            $st->execute($ids);
            $by = [];
            foreach ($st->fetchAll() as $it) { $by[$it['sale_id']][] = $it; }
            foreach ($sales as &$s) { $s['items'] = $by[$s['id']] ?? []; }
            unset($s);
        }
        Response::success('Sales fetched', $sales);
    }

    // GET /sales/{id} : bill + items (receipt aur return dialog ke liye)
    public static function show($id) {
        $db = Database::getInstance();
        $st = $db->prepare("SELECT s.*, c.name AS customer_name
                            FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?");
        $st->execute([(int)$id]);
        $sale = $st->fetch();
        if (!$sale) Response::error('Bill nahi mila', 404);

        $it = $db->prepare("SELECT product_id, name, qty, price, returned_qty FROM sale_items WHERE sale_id = ?");
        $it->execute([$sale['id']]);
        $sale['items'] = $it->fetchAll();
        $sale['due'] = max(0, round((float)$sale['total'] - (float)$sale['paid'], 2));
        Response::success('Sale fetched', $sale);
    }

    // PUT /sales/{id}  { items:[{id,qty,price}], customer_id, payment_method, discount, tax, subtotal, total, paid }
    // Bill edit: purani items ka stock wapas, nayi items ka stock minus, customer ka udhaar theek.
    // Invoice number aur date wahi rehte hain. Return ho chuka bill edit nahi hota.
    public static function update($id) {
        $id = (int)$id;
        $input = Request::getBody();
        $rawItems = $input['items'] ?? [];
        if (!is_array($rawItems) || empty($rawItems)) Response::error('Bill me kam az kam ek item hona chahiye');

        $items = [];
        foreach ($rawItems as $it) {
            $pid = (int)($it['id'] ?? $it['product_id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            $price = (float)($it['price'] ?? 0);
            if ($pid <= 0 || $qty <= 0 || $price < 0) Response::error('Bill me ghalat item hai');
            if (isset($items[$pid])) { $items[$pid]['qty'] += $qty; }
            else { $items[$pid] = ['id' => $pid, 'qty' => $qty, 'price' => $price]; }
        }
        ksort($items);

        $subtotal = (float)($input['subtotal'] ?? 0);
        $discount = (float)($input['discount'] ?? 0);
        $tax = (float)($input['tax'] ?? 0);
        $total = (float)($input['total'] ?? 0);
        $paid = (float)($input['paid'] ?? 0);
        if ($total < 0 || $paid < 0 || $discount < 0 || $tax < 0) Response::error('Raqam sahi likhein');
        $paid = min($paid, $total);
        $due = max(0, round($total - $paid, 2));
        $method = substr(trim($input['payment_method'] ?? 'cash') ?: 'cash', 0, 20);
        $customerId = !empty($input['customer_id']) ? (int)$input['customer_id'] : self::WALK_IN_ID;
        $userId = $GLOBALS['auth_user']['id'] ?? 1;
        $allowNeg = !empty($input['allow_negative_stock']); // sirf invoice page bhejta hai
        $priceType = self::priceType($input);
        $factor = self::discountFactor($items, $discount);

        if ($due > 0 && $customerId === self::WALK_IN_ID) {
            Response::error('Udhaar ya kam payment ke liye customer chunein');
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $sale = $st->fetch();
            if (!$sale) throw new Exception('Bill nahi mila');
            if ((float)$sale['returned_amount'] > 0 || in_array($sale['status'], ['returned', 'partial', 'partial_return'], true)) {
                throw new Exception('Is bill par return ho chuka hai, ab edit nahi ho sakta');
            }

            $c = $db->prepare("SELECT id FROM customers WHERE id = ?");
            $c->execute([$customerId]);
            if (!$c->fetch()) throw new Exception('Customer nahi mila');

            // Purani items: stock wapas, purani cost yaad rakho (profit na bigray)
            $oldSt = $db->prepare("SELECT product_id, name, qty, cost FROM sale_items WHERE sale_id = ?");
            $oldSt->execute([$id]);
            $old = [];
            $restore = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            foreach ($oldSt->fetchAll() as $o) {
                $old[(int)$o['product_id']] = $o;
                $restore->execute([$o['qty'], $o['product_id']]);
            }
            $db->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM stock_movements WHERE source = 'sale' AND ref_id = ?")->execute([$id]);

            // Purana udhaar purane customer se hatao
            $oldDue = max(0, round((float)$sale['total'] - (float)$sale['paid'], 2));
            if ($oldDue > 0) {
                $db->prepare("UPDATE customers SET balance = GREATEST(0, balance - ?) WHERE id = ?")->execute([$oldDue, $sale['customer_id']]);
            }

            $stmtItem = $db->prepare("INSERT INTO sale_items (sale_id, product_id, name, qty, price, cost) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtStock = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $stmtMove = $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, source, ref_id, user_id) VALUES (?, 'sale', ?, ?, 'sale', ?, ?)");
            $chk = $db->prepare("SELECT name, cost, stock, is_active, min_retail_price, min_wholesale_price FROM products WHERE id = ? FOR UPDATE");

            foreach ($items as $it) {
                $chk->execute([$it['id']]);
                $prod = $chk->fetch();
                if (!$prod) throw new Exception("Product #{$it['id']} nahi mila");
                if (!(int)$prod['is_active'] && !isset($old[$it['id']])) throw new Exception("{$prod['name']} ab active nahi hai");
                self::assertMinPrice($prod, $it['price'], $factor, $priceType);
                if (!$allowNeg && (float)$prod['stock'] < $it['qty']) throw new Exception("{$prod['name']} ka stock kam hai (maujood: {$prod['stock']})");

                $name = $old[$it['id']]['name'] ?? $prod['name'];
                $cost = $old[$it['id']]['cost'] ?? $prod['cost'];
                $stmtItem->execute([$id, $it['id'], $name, $it['qty'], $it['price'], $cost]);
                $stmtStock->execute([$it['qty'], $it['id']]);
                $stmtMove->execute([$it['id'], $it['qty'], 'Invoice ' . $sale['invoice_no'] . ' (edited)', $id, $userId]);
            }

            if ($due > 0) {
                $db->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")->execute([$due, $customerId]);
            }

            $db->prepare("UPDATE sales SET customer_id = ?, subtotal = ?, discount = ?, tax = ?, total = ?, paid = ?, payment_method = ?, price_type = ? WHERE id = ?")
               ->execute([$customerId, $subtotal, $discount, $tax, $total, $paid, $method, $priceType, $id]);

            $db->commit();
            Response::success('Bill update ho gaya', ['sale_id' => $id, 'invoice_no' => $sale['invoice_no'], 'total' => $total, 'paid' => $paid, 'due' => $due]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error($e->getMessage(), 400);
        }
    }

    // POST /sales/{id}/return  { items:[{product_id, qty}], refund_method, reason }
    public static function returnItems($id) {
        $id = (int)$id;
        $input = Request::getBody();
        $items = $input['items'] ?? [];
        if (!is_array($items) || !$items) Response::error('Wapas hone wali quantity likhein');
        $method = in_array($input['refund_method'] ?? 'cash', ['cash', 'bank', 'balance'], true) ? $input['refund_method'] : 'cash';
        $reason = trim($input['reason'] ?? '') ?: null;
        $userId = $GLOBALS['auth_user']['id'] ?? null;

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $sale = $st->fetch();
            if (!$sale) throw new Exception('Bill nahi mila');
            $hasCustomer = (int)$sale['customer_id'] > self::WALK_IN_ID;
            if ($method === 'balance' && !$hasCustomer) throw new Exception('Walk-in bill par udhaar me kami nahi ho sakti');

            $find = $db->prepare("SELECT id, qty, price, returned_qty FROM sale_items WHERE sale_id = ? AND product_id = ? FOR UPDATE");
            $markRet = $db->prepare("UPDATE sale_items SET returned_qty = returned_qty + ? WHERE id = ?");
            $addStock = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $move = $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, reason, source, ref_id, user_id) VALUES (?, 'return', ?, ?, 'Sale return', 'return', ?, ?)");

            $refund = 0.0;
            $lines = [];
            foreach ($items as $it) {
                $pid = (int)($it['product_id'] ?? 0);
                $qty = (float)($it['qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;
                $find->execute([$id, $pid]);
                $row = $find->fetch();
                if (!$row) throw new Exception('Is bill me ye product nahi hai');
                $left = (float)$row['qty'] - (float)$row['returned_qty'];
                if ($qty > $left + 0.0001) throw new Exception('Wapas hone wali quantity bill se zyada hai');

                $markRet->execute([$qty, $row['id']]);
                $addStock->execute([$qty, $pid]);
                $move->execute([$pid, $qty, 'Return of ' . $sale['invoice_no'], $id, $userId]);
                $refund += $qty * (float)$row['price'];
                $lines[] = [$pid, $qty, $row['price']];
            }
            if (!$lines) throw new Exception('Wapas hone wali quantity likhein');
            $refund = round($refund, 2);

            $db->prepare("INSERT INTO sale_returns (sale_id, refund_amount, refund_method, reason, user_id) VALUES (?, ?, ?, ?, ?)")
               ->execute([$id, $refund, $method, $reason, $userId]);
            $retId = (int)$db->lastInsertId();
            $li = $db->prepare("INSERT INTO sale_return_items (return_id, product_id, qty, price) VALUES (?, ?, ?, ?)");
            foreach ($lines as $l) { $li->execute([$retId, $l[0], $l[1], $l[2]]); }

            // Bill ki halat: sab wapas ho gaya ya kuch
            $left = $db->prepare("SELECT COALESCE(SUM(qty - returned_qty), 0) FROM sale_items WHERE sale_id = ?");
            $left->execute([$id]);
            $status = (float)$left->fetchColumn() <= 0.0001 ? 'returned' : 'partial';
            $db->prepare("UPDATE sales SET returned_amount = returned_amount + ?, status = ? WHERE id = ?")->execute([$refund, $status, $id]);

            if ($method === 'balance') {
                $db->prepare("UPDATE customers SET balance = GREATEST(0, balance - ?) WHERE id = ?")->execute([$refund, $sale['customer_id']]);
            }

            $db->commit();
            Response::success('Return ho gaya', ['refund_amount' => $refund, 'status' => $status]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error($e->getMessage(), 400);
        }
    }

    // DELETE /sales/{id} : bill mita deta hai. Bache hue (wapas na hue) items ka stock wapas aata hai
    // aur customer ka udhaar is bill ke hisab se kam hota hai.
    public static function delete($id) {
        $id = (int)$id;
        $userId = $GLOBALS['auth_user']['id'] ?? null;
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $sale = $st->fetch();
            if (!$sale) throw new Exception('Bill nahi mila');

            // Delete se pehle poore bill ki copy mehfooz karo (Excel me dekhne/export ke liye)
            $snap = self::snapshot($db, $sale);
            $uname = $GLOBALS['auth_user']['username'] ?? null;
            $db->prepare("INSERT INTO deleted_invoices (sale_id, invoice_no, deleted_by, deleted_name, data) VALUES (?, ?, ?, ?, ?)")
               ->execute([$id, $sale['invoice_no'], $userId, $uname, json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
            $archiveId = (int)$db->lastInsertId();
            $archived = ['id' => $archiveId, 'sale_id' => $id, 'invoice_no' => $sale['invoice_no'], 'deleted_at' => date('Y-m-d H:i:s'), 'deleted_name' => $uname, 'data' => $snap];

            $it = $db->prepare("SELECT product_id, qty, returned_qty FROM sale_items WHERE sale_id = ? FOR UPDATE");
            $it->execute([$id]);
            $addStock = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $move = $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, reason, source, user_id) VALUES (?, 'return', ?, ?, 'Invoice deleted', 'return', ?)");
            foreach ($it->fetchAll() as $row) {
                $back = (float)$row['qty'] - (float)$row['returned_qty'];
                if ($back > 0.0001 && $row['product_id']) {
                    $addStock->execute([$back, $row['product_id']]);
                    $move->execute([$row['product_id'], $back, 'Deleted ' . $sale['invoice_no'], $userId]);
                }
            }

            if ((int)$sale['customer_id'] > self::WALK_IN_ID) {
                $due = max(0, (float)$sale['total'] - (float)$sale['paid']);
                $rb = $db->prepare("SELECT COALESCE(SUM(refund_amount), 0) FROM sale_returns WHERE sale_id = ? AND refund_method = 'balance'");
                $rb->execute([$id]);
                $reduce = max(0, $due - (float)$rb->fetchColumn());
                if ($reduce > 0) {
                    $db->prepare("UPDATE customers SET balance = GREATEST(0, balance - ?) WHERE id = ?")->execute([$reduce, $sale['customer_id']]);
                }
            }

            $db->prepare("DELETE sri FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id WHERE sr.sale_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM sale_returns WHERE sale_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM sales WHERE id = ?")->execute([$id]);

            $db->commit();
            Response::success('Bill delete ho gaya', ['archived' => $archived]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error($e->getMessage(), 400);
        }
    }

    // Bill + items + returns ki poori tasveer (customer ka naam, sku wagera ke saath)
    private static function snapshot($db, array $sale) {
        $c = $db->prepare("SELECT name FROM customers WHERE id = ?");
        $c->execute([$sale['customer_id']]);
        $sale['customer_name'] = $c->fetchColumn() ?: 'Walk-in Customer';

        $it = $db->prepare("SELECT si.product_id, si.name, si.qty, si.price, si.cost, si.returned_qty, p.sku
                            FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?");
        $it->execute([$sale['id']]);
        $sale['items'] = $it->fetchAll();
        return $sale;
    }

    // GET /sales/deleted?limit=1000 : delete hue bills (naye pehle). Excel export isi se banta hai.
    public static function listDeleted() {
        $limit = max(1, min(5000, (int)Request::getQuery('limit', 1000)));
        $rows = Database::getInstance()->query("SELECT id, sale_id, invoice_no, deleted_by, deleted_name, deleted_at, data
                                                FROM deleted_invoices ORDER BY id DESC LIMIT $limit")->fetchAll();
        foreach ($rows as &$r) {
            $r['data'] = json_decode($r['data'], true) ?: [];
        }
        unset($r);
        Response::success('Deleted invoices fetched', $rows);
    }
}