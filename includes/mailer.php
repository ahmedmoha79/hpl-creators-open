<?php
/**
 * Simple mail helper.
 * Priority:
 *   1. SMTP (when smtp_host is configured) - see includes/smtp_mail.php
 *   2. PHP's native mail()
 *   3. storage/mail.log + in-page demo link (common on localhost)
 */

require_once __DIR__ . '/smtp_mail.php';

function app_mail(string $to, string $subject, string $html, string $text = ''): bool
{
    $host = preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com'));
    $fromEmail = setting('mail_from_email', '') ?: ('no-reply@' . $host);
    $fromName = setting('mail_from_name', APP_NAME);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: HPL-Creators/' . APP_VERSION,
    ];
    $bodyHtml = '<div style="background:#f6f0e6;padding:24px 12px">'
        . '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;background:#ffffff;color:#22160f;border:1px solid #e4d4bf;border-radius:16px;overflow:hidden">'
        . '<div style="background:#1e140f;padding:18px 24px;font-size:18px;font-weight:700;color:#e2ad7b">' . e(APP_NAME) . '</div>'
        . '<div style="padding:24px">' . $html
        . '<p style="color:#8a7a6a;font-size:13px;margin:24px 0 0">If you did not expect this email, you can ignore it.</p></div>'
        . '</div></div>';

    $ok = false;

    // 1. SMTP
    if (setting('smtp_host', '') !== '') {
        $ok = app_smtp_send($to, $subject, $bodyHtml, $text);
        if (!$ok) {
            // fall through to mail() if SMTP totally misconfigured, but remember why
            $smtpError = app_smtp_last_error();
        }
    }

    // 2. Native mail() through the hosting server.
    // Sent as multipart (plain text + HTML) with a Return-Path on our own domain (-f), so SPF,
    // DKIM and DMARC line up and Gmail/Yahoo deliver it to the inbox quickly instead of spam.
    if (!$ok) {
        $ok = app_native_mail($to, $subject, $bodyHtml, $text, $fromEmail, (string)$fromName);
        $method = 'server mail';
    } else {
        $method = 'SMTP';
    }
    try {
        set_setting('mail_last_status', date('Y-m-d H:i') . ' ' . ($ok ? 'sent via ' . $method : 'FAILED' . (isset($smtpError) ? ' (SMTP: ' . mb_substr((string)$smtpError, 0, 120) . ')' : '')));
    } catch (Throwable $e) {
    }

    try { require_once __DIR__ . '/brain.php'; brain_count('mail', $ok ? 'sent' : 'failed', '/mail'); } catch (Throwable $e) {} // the Brain watches delivery

    // 3. Log fallback
    if (!$ok) {
        $log = BASE_PATH . '/storage/mail.log';
        $line = '[' . date('Y-m-d H:i:s') . '] TO=' . $to . ' SUBJECT=' . $subject
            . (isset($smtpError) ? ' SMTP=' . $smtpError : '') . PHP_EOL . $bodyHtml . PHP_EOL . '--------' . PHP_EOL;
        @file_put_contents($log, $line, FILE_APPEND);
    }
    return $ok;
}

/** Send through the server's mailer as a proper multipart message with an aligned envelope sender. */
function app_native_mail(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (trim($text) === '') {
        $text = trim(html_entity_decode(strip_tags(preg_replace('~<(br|/p|/div|/h\d|/li)[^>]*>~i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
    }
    $domain = substr(strrchr($fromEmail, '@'), 1);
    $boundary = 'hpl_' . bin2hex(random_bytes(12));
    $enc = function (string $s): string { return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; };
    $headers = [
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'From: ' . $enc(str_replace(['"', "\r", "\n"], '', $fromName)) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: HPL-Creators/' . APP_VERSION,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode('<!DOCTYPE html><html><body>' . $html . '</body></html>')) . "\r\n"
        . '--' . $boundary . "--\r\n";
    return @mail($to, $enc($subject), $body, implode("\r\n", $headers), '-f' . $fromEmail);
}

/**
 * Email authentication health for the sending domain (public DNS).
 * Returns rows: [label, ok(bool|null), found value, what it should be].
 */
function app_mail_health(): array
{
    $host = preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com'));
    $from = setting('mail_from_email', '') ?: ('no-reply@' . $host);
    $domain = strtolower(substr(strrchr($from, '@'), 1));
    $txt = function (string $name): array {
        $out = [];
        foreach ((array)@dns_get_record($name, DNS_TXT) as $r) {
            $out[] = isset($r['entries']) ? implode('', $r['entries']) : (string)($r['txt'] ?? '');
        }
        return $out;
    };
    $ip = trim((string)setting('mail_sending_ip', ''));
    if ($ip === '') {
        $guess = gethostbyname((string)php_uname('n'));
        $ip = filter_var($guess, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $guess : '';
        if ($ip === '') {
            // Behind a private network: ask public DNS for this server's name instead.
            foreach ((array)@dns_get_record((string)php_uname('n'), DNS_A) as $r) {
                if (!empty($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ip = $r['ip'];
                    break;
                }
            }
        }
    }
    $spf = array_values(array_filter($txt($domain), function ($t) { return stripos($t, 'v=spf1') === 0; }));
    $spfOk = count($spf) === 1 && ($ip === '' || strpos($spf[0], $ip) !== false);
    $dkim = array_values(array_filter($txt('default._domainkey.' . $domain), function ($t) { return stripos($t, 'v=DKIM1') !== false; }));
    $dmarc = array_values(array_filter($txt('_dmarc.' . $domain), function ($t) { return stripos($t, 'v=DMARC1') === 0; }));
    return [
        'domain' => $domain, 'from' => $from, 'ip' => $ip,
        'rows' => [
            ['SPF (who may send)', $spfOk, $spf[0] ?? 'missing', 'v=spf1 ' . ($ip !== '' ? 'ip4:' . $ip . ' ' : '') . 'include:_spf.porkbun.com ~all'],
            ['DKIM (signature)', (bool)$dkim, $dkim ? 'found' : 'missing', 'TXT record named default._domainkey with the key from cPanel'],
            ['DMARC (policy)', (bool)$dmarc, $dmarc[0] ?? 'missing', 'v=DMARC1; p=none; adkim=r; aspf=r;'],
        ],
    ];
}
