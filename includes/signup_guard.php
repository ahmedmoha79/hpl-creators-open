<?php
/** Signup protections shared by password and Google registrations. */
function signup_limit(string $kind, int $limit): bool
{
    $dir = sys_get_temp_dir() . '/hpl-signup-' . substr(hash('sha256', BASE_PATH), 0, 16);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return false; }
    $key = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $file = @fopen($dir . '/' . $key . '.json', 'c+');
    if (!$file) { return false; }
    try {
        if (!flock($file, LOCK_EX)) { return false; }
        $state = json_decode(stream_get_contents($file), true) ?: [];
        $window = (int)floor(time() / 3600);
        if (($state['window'] ?? -1) !== $window) { $state = ['window' => $window]; }
        $count = (int)($state[$kind] ?? 0);
        if ($count >= $limit) { return false; }
        $state[$kind] = $count + 1;
        rewind($file); ftruncate($file, 0);
        $json = json_encode($state);
        return fwrite($file, $json) === strlen($json) && fflush($file);
    } finally { flock($file, LOCK_UN); fclose($file); }
}
// 2026-11-10 (launch): the limits are per internet address, and in Zambia hundreds of phones share one address
// (MTN, Airtel and Zamtel put many customers behind the same one). 5 new accounts an hour would turn real people
// away on a busy day, so the floor is now 60 accounts and 300 tries an hour per address, whatever was saved before.
function signup_attempt_limit(): int { return max(300, min(5000, (int)setting('signup_attempt_limit', '300'))); }
function signup_creation_limit(): int { return max(60, min(2000, (int)setting('signup_creation_limit', '60'))); }
function signup_turnstile_enabled(): bool { return setting('signup_turnstile_enabled', '0') === '1'; }
function signup_turnstile_result_valid(?array $data): bool
{
    $host = strtolower(trim((string)setting('signup_hostname', 'golddetectorinzambia.com')));
    return $data !== null && ($data['success'] ?? false) === true
        && ($data['action'] ?? '') === 'signup'
        && in_array(strtolower((string)($data['hostname'] ?? '')), [$host, 'www.' . $host], true);
}
function signup_guard_check(): ?string
{
    if (!signup_limit('attempts', signup_attempt_limit())) { return 'Too many signup attempts. Please try again in an hour or contact support.'; }
    $token = post('signup_nonce');
    $form = $_SESSION['signup_forms'][$token] ?? null;
    if ($token !== '') { unset($_SESSION['signup_forms'][$token]); }
    if (post('company_website') !== '') {
        return 'Please reload this page, wait a few seconds and try again.';
    }
    // Filled in under 2 seconds (password managers can be that quick) or the page was open for hours:
    // the form is shown again with a fresh check, so one more tap is all a real person needs.
    if (!$form || time() - (int)$form < 2 || time() - (int)$form > 6 * 3600) {
        return 'Almost there. Check your details and tap the button again.';
    }
    if (signup_turnstile_enabled()) {
        $secret = (string)setting('signup_turnstile_secret', '');
        $response = post('cf-turnstile-response');
        if ($secret === '' || $response === '' || strlen($response) > 2048 || !function_exists('curl_init')) {
            return 'Security verification is unavailable. Please try again later or contact support.';
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $response]), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $raw = curl_exec($ch); curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!signup_turnstile_result_valid(is_array($data) ? $data : null)) { return 'Security verification failed or expired. Please try again.'; }
    }
    return null;
}
function signup_guard_fields(): void
{
    $forms = $_SESSION['signup_forms'] ?? [];
    foreach ($forms as $token => $created) { if (time() - (int)$created > 3600) { unset($forms[$token]); } }
    $forms = array_slice($forms, -9, null, true);
    $nonce = bin2hex(random_bytes(24)); $forms[$nonce] = time(); $_SESSION['signup_forms'] = $forms;
    echo '<input type="hidden" name="signup_nonce" value="' . e($nonce) . '">';
    echo '<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden"><label>Leave this field empty<input name="company_website" type="text" tabindex="-1" autocomplete="off"></label></div>';
    if (signup_turnstile_enabled()) {
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><div class="cf-turnstile" data-size="flexible" data-action="signup" data-sitekey="' . e(setting('signup_turnstile_sitekey', '')) . '"></div>';
    }
}
