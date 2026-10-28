<?php
class Response {
    public static function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function error($message, $statusCode = 400) {
        self::json(['status' => 'error', 'message' => $message], $statusCode);
    }

    public static function success($message, $data = [], $statusCode = 200) {
        self::json(['status' => 'success', 'message' => $message, 'data' => $data], $statusCode);
    }
}