<?php
class RegisterController {
    private static function scalar($db, $sql, $args) {
        $st = $db->prepare($sql);
        $st->execute($args);
        return (float)$st->fetchColumn();
    }

    private static function openSession($db) {
        return $db->query("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    }

    // Session shuru hone ke baad drawer me aane/jaane wali cash ka hisaab
    private static function summary($db, $s) {
        $since = [$s['opened_at']];
        $parts = [
            'cash_sales' => self::scalar($db, "SELECT COALESCE(SUM(LEAST(paid, total)), 0) FROM sales WHERE payment_method = 'cash' AND created_at >= ?", $since),
            'customer_payments' => self::scalar($db, "SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE method = 'cash' AND created_at >= ?", $since),
            'cash_in' => self::scalar($db, "SELECT COALESCE(SUM(amount), 0) FROM cash_movements WHERE type = 'in' AND session_id = ?", [$s['id']]),
            'cash_out' => self::scalar($db, "SELECT COALESCE(SUM(amount), 0) FROM cash_movements WHERE type = 'out' AND session_id = ?", [$s['id']]),
            'refunds' => self::scalar($db, "SELECT COALESCE(SUM(refund_amount), 0) FROM sale_returns WHERE refund_method = 'cash' AND created_at >= ?", $since),
            'expenses' => self::scalar($db, "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE method = 'cash' AND created_at >= ?", $since),
            'supplier_payments' =>
                self::scalar($db, "SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE method = 'cash' AND created_at >= ?", $since)
              + self::scalar($db, "SELECT COALESCE(SUM(paid_amount), 0) FROM purchases WHERE payment_method = 'cash' AND created_at >= ?", $since),
        ];
        $expected = (float)$s['opening']
            + $parts['cash_sales'] + $parts['customer_payments'] + $parts['cash_in']
            - $parts['cash_out'] - $parts['refunds'] - $parts['expenses'] - $parts['supplier_payments'];
        $parts['expected'] = round($expected, 2);
        return $parts;
    }

    public static function status() {
        $db = Database::getInstance();
        $s = self::openSession($db);
        Response::success('Register status', ['session' => $s, 'summary' => $s ? self::summary($db, $s) : (object)[]]);
    }

    public static function open() {
        $db = Database::getInstance();
        if (self::openSession($db)) Response::error('Register pehle se khula hai');
        $opening = (float)(Request::getBody()['opening'] ?? 0);
        if ($opening < 0) Response::error('Opening cash sahi likhein');
        $userId = $GLOBALS['auth_user']['id'] ?? null;
        $db->prepare("INSERT INTO cash_sessions (user_id, opening, status) VALUES (?, ?, 'open')")->execute([$userId, $opening]);
        Response::success('Register opened', ['id' => (int)$db->lastInsertId()], 201);
    }

    // POST /register/movement { session_id, type: in|out, amount, reason }
    public static function movement() {
        $in = Request::getBody();
        $db = Database::getInstance();
        $s = self::openSession($db);
        if (!$s) Response::error('Register khula nahi hai');
        if (!empty($in['session_id']) && (int)$in['session_id'] !== (int)$s['id']) Response::error('Ye session band ho chuka hai');

        $type = $in['type'] ?? '';
        $amount = (float)($in['amount'] ?? 0);
        $reason = trim($in['reason'] ?? '');
        if (!in_array($type, ['in', 'out'], true)) Response::error('Type in ya out hona chahiye');
        if ($amount <= 0) Response::error('Raqam 0 se zyada likhein');
        if ($reason === '') Response::error('Wajah likhein');
        if ($type === 'out' && $amount > self::summary($db, $s)['expected'] + 0.001) Response::error('Drawer me itni cash nahi hai');

        $db->prepare("INSERT INTO cash_movements (session_id, type, amount, reason, user_id) VALUES (?, ?, ?, ?, ?)")
           ->execute([$s['id'], $type, $amount, $reason, $GLOBALS['auth_user']['id'] ?? null]);
        Response::success('Cash movement save ho gayi');
    }

    public static function close() {
        $in = Request::getBody();
        $db = Database::getInstance();
        $s = self::openSession($db);
        if (!$s) Response::error('Koi register khula nahi hai');
        if (!isset($in['closing']) || !is_numeric($in['closing']) || (float)$in['closing'] < 0) Response::error('Gini hui cash sahi likhein');

        $closing = (float)$in['closing'];
        $expected = self::summary($db, $s)['expected']; // hamesha server ka hisaab
        $diff = round($closing - $expected, 2);
        $note = trim($in['note'] ?? '') ?: null;

        $db->prepare("UPDATE cash_sessions SET closing = ?, expected = ?, difference = ?, note = ?, status = 'closed', closed_at = NOW() WHERE id = ?")
           ->execute([$closing, $expected, $diff, $note, $s['id']]);
        Response::success('Register closed', ['expected' => $expected, 'closing' => $closing, 'difference' => $diff]);
    }

    public static function sessions() {
        $rows = Database::getInstance()->query("SELECT cs.*, u.name AS user_name
                                                FROM cash_sessions cs LEFT JOIN users u ON u.id = cs.user_id
                                                ORDER BY cs.id DESC LIMIT 30")->fetchAll();
        Response::success('Register sessions', $rows);
    }
}
