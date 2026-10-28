<?php
class SettingsController {
    const KEYS = ['store_name', 'store_address', 'store_phone', 'currency', 'tax_rate', 'receipt_footer', 'store_logo'];

    // Bootstrap bhi isi ko istemal karta hai
    public static function all() {
        $out = [
            'store_name' => 'SWIFT SIGN POS', 'store_address' => '', 'store_phone' => '',
            'currency' => 'Rs', 'tax_rate' => 0, 'receipt_footer' => '', 'store_logo' => '',
        ];
        $rows = Database::getInstance()->query("SELECT k, v FROM app_settings")->fetchAll();
        foreach ($rows as $r) {
            $out[$r['k']] = $r['k'] === 'tax_rate' ? (float)$r['v'] : $r['v'];
        }
        return $out;
    }

    public static function update() {
        $in = Request::getBody();
        $db = Database::getInstance();

        if (isset($in['store_name']) && trim($in['store_name']) === '') {
            Response::error('Store ka naam khali nahi ho sakta');
        }
        if (isset($in['tax_rate'])) {
            $tax = (float)$in['tax_rate'];
            if ($tax < 0 || $tax > 100) Response::error('Tax rate 0 se 100 ke beech likhein');
            $in['tax_rate'] = $tax;
        }

        if (isset($in['store_logo']) && $in['store_logo'] !== '') {
            $logo = (string)$in['store_logo'];
            if (strpos($logo, 'data:image/') !== 0) Response::error('Logo sahi tasveer nahi hai');
            if (strlen($logo) > 500 * 1024) Response::error('Logo bohat bara hai (500KB se chhota rakhein)');
        }

        $st = $db->prepare("INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $in)) {
                $st->execute([$k, trim((string)$in[$k])]);
            }
        }
        Response::success('Settings save ho gayin', self::all());
    }
}