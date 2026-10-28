<?php
class ProductController {
    private static function fields($in, $partialFrom = null) {
        $name = trim($in['name'] ?? '');
        if ($name === '') Response::error('Product ka naam likhein');
        $price = (float)($in['price'] ?? 0);
        $cost = (float)($in['cost'] ?? 0);
        $stock = (float)($in['stock'] ?? 0);
        if ($price < 0 || $cost < 0) Response::error('Cost aur price sahi likhein');
        $wholesale = (float)($in['wholesale_price'] ?? 0);
        $minRetail = (float)($in['min_retail_price'] ?? 0);
        $minWholesale = (float)($in['min_wholesale_price'] ?? 0);
        if ($wholesale < 0 || $minRetail < 0 || $minWholesale < 0) Response::error('Wholesale aur minimum price sahi likhein');
        if ($price > 0 && $minRetail > $price + 0.0001) Response::error('Minimum retail price, retail price se zyada nahi ho sakti');
        if ($wholesale > 0 && $minWholesale > $wholesale + 0.0001) Response::error('Minimum wholesale price, wholesale price se zyada nahi ho sakti');
        if ($stock < 0) Response::error('Stock sahi likhein');
        $expiry = trim($in['expiry_date'] ?? '');
        if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) Response::error('Expiry date sahi likhein');
        $warranty = trim($in['warranty_date'] ?? '');
        if ($warranty !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $warranty)) Response::error('Warranty date sahi likhein');
        $serial = trim($in['serial_no'] ?? '');
        if (mb_strlen($serial) > 100) Response::error('Serial number bohat lamba hai');
        return [
            'name' => $name,
            'barcode' => trim($in['barcode'] ?? '') ?: null,
            'sku' => trim($in['sku'] ?? '') ?: null,
            'serial_no' => $serial ?: null,
            'category_id' => !empty($in['category_id']) ? (int)$in['category_id'] : null,
            'brand' => trim($in['brand'] ?? '') ?: null,
            'unit' => trim($in['unit'] ?? '') ?: 'pcs',
            'cost' => $cost, 'price' => $price, 'stock' => $stock,
            'wholesale_price' => $wholesale, 'min_retail_price' => $minRetail, 'min_wholesale_price' => $minWholesale,
            'min_stock' => (float)($in['min_stock'] ?? 5),
            'expiry_date' => $expiry ?: null,
            'warranty_date' => $warranty ?: null,
        ];
    }

    private static function dupMessage(PDOException $e) {
        if ($e->getCode() === '23000') Response::error('Ye barcode ya SKU pehle se kisi aur product ka hai');
        throw $e;
    }

    public static function list() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT p.*, c.name AS category_name
                            FROM products p LEFT JOIN categories c ON p.category_id = c.id
                            WHERE p.is_active = 1 ORDER BY p.id DESC");
        Response::success('Products fetched', $stmt->fetchAll());
    }

    public static function create() {
        $in = Request::getBody();
        $f = self::fields($in);
        $image = isset($in['image']) && $in['image'] !== '' ? (string)$in['image'] : null;
        if ($image !== null && strlen($image) > 3 * 1024 * 1024) Response::error('Tasveer bohat bari hai');
        $userId = $GLOBALS['auth_user']['id'] ?? null;

        $db = Database::getInstance();
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO products (name, barcode, sku, serial_no, category_id, brand, unit, cost, price, wholesale_price, min_retail_price, min_wholesale_price, stock, min_stock, expiry_date, warranty_date, image)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$f['name'], $f['barcode'], $f['sku'], $f['serial_no'], $f['category_id'], $f['brand'], $f['unit'], $f['cost'], $f['price'], $f['wholesale_price'], $f['min_retail_price'], $f['min_wholesale_price'], $f['stock'], $f['min_stock'], $f['expiry_date'], $f['warranty_date'], $image]);
            $id = (int)$db->lastInsertId();
            if ($f['stock'] > 0) {
                $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, reason, source, user_id) VALUES (?, 'in', ?, 'Initial stock', 'Initial stock', 'manual', ?)")
                   ->execute([$id, $f['stock'], $userId]);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            self::dupMessage($e);
        }
        Response::success('Product created successfully', ['id' => $id], 201);
    }

    public static function update($id) {
        $id = (int)$id;
        $in = Request::getBody();
        $db = Database::getInstance();
        $st = $db->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1");
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) Response::error('Product nahi mila', 404);

        $f = self::fields($in);
        $userId = $GLOBALS['auth_user']['id'] ?? null;
        $sql = "UPDATE products SET name=?, barcode=?, sku=?, serial_no=?, category_id=?, brand=?, unit=?, cost=?, price=?, wholesale_price=?, min_retail_price=?, min_wholesale_price=?, stock=?, min_stock=?, expiry_date=?, warranty_date=?";
        $args = [$f['name'], $f['barcode'], $f['sku'], $f['serial_no'], $f['category_id'], $f['brand'], $f['unit'], $f['cost'], $f['price'], $f['wholesale_price'], $f['min_retail_price'], $f['min_wholesale_price'], $f['stock'], $f['min_stock'], $f['expiry_date'], $f['warranty_date']];

        // image tabhi badalti hai jab frontend ne bheji ho ('' = hata di)
        if (array_key_exists('image', $in)) {
            $img = $in['image'] === '' || $in['image'] === null ? null : (string)$in['image'];
            if ($img !== null && strlen($img) > 3 * 1024 * 1024) Response::error('Tasveer bohat bari hai');
            $sql .= ", image=?";
            $args[] = $img;
        }
        $sql .= " WHERE id=?";
        $args[] = $id;

        try {
            $db->beginTransaction();
            $db->prepare($sql)->execute($args);
            $delta = $f['stock'] - (float)$old['stock'];
            if (abs($delta) > 0.0001) {
                $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, reason, source, user_id) VALUES (?, 'adjust', ?, 'Edited from product form', 'Count correction', 'manual', ?)")
                   ->execute([$id, $delta, $userId]);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            self::dupMessage($e);
        }
        Response::success('Product update ho gaya');
    }

    // POST /products/import  { mode: 'skip'|'update', items: [ {row, name, category, brand, sku, barcode, unit, cost, price, stock, min_stock, expiry_date, image} ] }
    // Har row alag savepoint me chalti hai: ek row kharab ho to baaqi save ho jati hain.
    // Maujood product ki pehchan: barcode, phir SKU, aur dono na hon to bilkul wahi naam.
    // Update me khali (null/'') maanon se purani value nahi mitti.
    const IMPORT_MAX_ROWS = 500;

    private static function blank($v) { return $v === null || trim((string)$v) === ''; }

    public static function import() {
        $in = Request::getBody();
        $mode = ($in['mode'] ?? 'skip') === 'update' ? 'update' : 'skip';
        $items = $in['items'] ?? [];
        if (!is_array($items) || !$items) Response::error('Import ke liye koi row nahi mili');
        if (count($items) > self::IMPORT_MAX_ROWS) Response::error('Ek baar me ' . self::IMPORT_MAX_ROWS . ' se zyada rows nahi ja sakti');
        $userId = $GLOBALS['auth_user']['id'] ?? null;

        $db = Database::getInstance();
        $byBarcode = $db->prepare("SELECT id, stock FROM products WHERE is_active = 1 AND barcode = ? LIMIT 1");
        $bySku     = $db->prepare("SELECT id, stock FROM products WHERE is_active = 1 AND sku = ? LIMIT 1");
        $byName    = $db->prepare("SELECT id, stock FROM products WHERE is_active = 1 AND name = ? LIMIT 1");
        $move = $db->prepare("INSERT INTO stock_movements (product_id, type, qty, note, reason, source, user_id) VALUES (?, ?, ?, ?, 'Excel import', 'manual', ?)");

        $catCache = [];
        $results = [];
        $count = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0];

        $db->beginTransaction();
        try {
            foreach ($items as $i => $it) {
                $row = (int)($it['row'] ?? ($i + 1));
                $sp = 'imp_' . $i;
                $db->exec("SAVEPOINT $sp");
                try {
                    $name = trim((string)($it['name'] ?? ''));
                    if ($name === '') throw new Exception('Naam khali hai');
                    if (mb_strlen($name) > 255) throw new Exception('Naam bohat lamba hai');

                    $barcode = self::blank($it['barcode'] ?? null) ? null : trim((string)$it['barcode']);
                    $sku     = self::blank($it['sku'] ?? null) ? null : trim((string)$it['sku']);

                    foreach (['cost', 'price', 'wholesale_price', 'min_retail_price', 'min_wholesale_price', 'stock', 'min_stock'] as $k) {
                        if (!self::blank($it[$k] ?? null) && (!is_numeric($it[$k]) || (float)$it[$k] < 0)) {
                            throw new Exception("$k sahi number nahi hai");
                        }
                    }
                    $expiry = self::blank($it['expiry_date'] ?? null) ? null : trim((string)$it['expiry_date']);
                    if ($expiry !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) throw new Exception('Expiry date YYYY-MM-DD me likhein');

                    $image = self::blank($it['image'] ?? null) ? null : (string)$it['image'];
                    if ($image !== null) {
                        if (!preg_match('#^(data:image/|https?://)#i', $image)) throw new Exception('Tasveer ka format sahi nahi');
                        if (strlen($image) > 3 * 1024 * 1024) throw new Exception('Tasveer bohat bari hai');
                    }

                    // Category: naam se, na ho to ban jati hai (ek hi category me kitne bhi products)
                    $catId = null;
                    if (!self::blank($it['category'] ?? null)) {
                        $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$it['category'])));
                        if (!array_key_exists($key, $catCache)) $catCache[$key] = CategoryController::findOrCreate($db, $it['category']);
                        $catId = $catCache[$key];
                    }

                    // Maujood product?
                    $found = null;
                    if ($barcode !== null) { $byBarcode->execute([$barcode]); $found = $byBarcode->fetch(); }
                    if (!$found && $sku !== null) { $bySku->execute([$sku]); $found = $bySku->fetch(); }
                    if (!$found && $barcode === null && $sku === null) { $byName->execute([$name]); $found = $byName->fetch(); }

                    if ($found) {
                        if ($mode === 'skip') {
                            $count['skipped']++;
                            $results[] = ['row' => $row, 'status' => 'skipped', 'message' => 'Pehle se maujood hai'];
                            $db->exec("RELEASE SAVEPOINT $sp");
                            continue;
                        }
                        $set = ['name = ?']; $args = [$name];
                        $opt = [
                            'category_id' => $catId, 'brand' => self::blank($it['brand'] ?? null) ? null : trim((string)$it['brand']),
                            'unit' => self::blank($it['unit'] ?? null) ? null : trim((string)$it['unit']),
                            'cost' => self::blank($it['cost'] ?? null) ? null : (float)$it['cost'],
                            'price' => self::blank($it['price'] ?? null) ? null : (float)$it['price'],
                            'wholesale_price' => self::blank($it['wholesale_price'] ?? null) ? null : (float)$it['wholesale_price'],
                            'min_retail_price' => self::blank($it['min_retail_price'] ?? null) ? null : (float)$it['min_retail_price'],
                            'min_wholesale_price' => self::blank($it['min_wholesale_price'] ?? null) ? null : (float)$it['min_wholesale_price'],
                            'min_stock' => self::blank($it['min_stock'] ?? null) ? null : (float)$it['min_stock'],
                            'expiry_date' => $expiry, 'image' => $image,
                        ];
                        foreach ($opt as $col => $val) { if ($val !== null) { $set[] = "$col = ?"; $args[] = $val; } }
                        // barcode/SKU sirf tab likhein jab product dusri cheez se mila ho
                        if ($barcode !== null) { $set[] = 'barcode = ?'; $args[] = $barcode; }
                        if ($sku !== null)     { $set[] = 'sku = ?';     $args[] = $sku; }
                        $args[] = $found['id'];
                        $db->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);

                        if (!self::blank($it['stock'] ?? null)) {
                            $delta = (float)$it['stock'] - (float)$found['stock'];
                            if (abs($delta) > 0.0001) {
                                $db->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([(float)$it['stock'], $found['id']]);
                                $move->execute([$found['id'], 'adjust', $delta, 'Excel import (stock replace)', $userId]);
                            }
                        }
                        $count['updated']++;
                        $results[] = ['row' => $row, 'status' => 'updated', 'message' => 'Update ho gaya'];
                    } else {
                        $stock = self::blank($it['stock'] ?? null) ? 0.0 : (float)$it['stock'];
                        $db->prepare("INSERT INTO products (name, barcode, sku, category_id, brand, unit, cost, price, wholesale_price, min_retail_price, min_wholesale_price, stock, min_stock, expiry_date, image)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                           ->execute([
                               $name, $barcode, $sku, $catId,
                               self::blank($it['brand'] ?? null) ? null : trim((string)$it['brand']),
                               self::blank($it['unit'] ?? null) ? 'pcs' : trim((string)$it['unit']),
                               self::blank($it['cost'] ?? null) ? 0 : (float)$it['cost'],
                               self::blank($it['price'] ?? null) ? 0 : (float)$it['price'],
                               self::blank($it['wholesale_price'] ?? null) ? 0 : (float)$it['wholesale_price'],
                               self::blank($it['min_retail_price'] ?? null) ? 0 : (float)$it['min_retail_price'],
                               self::blank($it['min_wholesale_price'] ?? null) ? 0 : (float)$it['min_wholesale_price'],
                               $stock,
                               self::blank($it['min_stock'] ?? null) ? 5 : (float)$it['min_stock'],
                               $expiry, $image,
                           ]);
                        $pid = (int)$db->lastInsertId();
                        if ($stock > 0) $move->execute([$pid, 'in', $stock, 'Initial stock (Excel import)', $userId]);
                        $count['created']++;
                        $results[] = ['row' => $row, 'status' => 'created', 'message' => 'Naya product'];
                    }
                    $db->exec("RELEASE SAVEPOINT $sp");
                } catch (PDOException $e) {
                    $db->exec("ROLLBACK TO SAVEPOINT $sp");
                    $msg = $e->getCode() === '23000' ? 'Ye barcode ya SKU kisi aur product ka hai' : 'Database error: ' . $e->getMessage();
                    $count['error']++;
                    $results[] = ['row' => $row, 'status' => 'error', 'message' => $msg];
                } catch (Exception $e) {
                    $db->exec("ROLLBACK TO SAVEPOINT $sp");
                    $count['error']++;
                    $results[] = ['row' => $row, 'status' => 'error', 'message' => $e->getMessage()];
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::error('Import fail: ' . $e->getMessage(), 500);
        }

        Response::success('Import mukammal', ['summary' => $count, 'results' => $results]);
    }

    // Soft delete: purane bills theek rehte hain. Barcode/SKU khali kar dete hain taake dobara istemal ho saken.
    // Product delete (soft). Agar is product ke baad us category me koi aur active product nahi bacha
    // to category bhi saath hi delete ho jati hai. Jis category me aur products hon wo rehti hai.
    public static function delete($id) {
        $id = (int)$id;
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT category_id FROM products WHERE id = ? AND is_active = 1 FOR UPDATE");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) { $db->rollBack(); Response::error('Product nahi mila', 404); }
            $catId = $row['category_id'] ? (int)$row['category_id'] : null;

            $db->prepare("UPDATE products SET is_active = 0, barcode = NULL, sku = NULL WHERE id = ?")->execute([$id]);

            if ($catId) {
                $left = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ? AND is_active = 1");
                $left->execute([$catId]);
                if ((int)$left->fetchColumn() === 0) {
                    // purane (delete ho chuke) products ka category link hata do, phir category mita do
                    $db->prepare("UPDATE products SET category_id = NULL WHERE category_id = ?")->execute([$catId]);
                    $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$catId]);
                }
            }
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        Response::success('Product delete ho gaya');
    }
}