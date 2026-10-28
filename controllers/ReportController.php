<?php
class ReportController {
    // Return ho chuka maal hata kar: item margin minus bill ki discount
    private static function profit($db, $from, $toExclusive) {
        $st = $db->prepare("SELECT COALESCE(SUM((si.price - si.cost) * (si.qty - si.returned_qty)), 0)
                            FROM sale_items si JOIN sales s ON s.id = si.sale_id
                            WHERE s.created_at >= ? AND s.created_at < ?");
        $st->execute([$from, $toExclusive]);
        $margin = (float)$st->fetchColumn();
        $d = $db->prepare("SELECT COALESCE(SUM(discount), 0) FROM sales WHERE created_at >= ? AND created_at < ?");
        $d->execute([$from, $toExclusive]);
        return $margin - (float)$d->fetchColumn();
    }

    public static function dashboard() {
        $db = Database::getInstance();
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+1 day'));

        $st = $db->prepare("SELECT COALESCE(SUM(total - returned_amount), 0) AS total_sales, COUNT(*) AS invoice_count
                            FROM sales WHERE created_at >= ? AND created_at < ?");
        $st->execute([$from, $to]);
        $today = $st->fetch();

        $low = $db->query("SELECT COUNT(*) FROM products WHERE stock <= min_stock AND is_active = 1")->fetchColumn();
        $credit = $db->query("SELECT COALESCE(SUM(balance), 0) FROM customers")->fetchColumn();

        Response::success('Dashboard statistics', [
            'today_sales' => (float)$today['total_sales'],
            'invoice_count' => (int)$today['invoice_count'],
            'today_profit' => round(self::profit($db, $from, $to), 2),
            'low_stock_count' => (int)$low,
            'customer_credit' => (float)$credit,
        ]);
    }

    // GET /reports/summary?from=YYYY-MM-DD&to=YYYY-MM-DD
    public static function summary() {
        $from = Request::getQuery('from', date('Y-m-01'));
        $to = Request::getQuery('to', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            Response::error('Tareekh sahi likhein');
        }
        $end = date('Y-m-d', strtotime($to . ' +1 day'));
        $db = Database::getInstance();

        $q = function ($sql, $args = null) use ($db, $from, $end) {
            $st = $db->prepare($sql);
            $st->execute($args ?? [$from, $end]);
            return $st;
        };

        $t = $q("SELECT COALESCE(SUM(total - returned_amount), 0) AS revenue, COUNT(*) AS orders
                 FROM sales WHERE created_at >= ? AND created_at < ?")->fetch();
        $profit = round(self::profit($db, $from, $end), 2);
        $expenses = (float)$q("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= ? AND expense_date <= ?", [$from, $to])->fetchColumn();

        $dailySales = $q("SELECT DATE(created_at) AS date, COALESCE(SUM(total - returned_amount), 0) AS revenue, COUNT(*) AS orders
                          FROM sales WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)")->fetchAll();
        $dailyProfit = $q("SELECT DATE(s.created_at) AS date, COALESCE(SUM((si.price - si.cost) * (si.qty - si.returned_qty)), 0) AS profit
                           FROM sale_items si JOIN sales s ON s.id = si.sale_id
                           WHERE s.created_at >= ? AND s.created_at < ? GROUP BY DATE(s.created_at)")->fetchAll();
        $dailyDisc = $q("SELECT DATE(created_at) AS date, COALESCE(SUM(discount), 0) AS d
                         FROM sales WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)")->fetchAll();
        $days = [];
        foreach ($dailySales as $r) { $days[$r['date']] = ['date' => $r['date'], 'revenue' => (float)$r['revenue'], 'orders' => (int)$r['orders'], 'profit' => 0.0]; }
        foreach ($dailyProfit as $r) { if (isset($days[$r['date']])) $days[$r['date']]['profit'] += (float)$r['profit']; }
        foreach ($dailyDisc as $r) { if (isset($days[$r['date']])) $days[$r['date']]['profit'] -= (float)$r['d']; }
        ksort($days);

        $payments = $q("SELECT payment_method AS method, COALESCE(SUM(total - returned_amount), 0) AS amount
                        FROM sales WHERE created_at >= ? AND created_at < ? GROUP BY payment_method")->fetchAll();

        $products = $q("SELECT si.name, SUM(si.qty - si.returned_qty) AS qty,
                               SUM(si.price * (si.qty - si.returned_qty)) AS revenue,
                               SUM((si.price - si.cost) * (si.qty - si.returned_qty)) AS profit
                        FROM sale_items si JOIN sales s ON s.id = si.sale_id
                        WHERE s.created_at >= ? AND s.created_at < ?
                        GROUP BY si.product_id, si.name HAVING qty > 0 ORDER BY revenue DESC LIMIT 200")->fetchAll();

        $customers = $q("SELECT COALESCE(c.name, 'Walk-in') AS name, COUNT(*) AS orders, SUM(s.total - s.returned_amount) AS total
                         FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
                         WHERE s.created_at >= ? AND s.created_at < ?
                         GROUP BY s.customer_id, c.name ORDER BY total DESC LIMIT 200")->fetchAll();

        Response::success('Report summary', [
            'totals' => [
                'revenue' => (float)$t['revenue'], 'orders' => (int)$t['orders'],
                'profit' => $profit, 'cost' => round((float)$t['revenue'] - $profit, 2), 'expenses' => $expenses,
            ],
            'daily' => array_values($days),
            'payments' => $payments,
            'products' => $products,
            'customers' => $customers,
        ]);
    }
}
