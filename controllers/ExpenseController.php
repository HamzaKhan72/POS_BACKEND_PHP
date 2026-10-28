<?php
class ExpenseController {
    private static function validDate($d) {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    // GET /expenses?from=YYYY-MM-DD&to=YYYY-MM-DD
    public static function list() {
        $from = Request::getQuery('from', date('Y-m-01'));
        $to = Request::getQuery('to', date('Y-m-d'));
        if (!self::validDate($from) || !self::validDate($to)) Response::error('Tareekh sahi likhein');

        $st = Database::getInstance()->prepare("SELECT id, category, description, amount, method, expense_date AS date, created_at
                                                FROM expenses WHERE expense_date >= ? AND expense_date <= ?
                                                ORDER BY expense_date DESC, id DESC LIMIT 2000");
        $st->execute([$from, $to]);
        Response::success('Expenses', $st->fetchAll());
    }

    public static function create() {
        $in = Request::getBody();
        $amount = (float)($in['amount'] ?? 0);
        $category = trim($in['category'] ?? '');
        $date = $in['date'] ?? date('Y-m-d');
        if ($amount <= 0) Response::error('Raqam 0 se zyada likhein');
        if ($category === '') Response::error('Category chunein');
        if (!self::validDate($date)) Response::error('Tareekh sahi likhein');

        $db = Database::getInstance();
        $db->prepare("INSERT INTO expenses (category, description, amount, method, expense_date, user_id) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([substr($category, 0, 60), trim($in['description'] ?? '') ?: null, $amount, substr(trim($in['method'] ?? 'cash') ?: 'cash', 0, 20), $date, $GLOBALS['auth_user']['id'] ?? null]);
        Response::success('Expense save ho gaya', ['id' => (int)$db->lastInsertId()], 201);
    }

    public static function delete($id) {
        $st = Database::getInstance()->prepare("DELETE FROM expenses WHERE id = ?");
        $st->execute([(int)$id]);
        if (!$st->rowCount()) Response::error('Expense nahi mila', 404);
        Response::success('Expense delete ho gaya');
    }
}
