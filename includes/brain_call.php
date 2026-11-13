<?php
/**
 * HPL Brain: speaking (owner, 2026-11-12).
 *  - Voice notes on Telegram: the Brain's update read aloud by a natural voice (ElevenLabs text-to-speech, the site's own key),
 *    sent with sendVoice after the text message. Needs `elevenlabs_api_key`; voice `elevenlabs_voice_id`.
 *  - Phone calls for "Needs you now" (Twilio): rings the owner's phone and explains the problem. With ElevenLabs the call plays the
 *    same natural voice (a short-lived mp3 under uploads/brainvoice/<random>.mp3, deleted after 2 hours); without it Twilio's own
 *    voice reads the text. Needs `twilio_sid`, `twilio_auth_token` (secret), `twilio_from` (a Twilio number) and `brain_call_to`.
 *    At most `brain_call_max` (3) calls a day, never in quiet hours (22:00 to 07:00) unless it is about money.
 * Keys are typed by the owner in Admin > Telegram and AI help; they are never printed back into the page.
 */

function brain_voice_ready(): bool { return trim((string)setting('elevenlabs_api_key', '')) !== ''; }
function brain_call_ready(): bool
{
    return trim((string)setting('twilio_sid', '')) !== '' && trim((string)setting('twilio_auth_token', '')) !== ''
        && trim((string)setting('twilio_from', '')) !== '' && brain_call_to() !== '';
}
/** The owner's phone in +260... form. */
function brain_call_to(): string
{
    $n = preg_replace('~[^0-9+]~', '', (string)setting('brain_call_to', ''));
    if ($n === '') return '';
    if ($n[0] !== '+') { $n = '+260' . ltrim(preg_replace('~^260~', '', $n), '0'); }
    return $n;
}

/** Text to speech (mp3 bytes) or null. Spoken text: no symbols, short sentences. */
function brain_tts(string $text): ?string
{
    $key = trim((string)setting('elevenlabs_api_key', ''));
    if ($key === '' || !function_exists('curl_init')) return null;
    $text = trim(preg_replace(['~https?://\S+~', '~[*_#>`•]+~u', '~\s+~u'], ['', '', ' '], $text));
    if ($text === '') return null;
    $voice = preg_replace('~[^A-Za-z0-9]~', '', (string)setting('elevenlabs_voice_id', '')) ?: 'JBFqnCBsd6RMkjVDRZzb';
    $ch = curl_init('https://api.elevenlabs.io/v1/text-to-speech/' . $voice . '?output_format=mp3_44100_64');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['xi-api-key: ' . $key, 'Content-Type: application/json', 'Accept: audio/mpeg'],
        CURLOPT_POSTFIELDS => json_encode(['text' => mb_substr($text, 0, 2500), 'model_id' => (string)setting('elevenlabs_model', '') ?: 'eleven_multilingual_v2',
            'voice_settings' => ['stability' => 0.45, 'similarity_boost' => 0.8]]),
    ]);
    $mp3 = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code !== 200 || !is_string($mp3) || strlen($mp3) < 1000 || stripos($type, 'audio') === false) {
        error_log('brain tts: HTTP ' . $code . ' ' . substr((string)$mp3, 0, 200));
        set_setting('brain_voice_last_error', 'ElevenLabs answered ' . $code . ': ' . mb_substr(strip_tags((string)$mp3), 0, 160));
        return null;
    }
    set_setting('brain_voice_last_error', '');
    return $mp3;
}

/** Speech to text with word times (ElevenLabs scribe_v1). Returns the decoded answer, or null (reason in brain_voice_last_error). */
function brain_stt(string $file, string $lang = 'en'): ?array
{
    $key = trim((string)setting('elevenlabs_api_key', ''));
    if ($key === '' || !function_exists('curl_init') || !is_file($file)) return null;
    $ch = curl_init('https://api.elevenlabs.io/v1/speech-to-text');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['xi-api-key: ' . $key],
        CURLOPT_POSTFIELDS => ['model_id' => 'scribe_v1', 'language_code' => preg_replace('~[^a-z]~', '', $lang) ?: 'en',
            'timestamps_granularity' => 'word', 'tag_audio_events' => 'false',
            'file' => new CURLFile($file, 'audio/mpeg', 'audio.mp3')],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    if ($code !== 200 || !is_array($j)) {
        set_setting('brain_voice_last_error', 'ElevenLabs answered ' . $code . ': ' . mb_substr(strip_tags((string)$body), 0, 160));
        return null;
    }
    return $j;
}

