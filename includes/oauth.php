<?php
/** Real Google OAuth only. Simulated email-only sign-in is disabled. */

function google_configured(): bool
{
    return setting('google_client_id', '') !== '' && setting('google_client_secret', '') !== '';
}

function google_sandbox_enabled(): bool
{
    return false; // Simulated email-only sign-in is disabled.
}

function google_redirect_uri(): string
{
    $host = ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host . u('google_auth.php');
}

/** Build the Google authorization URL (real flow). */
function google_auth_url(): string
{
    $params = http_build_query([
        'client_id'     => setting('google_client_id', ''),
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
        'state'         => csrf_token(),
    ]);
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
}

/** Exchange the auth code for a token, then fetch the profile. Returns null on failure. */
function google_exchange_code(string $code): ?array
{
    $token = http_post_json('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => setting('google_client_id', ''),
        'client_secret' => setting('google_client_secret', ''),
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
    if (!$token || empty($token['id_token'])) {
        return null;
    }
    // The id_token came straight from Google's token endpoint over HTTPS, so its claims can be
    // trusted without a second network call (Google's guidance). Check it is for our app and fresh.
    $parts = explode('.', (string)$token['id_token']);
    if (count($parts) === 3) {
        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (is_array($claims) && !empty($claims['sub'])
            && (string)($claims['aud'] ?? '') === (string)setting('google_client_id', '')
            && in_array((string)($claims['iss'] ?? ''), ['https://accounts.google.com', 'accounts.google.com'], true)
            && (int)($claims['exp'] ?? 0) > time() && !empty($claims['email'])) {
            $claims['email_verified'] = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
            return $claims;
        }
    }
    // Verify + decode the id_token (HS/RS algorithms). For robustness we prefer
    // the userinfo endpoint with the access token when present.
    if (!empty($token['access_token'])) {
        $info = http_get_json('https://www.googleapis.com/oauth2/v3/userinfo', $token['access_token']);
        if ($info && !empty($info['sub'])) {
            return $info;
        }
    }
    return null;
}

/** A simulated Google profile for offline/local testing. */
function google_simulated_profile(string $email): array
{
    $name = 'Sim' . 'ulated ' . ucfirst(substr(hash('crc32', $email), 0, 6));
    return [
        'sub'         => 'g-sandbox-' . hash('crc32', $email),
        'email'       => $email,
        'email_verified' => true,
        'name'        => trim($name),
        'given_name'  => 'Simulated',
        'picture'     => '',
    ];
}

/** Log a user in from a verified Google profile, creating the account if needed. */
function google_login_profile(array $profile, string $emailOverride = ''): array|string
{
    if (($profile['email_verified'] ?? false) !== true || empty($profile['sub'])) { return 'Google must verify your email address before sign-in.'; }
    $email = strtolower(trim($emailOverride !== '' ? $emailOverride : ($profile['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'We couldn\'t read a valid email from your Google account.';
    }
    $googleId = (string)($profile['sub'] ?? '');
    $name = trim((string)($profile['name'] ?? ''));
    $phone = '';

    $user = fetch_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user) {
        require_once __DIR__ . '/signup_guard.php';
        if (!signup_limit('created', signup_creation_limit())) { return 'Signup limit reached. Please try again in an hour.'; }
        if ($name === '') {
            $name = explode('@', $email)[0];
        }
        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        q(
            'INSERT INTO users (full_name, email, phone, password, google_id, auth_provider, role, status) VALUES (?,?,?,?,?,?, "creator", "active")',
            [$name, $email, $phone, $password, $googleId, 'google']
        );
        $user = fetch_one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user) {
            require_once __DIR__ . '/signup_source.php';
            signup_source_save((int)$user['id']); // their answer is asked on the dashboard
            try { require_once __DIR__ . '/visits.php'; visit_signed_up((int)$user['id']); } catch (Throwable $e) {}
            require_once __DIR__ . '/bonus.php';
            bonus_grant_signup((int)$user['id']);
            if (setting('tg_owner_chat_id', '') !== '') {
                try { require_once __DIR__ . '/telegram.php'; tg_alert_signup((int)$user['id']); } catch (Throwable $e) {}
            }
        }
    } else {
        // Link google_id if this account was password-based.
        if ((string)($user['google_id'] ?? '') === '' && (string)$user['auth_provider'] === 'password') {
            q('UPDATE users SET google_id = ? WHERE id = ?', [$googleId, (int)$user['id']]);
            $user = fetch_one('SELECT * FROM users WHERE email = ?', [$email]);
        }
    }

    if (!$user || $user['status'] === 'suspended') {
        return 'Your account has been suspended. Please contact support.';
    }

    // Google only signs in addresses it has verified, so the email is confirmed.
    require_once __DIR__ . '/email_verify.php';
    email_mark_verified((int)$user['id']);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    return $user;
}

/* -------------------------------------------------------------------------- */
/* Small HTTP helpers (cURL).                                                  */
/* -------------------------------------------------------------------------- */

function http_post_json(string $url, array $fields): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // avoid slow IPv6 fallbacks on shared hosting
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function http_get_json(string $url, string $bearer = ''): ?array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // avoid slow IPv6 fallbacks on shared hosting
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}