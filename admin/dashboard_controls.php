<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
require_once __DIR__ . '/../includes/support_faq.php';
require_once __DIR__ . '/../includes/signup_guard.php';
$pageTitle = 'Dashboard & signup';
$fields = [
 'dash_intro_title' => ['Intro heading', 'Watch the 2-minute intro before you start'],
 'dash_intro_text' => ['Intro instructions', 'This short video shows you how to clip, post and get paid. Watch it once, then pick where you want to go next.'],
 'dash_support_intro' => ['Support introduction', 'Ask below — the bot answers instantly, and a human from the team will join this chat when needed.'],
 'dash_step_1_title' => ['Guide step 1 heading', '1. Open your profile'],
 'dash_step_1_text' => ['Guide step 1 instructions', 'Go to your TikTok / Instagram / Facebook page and tap the share icon, then "Copy link" to get your public profile URL.'],
 'dash_step_2_title' => ['Guide step 2 heading', '2. Make it public'],
 'dash_step_2_text' => ['Guide step 2 instructions', 'Switch the account to public so our team can view your posts and verify your clips.'],
 'dash_step_3_title' => ['Guide step 3 heading', '3. Paste & submit'],
 'dash_step_3_text' => ['Guide step 3 instructions', 'Paste the link above, choose the platform and hit "Submit page". We will review it.'],
];
$faqs = support_faq();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 csrf_check();
 $enabled = post('signup_turnstile_enabled') === '1';
 $sitekey = post('signup_turnstile_sitekey');
 $secret = post('signup_turnstile_secret');
 if ($secret === '') { $secret = (string)setting('signup_turnstile_secret', ''); }
 $host = strtolower(trim(post('signup_hostname')));
 if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host)) { flash('error', 'Enter your website hostname without https:// or a path.'); redirect('admin/dashboard_controls.php'); }
 if ($enabled && ($sitekey === '' || $secret === '' || !function_exists('curl_init'))) {
  flash('error', 'Turnstile needs a site key, secret key and PHP cURL before enabling.'); redirect('admin/dashboard_controls.php');
 }
 foreach ($fields as $key => $field) { set_setting($key, mb_substr(post($key), 0, 4000)); }
 set_setting('signup_turnstile_enabled', $enabled ? '1' : '0');
 set_setting('signup_turnstile_sitekey', $sitekey);
 set_setting('signup_turnstile_secret', $secret);
 set_setting('signup_hostname', $host);
 set_setting('signup_attempt_limit', (string)max(300, min(5000, (int)post('signup_attempt_limit'))));
 set_setting('signup_creation_limit', (string)max(60, min(2000, (int)post('signup_creation_limit'))));
 $custom = [];
 foreach ($faqs as $i => $faq) {
  if (post('faq_enabled_' . $i) !== '1') { continue; }
  $question = mb_substr(post('faq_question_' . $i), 0, 200);
  $answer = mb_substr(post('faq_answer_' . $i), 0, 4000);
  if ($question !== '' && $answer !== '') { $custom[$i] = ['question'=>$question,'answer'=>$answer,'keywords'=>mb_substr(post('faq_keywords_' . $i),0,1000)]; }
 }
 set_setting('dash_faq_overrides', json_encode($custom));
 flash('success', 'Dashboard content and signup settings saved.'); redirect('admin/dashboard_controls.php');
}
$overrides = json_decode((string)setting('dash_faq_overrides', '[]'), true) ?: [];
require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Dashboard &amp; signup</h1>
<p class="muted">Edit dashboard instructions and support answers, and control signup protection. Campaigns, courses and account records have their own admin pages below.</p>
<form method="post" autocomplete="off">
 <?= csrf_field() ?>
 <section class="panel"><div class="panel__head"><h3>Signup protection</h3></div><div class="panel__body">
 <p>Bot-trap fields, form expiry, replay checks and limits per network address are always enforced. Google sandbox sign-in is disabled.</p>
 <p class="muted">For stronger protection, create a Cloudflare Turnstile widget for your domain and enter its keys here. CAPTCHA is inactive until configured and enabled.</p>
 <div class="field"><label class="label"><input type="checkbox" name="signup_turnstile_enabled" value="1" <?= signup_turnstile_enabled() ? 'checked' : '' ?>> Enable Turnstile verification</label></div>
 <div class="form-grid">
 <div class="field"><label class="label" for="signup_turnstile_sitekey">Turnstile site key</label><input class="input" id="signup_turnstile_sitekey" name="signup_turnstile_sitekey" value="<?= e(setting('signup_turnstile_sitekey','')) ?>"></div>
 <div class="field"><label class="label" for="signup_turnstile_secret">Turnstile secret key</label><input class="input" type="password" id="signup_turnstile_secret" name="signup_turnstile_secret" autocomplete="new-password" placeholder="Leave blank to keep the saved secret"><small class="muted"><?= setting('signup_turnstile_secret','') !== '' ? 'A secret is saved.' : 'No secret saved.' ?></small></div>
 <div class="field"><label class="label" for="signup_hostname">Website hostname</label><input class="input" id="signup_hostname" name="signup_hostname" required value="<?= e(setting('signup_hostname','golddetectorinzambia.com')) ?>"></div>
 <div class="field"><label class="label" for="signup_attempt_limit">Signup attempts / network address / hour</label><input class="input" type="number" min="300" max="5000" id="signup_attempt_limit" name="signup_attempt_limit" value="<?= signup_attempt_limit() ?>"></div>
 <div class="field"><label class="label" for="signup_creation_limit">New accounts / network address / hour</label><input class="input" type="number" min="60" max="2000" id="signup_creation_limit" name="signup_creation_limit" value="<?= signup_creation_limit() ?>"></div>
 </div><p class="muted small">Shared office/mobile networks may share an address. Adjust limits if legitimate users are blocked. Limits require a writable PHP temporary directory. With Turnstile enabled, failed verification blocks signup.</p>
 </div></section>
 <section class="panel"><div class="panel__head"><h3>Dashboard instructions</h3></div><div class="panel__body">
 <?php foreach ($fields as $key => $field): ?><div class="field"><label class="label" for="<?= e($key) ?>"><?= e($field[0]) ?></label><textarea class="textarea" id="<?= e($key) ?>" name="<?= e($key) ?>" rows="2" maxlength="4000"><?= e(setting($key,$field[1])) ?></textarea></div><?php endforeach; ?>
 <p class="muted">Manage the welcome video under Settings → Promo video URL / embed.</p>
 </div></section>
 <section class="panel"><div class="panel__head"><h3>Support answers</h3></div><div class="panel__body">
 <p class="muted">Enable an override to edit an answer. Leave unchecked to keep the default answer, including live payout settings. Custom answers use plain text.</p>
 <?php foreach ($faqs as $i => $faq): ?>
 <details style="padding:14px 0;border-bottom:1px solid var(--line)"><summary><?= e($faq['question']) ?></summary>
 <label class="label mt-2"><input type="checkbox" name="faq_enabled_<?= $i ?>" value="1" <?= isset($overrides[$i])?'checked':'' ?>> Use custom answer</label>
 <label class="label" for="faq_q_<?= $i ?>">Question</label><input class="input" id="faq_q_<?= $i ?>" name="faq_question_<?= $i ?>" value="<?= e($faq['question']) ?>" maxlength="200">
 <label class="label" for="faq_a_<?= $i ?>">Answer</label><textarea class="textarea" id="faq_a_<?= $i ?>" name="faq_answer_<?= $i ?>" maxlength="4000" rows="4"><?= e(isset($overrides[$i]) ? $overrides[$i]['answer'] : html_entity_decode(strip_tags($faq['answer']), ENT_QUOTES, 'UTF-8')) ?></textarea>
 <label class="label" for="faq_k_<?= $i ?>">Matching phrases, separated by commas</label><input class="input" id="faq_k_<?= $i ?>" name="faq_keywords_<?= $i ?>" value="<?= e(implode(', ', $faq['keywords'])) ?>">
 </details><?php endforeach; ?>
 </div></section>
 <button class="btn btn--primary" type="submit">Save dashboard &amp; signup settings</button>
