<?php
// Signed token (HMAC). Purana base64 token koi bhi bana sakta tha; ye nahi bana sakta.
class Token {
    private static function secret() {
        // .php file me rakhi hai, taake browser se link khol kar koi parh na sake
        $file = __DIR__ . '/../config/secret.php';
        if (is_file($file)) {
            $s = include $file;
            if (is_string($s) && $s !== '') return $s;
        }
        $s = bin2hex(random_bytes(32));
        @file_put_contents($file, "<?php return '" . $s . "';\n"); // pehli baar khud ban jati hai
        return $s;
    }

    private static function b64($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
    private static function unb64($d) { return base64_decode(strtr($d, '-_', '+/')); }

    public static function make(array $user, $ttl = 604800) { // 7 din
        $payload = self::b64(json_encode(['id' => (int)$user['id'], 'exp' => time() + $ttl]));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, self::secret(), true));
    }

    public static function verify($token) {
        $parts = explode('.', (string)$token);
        if (count($parts) !== 2) return null;
        $good = self::b64(hash_hmac('sha256', $parts[0], self::secret(), true));
        if (!hash_equals($good, $parts[1])) return null;
        $data = json_decode(self::unb64($parts[0]), true);
        if (!$data || empty($data['id']) || ($data['exp'] ?? 0) < time()) return null;
        return $data;
    }
}

class AuthMiddleware {
    // Har logged-in user
    public static function handle() {
        $token = Request::getBearerToken();
        if (!$token) {
            Response::error('Unauthorized access. Token required.', 401);
        }
        $data = Token::verify($token);
        if (!$data) {
            Response::error('Invalid or expired token', 401);
        }

        // Role aur active status har baar DB se, taake admin ka badlav foran lagu ho
        $st = Database::getInstance()->prepare("SELECT id, username, role FROM users WHERE id = ? AND is_active = 1");
        $st->execute([$data['id']]);
        $user = $st->fetch();
        if (!$user) {
            Response::error('Account not found or disabled', 401);
        }
        $GLOBALS['auth_user'] = $user;
    }

    // Sirf admin
    public static function admin() {
        self::handle();
        if (($GLOBALS['auth_user']['role'] ?? '') !== 'admin') {
            Response::error('Admin access required', 403);
        }
    }
}
