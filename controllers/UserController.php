<?php
class UserController {
    const MIN_PASSWORD = 6;

    public static function list() {
        $rows = Database::getInstance()->query("SELECT id, name, username, email, role, avatar, last_login_at, created_at
                                                FROM users WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
        foreach ($rows as &$r) { $r['role'] = $r['role'] === 'admin' ? 'admin' : 'user'; }
        unset($r);
        Response::success('Users', $rows);
    }

    public static function create() {
        $in = Request::getBody();
        $name = trim($in['name'] ?? '');
        $username = trim($in['username'] ?? '');
        $email = mb_strtolower(trim($in['email'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $role = ($in['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($name === '' || $username === '') Response::error('Please enter a name and username');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Please enter a valid email address');
        if (strlen($password) < self::MIN_PASSWORD) Response::error('Password must be at least ' . self::MIN_PASSWORD . ' characters');

        $db = Database::getInstance();
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$username]);
        if ($st->fetch()) Response::error('This username is already taken');
        $st = $db->prepare("SELECT id FROM users WHERE email = ?");
        $st->execute([$email]);
        if ($st->fetch()) Response::error('This email is already used by another account');

        $db->prepare("INSERT INTO users (name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)")
           ->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        Response::success('User created', ['id' => (int)$db->lastInsertId()], 201);
    }

    private static function target($id) {
        $st = Database::getInstance()->prepare("SELECT id, role FROM users WHERE id = ? AND is_active = 1");
        $st->execute([(int)$id]);
        $u = $st->fetch();
        if (!$u) Response::error('User not found', 404);
        return $u;
    }

    private static function otherAdmins($exceptId) {
        $st = Database::getInstance()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
        $st->execute([$exceptId]);
        return (int)$st->fetchColumn();
    }

    // PUT /users/{id}  { role }  or  { email }
    public static function update($id) {
        $u = self::target($id);
        $in = Request::getBody();

        if (array_key_exists('email', $in)) {
            $email = mb_strtolower(trim((string)$in['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Please enter a valid email address');
            $dup = Database::getInstance()->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
            $dup->execute([$email, $u['id']]);
            if ($dup->fetch()) Response::error('This email is already used by another account');
            Database::getInstance()->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $u['id']]);
            Response::success('Email updated', ['email' => $email]);
        }

        $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'user';
        if ($u['role'] === 'admin' && $role !== 'admin') {
            if ((int)$u['id'] === (int)$GLOBALS['auth_user']['id']) Response::error('You cannot lower your own role');
            if (self::otherAdmins($u['id']) < 1) Response::error('At least one admin is required');
        }
        Database::getInstance()->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $u['id']]);
        Response::success('Role updated');
    }

    // PUT /users/{id}/password { newPassword }
    public static function resetPassword($id) {
        $u = self::target($id);
        $pw = (string)(Request::getBody()['newPassword'] ?? '');
        if (strlen($pw) < self::MIN_PASSWORD) Response::error('New password must be at least ' . self::MIN_PASSWORD . ' characters');
        Database::getInstance()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        Response::success('Password changed');
    }

    // Soft delete (old bills keep the user's name)
    public static function delete($id) {
        $u = self::target($id);
        if ((int)$u['id'] === (int)$GLOBALS['auth_user']['id']) Response::error('You cannot delete your own account');
        if ($u['role'] === 'admin' && self::otherAdmins($u['id']) < 1) Response::error('The last admin cannot be deleted');
        Database::getInstance()->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$u['id']]);
        Response::success('User deleted');
    }
}