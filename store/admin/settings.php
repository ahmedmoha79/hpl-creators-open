<?php
require __DIR__ . '/_boot.php';
require_admin_page();

$plain = [
    'co_legal' => ['Company legal name', 'Hayati Prospectors Limited'],
    'co_brand' => ['Brand name', 'HPL Gold Detectors'],
    'co_pacra' => ['PACRA registration number', '120251029003'],
    'co_tpin' => ['TPIN', '2003978298'],
    'co_address' => ['Showroom street address', 'e.g. Plot 123, Cairo Road'],
    'co_city' => ['City, country', 'Lusaka, Zambia'],
    'co_map_url' => ['Google Maps link to the showroom', 'https://maps.app.goo.gl/…'],
    'co_hours' => ['Opening hours', 'Mon–Sat 08:00–17:00'],
    'co_phone' => ['Phone number', '+260 …'],
    'co_phone2' => ['Second phone number', '+260 …'],
    'co_whatsapp' => ['WhatsApp number (if different)', '+260 …'],
    'co_email' => ['Public email', 'sales@…'],
    'co_tiktok' => ['TikTok', ''],
    'co_facebook' => ['Facebook page', ''], 'co_instagram' => ['Instagram', ''], 'co_youtube' => ['YouTube', ''],
    'co_website' => ['Company website', 'https://hayatiprospectors.com'],
    'co_pacra_url' => ['PACRA search link', 'https://search.pacra.org.zm/'],
    'usd_rate' => ['Kwacha per 1 US dollar (dollar guide price, and the amount asked on the USD bank account)', '25'],
    'bank_beneficiary' => ['Bank: account name (beneficiary)', 'Hayati Prospectors Limited'],
    'bank_name' => ['Bank: bank name', 'Absa Bank Zambia PLC'],
    'bank_branch' => ['Bank: branch', 'Kitwe City Square'],
    'bank_swift' => ['Bank: SWIFT code', 'BARCZMLX'],
    'bank_sort' => ['Bank: sort code', '020209'],
    'bank_acc_zmw' => ['Bank: kwacha (ZMW) account number', ''],
    'bank_acc_usd' => ['Bank: US dollar (USD) account number (empty = kwacha only)', ''],
    'bank_corr_bank' => ['Bank: USD correspondent bank', 'JP Morgan, New York'],
    'bank_corr_swift' => ['Bank: USD correspondent SWIFT code', 'CHASUS33'],
    'google_verify' => ['Google Search Console code (the content="..." part of the meta tag)', ''],
    'alert_email' => ['Send chat and order alerts to this email', ''],
    'tg_chat' => ['Telegram chat ID that receives the alerts', ''],
];
$multi = [
    'chat_greeting' => ['First message in every new chat', 2],
    'tpl_wu' => ['Western Union details (sent with one tap in the chat)', 5],
    'tpl_mg' => ['MoneyGram details', 5],
    'tpl_bank' => ['Bank transfer (SWIFT) details', 6],
    'tpl_quote' => ['Delivery quote message', 4],
    'page_terms' => ['Terms of sale (empty = standard text)', 6],
    'page_delivery' => ['Delivery and returns (empty = standard text)', 6],
    'page_privacy' => ['Privacy (empty = standard text)', 6],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($plain as $k => $_) {
        if (!array_key_exists($k, $_POST)) continue;
        $val = mb_substr(trim((string)$_POST[$k]), 0, 400);
        if ($k === 'usd_rate' && $val !== '' && (float)$val <= 0) { flash('err', 'The dollar rate must be a number like 25.'); continue; }
        if (preg_match('~(_url|_website|tiktok|facebook|instagram|youtube)$~', $k) && $val !== '' && !safe_href($val)) {
            flash('err', 'Links must start with https://. "' . $plain[$k][0] . '" was not saved.');
            continue;
        }
        cfg_set($k, $val);
    }
    foreach ($multi as $k => $_) {
        cfg_set($k, post_langs($k, 6000));
    }
    cfg_set('co_branches', mb_substr(trim(post('co_branches')), 0, 1000));
    cfg_set('co_receivers', mb_substr(trim(post('co_receivers')), 0, 1000));
    if (post('tg_token') !== '') cfg_set('tg_token', mb_substr(post('tg_token'), 0, 200));
    if (post('tg_token_clear') === '1') cfg_set('tg_token', '');
    cfg_set('tg_alerts', post('tg_alerts') ? '1' : '0');
    cfg_set('email_alerts', post('email_alerts') ? '1' : '0');
    if (!empty($_FILES['cert']['name'])) {
        $img = save_image($_FILES['cert'], 'docs', 2000);
        if ($img) cfg_set('co_cert_image', $img); else flash('err', 'Certificate must be a JPG, PNG or WebP photo/scan under 12 MB.');
    }
    if (post('remove_cert') === '1') cfg_set('co_cert_image', '');
    flash('ok', 'Settings saved.');
    if (post('test_alert') === '1') {
        flash('ok', 'Test alert sent to Telegram' . (cfg('alert_email', '', true) !== '' ? ' and email' : '') . '. If nothing arrives, check the bot token and chat ID.');
        team_alert("Test alert from the HPL Gold Detectors website. If you can read this, chat and order alerts work."); // closes the session, so it goes last
    }
    redirect('admin/settings.php');
}