/** Send a voice note to the owner's Telegram. */
function brain_send_voice_note(string $text, string $caption = ''): bool
{
    require_once __DIR__ . '/telegram.php';
    if (!tg_ready() || setting('brain_voice_notes', '1') !== '1') return false;
    $mp3 = brain_tts($text);
    if ($mp3 === null) return false;
    $tmp = tempnam(sys_get_temp_dir(), 'hplv') . '.mp3';
    file_put_contents($tmp, $mp3);
    $ok = false;
    if (getenv('HPL_TG_FAKE')) { // local tests: record instead of sending
        file_put_contents(getenv('HPL_TG_FAKE'), json_encode(['sendVoice', ['bytes' => strlen($mp3), 'caption' => $caption]]) . "\n", FILE_APPEND);
        $ok = true;
    } else {
        $ch = curl_init('https://api.telegram.org/bot' . tg_token() . '/sendVoice');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => ['chat_id' => tg_owner(), 'voice' => new CURLFile($tmp, 'audio/mpeg', 'brain.mp3'), 'caption' => mb_substr($caption, 0, 900)]]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $d = json_decode((string)$raw, true);
        $ok = is_array($d) && !empty($d['ok']);
        if (!$ok) set_setting('brain_voice_last_error', 'Telegram refused the voice note: ' . mb_substr((string)($d['description'] ?? 'no answer'), 0, 160));
    }
    @unlink($tmp);
    return $ok;
}

/** The TwiML that waits for the owner to speak and sends it to api/brain_talk.php. */
function brain_talk_listen(string $prompt): string
{
    $say = fn(string $t) => '<Say voice="Polly.Amy-Neural">' . htmlspecialchars($t, ENT_XML1) . '</Say>';
    return '<Gather input="speech" action="' . htmlspecialchars(abs_url('api/brain_talk.php'), ENT_XML1) . '" method="POST" speechTimeout="auto" timeout="7" language="en-GB" actionOnEmptyResult="true">'
        . $say($prompt) . '</Gather>' . $say('I did not hear anything. Talk to you later. Goodbye.') . '<Hangup/>';
}

/** Ring the owner and say $text. Returns [ok, message]. $money lets it ring in quiet hours. */
function brain_phone_call(string $text, bool $money = false, bool $test = false): array
{
    if (!brain_call_ready()) return [false, 'Phone calls are not set up yet (Twilio account, number and your phone).'];
    if (!$test) {
        if (setting('brain_calls_on', '1') !== '1') return [false, 'Calls are switched off.'];
        $h = (int)date('G');
        if (!$money && ($h >= 22 || $h < 7)) return [false, 'Quiet hours: no call, Telegram only.'];
        $day = date('Y-m-d');
        $cnt = json_decode((string)setting('brain_call_count', '{}'), true) ?: [];
        if ((int)($cnt[$day] ?? 0) >= max(1, (int)setting('brain_call_max', '3'))) return [false, 'Already called the most times for today.'];
        set_setting('brain_call_count', json_encode([$day => (int)($cnt[$day] ?? 0) + 1]));
    }
    $spoken = 'Hello, this is the HPL Brain. ' . trim(preg_replace(['~https?://\S+~', '~[*_#>`•⚠️🧠]+~u', '~\s+~u'], ['', '', ' '], $text)) . ' The details are on your Telegram.';
    // Natural voice when ElevenLabs is set: a short-lived, unguessable mp3 the call plays.
    $twiml = null;
    $mp3 = brain_voice_ready() ? brain_tts($spoken) : null;
    if ($mp3 !== null) {
        $dir = BASE_PATH . '/uploads/brainvoice';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); @file_put_contents($dir . '/index.html', ''); }
        foreach ((array)glob($dir . '/*.mp3') as $old) { if (is_file($old) && filemtime($old) < time() - 7200) @unlink($old); }
        $name = bin2hex(random_bytes(16)) . '.mp3';
        if (@file_put_contents($dir . '/' . $name, $mp3) !== false) {
            $twiml = '<Play>' . htmlspecialchars(abs_url('uploads/brainvoice/' . $name), ENT_XML1) . '</Play>';
        }
    }
    if ($twiml === null) {
        $twiml = '<Say voice="Polly.Amy-Neural">' . htmlspecialchars(mb_substr($spoken, 0, 1500), ENT_XML1) . '</Say>';
    }
    // Then it listens: the owner can ask questions and talk back (api/brain_talk.php answers each one).
    $twiml = '<Response>' . $twiml . brain_talk_listen('What would you like to know? Ask me anything about the app, or say goodbye.') . '</Response>';
    if (getenv('HPL_TG_FAKE')) {
        file_put_contents(getenv('HPL_TG_FAKE'), json_encode(['twilioCall', ['to' => brain_call_to(), 'twiml' => $twiml]]) . "\n", FILE_APPEND);
        return [true, 'Test call recorded.'];
    }
    $sid = trim((string)setting('twilio_sid', ''));
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Calls.json');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_USERPWD => $sid . ':' . trim((string)setting('twilio_auth_token', '')),
        CURLOPT_POSTFIELDS => http_build_query(['To' => brain_call_to(), 'From' => trim((string)setting('twilio_from', '')), 'Twiml' => $twiml])]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string)$raw, true);
    if ($code >= 200 && $code < 300) {
        set_setting('brain_call_last_error', '');
        return [true, 'Calling ' . brain_call_to() . ' now.'];
    }
    $msg = 'Twilio answered ' . $code . ': ' . mb_substr((string)($d['message'] ?? 'no answer'), 0, 200);
    set_setting('brain_call_last_error', $msg);
    return [false, $msg];
}

