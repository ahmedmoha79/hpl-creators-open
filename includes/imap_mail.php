<?php
/**
 * IMAP inbox helper for the admin Email page.
 * Reads a mailbox configured in Settings and exposes list/read/count.
 */

if (!function_exists('imap_settings_configured')) {
    function imap_settings_configured(): bool
    {
        return setting('imap_host', '') !== '' && setting('imap_username', '') !== '' && setting('imap_password', '') !== '';
    }

    function imap_mailbox_string(): string
    {
        $host = setting('imap_host', '');
        $port = setting('imap_port', '993');
        $enc  = setting('imap_encryption', 'ssl');
        if ($enc === 'ssl' || $port === '993') {
            return '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}INBOX';
        }
        if ($enc === 'tls') {
            return '{' . $host . ':' . $port . '/imap/tls/novalidate-cert}INBOX';
        }
        return '{' . $host . ':' . $port . '/imap/novalidate-cert}INBOX';
    }

    /**
     * Returns an array of inbox messages: [seqno, uid, date, from, subject, seen].
     * Returns ['error' => msg] on connection failure.
     */
    function imap_fetch_inbox(int $limit = 30): array
    {
        if (!extension_loaded('imap')) {
            return ['error' => 'The PHP IMAP extension is not enabled.'];
        }
        if (!imap_settings_configured()) {
            return ['error' => 'IMAP settings are not configured yet. Open Settings › Email — receive and fill them in.'];
        }
        $mb = imap_mailbox_string();
        $conn = @imap_open($mb, setting('imap_username', ''), setting('imap_password', ''), OP_READONLY, 1);
        if (!$conn) {
            $last = imap_last_error();
            return ['error' => $last ?: 'Could not connect to the mailbox.'];
        }
        $total = imap_num_msg($conn);
        $messages = [];
        $range = $total - min($limit, $total) + 1;
        for ($i = $total; $i >= max(1, $range) && $i >= 1; $i--) {
            $h = @imap_headerinfo($conn, $i);
            if (!$h) {
                continue;
            }
            $from = '';
            if (isset($h->from[0])) {
                $from = $h->from[0]->personal ?: ($h->from[0]->mailbox . '@' . $h->from[0]->host);
                if (function_exists('mb_decode_mimeheader')) {
                    $from = mb_decode_mimeheader($from);
                }
            }
            $subject = trim($h->subject ?? '');
            if (function_exists('mb_decode_mimeheader')) {
                $subject = mb_decode_mimeheader($subject);
            }
            $messages[] = [
                'seqno'   => $i,
                'date'    => $h->udate ?? null,
                'from'    => $from,
                'subject' => $subject !== '' ? $subject : '(no subject)',
                'seen'    => (bool) ($h->Unseen === 'U' ? false : true),
            ];
        }
        imap_close($conn);
        return $messages;
    }

    /**
     * Fetches the full body + headers of one message by sequence number.
     */
    function imap_read_message(int $seq): array
    {
        if (!extension_loaded('imap')) {
            return ['error' => 'The PHP IMAP extension is not enabled.'];
        }
        $mb     = imap_mailbox_string();
        $conn   = @imap_open($mb, setting('imap_username', ''), setting('imap_password', ''), OP_READONLY, 1);
        $result = ['error' => 'Could not open the mailbox.'];
        if (!$conn) {
            return ['error' => imap_last_error() ?: $result['error']];
        }
        $h = @imap_headerinfo($conn, $seq);
        if (!$h) {
            imap_close($conn);
            return ['error' => 'Message not found.'];
        }
        $from = '';
        if (isset($h->from[0])) {
            $from = $h->from[0]->personal ?: ($h->from[0]->mailbox . '@' . $h->from[0]->host);
            if (function_exists('mb_decode_mimeheader')) {
                $from = mb_decode_mimeheader($from);
            }
        }
        $subject = trim($h->subject ?? '');
        if (function_exists('mb_decode_mimeheader')) {
            $subject = mb_decode_mimeheader($subject);
        }
        $body = @imap_fetchbody($conn, $seq, 1);
        if (!$body) {
            $body = @imap_body($conn, $seq);
        }
        if (function_exists('imap_fetchbody')) {
            // Try to get the HTML/plain part properly
            $htmlPart = @imap_fetchbody($conn, $seq, '1.2');
            $plainPart = @imap_fetchbody($conn, $seq, '1.1');
        }
        if (!empty($htmlPart)) {
            $body = $htmlPart;
        } elseif (!empty($plainPart)) {
            $body = $plainPart;
        } else {
            $body = $body ?: @imap_fetchbody($conn, $seq, '1');
            $body = $body ?: @imap_body($conn, $seq);
        }
        if ($body && function_exists('imap_fetchstructure')) {
            $structure = @imap_fetchstructure($conn, $seq);
            if ($structure) {
                $encoding = $structure->encoding ?? 0;
                if ($encoding === 3 || $encoding === 4) { // base64 / quoted-printable
                    if (function_exists('imap_base64') && $encoding === 3) {
                        $body = imap_base64($body);
                    } elseif (function_exists('imap_qprint') && $encoding === 4) {
                        $body = imap_qprint($body);
                    }
                }
            }
        }
        // Strip HTML to text for safety if it looks like HTML
        if (stripos((string)$body, '<html') !== false || stripos((string)$body, '<div') !== false || stripos((string)$body, '<p') !== false) {
            $plain = trim(preg_replace('/\s+/', ' ', (string)$body));
            $clean = $plain;
        } else {
            $clean = (string)$body;
        }
        $result = [
            'seqno'   => $seq,
            'date'    => $h->udate ?? null,
            'from'    => $from,
            'to'      => (isset($h->to[0]) ? ($h->to[0]->mailbox . '@' . $h->to[0]->host) : ''),
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'body'    => $clean,
            'html'    => $clean,
        ];
        imap_close($conn);
        return $result;
    }

    function imap_unread_count(): int
    {
        if (!imap_settings_configured()) {
            return 0;
        }
        $mb   = imap_mailbox_string();
        $conn = @imap_open($mb, setting('imap_username', ''), setting('imap_password', ''), OP_READONLY, 1);
        if (!$conn) {
            return 0;
        }
        $unread = 0;
        $check  = @imap_mailboxmsginfo($conn);
        if ($check && isset($check->Unseen)) {
            $unread = (int) $check->Unseen;
        }
        imap_close($conn);
        return $unread;
    }
}