<?php
class AuthController {
    const MIN_PASSWORD = 6;
    const CODE_MINUTES = 10;
    const MAX_ATTEMPTS = 5;
    const RESEND_SECONDS = 60;

    // The user object sent to the frontend (password hash is never included)
    private static function publicUser($u) {
        return [
            'id' => (int)$u['id'],
            'name' => $u['name'] ?: $u['username'],
            'username' => $u['username'],
            'email' => $u['email'] ?? null,
            'role' => $u['role'] === 'admin' ? 'admin' : 'user',
            'avatar' => $u['avatar'] ?? null,
        ];
    }

    private static function find($id) {
        $st = Database::getInstance()->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch();
    }

    private static function cleanEmail($v) {
        return mb_strtolower(trim((string)$v));
    }

    private static function emailTaken($email, $exceptId = 0) {
        $st = Database::getInstance()->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $st->execute([$email, $exceptId]);
        return (bool)$st->fetch();
    }

    public static function login() {
        $input = Request::getBody();
        $username = trim($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::error('Username and password are required');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
            Response::success('Login successful', [
                'token' => Token::make($user),
                'user' => self::publicUser($user),
            ]);
        }
        Response::error('Invalid credentials', 401);
    }

    // A new account is always a 'user' (cashier). Only an admin can create admins (Settings > Users).
    public static function register() {
        $in = Request::getBody();
        $name = trim($in['name'] ?? '');
        $username = trim($in['username'] ?? '');
        $email = self::cleanEmail($in['email'] ?? '');
        $password = (string)($in['password'] ?? '');

        if ($name === '' || $username === '') Response::error('Please enter your name and username');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Please enter a valid email address');
        if (strlen($password) < self::MIN_PASSWORD) Response::error('Password must be at least ' . self::MIN_PASSWORD . ' characters');

        $db = Database::getInstance();
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$username]);
        if ($st->fetch()) Response::error('This username is already taken');
        if (self::emailTaken($email)) Response::error('This email is already used by another account');

        $db->prepare("INSERT INTO users (name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 'user', 1)")
           ->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);

        Response::success('Account created', ['id' => (int)$db->lastInsertId()], 201);
    }

    // Forgot password, step 1: email a 6-digit code
    public static function forgotPassword() {
        $email = self::cleanEmail(Request::getBody()['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Please enter a valid email address');

        $db = Database::getInstance();
        $st = $db->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 1");
        $st->execute([$email]);
        $u = $st->fetch();

        // Same reply whether or not the email exists, so strangers cannot probe for accounts
        $generic = 'If this email is registered, a 6-digit code has been sent to it.';
        if (!$u) Response::success($generic);

        $last = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $last->execute([$u['id']]);
        $ago = $last->fetchColumn();
        if ($ago !== false && $ago !== null && (int)$ago < self::RESEND_SECONDS) {
            Response::error('Please wait ' . (self::RESEND_SECONDS - (int)$ago) . ' seconds before asking for a new code', 429);
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$u['id']]);
        $db->prepare("INSERT INTO password_resets (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . self::CODE_MINUTES . " MINUTE))")
           ->execute([$u['id'], password_hash($code, PASSWORD_DEFAULT)]);

        try {
            Mailer::send(
                $email,
                'Your password reset code',
                "Hello " . ($u['name'] ?: 'there') . ",\n\n" .
                "Your password reset code is: $code\n\n" .
                "It is valid for " . self::CODE_MINUTES . " minutes. If you did not ask for this, you can ignore this email.\n\n" .
                "SWIFT SIGN POS"
            );
        } catch (Exception $e) {
            $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$u['id']]);
            Response::error('Could not send the email. ' . $e->getMessage(), 502);
        }
        Response::success($generic);
    }

    // Forgot password, step 2: code + new password
    public static function resetPassword() {
        $in = Request::getBody();
        $email = self::cleanEmail($in['email'] ?? '');
        $code = trim((string)($in['code'] ?? ''));
        $newPassword = (string)($in['newPassword'] ?? '');
        if (strlen($newPassword) < self::MIN_PASSWORD) Response::error('New password must be at least ' . self::MIN_PASSWORD . ' characters');

        $db = Database::getInstance();
        $st = $db->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1");
        $st->execute([$email]);
        $u = $st->fetch();
        $invalid = 'This code is invalid or has expired. Please request a new one.';
        if (!$u) Response::error($invalid, 401);

        $rs = $db->prepare("SELECT id, code_hash, attempts, (expires_at > NOW()) AS valid FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $rs->execute([$u['id']]);
        $r = $rs->fetch();
        if (!$r || !(int)$r['valid'] || (int)$r['attempts'] >= self::MAX_ATTEMPTS) {
            if ($r) $db->prepare("DELETE FROM password_resets WHERE id = ?")->execute([$r['id']]);
            Response::error($invalid, 401);
        }
        if (!password_verify($code, $r['code_hash'])) {
            $db->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?")->execute([$r['id']]);
            Response::error('Incorrect code', 401);
        }

        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($newPassword, PASSWORD_DEFAULT), $u['id']]);
        $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$u['id']]);
        Response::success('Password changed');
    }

    // Profile: name, username, email, avatar (avatar is sent only when changed; '' = remove it)
    public static function updateProfile() {
        $in = Request::getBody();
        $id = $GLOBALS['auth_user']['id'];
        $me = self::find($id);

        $name = array_key_exists('name', $in) ? trim($in['name']) : $me['name'];
        $username = array_key_exists('username', $in) ? trim($in['username']) : $me['username'];
        if ($name === '' || $username === '') Response::error('Name and username cannot be empty');

        $db = Database::getInstance();
        $dup = $db->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $dup->execute([$username, $id]);
        if ($dup->fetch()) Response::error('This username is already taken');

        $email = $me['email'] ?? null;
        if (array_key_exists('email', $in)) {
            $e = self::cleanEmail($in['email']);
            if ($e === '') {
                $email = null;
            } else {
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) Response::error('Please enter a valid email address');
                if (self::emailTaken($e, $id)) Response::error('This email is already used by another account');
                $email = $e;
            }
        }

        $avatar = $me['avatar'];
        if (array_key_exists('avatar', $in)) {
            $avatar = $in['avatar'] === '' || $in['avatar'] === null ? null : (string)$in['avatar'];
            if ($avatar !== null && strlen($avatar) > 3 * 1024 * 1024) Response::error('The image is too large');
        }

        $db->prepare("UPDATE users SET name = ?, username = ?, email = ?, avatar = ? WHERE id = ?")->execute([$name, $username, $email, $avatar, $id]);
        Response::success('Profile updated', ['user' => self::publicUser(self::find($id))]);
    }

    public static function changePassword() {
        $in = Request::getBody();
        $new = (string)($in['newPassword'] ?? '');
        $me = self::find($GLOBALS['auth_user']['id']);
        if (!password_verify((string)($in['currentPassword'] ?? ''), $me['password_hash'])) Response::error('Current password is incorrect', 401);
        if (strlen($new) < self::MIN_PASSWORD) Response::error('New password must be at least ' . self::MIN_PASSWORD . ' characters');
        Database::getInstance()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        Response::success('Password changed');
    }
}