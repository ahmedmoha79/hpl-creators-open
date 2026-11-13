<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/social_proof.php';
require_once __DIR__ . '/includes/ready_clips.php';

/*
 * Front page (owner, 2026-11-12).
 * 1) "Easy to read, even a child should understand": short sentences, one idea per screen, ~3,500px on a phone.
 * 2) "Use psychology: 'free, start today' makes people not take it seriously. Make it special: you must engage with your
 *    account." So the page sells a standard, not a freebie: you APPLY, pass a test, get your TikTok ready, and we expect
 *    real engagement. Everything said is true (the test, the TikTok-ready checks, the review, the 2-a-day limit, real
 *    payouts); no invented scarcity or numbers. "No fee" is answered in the questions, not shouted.
 * Public pages never state one rate: "paid for every 1,000 views; each campaign shows its rate" (owner).
 */
$pageTitle = 'Get paid for your TikTok views';
$pageCss = ['assets/css/landing.css?v=20261113f'];
$pagePreload = ['assets/media/home/hero-field.jpg'];
$pageTitle = 'Get paid for your TikTok views';
$minPayout = payout_min();
$user = current_user();
$home = $user ? u($user['role'] === 'admin' ? 'admin/index.php' : 'creator/index.php') : '';
// Owner, 2026-11-13 21:40: one testimonial with "2 weeks ago" in the corner looked dead. Every real figure now cycles fast:
// payouts ("was paid") and money earned from clip views ("earned"), small amounts too. Never invented numbers.
$proof = sp_signup_proof(40, 0.01, 30);
$paid = array_values(array_filter($proof, fn($p) => $p['kind'] === 'paid'));
// Each clip's own earnings too ("Daniel N. made K23.00 from one clip"): more real figures to cycle, all from the database.
try {
    foreach (fetch_all('SELECT u.full_name, s.earned FROM campaign_submissions s JOIN users u ON u.id = s.user_id
        WHERE s.earned >= 0.01 AND s.status <> "rejected" AND u.role = "creator" AND u.is_demo = 0 ORDER BY s.id DESC LIMIT 30') as $r) {
        $proof[] = ['kind' => 'clip', 'name' => sp_short_name((string)$r['full_name']), 'amount' => round((float)$r['earned'], 2)];
    }
} catch (Throwable $e) {
}
$ticker = [];
$pp = $paid; $ee = array_values(array_filter($proof, fn($p) => $p['kind'] !== 'paid'));
// mix the per-person and per-clip figures so the same name is not shown twice in a row
usort($ee, fn($a, $b) => crc32($a['name'] . $a['amount']) <=> crc32($b['name'] . $b['amount']));
while ($pp || $ee) { if ($pp) $ticker[] = array_shift($pp); if ($ee) $ticker[] = array_shift($ee); if ($ee) $ticker[] = array_shift($ee); }
// Pop-up notices (owner, 2026-11-13 21:52: "look at professional websites"): the usual social-proof toast, bottom corner,
// dark, one at a time, with the person's town and when. Real rows only: payouts and each clip's earnings.
$toasts = [];
try {
    $when = function ($t): string {
        $d = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime((string)$t)))) / 86400);
        return $d <= 0 ? 'today' : ($d === 1 ? 'yesterday' : ($d < 7 ? $d . ' days ago' : ($d < 14 ? 'last week' : 'this month')));
    };
    foreach (fetch_all('SELECT u.full_name, u.town, w.amount, COALESCE(w.resolved_at, w.requested_at) t FROM withdrawals w JOIN users u ON u.id = w.user_id
        WHERE w.status = "paid" AND u.role = "creator" AND u.is_demo = 0 ORDER BY t DESC LIMIT 10') as $r) {
        $toasts[] = ['n' => sp_short_name((string)$r['full_name']), 'p' => trim((string)$r['town']), 'k' => 'paid', 'a' => money($r['amount']), 'w' => $when($r['t'])];
    }
    foreach (fetch_all('SELECT u.full_name, u.town, s.earned, COALESCE(s.last_fetched_at, s.submitted_at) t FROM campaign_submissions s JOIN users u ON u.id = s.user_id
        WHERE s.earned >= 0.01 AND s.status <> "rejected" AND u.role = "creator" AND u.is_demo = 0 ORDER BY s.id DESC LIMIT 30') as $r) {
        $toasts[] = ['n' => sp_short_name((string)$r['full_name']), 'p' => trim((string)$r['town']), 'k' => 'clip', 'a' => money($r['earned']), 'w' => $when($r['t'])];
    }
    foreach (fetch_all('SELECT u.full_name, u.town, SUM(e.amount) a, MAX(e.day) t FROM earning_days e JOIN users u ON u.id = e.user_id
        WHERE e.day >= CURDATE() - INTERVAL 30 DAY AND u.role = "creator" AND u.is_demo = 0 GROUP BY u.id, u.full_name, u.town HAVING a >= 0.01') as $r) {
        $toasts[] = ['n' => sp_short_name((string)$r['full_name']), 'p' => trim((string)$r['town']), 'k' => 'earned', 'a' => money($r['a']), 'w' => 'this month'];
    }
} catch (Throwable $e) {
}
// Never the same person twice in a row, at most 3 notices each: take one from each person in turn (payouts first).
$byName = [];
foreach ($toasts as $t) { if (count($byName[$t['n']] ?? []) < 3) $byName[$t['n']][] = $t; }
$toasts = [];
while ($byName) { foreach ($byName as $k => &$list) { $toasts[] = array_shift($list); if (!$list) unset($byName[$k]); } unset($list); }
/** A small copy of a campaign picture for the front page (a 1.1 MB banner made the page slow, 2026-11-13). */
function lp_thumb(string $url): string
{
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (strpos($path, '/uploads/campaigns/') === false || !preg_match('~\.(jpe?g|png|webp)$~i', $path)) return $url;
    $src = __DIR__ . substr($path, strpos($path, '/uploads/campaigns/'));
    $dir = __DIR__ . '/uploads/campaigns/thumb';
    $dst = $dir . '/' . pathinfo($src, PATHINFO_FILENAME) . '-t240.jpg';
    $web = u('uploads/campaigns/thumb/' . basename($dst));
    if (is_file($dst)) return $web;
    if (!is_file($src) || !function_exists('imagecreatefromstring')) return $url;
    try {
        $im = @imagecreatefromstring((string)file_get_contents($src));
        if (!$im) return $url;
        $w = imagesx($im); $h = imagesy($im); $side = min($w, $h);
        $t = imagecreatetruecolor(240, 240);
        imagecopyresampled($t, $im, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 240, 240, $side, $side);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ok = @imagejpeg($t, $dst, 78);
        imagedestroy($t); imagedestroy($im);
        return $ok ? $web : $url;
    } catch (Throwable $e) {
        return $url;
    }
}
$tag = ready_tag_handle();
// The owner's own explainer videos (2026-11-13): "From Lusaka to Kitwe" explains the job; "Your questions, answered" sits with the questions.
$howVid = 'assets/media/home/lusaka-to-kitwe.mp4?v=20261113a';
$howPoster = 'assets/media/home/lusaka-to-kitwe.jpg';
// Owner, 2026-11-13 21:26: "we give you videos of gold hunting" contradicted the Lusaka campaign (podcast clips), the
// "Straight from the field" strip looked like an empty window, and the words were vague. Now: the two real kinds of clips,
// labelled, with frames from our own clips; the campaigns open right now with their real rate, money left and clips left
// (true scarcity: one creator per clip, a campaign closes when its budget is spent); short headlines, no vague lines.
$open = [];
try {
    $rc = ready_clip_counts();
    foreach (fetch_all('SELECT * FROM campaigns WHERE status = "active" AND COALESCE(camp_status, "accepting") = "accepting" ORDER BY id DESC LIMIT 4') as $c) {
        $left = max(0, (float)$c['total_budget'] - (float)$c['paid_amount']);
        if ((float)$c['total_budget'] > 0 && $left <= 0) continue;
        $open[] = ['title' => (string)$c['title'], 'img' => (string)$c['banner_url'], 'rate' => campaign_best_rate($c), 'left' => $left,
            'total' => (float)$c['total_budget'], 'clips' => (int)($rc[(int)$c['id']]['free'] ?? 0), 'cur' => (string)($c['currency'] ?: 'K')];
    }
} catch (Throwable $e) {
}

require __DIR__ . '/includes/header.php';
?>

<section class="lp-hero" style="--lp-hero-img:url('<?= e(u('assets/media/home/hero-field.jpg')) ?>')">
    <div class="lp-wrap">
        <h1>Your TikTok can pay you <span>while Zambia watches.</span></h1>
        <p class="lp-lead">We make the videos. You post them. Every 1,000 views sends money to your phone.</p>
        <p class="lp-sub">No filming. No editing. No big following needed.</p>
        <?php if ($user): ?>
            <a class="btn btn--primary lp-btn" href="<?= e($home) ?>">Go to my account</a>
        <?php else: ?>
            <a class="btn btn--primary lp-btn" href="<?= e(u('register.php')) ?>">Apply to join</a>
            <p class="lp-have">Takes 2 minutes. Already a creator? <a href="<?= e(u('login.php')) ?>">Sign in</a></p>
        <?php endif; ?>
    </div>
    <div class="lp-dust" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
    <a class="lp-down" href="#how" aria-label="See how it works"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></a>
</section>

<section class="lp-section lp-job" id="how">
    <div class="lp-wrap">
        <h2>How it works</h2>
        <ol class="lp-photosteps">
            <?php $last = $paid[0] ?? null; foreach ([
                ['step-film.jpg', 'We make the videos', 'Podcast talks and gold hunts, filmed in Zambia.'],
                ['clip-podcast-2.jpg', 'You take one', 'It is yours alone. Nobody else gets it.'],
                ['step-post.jpg', 'You post it', 'On your TikTok, as it is.'],
                [$last ? '' : 'step-paid.jpg', 'You get paid', 'For every 1,000 views, to mobile money.'],
            ] as $i => [$img, $t, $d]): ?>
                <li>
                    <?php if ($img === ''): /* a real, recent payout, not a stock picture */ ?>
                        <div class="lp-paidcard" aria-hidden="true">
                            <span class="lp-paidcard__tick">&#10003;</span>
                            <b data-countup="<?= e((string)$last['amount']) ?>"><?= e(money($last['amount'])) ?></b>
                            <span>paid to <?= e($last['name']) ?></span>
                        </div>
                    <?php else: ?>
                    <img src="<?= e(u('assets/media/home/' . $img)) ?>" alt="" loading="lazy" decoding="async" width="360" height="360">
                    <?php endif; ?>
                    <div><span class="lp-num"><?= $i + 1 ?></span><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($howVid !== ''): ?>
            <details class="lp-video">
                <summary><?= icon('play', 'ico ico--sm') ?> Watch how it works (40 seconds)</summary>
                <video controls playsinline preload="none"<?= $howPoster ? ' data-poster="' . e(u($howPoster)) . '"' : '' ?> src="<?= e(u($howVid)) ?>"></video>
            </details>
        <?php endif; ?>
    </div>
</section>

<?php if ($ticker): ?>
<section class="lp-paid" aria-label="Money our creators made">
    <h2 class="lp-small">Real people. Real money.</h2>
    <div class="lp-belt"><div class="lp-belt__track" style="--lp-belt-s:<?= max(14, count($ticker) * 2) ?>s">
        <?php for ($rep = 0; $rep < 2; $rep++): foreach ($ticker as $p): ?>
            <span class="lp-chip<?= $p['kind'] === 'paid' ? ' lp-chip--paid' : '' ?>"<?= $rep ? ' aria-hidden="true"' : '' ?>><strong><?= e($p['name']) ?></strong> <?= $p['kind'] === 'paid' ? 'paid' : ($p['kind'] === 'clip' ? 'made' : 'earned') ?> <b><?= e(money($p['amount'])) ?></b><?= $p['kind'] === 'clip' ? ' from one clip' : '' ?></span>
        <?php endforeach; endfor; ?>
    </div></div>
</section>
<?php endif; ?>

<section class="lp-section lp-kinds" aria-label="What you will post">
    <div class="lp-wrap">
        <h2>The videos are ready. They need your page.</h2>
        <?php foreach ([
            ['HPL podcast clips', 'Real talk about gold, money and mining.', ['podcast-1', 'podcast-2', 'podcast-3']],
            ['Gold hunting', 'Real finds, filmed in the field.', ['gold-2', 'gold-3', 'gold-4']],
        ] as [$kt, $kd, $kp]): ?>
            <div class="lp-kind">
                <h3><?= e($kt) ?></h3>
                <p><?= e($kd) ?></p>
                <div class="lp-kind__row">
                    <?php foreach ($kp as $img): ?><img src="<?= e(u('assets/media/home/clip-' . $img . '.jpg')) ?>" alt="" loading="lazy" decoding="async" width="360" height="640"><?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($open): ?>
<section class="lp-section lp-section--alt lp-open" id="open" aria-label="Campaigns open now">
    <div class="lp-wrap">
        <h2>Open right now</h2>
        <p class="lp-intro">Each clip goes to one creator. When the money runs out, it closes.</p>
        <?php foreach ($open as $o): $pct = $o['total'] > 0 ? max(2, min(100, $o['left'] / $o['total'] * 100)) : 100; ?>
            <div class="lp-camp">
                <img src="<?= e($o['img'] !== '' ? lp_thumb($o['img']) : u('assets/media/campaign-default.svg')) ?>" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='<?= e(u('assets/media/home/clip-gold-2.jpg')) ?>'">
                <div class="lp-camp__body">
                    <h3><?= e($o['title']) ?></h3>
                    <?php if ($o['rate'] > 0): ?><p class="lp-camp__rate"><b><?= e(money($o['rate'])) ?></b> per 1,000 views</p><?php endif; ?>
                    <?php if ($o['total'] > 0): ?><div class="lp-camp__bar"><i style="width:<?= round($pct, 1) ?>%"></i></div><?php endif; ?>
                    <p class="lp-camp__left"><?= $o['total'] > 0 ? e($o['cur'] . number_format($o['left'], 0)) . ' left' : '' ?><?= $o['clips'] > 0 ? ($o['total'] > 0 ? ' · ' : '') . $o['clips'] . ' clip' . ($o['clips'] === 1 ? '' : 's') . ' free' : '' ?></p>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$user): ?><a class="btn btn--primary lp-btn" href="<?= e(u('register.php')) ?>">Apply to join</a><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="lp-section" id="expect">
    <div class="lp-wrap">
        <h2>Small page? That's fine.</h2>
        <p class="lp-intro">TikTok pushes active people, not big ones. Here is the deal.</p>
        <ul class="lp-expect">
            <li><span class="lp-num lp-num--dark">1</span><div><h3>Use TikTok every day</h3><p>Watch, like, comment.</p></div></li>
            <li><span class="lp-num lp-num--dark">2</span><div><h3>Reply to your comments</h3><p>More talk, more views, more money.</p></div></li>
            <li><span class="lp-num lp-num--dark">3</span><div><h3>Post it as it is</h3><p>No cuts, no music. Tag HPL.</p></div></li>
            <li><span class="lp-num lp-num--dark">4</span><div><h3>2 videos a day, max</h3><p>Every video is checked before it pays.</p></div></li>
        </ul>
    </div>
</section>

<section class="lp-section lp-section--alt" id="start">
    <div class="lp-wrap">
        <h2>How to start</h2>
        <ol class="lp-steps">
            <li><span class="lp-num">1</span><div><h3>Apply</h3><p>2 minutes.</p></div></li>
            <li><span class="lp-num">2</span><div><h3>Pass the test</h3><p>5 easy questions.</p></div></li>
            <li><span class="lp-num">3</span><div><h3>Connect your TikTok</h3><p>And tag HPL in your bio.</p></div></li>
            <li><span class="lp-num">4</span><div><h3>Take your first clip</h3><p>Post it. Get paid from <?= e(money($minPayout)) ?>.</p></div></li>
        </ol>
    </div>
</section>

<section class="lp-section" id="faq">
    <div class="lp-wrap">
        <h2>Questions</h2>
        <details class="lp-video" style="margin:-6px 0 18px">
            <summary><?= icon('play', 'ico ico--sm') ?> Watch the answers (40 seconds)</summary>
            <video controls playsinline preload="none" data-poster="<?= e(u('assets/media/home/questions.jpg')) ?>" src="<?= e(u('assets/media/home/questions.mp4?v=20261113a')) ?>"></video>
        </details>
        <?php foreach ([
            ['Do I need a lot of followers?', 'No. TikTok shows good videos to new people, big account or small.'],
            ['Do I have to pay to join?', 'No. There is no fee.'],
            ['How much can I earn?', 'You are paid for every 1,000 real views. Each campaign shows its rate. Active pages earn the most.'],
            ['When can I take my money out?', 'From ' . money($minPayout) . ', to MTN, Airtel or Zamtel.'],
        ] as $f): ?>
            <details class="lp-faq">
                <summary><?= e($f[0]) ?></summary>
                <p><?= e($f[1]) ?></p>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="lp-end">
    <div class="lp-wrap">
        <h2>Clips go one by one. Get yours.</h2>
        <?php if ($user): ?>
            <a class="btn btn--primary lp-btn" href="<?= e($home) ?>">Go to my account</a>
        <?php else: ?>
            <a class="btn btn--primary lp-btn" href="<?= e(u('register.php')) ?>">Apply to join</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($toasts): ?>
<div class="lp-toast" id="lpToast" role="status" aria-live="polite" hidden>
    <span class="lp-toast__ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.2 4.2L19 7"/></svg></span>
    <span class="lp-toast__txt"><strong id="lpToastWho"></strong><span id="lpToastWhat"></span><small id="lpToastWhen"></small></span>
    <button type="button" class="lp-toast__x" id="lpToastX" aria-label="Close">&times;</button>
</div>
<script type="application/json" id="lpToasts"><?= json_encode($toasts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<script src="<?= e(u('assets/js/landing.js?v=20261113g')) ?>" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
