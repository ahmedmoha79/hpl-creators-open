<?php
/**
 * Where sign-ups come from (2026-11-05).
 * 1) The person's own answer: "How did you hear about HPL Creators?" (sign-up form, or asked once on the
 *    dashboard for Google sign-ups and older accounts).
 * 2) Automatic clues kept in a cookie from their first visit: the site that sent them (referrer),
 *    ad tags (?utm_source=...), the first page they landed on, and the app they browsed in (TikTok, Facebook...).
 */

function heard_options(): array
{
    return [
        'tiktok' => 'TikTok',
        'facebook' => 'Facebook',
        'whatsapp' => 'WhatsApp (group or status)',
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',
        'google' => 'Google search',
        'friend' => 'A friend or family member',
        'hpl' => 'HPL shop, staff or event',
        'ad' => 'An advert',
        'other' => 'Somewhere else',
    ];
}

/** Which app's built-in browser is this (TikTok, Facebook...)? '' for a normal browser. */
function signup_app_from_ua(string $ua): string
{
    $map = ['TikTok' => '/musical_ly|BytedanceWebview|TikTok/i', 'Facebook' => '/FBAN|FBAV|FB_IAB/i', 'Instagram' => '/Instagram/i',
        'WhatsApp' => '/WhatsApp/i', 'Snapchat' => '/Snapchat/i', 'Telegram' => '/Telegram/i', 'LinkedIn' => '/LinkedInApp/i'];
    foreach ($map as $name => $re) {
        if (preg_match($re, $ua)) {
            return $name;
        }
    }
    return '';
}

/** Remember where this visitor first came from (first visit wins; a new ad link replaces it). */
function signup_source_capture(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    $utm = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'ref'] as $k) {
        $v = trim((string)($_GET[$k] ?? ''));
        if ($v !== '') {
            $utm[$k] = mb_substr(preg_replace('/[^\w.\-@ ]/u', '', $v), 0, 60);
        }
    }
    if (!empty($_COOKIE['hpl_src']) && !$utm) {
        return;
    }
    $refHost = '';
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $h = strtolower((string)parse_url($ref, PHP_URL_HOST));
        $own = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($h !== '' && $h !== $own && !str_ends_with($h, '.' . $own)) {
            $refHost = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $h);
        }
    }
    $data = [
        'r' => mb_substr($refHost, 0, 80),
        'u' => $utm,
        'l' => mb_substr((string)strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '#'), 0, 120),
        'a' => signup_app_from_ua((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        't' => time(),
    ];
    setcookie('hpl_src', base64_encode(json_encode($data)), ['expires' => time() + 90 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['hpl_src'] = base64_encode(json_encode($data));
}

/** Save the automatic clues on a new account. */
function signup_source_save(int $userId): void
{
    $d = json_decode((string)base64_decode((string)($_COOKIE['hpl_src'] ?? ''), true), true);
    $d = is_array($d) ? $d : [];
    $app = signup_app_from_ua((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?: (string)($d['a'] ?? '');
    $utm = !empty($d['u']) && is_array($d['u']) ? http_build_query($d['u']) : '';
    try {
        q('UPDATE users SET signup_ref = ?, signup_utm = ?, signup_landing = ?, signup_app = ? WHERE id = ?',
            [mb_substr((string)($d['r'] ?? ''), 0, 80) ?: null, mb_substr($utm, 0, 200) ?: null, mb_substr((string)($d['l'] ?? ''), 0, 120) ?: null, $app ?: null, $userId]);
    } catch (Throwable $e) {
    }
}

/** Save the person's own answer. Returns an error message, or null. */
function heard_save(int $userId, string $from, string $detail): ?string
{
    if (!isset(heard_options()[$from])) {
        return 'Please choose how you heard about HPL Creators.';
    }
    q('UPDATE users SET heard_from = ?, heard_detail = ?, heard_at = NOW() WHERE id = ?', [$from, mb_substr(trim($detail), 0, 120) ?: null, $userId]);
    return null;
}

/** A short line for admins: "TikTok (friend: Mwila) · came from TikTok app". */
function signup_source_line(array $u): string
{
    $parts = [];
    if (!empty($u['heard_from'])) {
        $parts[] = (heard_options()[$u['heard_from']] ?? $u['heard_from']) . (!empty($u['heard_detail']) ? ': ' . $u['heard_detail'] : '');
    }
    $auto = [];
    if (!empty($u['signup_app'])) {
        $auto[] = 'opened in ' . $u['signup_app'];
    }
    if (!empty($u['signup_ref'])) {
        $auto[] = 'from ' . $u['signup_ref'];
    }
    if (!empty($u['signup_utm'])) {
        $auto[] = $u['signup_utm'];
    }
    if ($auto) {
        $parts[] = '(' . implode(', ', $auto) . ')';
    }
    return $parts ? implode(' ', $parts) : '';
}

/** The question as form fields (select + optional detail). */
function heard_fields(string $from = '', string $detail = '', ?string $error = null): void
{
    ?>
    <div class="field">
        <label class="label" for="heard_from">How did you hear about HPL Creators?</label>
        <select class="input" id="heard_from" name="heard_from" required onchange="document.getElementById('heardDetailWrap').style.display=this.value?'block':'none'">
            <option value="">Choose one</option>
            <?php foreach (heard_options() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $from === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($error): ?><div class="field__error"><?= e($error) ?></div><?php endif; ?>
    </div>
    <div class="field" id="heardDetailWrap" style="<?= $from === '' ? 'display:none' : '' ?>">
        <label class="label" for="heard_detail">Tell us more <span class="muted">(optional: which page, group or friend?)</span></label>
        <input class="input" type="text" id="heard_detail" name="heard_detail" maxlength="120" value="<?= e($detail) ?>" placeholder="e.g. @hpldetectors.zambia video, or my friend Mwila">
    </div>
    <?php
}
