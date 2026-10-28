<?php
class CategoryController {
    // Naam se category dhundta hai, na mile to bana deta hai. Id wapas karta hai.
    // Excel import isi ko istemal karta hai: "Soft   Drinks" aur "soft drinks" ek hi category hain.
    public static function findOrCreate($db, $name) {
        $name = preg_replace('/\s+/u', ' ', trim((string)$name));
        if ($name === '') return null;
        if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);

        $st = $db->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
        $st->execute([$name]);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;

        try {
            $db->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
            return (int)$db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            $st->execute([$name]); // kisi aur ne abhi bana di
            return (int)$st->fetchColumn();
        }
    }

    // GET /api/categories : all categories, A to Z
    public static function list() {
        $rows = Database::getInstance()->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
        Response::success('Categories', $rows);
    }

    // POST /api/categories { name } : creates a category and returns { id, name }
    public static function create() {
        $in = Request::getBody();
        $name = trim($in['name'] ?? '');
        // Collapse repeated spaces so "Soft   Drinks" and "Soft Drinks" are the same category
        $name = preg_replace('/\s+/u', ' ', $name);

        if ($name === '') Response::error('Enter a category name.');
        if (mb_strlen($name) > 100) Response::error('The category name must be 100 characters or fewer.');

        $db = Database::getInstance();

        // The name column is unique (case-insensitive), so look it up first for a clear message
        $st = $db->prepare("SELECT id, name FROM categories WHERE name = ? LIMIT 1");
        $st->execute([$name]);
        if ($st->fetch()) Response::error('A category with this name already exists.', 409);

        try {
            $db->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') Response::error('A category with this name already exists.', 409);
            throw $e;
        }

        Response::success('Category added', ['id' => (int)$db->lastInsertId(), 'name' => $name], 201);
    }
}