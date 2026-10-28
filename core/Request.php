<?php
class Request {
    public static function getMethod() {
        return $_SERVER['REQUEST_METHOD'];
    }

    public static function getUri() {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }

    public static function getBody() {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    // ?from=2026-01-01 jaisi query values
    public static function getQuery($key, $default = null) {
        return isset($_GET[$key]) && $_GET[$key] !== '' ? $_GET[$key] : $default;
    }

    public static function getBearerToken() {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$auth && function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            $auth = $headers['authorization'] ?? '';
        }
        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
