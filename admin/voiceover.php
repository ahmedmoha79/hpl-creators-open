<?php
/**
 * Voiceovers (owner, 2026-11-12): turn a script into a natural voice with the site's own ElevenLabs key (Admin > Telegram and
 * AI help), for the site's videos. Admin only. The mp3 is kept 2 hours at an unguessable address in uploads/brainvoice (the
 * same place the phone calls use), so it can be downloaded and put into a video.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/brain_call.php';
// Transcribe (2026-11-12): words with their times from a short audio file (ElevenLabs speech to text), to cut the team's
// own videos. Works with an admin session, or with the 2-hour upload key from Admin > Ready clips (same key, same lock-out).
if (($_POST['action'] ?? '') === 'transcribe') {
    $sentKey = (string)($_SERVER['HTTP_X_HPL_UPLOAD_KEY'] ?? '');
    if ($sentKey !== '') {
        $tkKey = 'upkey:' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $hash = (string)setting('rc_upload_key_hash', '');
        if (throttle_blocked($tkKey, 10, 3600)) json_response(['ok' => false, 'error' => 'Too many wrong keys.'], 429);
        if ($hash === '' || (int)setting('rc_upload_key_until', '0') <= time() || !hash_equals($hash, hash('sha256', $sentKey))) {
            throttle_fail($tkKey, 3600);
            json_response(['ok' => false, 'error' => 'The upload key is wrong or has ended.'], 403);
        }
    } else {
        require_admin();
        csrf_check();
    }
    $f = $_FILES['audio'] ?? null;
    if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || (int)$f['size'] < 1000) json_response(['ok' => false, 'error' => 'No audio file arrived.'], 400);
    $out = brain_stt((string)$f['tmp_name'], (string)post('lang', 'en'));
    if ($out === null) json_response(['ok' => false, 'error' => (string)setting('brain_voice_last_error', 'no answer')], 502);
    json_response(['ok' => true, 'result' => $out]);
}
$user = require_admin();
$pageTitle = 'Voiceover';

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $text = trim((string)($_POST['text'] ?? ''));
    $voice = preg_replace('~[^A-Za-z0-9]~', '', (string)post('voice'));
    $model = in_array(post('model'), ['eleven_multilingual_v2', 'eleven_v3', 'eleven_turbo_v2_5'], true) ? post('model') : 'eleven_multilingual_v2';
    if ($text === '' || trim((string)setting('elevenlabs_api_key', '')) === '') {
        flash('error', $text === '' ? 'Type the words to say.' : 'Add the ElevenLabs key first (Admin > Telegram and AI help).');
    } else {
        // brain_tts reads the voice and model from settings: use this page's choice for this one call only.
        $GLOBALS['hpl_settings_cache']['elevenlabs_voice_id'] = $voice !== '' ? $voice : (string)setting('elevenlabs_voice_id', '');
        $GLOBALS['hpl_settings_cache']['elevenlabs_model'] = $model;
        $mp3 = brain_tts($text);
        if ($mp3 === null) {
            flash('error', 'ElevenLabs did not make it: ' . (string)setting('brain_voice_last_error', 'no answer'));
        } else {
            $dir = BASE_PATH . '/uploads/brainvoice';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); @file_put_contents($dir . '/index.html', ''); }
            foreach ((array)glob($dir . '/*.mp3') as $old) { if (is_file($old) && filemtime($old) < time() - 7200) @unlink($old); }
            $name = 'vo-' . bin2hex(random_bytes(12)) . '.mp3';
            file_put_contents($dir . '/' . $name, $mp3);
            $result = ['url' => abs_url('uploads/brainvoice/' . $name), 'bytes' => strlen($mp3)];
        }
    }
}
require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Voiceover</h1>
<p class="muted">Turns a script into a natural voice for the site's videos, with the ElevenLabs key saved in Telegram and AI help. Each one uses that account's credits.</p>
<?php if ($result): ?>
    <div class="panel" style="margin-bottom:12px"><div class="panel__body">
        <p style="margin-top:0"><strong>Ready</strong> (<?= num((int)round($result['bytes'] / 1024)) ?> KB, kept for 2 hours):</p>
        <audio controls src="<?= e($result['url']) ?>" style="width:100%"></audio>
        <p class="small" style="margin-bottom:0"><a id="voUrl" href="<?= e($result['url']) ?>" download>Download the mp3</a></p>
    </div></div>
<?php endif; ?>
<form method="post" class="panel"><div class="panel__body">
    <?= csrf_field() ?>
    <div class="field"><label class="label" for="text">Words to say</label><textarea class="input" id="text" name="text" rows="12" maxlength="2500" required><?= e((string)($_POST['text'] ?? '')) ?></textarea></div>
    <div class="form-grid">
        <div class="field"><label class="label" for="voice">Voice ID</label><input class="input" id="voice" name="voice" value="<?= e((string)($_POST['voice'] ?? setting('elevenlabs_voice_id', ''))) ?>" placeholder="Empty = the voice set in Telegram and AI help"></div>
        <div class="field"><label class="label" for="model">Model</label><select class="input" id="model" name="model">
            <?php foreach (['eleven_multilingual_v2' => 'Natural (multilingual v2)', 'eleven_v3' => 'Most expressive (v3)', 'eleven_turbo_v2_5' => 'Fast (turbo)'] as $mk => $ml): ?>
                <option value="<?= e($mk) ?>"<?= ($_POST['model'] ?? '') === $mk ? ' selected' : '' ?>><?= e($ml) ?></option>
            <?php endforeach; ?>
        </select></div>
    </div>
    <button class="btn btn--primary" type="submit">Make the voice</button>
</div></form>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
