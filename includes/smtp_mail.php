<?php
/**
 * SMTP mail sender.
 * Minimal pure-socket SMTP client with STARTTLS / implicit SSL support.
 * Used by app_mail() when SMTP settings are configured, with graceful
 * fallback to PHP mail() and then to storage/mail.log.
 */

if (!function_exists('app_smtp_send')) {
    function app_smtp_last_error(): string
    {
        return $_SESSION['smtp_last_error'] ?? '';
    }

    function app_smtp_send(string $to, string $subject, string $html, string $text = ''): bool
    {
        $host        = (string) setting('smtp_host', '');
        $port        = (int) setting('smtp_port', 587);
        $user        = (string) setting('smtp_username', '');
        $pass        = (string) setting('smtp_password', '');
        $encryption  = (string) setting('smtp_encryption', 'tls');
        $fromEmail   = (string) (setting('mail_from_email', '') ?: ('no-reply@' . preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com'))));
        $fromName    = (string) setting('mail_from_name', APP_NAME);
        $replyTo     = (string) setting('payout_contact_email', $fromEmail) ?: $fromEmail;

        if ($host === '' || $user === '' || $pass === '') {
            $_SESSION['smtp_last_error'] = 'SMTP not configured (host, username or password missing).';
            return false;
        }

        $boundary = '----HPL_' . bin2hex(random_bytes(8));
        $encoded  = mb_encode_mimeheader($fromName, 'UTF-8', 'B');

        $headers = "From: {$encoded} <{$fromEmail}>\r\n"
            . "Reply-To: {$replyTo}\r\n"
            . 'MIME-Version: 1.0' . "\r\n"
            . "Message-ID: <" . bin2hex(random_bytes(8)) . '@' . $host . ">\r\n"
            . "Date: " . date('r') . "\r\n"
            . "X-Mailer: HPL-Creators/" . APP_VERSION . "\r\n";
        $contentType = $text !== ''
            ? "Content-Type: multipart/alternative; boundary=\"{$boundary}\""
            : 'Content-Type: text/html; charset=UTF-8';

        if ($text !== '') {
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text)) . "\r\n"
                . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html)) . "\r\n"
                . "--{$boundary}--";
        } else {
            $body = $html;
        }

        $cmd = "EHLO " . (gethostname() ?: 'localhost') . "\r\n";
        $err = '';
        $ok  = app_smtp_raw(
            $host,
            $port,
            $encryption,
            $cmd,
            $to,
            $fromEmail,
            $user,
            $pass,
            $headers . $contentType . "\r\n\r\n",
            $body,
            $err
        );

        if (!$ok) {
            $_SESSION['smtp_last_error'] = $err;
        } else {
            unset($_SESSION['smtp_last_error']);
        }
        return $ok;
    }

    /**
     * Raw SMTP conversation over a socket.
     * Handles implicit SSL (smtps), STARTTLS upgrade, and optional auth.
     * @return bool
     */
    function app_smtp_raw(string $host, int $port, string $encryption, string $ehlo, string $rcptTo, string $mailFrom, string $user, string $pass, string $prelude, string $body, string &$err): bool
    {
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $ccode, $cerr, 20, STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]));
        if (!$fp) {
            $err = "Cannot connect to {$host}:{$port} ({$cerr}).";
            return false;
        }
        stream_set_timeout($fp, 20);
        $err = '';

        if (!app_smtp_expect_read($fp, 220, $err)) { fclose($fp); return false; }
        fwrite($fp, $ehlo);
        if (!app_smtp_expect_read($fp, 250, $err)) { fclose($fp); return false; }

        if ($encryption === 'tls') {
            fwrite($fp, "STARTTLS\r\n");
            if (!app_smtp_expect_read($fp, 220, $err)) { fclose($fp); return false; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $err = 'STARTTLS upgrade failed.';
                fclose($fp);
                return false;
            }
            // Re-identify after TLS handshake
            fwrite($fp, $ehlo);
            if (!app_smtp_expect_read($fp, 250, $err)) { fclose($fp); return false; }
        }

        if ($user !== '' && $pass !== '') {
            fwrite($fp, "AUTH LOGIN\r\n");
            if (!app_smtp_expect_read($fp, 334, $err)) { fclose($fp); return false; }
            fwrite($fp, base64_encode($user) . "\r\n");
            if (!app_smtp_expect_read($fp, 334, $err)) { fclose($fp); return false; }
            fwrite($fp, base64_encode($pass) . "\r\n");
            if (!app_smtp_expect_read($fp, 235, $err)) { fclose($fp); return false; }
        }

        fwrite($fp, "MAIL FROM:<{$mailFrom}>\r\n");
        if (!app_smtp_expect_read($fp, 250, $err)) { fclose($fp); return false; }

        fwrite($fp, "RCPT TO:<{$rcptTo}>\r\n");
        if (!app_smtp_expect_read($fp, 250, $err)) { fclose($fp); return false; }

        fwrite($fp, "DATA\r\n");
        if (!app_smtp_expect_read($fp, 354, $err)) { fclose($fp); return false; }
        $message = str_replace("\r\n.", "\r\n..", $prelude . $body);
        fwrite($fp, rtrim($message, "\r\n") . "\r\n.\r\n");
        if (!app_smtp_expect_read($fp, 250, $err)) { fclose($fp); return false; }

        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }

    /**
     * Reads one SMTP reply line and checks the expected code.
     */
    function app_smtp_expect_read($fp, int $expected, string &$err): bool
    {
        $meta = stream_get_meta_data($fp);
        $line = '';
        while (($chunk = fgets($fp, 515)) !== false) {
            $line .= $chunk;
            // Multiline replies end with "NNN " instead of "NNN-"
            if (strlen($chunk) >= 3 && substr($chunk, 3, 1) === ' ') {
                break;
            }
        }
        $code = (int) substr($line, 0, 3);
        if ($code !== $expected) {
            $err = 'SMTP expected ' . $expected . ', got ' . $code . ': ' . trim($line);
            return false;
        }
        if ($expected === 250 && strpos($line, 'PIPELINING') !== false) {
            // fine, just a capability list
        }
        return true;
    }
}