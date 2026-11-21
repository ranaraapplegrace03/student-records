<?php
// Sends email through Gmail's SMTP server using the settings in mail_config.php (kept on the server only)

function smtp_read($fp) {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
}

function smtp_cmd($fp, $cmd, $expect) {
    if ($cmd !== null) {
        fwrite($fp, $cmd . "\r\n");
    }
    $resp = smtp_read($fp);
    if (substr($resp, 0, 3) !== (string)$expect) {
        throw new RuntimeException('Mail server replied: ' . trim($resp));
    }
}

function send_mail($to, $subject, $body) {
    $cfgFile = __DIR__ . '/mail_config.php';
    if (!is_file($cfgFile)) {
        throw new RuntimeException('Email is not set up yet. mail_config.php is missing on the server.');
    }
    $cfg = require $cfgFile;

    $fp = @stream_socket_client('tcp://' . $cfg['host'] . ':' . $cfg['port'], $errno, $errstr, 20);
    if (!$fp) {
        throw new RuntimeException('Cannot reach the mail server: ' . $errstr);
    }
    stream_set_timeout($fp, 20);

    try {
        smtp_cmd($fp, null, 220);
        smtp_cmd($fp, 'EHLO localhost', 250);
        smtp_cmd($fp, 'STARTTLS', 220);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Could not start an encrypted connection.');
        }
        smtp_cmd($fp, 'EHLO localhost', 250);
        smtp_cmd($fp, 'AUTH LOGIN', 334);
        smtp_cmd($fp, base64_encode($cfg['username']), 334);
        smtp_cmd($fp, base64_encode($cfg['password']), 235);
        smtp_cmd($fp, 'MAIL FROM:<' . $cfg['username'] . '>', 250);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', 250);
        smtp_cmd($fp, 'DATA', 354);

        $headers = 'From: ' . $cfg['from_name'] . ' <' . $cfg['username'] . ">\r\n"
                 . 'To: ' . $to . "\r\n"
                 . 'Subject: ' . $subject . "\r\n"
                 . 'Date: ' . date('r') . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message = $headers . str_replace(["\r\n", "\n"], "\r\n", $body);
        $message = preg_replace('/^\./m', '..', $message);
        smtp_cmd($fp, $message . "\r\n.", 250);
        smtp_cmd($fp, 'QUIT', 221);
    } finally {
        fclose($fp);
    }
}