/** "Call me with a briefing": the Brain looks at today, words it like the app's developer talking, and rings the owner. */
function brain_briefing_call(): array
{
    require_once __DIR__ . '/brain.php';
    require_once __DIR__ . '/brain_voice.php';
    brain_run(true);
    $p = brain_pulse(strtotime('today'));
    $p['totals'] = [
        'creators' => (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND status = "active" AND is_demo = 0', [], 0),
        'passed_test_ever' => (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND qualified = 1', [], 0),
        'tiktok_connected_ever' => (int)fetch_val('SELECT COUNT(DISTINCT user_id) FROM tiktok_accounts', [], 0),
        'open_campaigns' => (int)fetch_val('SELECT COUNT(*) FROM campaigns WHERE status = "active" AND camp_status = "accepting"', [], 0),
    ];
    $t = $p['totals'];
    $text = brain_word_with_claude('briefing', $p)
        ?? ('Here is the big picture. We have ' . $t['creators'] . ' creators. ' . $t['passed_test_ever'] . ' have passed the test and ' . $t['tiktok_connected_ever'] . ' have connected TikTok. '
            . $t['open_campaigns'] . ' campaign' . ($t['open_campaigns'] === 1 ? ' is' : 's are') . ' open. ' . brain_word_plain($p));
    return brain_phone_call($text, true, true);
}

/**
 * Briefing calls on a schedule, run by the server itself (no laptop needed): a one-off time (`brain_brief_call_at`, unix time,
 * set by "in N minutes" or the Telegram command /call) and/or every day at `brain_brief_daily` ("HH:MM"). Checked on page views
 * (the site has no cron) by hpl_autosync_maybe(); the call itself runs after the page has been sent.
 */
function brain_brief_schedule(int $minutes): int
{
    $at = time() + max(0, $minutes) * 60;
    set_setting('brain_brief_call_at', (string)$at);
    return $at;
}

function brain_brief_due(): void
{
    $now = time();
    $once = (int)setting('brain_brief_call_at', '0');
    $due = $once > 0 && $once <= $now && $once > $now - 3 * 3600;
    $daily = (string)setting('brain_brief_daily', '');
    $dayKey = date('Y-m-d');
    if (!$due && preg_match('~^([01]?\d|2[0-3]):([0-5]\d)$~', $daily, $m) && $now >= strtotime($dayKey . ' ' . $m[1] . ':' . $m[2]) && (string)setting('brain_brief_daily_done', '') !== $dayKey) {
        set_setting('brain_brief_daily_done', $dayKey);
        $due = true;
    } elseif ($due) {
        if (q('UPDATE settings SET setting_value = "0" WHERE setting_key = "brain_brief_call_at" AND setting_value = ?', [(string)$once])->rowCount() !== 1) return; // another request took it
    }
    if (!$due) {
        if ($once > 0 && $once <= $now - 3 * 3600) set_setting('brain_brief_call_at', '0'); // too old: forget it
        return;
    }
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(90);
        try {
            [$ok, $msg] = brain_briefing_call();
            if (!$ok) {
                require_once __DIR__ . '/telegram.php';
                if (tg_ready()) tg_send('📞 The briefing call did not go through: ' . tg_h($msg));
            }
        } catch (Throwable $e) {
            error_log('brain brief call: ' . $e->getMessage());
        }
    });
}
