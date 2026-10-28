<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'swiftsign_pos');
define('DB_USER', 'root');
define('DB_PASS', '');

// PHP aur MySQL dono ek hi waqt (Pakistan) par chalen, warna "aaj" ke sales/dashboard aage peeche ho jate hain
define('APP_TIMEZONE', 'Asia/Karachi');
define('DB_TZ_OFFSET', '+05:00');

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . DB_TZ_OFFSET . "'",
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->pdo;
    }
}
