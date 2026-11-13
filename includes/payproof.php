<?php
/**
 * Proof of payment (owner, 2026-11-12): payouts are sent by hand from the owner's mobile money, so every payout keeps
 *  - the screenshot of the mobile money "sent" message, saved privately in storage/payproof (never a public address),
 *  - the transaction reference, number, amount and time,
 *  - the creator's answer: "received", "not_received", or "auto" when they did not answer within payproof_days().
 * withdrawals.receipt: NULL = paid before this existed, waiting = asked the creator.
 */

function payproof_days(): int { return 3; }

function payproof_dir(): string
{
    $d = dirname(__DIR__) . '/storage/payproof';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/**
 * Mark one pending withdrawal as paid (single, fast list and "all ticked" share this). Re-checks under a lock that the creator
 * still has the money. The creator is told and asked "I got it". Returns [ok, message].
 */
function payout_mark_paid(int $id, string $provider, string $txn, string $phone = '', string $note = '', ?string $proofFile = null): array
{
    require_once __DIR__ . '/earnings.php';
    $w = fetch_one('SELECT w.*, u.full_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$id]);
    if (!$w || $w['status'] !== 'pending') {
        return [false, 'This request was already handled.'];
    }
    if (!array_key_exists($provider, mobile_providers())) {
        $provider = (string)($w['payment_provider'] ?: '');
        if (!array_key_exists($provider, mobile_providers())) {
            return [false, 'Choose the network you paid with.'];
        }
    }
    $txn = mb_substr(trim($txn), 0, 100);
    if ($txn === '') {
        return [false, 'Paste the transaction ID from the mobile money message.'];
    }
    $phone = $phone !== '' ? $phone : (string)($w['paid_to_number'] ?? '');
    $done = hpl_tx(function () use ($w, $id, $provider, $txn, $phone, $note, $proofFile) {
        q('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int)$w['user_id']]);
        $cur = fetch_one('SELECT amount, status FROM withdrawals WHERE id = ? FOR UPDATE', [$id]);
        if (!$cur || $cur['status'] !== 'pending') {
            return 0;
        }
        $st = creator_stats((int)$w['user_id']);
        if ((float)$cur['amount'] > round($st['available'] + (float)$cur['amount'], 2) + 0.001) {
            return -1;
        }
        return q('UPDATE withdrawals SET status = "paid", payment_provider = ?, txn_reference = ?, paid_to_number = ?, admin_note = ?, resolved_at = NOW(), proof_file = ?, receipt = "waiting" WHERE id = ? AND status = "pending"',
            [$provider, $txn, $phone ?: null, $note ?: 'Paid via ' . mobile_providers()[$provider]['label'], $proofFile, $id])->rowCount();
    });
    if ($done === -1) {
        $st = creator_stats((int)$w['user_id']);
        return [false, $w['full_name'] . ' now has only ' . money(max(0, $st['available'] + (float)$w['amount'])) . ' to withdraw'
            . ($st['in_review'] > 0 ? ' (' . money($st['in_review']) . ' waits for you to approve their clips)' : ' (a clip was rejected after they asked)') . '. Not marked as paid.'];
    }
    if ($done !== 1) {
        return [false, 'This request was already handled.'];
    }
    notif_add((int)$w['user_id'], 'withdrawal', 'Withdrawal paid: please confirm',
        money($w['amount']) . ' was sent to ' . ($phone ? zm_phone_pretty((string)$phone) : 'your mobile money') . '. Ref: ' . $txn . '. Open Wallet and tap "I got it", or "I did not get it". No answer in ' . payproof_days() . ' days counts as received.', 'creator/withdraw.php');
    try { require_once __DIR__ . '/community.php'; chat_event((int)$w['user_id'], 'wins', 'got paid ' . money($w['amount'])); } catch (Throwable $e) {}
    return [true, money($w['amount']) . ' to ' . $w['full_name'] . ' marked as paid.'];
}

/** Save an uploaded screenshot ($_FILES entry). Re-drawn as JPEG (no scripts or hidden data kept). Returns the file name or an error. */
function payproof_save(?array $f): array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'Add the screenshot of the "sent" message from your mobile money.'];
    }
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        return [null, 'The screenshot did not arrive. Try again.'];
    }
    if ((int)$f['size'] > 12 * 1048576) {
        return [null, 'The screenshot is too big (12 MB at most).'];
    }
    if (!function_exists('imagecreatefromstring')) {
        return [null, 'This server cannot read pictures right now.'];
    }
    $img = @imagecreatefromstring((string)file_get_contents((string)$f['tmp_name']));
    if (!$img) {
        return [null, 'That file is not a picture. Send a screenshot (JPG or PNG).'];
    }
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, 1600 / max($w, $h));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $ok = imagejpeg($out, payproof_dir() . '/' . $name, 85);
    imagedestroy($img); imagedestroy($out);
    return $ok ? [$name, null] : [null, 'The screenshot could not be saved. Check the server space.'];
}

/** Send the proof picture of a withdrawal to the browser (callers check who may see it). */
function payproof_send(array $w): void
{
    $file = payproof_dir() . '/' . basename((string)($w['proof_file'] ?? ''));
    if (empty($w['proof_file']) || !is_file($file)) {
        http_response_code(404);
        exit('No proof picture for this payout.');
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

/** What the creator has said about a paid withdrawal, in plain words (for the admin). */
function payproof_receipt_text(array $w): string
{
    $at = !empty($w['receipt_at']) ? date('j M, H:i', strtotime((string)$w['receipt_at'])) : '';
    switch ((string)($w['receipt'] ?? '')) {
        case 'received': return 'Creator confirmed: received (' . $at . ')';
        case 'not_received': return 'Creator says NOT received (' . $at . ')' . (!empty($w['receipt_note']) ? ': ' . $w['receipt_note'] : '');
        case 'auto': return 'Counted as received: no answer in ' . payproof_days() . ' days';
        case 'waiting': return 'Waiting for the creator to confirm (counted as received after ' . payproof_days() . ' days)';
        default: return '';
    }
}

/** Unanswered for payproof_days(): counted as received. Run by the background sync. */
function payproof_autoconfirm(): int
{
    try {
        return q('UPDATE withdrawals SET receipt = "auto", receipt_at = NOW() WHERE status = "paid" AND receipt = "waiting" AND resolved_at < NOW() - INTERVAL ? DAY', [payproof_days()])->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}
