<?php
// Minimal SMTP client (SSL, AUTH LOGIN) - no Composer needed. Works with Gmail.
class Mailer {
    public static function send($to, $subject, $body) {
        $cfg = require __DIR__ . '/../config/mail.php';
        $user = trim($cfg['username'] ?? '');
        $pass = str_replace(' ', '', (string)($cfg['password'] ?? ''));
        if ($user === '' || $user === 'yourshop@gmail.com' || $pass === '' || $pass === 'xxxxxxxxxxxxxxxx') {
            throw new Exception('Email is not configured. Fill in config/mail.php first.');
        }

        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => !empty($cfg['verify_ssl']),
            'verify_peer_name' => !empty($cfg['verify_ssl']),
        ]]);
        $fp = @stream_socket_client('ssl://' . $cfg['host'] . ':' . $cfg['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new Exception('Cannot connect to mail server (' . $errstr . ')');
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function ($c, $expect) use ($fp, $read) {
            if ($c !== null) fwrite($fp, $c . "\r\n");
            $r = $read();
            if (strpos($r, (string)$expect) !== 0) throw new Exception('Mail server said: ' . trim($r));
            return $r;
        };

        try {
            $cmd(null, '220');
            $cmd('EHLO localhost', '250');
            $cmd('AUTH LOGIN', '334');
            $cmd(base64_encode($user), '334');
            $cmd(base64_encode($pass), '235');
            $from = $cfg['from_email'] ?: $user;
            $cmd('MAIL FROM:<' . $from . '>', '250');
            $cmd('RCPT TO:<' . $to . '>', '250');
            $cmd('DATA', '354');

            $headers = [
                'From: =?UTF-8?B?' . base64_encode($cfg['from_name'] ?? 'POS') . '?= <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'Date: ' . date('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n");
            $cmd(null, '250');
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }
}