</form>
<section class="panel mt-3"><div class="panel__head"><h3>Manage the clipper dashboard</h3></div><div class="panel__body">
<?php $sections = [
 ['Campaigns','admin/campaigns.php','Banners, descriptions, budgets, platform rates, materials, requirements and campaign submissions.'],
 ['Courses','admin/courses.php','Published courses, chapters, lesson links and thumbnails.'],
 ['Announcements','admin/announcements.php','Announcements, edits, replies, pinning and removal.'],
 ['Footage','admin/footage.php','Upload files, Drive links, labels and availability.'],
 ['Tutorials','admin/tutorials.php','Tutorial uploads and video links.'],
 ['Creators','admin/creators.php','Profiles, access status, qualification and submitted links.'],
 ['Social pages','admin/social_pages.php','Review, edit, approve and reject submitted profiles.'],
 ['Withdrawals','admin/withdrawals.php','Payout requests and statuses.'],
 ['Community','admin/community.php','Community messages, replies, edits and moderation.'],
 ['Support & messages','admin/messages.php','Read and reply to creator conversations.'],
 ['Settings','admin/settings.php','Global rates, payout limits, daily limits, integrations and promo video.'],
]; foreach ($sections as $section): ?><p><a href="<?= e(u($section[1])) ?>"><strong><?= e($section[0]) ?></strong></a> — <?= e($section[2]) ?></p><?php endforeach; ?>
<p class="muted small">Computed balances, view totals and progress come from activity records. Page structure, icons, navigation labels and some system messages remain code-controlled.</p>
</div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