$tgOk = cfg('tg_token', '') !== '' && cfg('tg_chat', '') !== '';
admin_head('Settings', 'settings.php');
?>
<h1>Settings</h1>
<form class="form a-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="a-cols">
        <section class="card form">
            <h2>Company and contacts</h2>
            <p class="muted small">Shown in the header, footer, "Check us" page and order pages. A full street address and a working number make buyers trust you.</p>
            <?php foreach ($plain as $k => [$label, $ph]): if (in_array($k, ['alert_email', 'tg_chat'], true)) continue; ?>
                <div class="field"><label for="<?= $k ?>"><?= e($label) ?></label><input class="input" id="<?= $k ?>" name="<?= $k ?>" value="<?= e(cfg($k, '')) ?>" placeholder="<?= e($ph) ?>"></div>
            <?php endforeach; ?>
            <div class="field"><label for="co_branches">Shops and branches (one per line, like "Kitwe: Nkana Mall, shop G29")</label><textarea class="input" id="co_branches" name="co_branches" rows="4"><?= e(cfg('co_branches', '')) ?></textarea></div>
            <div class="field"><label for="co_receivers">Western Union / MoneyGram receivers (one per line: full name as on ID, city). Shown on "Check us" so buyers know who may collect.</label><textarea class="input" id="co_receivers" name="co_receivers" rows="3" placeholder="e.g. Jane Banda, Lusaka (sales manager)"><?= e(cfg('co_receivers', '')) ?></textarea></div>
            <div class="field"><label>Certificate of incorporation (photo or scan, shown on "Check us")</label>
                <?php if (cfg('co_cert_image', '') !== ''): ?><a href="<?= e(upload_url(cfg('co_cert_image'))) ?>" target="_blank" rel="noopener"><img src="<?= e(upload_url(cfg('co_cert_image'))) ?>" alt="" style="max-height:160px;border:1px solid var(--line);border-radius:6px"></a><label class="choice"><input type="checkbox" name="remove_cert" value="1"><span>Remove it</span></label><?php endif; ?>
                <input class="input" type="file" name="cert" accept="image/*"></div>
        </section>
        <section class="card form">
            <h2>Alerts</h2>
            <p class="muted small">Every new chat message (at most every 10 minutes per chat) and every order is sent to the team.</p>
            <label class="choice"><input type="checkbox" name="tg_alerts" value="1"<?= cfg('tg_alerts', '1') === '1' ? ' checked' : '' ?>><span>Telegram <small><?= $tgOk ? 'Ready.' : 'Add a bot token and a chat ID below.' ?></small></span></label>
            <div class="field"><label for="tg_token">Telegram bot token <small class="muted">(from @BotFather; <?= cfg('tg_token', '') !== '' ? 'one is saved, leave empty to keep it' : 'none saved yet' ?>)</small></label><input class="input" id="tg_token" name="tg_token" type="password" autocomplete="off" value=""><?php if (cfg('tg_token', '') !== ''): ?><label class="choice"><input type="checkbox" name="tg_token_clear" value="1"><span>Remove the saved token</span></label><?php endif; ?></div>
            <label class="choice"><input type="checkbox" name="email_alerts" value="1"<?= cfg('email_alerts', '1') === '1' ? ' checked' : '' ?>><span>Email</span></label>
            <?php foreach (['alert_email', 'tg_chat'] as $k): ?><div class="field"><label for="<?= $k ?>"><?= e($plain[$k][0]) ?></label><input class="input" id="<?= $k ?>" name="<?= $k ?>" value="<?= e(cfg($k, '')) ?>"></div><?php endforeach; ?>
            <label class="choice"><input type="checkbox" name="test_alert" value="1"><span>Send a test alert when I save</span></label>
            <h2 style="margin-top:20px">Payment details and messages</h2>
            <p class="muted small">Write the details once. In a chat, one tap sends them in the buyer's language. Always use the company's name and accounts.</p>
        </section>
    </div>
    <?php foreach ($multi as $k => [$label, $rows]): ?>
        <section class="card form" data-langtabs>
            <h3 style="margin:0"><?= e($label) ?></h3>
            <div class="tabs" role="tablist"><?php foreach (STORE_LANGS as $l => $n): ?><button class="tab" type="button" role="tab" data-lang-tab="<?= $l ?>" aria-selected="<?= $l === 'en' ? 'true' : 'false' ?>"><?= e($n) ?></button><?php endforeach; ?></div>
            <?php foreach (STORE_LANGS as $l => $n): ?>
                <div data-lang-pane="<?= $l ?>"<?= $l === 'en' ? '' : ' hidden' ?>><textarea class="input" name="<?= $k ?>_<?= $l ?>" rows="<?= $rows ?>" aria-label="<?= e($label . ' (' . $n . ')') ?>"><?= e(lang_val(cfg($k, ''), $l)) ?></textarea></div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <div class="a-savebar"><button class="btn btn--buy btn--lg" type="submit">Save settings</button></div>
</form>
<?php admin_foot();
