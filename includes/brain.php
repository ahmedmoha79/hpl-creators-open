<?php
/**
 * HPL Brain (owner, 2026-11-12): thinks like the developer who owns this app. It READS and REPORTS; it never messages
 * creators, never changes money or rules. Server-side only (this folder is never served).
 *
 * Eyes (sensors):
 *   brain_count()        counters per day in `brain_counts`: every "no" the app says to a creator (flash errors/warnings and
 *                        JSON refusals, normalised so names and numbers do not split them), browser errors and failed requests
 *                        (api/brain.php fed by assets/js/brain.js), PHP errors (read from the error log at each run).
 *   brain_analyse()      also reads the database: the creator journey, the public community chat (never private messages),
 *                        clip supply, waiting work, sign-ups against the usual pace.
 * Thinking: every analysis returns findings: who and how many are affected, how much it matters (money and people quitting
 *   weigh most), whether it is new or getting worse against the last 7 days, the likely cause and what to fix. Ranked by score.
 * Memory: `brain_findings` keeps each problem across runs (first seen, last seen); one not seen for a day is marked solved.
 * Voice and hands: includes/brain_voice.php (Telegram updates 4 times a day in a developer's words, instant alerts, safe repairs).
 */

function brain_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)fetch_val('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "brain_findings"', [], 0); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** One message as a stable key: names, numbers, handles and links are replaced, so "K12.50" and "K40" count as one. */
function brain_normalise(string $msg): string
{
    $m = html_entity_decode(strip_tags($msg), ENT_QUOTES, 'UTF-8');
    $m = preg_replace('~https?://\S+~u', '<link>', $m);
    $m = preg_replace('~@[A-Za-z0-9_.]+~u', '@name', $m);
    $m = preg_replace('~\b(K|ZMW)\s?[0-9][0-9,]*(\.[0-9]+)?~u', 'K#', $m);
    $m = preg_replace('~[0-9][0-9,.:/]*~u', '#', $m);
    $m = preg_replace('~"[^"]{1,80}"~u', '"…"', $m);
    $m = trim(preg_replace('~\s+~u', ' ', $m));
    return mb_substr($m, 0, 250);
}

/** Count one event for today. Cheap (one upsert), never throws, never slows the page. */
function brain_count(string $kind, string $label, string $page = '', string $sample = ''): void
{
    try {
        $label = $kind === 'repair' ? mb_substr(trim($label), 0, 250) : brain_normalise($label); // repairs keep their numbers
        if ($label === '') return;
        $page = mb_substr(preg_replace('~\?.*$~', '', $page !== '' ? $page : (string)($_SERVER['SCRIPT_NAME'] ?? '')), 0, 120);
        $key = substr(hash('sha256', $kind . '|' . $label . '|' . $page), 0, 16);
        q('INSERT INTO brain_counts (day, kind, k, label, page, n, sample, last_at) VALUES (CURDATE(), ?, ?, ?, ?, 1, ?, NOW())
           ON DUPLICATE KEY UPDATE n = n + 1, last_at = NOW(), sample = VALUES(sample)',
          [mb_substr($kind, 0, 16), $key, $label, $page, mb_substr($sample, 0, 255)]);
    } catch (Throwable $e) {
    }
}

/** Called by flash() and json_response(): a "no" said to a creator (admins' own messages are not counted). */
function brain_refusal(string $message): void
{
    static $n = 0;
    if (++$n > 3) return; // one request never counts more than three
    $role = (string)($_SESSION['role'] ?? '');
    if ($role === 'admin') return;
    // "Please sign in": a page left open after the session ended (or the account was paused). Not a confusing rule;
    // since 2026-11-12 the page reloads to the sign-in screen by itself (assets/js/topbar.js).
    if (preg_match('~^please sign in~i', trim($message))) return;
    brain_count($role === 'creator' ? 'refusal' : 'refusal_pub', $message);
}

// ---------------------------------------------------------------------------------------------
// Knowledge: what a repeated refusal usually means, and what the developer should do about it.
// ---------------------------------------------------------------------------------------------
function brain_knowledge(): array
{
    return [
        // Most specific first: the first rule that matches explains the message.
        ['~caption must tag~i', 'The caption tag', 'People forget the tag in the caption, or TikTok gives the tag to us in a form the check did not know (fixed 12 Nov: the caption now accepts what the bio accepts).', 'If it keeps coming, show the caption to copy (with the tag) right under the Download button, with a Copy button.'],
        ['~most for one day|1 clip a day|clips today~i', 'The daily clip limit', 'People try to post more clips in a day than allowed (2, or 1 while their recent clips are weak).', 'Normal while it is a few people. If many, check the clips themselves: weak clips make everyone weak.'],
        ['~answer every question~i', 'The test', 'People press Submit with a question unanswered (fixed 12 Nov: the page now shows which one).', 'If it keeps coming, make each question one screen with a Next button.'],
        ['~bio|tag~i', 'The TikTok bio tag rule', 'People do not understand how to put @' . (function_exists('ready_tag_handle') ? ready_tag_handle() : 'hpldetectors.zambia') . ' in their TikTok bio, or TikTok has not updated the bio yet.', 'Add a 3-picture guide (Edit profile > Bio > type @ and pick the account) on the TikTok-ready page, and say that TikTok can take a few minutes to show a new bio.'],
        ['~hours apart|posted less than|next clip|wait~i', 'The wait between clips', 'People try to post faster than the rule allows.', 'Show the countdown more clearly on Home and in the "clip ready" notification, so nobody posts early.'],
        ['~connect (your )?tiktok|not a tiktok account you connected~i', 'Connecting TikTok', 'People post on an account they did not connect, or never connected one.', 'Put "Connect TikTok" first on Home for people without an account, and show which account is connected next to the Send link button.'],
        ['~number|network~i', 'Mobile money number', 'People type a number from another network or a wrong number.', 'Show the network logo as they type and say which network the number belongs to before they press Send.'],
        ['~minimum|below the minimum~i', 'Withdrawal minimum', 'People try to withdraw before reaching the minimum.', 'Show "K… to go" on the Withdraw button itself instead of letting them try.'],
        ['~test~i', 'The test', 'People try to do things before passing the test.', 'Point them straight to the test with one button from where they got stuck.'],
        ['~already been submitted|already sent|each video can only earn once~i', 'Sending the same video twice', 'People send a link they already sent, or reuse a video.', 'Show "Sent" on the clip and hide the Send button once a link is in.'],
        ['~full link|copy link|valid public post url~i', 'Wrong kind of link', 'People paste a profile link or a shortened link that does not work.', 'Add a short picture guide: on TikTok tap Share > Copy link, on the video (not the profile).'],
        ['~session expired|refresh the page|token~i', 'Pages left open too long', 'People open a page, leave it, and press a button much later.', 'Keep the security token fresh while the page is open, or ask them to refresh in plain words.'],
        ['~as it is|music|sound~i', 'The "post as it is" tick', 'People miss the tick box.', 'Make the tick box bigger and place it right above the Send button.'],
        ['~warm|following|follow at least~i', 'TikTok warm-up / following rule', 'New TikTok accounts are blocked from taking clips.', 'Tell people on sign-up to start using TikTok daily straight away, and show the date they can take clips.'],
    ];
}

function brain_explain(string $label): array
{
    foreach (brain_knowledge() as [$re, $topic, $cause, $fix]) {
        if (preg_match($re, $label)) return [$topic, $cause, $fix];
    }
    return ['A message people keep seeing', 'Many people see this message, so the step before it is probably unclear.', 'Read the message as a new creator would. Make the step before it clearer, or let the screen prevent the mistake instead of refusing after.'];
}

// ---------------------------------------------------------------------------------------------
// Thinking
// ---------------------------------------------------------------------------------------------

/** Trend of a daily count against the previous 7 days. Returns [label, factor]. */
function brain_trend(float $today, float $avg7): array
{
    if ($avg7 < 0.5 && $today >= 3) return ['new', 1.6];
    if ($avg7 > 0 && $today >= $avg7 * 2 && $today - $avg7 >= 3) return ['getting worse', 1.4];
    if ($avg7 > 0 && $today <= $avg7 * 0.5) return ['getting better', 0.7];
    return ['about the same', 1.0];
}

/** Run every analysis. Returns findings (not yet saved). */
function brain_analyse(bool $all = false): array
{
    $out = [];
    // The watch list: everything that can go wrong, each on its own schedule and time budget (includes/brain_watch.php).
    require_once __DIR__ . '/brain_watch.php';
    try { foreach (brain_look_watch($all) as $f) { $out[] = $f; } } catch (Throwable $e) { error_log('brain watch: ' . $e->getMessage()); }
    $cost = [];
    require_once __DIR__ . '/brain_mind.php';
    foreach (['brain_look_mind', 'brain_look_visitors', 'brain_look_quality', 'brain_look_refusals', 'brain_look_errors', 'brain_look_journey', 'brain_look_chat', 'brain_look_supply', 'brain_look_work', 'brain_look_signups', 'brain_look_payouts'] as $fn) {
        $t0 = microtime(true);
        try { foreach ($fn() as $f) { $out[] = $f; } } catch (Throwable $e) { error_log('brain ' . $fn . ': ' . $e->getMessage()); }
        $cost[$fn] = (int)round((microtime(true) - $t0) * 1000);
    }
    set_setting('brain_cost_ms', json_encode($cost)); // the Brain watches its own cost too (Admin > Brain)
    foreach ($out as &$f) {
        $f['score'] = (int)round(max(1, $f['people']) * $f['weight'] * ($f['trend_factor'] ?? 1.0) * 10);
    }
    unset($f);
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}

/** Repeated "no" messages to creators: a confusing rule or screen. */
function brain_look_refusals(): array
{
    $out = [];
    $rows = fetch_all('SELECT kind, k, label, page, SUM(CASE WHEN day = CURDATE() THEN n ELSE 0 END) today, SUM(CASE WHEN day < CURDATE() THEN n ELSE 0 END) / 7 avg7,
                              SUM(CASE WHEN day >= CURDATE() - INTERVAL 1 DAY THEN n ELSE 0 END) last2
                       FROM brain_counts WHERE kind IN ("refusal","refusal_pub") AND day >= CURDATE() - INTERVAL 7 DAY GROUP BY kind, k, label, page HAVING last2 >= 3 ORDER BY last2 DESC LIMIT 12');
    foreach ($rows as $r) {
        [$topic, $cause, $fix] = brain_explain((string)$r['label']);
        [$tr, $tf] = brain_trend((float)$r['today'], (float)$r['avg7']);
        $out[] = ['key' => 'refusal:' . $r['k'], 'area' => 'Confusing step', 'title' => $topic . ': people were told "' . mb_substr((string)$r['label'], 0, 120) . '"',
            'people' => (int)$r['last2'], 'weight' => $r['kind'] === 'refusal' ? 1.2 : 0.8, 'trend' => $tr, 'trend_factor' => $tf,
            'evidence' => [(int)$r['today'] . ' times today, ' . (int)$r['last2'] . ' in the last 2 days (usual: ' . round((float)$r['avg7'], 1) . ' a day)', 'Page: ' . $r['page']],
            'cause' => $cause, 'fix' => $fix];
    }
    return $out;
}

/** Things that broke: browser errors, failed requests, server errors. */
function brain_look_errors(): array
{
    $out = [];
    foreach (fetch_all('SELECT kind, k, label, page, sample, SUM(CASE WHEN day = CURDATE() THEN n ELSE 0 END) today, SUM(CASE WHEN day >= CURDATE() - INTERVAL 1 DAY THEN n ELSE 0 END) last2,
                               SUM(CASE WHEN day < CURDATE() THEN n ELSE 0 END) / 7 avg7
                        FROM brain_counts WHERE kind IN ("jserror","failreq","phperror") AND day >= CURDATE() - INTERVAL 7 DAY GROUP BY kind, k, label, page, sample HAVING last2 >= 2 ORDER BY last2 DESC LIMIT 20') as $r) {
        [$tr, $tf] = brain_trend((float)$r['today'], (float)$r['avg7']);
        if ($r['kind'] === 'failreq' && stripos((string)$r['label'], 'No answer') === 0) {
            continue; // a phone that lost its network for a moment: counted together below, not a code bug
        }
        if ($r['kind'] === 'jserror' && preg_match('~ @(?!/)~', (string)$r['label'])) {
            continue; // an error in a script that is not ours (Facebook's in-app browser, extensions): nothing we can fix
        }
        if (count($out) >= 10) break;
        $what = ['jserror' => 'Something broke on people\'s phones', 'failreq' => 'A button or screen failed to load', 'phperror' => 'The server hit an error'][$r['kind']];
        if ($r['kind'] === 'phperror' && preg_match('~database connection failed|Too many connections~i', (string)$r['label'])) {
            $out[] = ['key' => 'err:' . $r['k'], 'area' => 'Bug', 'title' => 'The database was too busy for a moment (' . (int)$r['last2'] . ' times in 2 days)',
                'people' => (int)$r['last2'], 'weight' => 1.0, 'trend' => $tr, 'trend_factor' => $tf,
                'evidence' => [(int)$r['today'] . ' times today', 'Each time, a page could not open for someone'],
                'cause' => 'Too many people at the same moment for the shared hosting database. Since 11 Nov the site waits and retries, so most visitors never notice.',
                'fix' => 'If this keeps coming back, ask the hosting company to raise "max_user_connections" for your database. Admin > Site health shows the numbers.'];
            continue;
        }
        $out[] = ['key' => 'err:' . $r['k'], 'area' => 'Bug', 'title' => $what . ': ' . mb_substr((string)$r['label'], 0, 110),
            'people' => (int)$r['last2'], 'weight' => $r['kind'] === 'jserror' ? 1.3 : 1.6, 'trend' => $tr, 'trend_factor' => $tf,
            'evidence' => array_values(array_filter([(int)$r['last2'] . ' times in 2 days', $r['page'] !== '' ? 'Page: ' . $r['page'] : '', $r['sample'] !== '' ? 'Seen on: ' . $r['sample'] : ''])),
            'cause' => 'A bug in the page or the server. It happens to real people right now.',
            'fix' => 'Ask Claude to fix it: "fix the Brain bug ' . substr((string)$r['k'], 0, 6) . '". The page, the device and the error are recorded here.'];
    }
    // Requests that got no answer at all: almost always a weak mobile signal. Only worth a look when it piles up.
    $na = fetch_one('SELECT SUM(CASE WHEN day >= CURDATE() - INTERVAL 1 DAY THEN n ELSE 0 END) last2, SUM(CASE WHEN day = CURDATE() THEN n ELSE 0 END) today,
                            SUM(CASE WHEN day < CURDATE() THEN n ELSE 0 END) / 7 avg7, COUNT(DISTINCT page) pages
                     FROM brain_counts WHERE kind = "failreq" AND label LIKE "No answer%" AND day >= CURDATE() - INTERVAL 7 DAY') ?: [];
    if ((int)($na['last2'] ?? 0) >= 40) {
        [$tr, $tf] = brain_trend((float)$na['today'], (float)$na['avg7']);
        $out[] = ['key' => 'err:noanswer', 'area' => 'Slow network', 'title' => 'Phones lost the connection ' . (int)$na['last2'] . ' times in 2 days while a page was loading',
            'people' => (int)round((int)$na['last2'] / 4), 'weight' => 0.6, 'trend' => $tr, 'trend_factor' => $tf,
            'evidence' => ['On ' . (int)$na['pages'] . ' different pages, so not one broken page', 'The site answers in under a second when tested from outside'],
            'cause' => 'Weak mobile data. If it jumps suddenly, the hosting may have been slow or down for a while.',
            'fix' => 'Nothing to change while it stays low. If it jumps, open Admin > Site health and check the hosting.'];
    }
    return $out;
}

/**
 * Clip quality across creators (2026-11-12): who is on 1 clip a day (includes/clip_trust.php) and, more important, whether
 * the weakness is the people or the clips. When most posters are weak at once, the clips or the posting times are the problem.
 */
function brain_look_quality(): array
{
    $out = [];
    try {
        $posters = (int)fetch_val('SELECT COUNT(DISTINCT user_id) FROM campaign_submissions WHERE submitted_at >= NOW() - INTERVAL 21 DAY AND submitted_at <= NOW() - INTERVAL 1 DAY', [], 0);
        $limited = fetch_all('SELECT clip_trust_why FROM users WHERE role = "creator" AND clip_trust = 1 AND clip_trust_override IS NULL');
    } catch (Throwable $e) {
        return $out;
    }
    $n = count($limited);
    if ($n === 0) return $out;
    $why = ['few views' => 0, 'few people like' => 0, 'page score' => 0, 'not accepted' => 0, 'did not post' => 0];
    foreach ($limited as $l) { foreach ($why as $k => $c) { if (stripos((string)$l['clip_trust_why'], $k) !== false) $why[$k]++; } }
    arsort($why);
    $ev = [];
    foreach ($why as $k => $c) { if ($c > 0) $ev[] = $c . ' because ' . $k; }
    if ($posters >= 5 && $n / $posters >= 0.4 && ($why['few views'] + $why['few people like']) >= $n * 0.6) {
        $out[] = ['key' => 'quality:clips', 'area' => 'Clip quality', 'title' => $n . ' of ' . $posters . ' posters get weak results at the same time',
            'people' => $n, 'weight' => 1.5, 'trend' => '', 'evidence' => $ev,
            'cause' => 'When almost everyone is weak at once, it is the clips (first 2 seconds, sound, length) or the posting times, not the people.',
            'fix' => 'Compare the clips that did well with the rest (Admin > Analytics, best creators) and upload more of that style. Post at the busy hour shown in Analytics.'];
    } else {
        $out[] = ['key' => 'quality:limited', 'area' => 'Clip quality', 'title' => $n . ' creator' . ($n === 1 ? ' is' : 's are') . ' on 1 clip a day for weak results',
            'people' => $n, 'weight' => 0.4, 'trend' => '', 'evidence' => $ev,
            'cause' => 'Their recent clips got few views or reactions, or they took clips and did not post them. The website limits them by itself and tells them how to get back.',
            'fix' => 'Nothing to do. Admin > Ready clips > On 1 clip a day lists them; give one back by hand if you know better.'];
    }
    return $out;
}

/**
 * Visitors to sign-ups (2026-11-12, owner: "500 people came from the ad and only 3 signed up"). Where do new visitors stop:
 * on the front page (never open the sign-up page) or on the form (open it, do not finish)? Per source, against the usual.
 */
function brain_look_visitors(): array
{
    $since = (int)setting('funnel_since', '0');
    if ($since <= 0) return [];
    $out = [];
    $rows = fetch_all('SELECT source, COUNT(*) v, SUM(step >= 1) opened, SUM(step = 2) done, SUM(inapp <> "") inapp
                       FROM site_visits WHERE day = CURDATE() AND is_new = 1 AND first_at >= FROM_UNIXTIME(?) GROUP BY source ORDER BY v DESC', [$since]);
    $base = fetch_one('SELECT COUNT(*) v, SUM(step >= 1) opened, SUM(step = 2) done FROM site_visits
                       WHERE day < CURDATE() AND day >= CURDATE() - INTERVAL 14 DAY AND is_new = 1 AND first_at >= FROM_UNIXTIME(?)', [$since]) ?: [];
    $v = array_sum(array_column($rows, 'v')); $opened = array_sum(array_column($rows, 'opened')); $done = array_sum(array_column($rows, 'done'));
    $inapp = array_sum(array_column($rows, 'inapp'));
    if ($v < 60) return $out; // too few people today to judge
    $conv = $done / $v;
    $baseConv = (int)($base['v'] ?? 0) >= 100 ? (int)$base['done'] / (int)$base['v'] : 0.05; // ~5 in 100 is a fair sign-up rate for ad visitors
    if ($conv >= $baseConv * 0.6) return $out;
    $openRate = $opened / $v; $finishRate = $opened > 0 ? $done / $opened : 0;
    $evidence = [];
    foreach (array_slice($rows, 0, 4) as $r) {
        $evidence[] = ($r['source'] === 'direct' ? 'Typed or app icon' : $r['source']) . ': ' . (int)$r['v'] . ' new, ' . (int)$r['opened'] . ' opened sign-up, ' . (int)$r['done'] . ' signed up';
    }
    if ($inapp > 0) $evidence[] = round($inapp / $v * 100) . '% opened the site inside an app (Facebook, TikTok...)';
    $top = fetch_one('SELECT label, SUM(n) n FROM brain_counts WHERE kind = "refusal_pub" AND page = "/register.php" AND day = CURDATE() GROUP BY label ORDER BY n DESC LIMIT 1');
    if ($top) $evidence[] = 'Most common sign-up mistake today: "' . mb_substr((string)$top['label'], 0, 80) . '" (' . (int)$top['n'] . ' times)';
    if ($openRate < 0.15) {
        $out[] = ['key' => 'visitors:front', 'area' => 'Sign-ups', 'title' => 'Only ' . round($openRate * 100) . '% of new visitors even open the sign-up page (' . $opened . ' of ' . $v . ' today)',
            'people' => $v - $opened, 'weight' => 1.6, 'trend' => '', 'evidence' => $evidence,
            'cause' => 'The front page does not convince them, or the ad brings people who are not looking for work (curious clicks). When the ad promises something the page does not show at once, people leave in seconds.',
            'fix' => 'Make the ad and the first screen say the same thing in the same words, with one button. Check the ad audience: Zambia, 18 to 35, people who use TikTok. Compare sources above: if WhatsApp visitors sign up and ad visitors do not, it is the ad audience.'];
    } elseif ($finishRate < 0.35) {
        $out[] = ['key' => 'visitors:form', 'area' => 'Sign-ups', 'title' => round((1 - $finishRate) * 100) . '% of people who open the sign-up page leave without finishing (' . $done . ' of ' . $opened . ' today)',
            'people' => $opened - $done, 'weight' => 1.8, 'trend' => '', 'evidence' => $evidence,
            'cause' => $inapp > $v * 0.4 ? 'Most of them are inside the Facebook or TikTok app, where typing is slow and Google sign-in is blocked. Then the boxes that refuse them (see the mistake above).' : 'A box on the form refuses them, or the form asks too much before they trust us.',
            'fix' => 'Fix the most common mistake above first (make the box explain itself before they press the button). Keep the form to name, number, email and password.'];
    } else {
        $out[] = ['key' => 'visitors:low', 'area' => 'Sign-ups', 'title' => 'Few visitors sign up today: ' . $done . ' of ' . $v . ' (' . round($conv * 100, 1) . '%, usual ' . round($baseConv * 100, 1) . '%)',
            'people' => $v - $done, 'weight' => 1.2, 'trend' => '', 'evidence' => $evidence,
            'cause' => 'People open the sign-up page and many finish, but too few open it: the visitors themselves are the difference today.',
            'fix' => 'Look at which source brings the people who sign up and put the ad money there.'];
    }
    return $out;
}

/** The creator journey: where people stop, compared with the usual. */
function brain_look_journey(): array
{
    $out = [];
    $base = 'role = "creator" AND status = "active" AND is_demo = 0';
    // Signed up 2-14 days ago and never passed the test.
    $noTest = (int)fetch_val("SELECT COUNT(*) FROM users WHERE $base AND qualified = 0 AND created_at BETWEEN NOW() - INTERVAL 14 DAY AND NOW() - INTERVAL 2 DAY", [], 0);
    $joined = (int)fetch_val("SELECT COUNT(*) FROM users WHERE $base AND created_at BETWEEN NOW() - INTERVAL 14 DAY AND NOW() - INTERVAL 2 DAY", [], 0);
    if ($joined >= 5 && $noTest / $joined >= 0.4) {
        $out[] = ['key' => 'journey:test', 'area' => 'People stop', 'title' => round($noTest / $joined * 100) . '% of new people never pass the test',
            'people' => $noTest, 'weight' => 1.5, 'trend' => '', 'evidence' => [$noTest . ' of ' . $joined . ' who signed up 2 to 14 days ago'],
            'cause' => 'The test feels long or hard, or people do not know it is the next step.',
            'fix' => 'Check the 3 questions people fail most (Admin > Test questions) and make Home show only "Take the test" until it is passed.'];
    }
    // Passed the test but no TikTok connected after 2 days.
    $noTt = (int)fetch_val("SELECT COUNT(*) FROM users u WHERE $base AND qualified = 1 AND qualified_at < NOW() - INTERVAL 2 DAY AND NOT EXISTS (SELECT 1 FROM tiktok_accounts t WHERE t.user_id = u.id)", [], 0);
    if ($noTt >= 3) {
        $out[] = ['key' => 'journey:tiktok', 'area' => 'People stop', 'title' => $noTt . ' people passed the test but never connected TikTok',
            'people' => $noTt, 'weight' => 1.4, 'trend' => '', 'evidence' => ['Passed the test more than 2 days ago, no TikTok account connected'],
            'cause' => 'Connecting TikTok is confusing, fails on their phone, or they do not have TikTok ready.',
            'fix' => 'Look at the "Connecting TikTok" refusals above; add a short video of connecting TikTok on a phone.'];
    }
    // TikTok connected but never took a clip, while clips were free.
    $free = (int)fetch_val('SELECT COUNT(*) FROM ready_clips WHERE is_active = 1 AND claimed_by IS NULL', [], 0);
    $noTake = (int)fetch_val("SELECT COUNT(*) FROM users u WHERE $base AND EXISTS (SELECT 1 FROM tiktok_accounts t WHERE t.user_id = u.id AND t.connected_at < NOW() - INTERVAL 4 DAY)
                              AND NOT EXISTS (SELECT 1 FROM ready_clips r WHERE r.claimed_by = u.id) AND NOT EXISTS (SELECT 1 FROM campaign_submissions s WHERE s.user_id = u.id)", [], 0);
    if ($noTake >= 3 && $free > 0) {
        $out[] = ['key' => 'journey:take', 'area' => 'People stop', 'title' => $noTake . ' people with TikTok connected never took a clip',
            'people' => $noTake, 'weight' => 1.3, 'trend' => '', 'evidence' => ['TikTok connected more than 4 days ago', $free . ' clips were free to take'],
            'cause' => 'The warm-up or following rule blocks them, or they do not find the clips.',
            'fix' => 'Check the "warm-up / following" refusals; put the free clips as pictures straight on Home.'];
    }
    // Took a clip, never sent a link (lost clips).
    $lost = (int)fetch_val('SELECT COUNT(DISTINCT u.id) FROM users u JOIN ready_clips r ON r.claimed_by = u.id WHERE r.submission_id IS NULL AND r.claimed_at < NOW() - INTERVAL 1 DAY', [], 0);
    if ($lost >= 3) {
        $out[] = ['key' => 'journey:send', 'area' => 'People stop', 'title' => $lost . ' people took a clip and have not sent the link after a day',
            'people' => $lost, 'weight' => 1.2, 'trend' => '', 'evidence' => ['Clip taken more than 24 hours ago, no link yet'],
            'cause' => 'Posting or sending the link is the hard part (bio tag, caption tag, wrong link).',
            'fix' => 'Compare with the refusals on the Send-link step above; the most common one is what to fix first.'];
    }
    return $out;
}

/** The public community chat: what people complain about. Private messages are never read. */
function brain_look_chat(): array
{
    $topics = [
        'Withdrawals and payment' => '~withdraw|payment|paid|pay me|momo|airtel money|mtn money|not received|money~i',
        'Something not working' => '~not working|doesn\'?t work|error|bug|stuck|not loading|blank|crash|fail|can\'?t|cannot|won\'?t~i',
        'TikTok and bio' => '~tiktok|bio|tag|connect|login|account~i',
        'Clips and posting' => '~clip|download|video|post|take|wait|hours~i',
        'Trust' => '~scam|fake|lie|cheat|steal|stolen~i',
    ];
    $rows = fetch_all('SELECT user_id, message, created_at FROM community_messages WHERE deleted_at IS NULL AND (kind IS NULL OR kind IN ("", "msg")) AND created_at >= NOW() - INTERVAL 7 DAY AND message <> "" ORDER BY id DESC LIMIT 3000');
    $stat = [];
    foreach ($rows as $m) {
        $txt = (string)$m['message'];
        $recent = strtotime((string)$m['created_at']) >= time() - 86400;
        // Only messages that sound like a problem or a question count (chatter like "posted my clip" does not).
        $isProblem = preg_match('~\?|\b(not|no|never|can\'?t|cannot|won\'?t|why|how|help|problem|issue|stuck|error|still|yet|failed|wrong|missing)\b~i', $txt) === 1;
        foreach ($topics as $t => $re) {
            if (!preg_match($re, $txt) || (!$isProblem && $t !== 'Trust')) continue;
            $s = &$stat[$t];
            $s['week'] = ($s['week'] ?? 0) + 1;
            if ($recent) {
                $s['day'] = ($s['day'] ?? 0) + 1;
                $s['who'][(int)$m['user_id']] = 1;
                if (count($s['quotes'] ?? []) < 3) $s['quotes'][] = '"' . mb_substr(trim(preg_replace('/\s+/u', ' ', $txt)), 0, 90) . '"';
            }
            unset($s);
        }
    }
    $out = [];
    foreach ($stat as $t => $s) {
        $day = (int)($s['day'] ?? 0);
        $people = count($s['who'] ?? []);
        if ($day < 3 || $people < 2) continue;
        [$tr, $tf] = brain_trend($day, max(0, ((int)$s['week'] - $day) / 6));
        $weight = ['Trust' => 1.8, 'Withdrawals and payment' => 1.5, 'Something not working' => 1.6][$t] ?? 1.0;
        $out[] = ['key' => 'chat:' . md5($t), 'area' => 'What people say', 'title' => $people . ' people talk about "' . $t . '" in the community chat today',
            'people' => $people, 'weight' => $weight, 'trend' => $tr, 'trend_factor' => $tf,
            'evidence' => array_merge([$day . ' messages in 24 hours'], $s['quotes'] ?? []),
            'cause' => $t === 'Trust' ? 'People doubt they will be paid. One unanswered doubt spreads fast in a group.' : 'This is what is on people\'s minds right now.',
            'fix' => $t === 'Trust' || $t === 'Withdrawals and payment' ? 'Post a short message in HPL News with a real recent payout (amount and date) and the pay days.' : 'Answer once in the group, and fix the cause if the Bug list above shows the same thing.'];
    }
    return $out;
}

/** Clip supply: when will the free clips run out at today's pace? */
function brain_look_supply(): array
{
    $out = [];
    foreach (fetch_all('SELECT c.id, c.title, (SELECT COUNT(*) FROM ready_clips r WHERE r.campaign_id = c.id AND r.is_active = 1 AND r.claimed_by IS NULL) free,
                               (SELECT COUNT(*) FROM ready_clips r WHERE r.campaign_id = c.id AND r.claimed_at >= NOW() - INTERVAL 1 DAY) taken24
                        FROM campaigns c WHERE c.status = "active" AND c.camp_status = "accepting"') as $c) {
        $free = (int)$c['free']; $rate = (int)$c['taken24'];
        if ($rate < 2) continue;
        $hours = $rate > 0 ? $free / ($rate / 24) : 999;
        if ($hours > 36) continue;
        $out[] = ['key' => 'supply:' . (int)$c['id'], 'area' => 'Clips running out', 'title' => $c['title'] . ': free clips run out in about ' . max(1, (int)round($hours)) . ' hours',
            'people' => $rate, 'weight' => 1.4, 'trend' => '', 'evidence' => [$free . ' free now', $rate . ' taken in the last 24 hours'],
            'cause' => 'Creators take clips faster than new ones are uploaded.',
            'fix' => 'Upload more ready clips for ' . $c['title'] . ' today. A creator with nothing to take goes quiet and may leave.'];
    }
    return $out;
}

/** Work waiting for the team that costs trust when it waits. */
function brain_look_work(): array
{
    $out = [];
    $old = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE status = "pending" AND submitted_at < NOW() - INTERVAL 36 HOUR', [], 0);
    if ($old >= 3) {
        $out[] = ['key' => 'work:clips', 'area' => 'Waiting for you', 'title' => $old . ' clip links have waited more than 36 hours for approval',
            'people' => $old, 'weight' => 1.3, 'trend' => '', 'evidence' => ['Their money stays locked until you approve'],
            'cause' => 'Approving by hand takes time as more people join.', 'fix' => 'Approve them in Clips sent by creators, or switch on automatic approval for clips that pass every check (Admin > Ready clips).'];
    }
    $help = (int)fetch_val('SELECT COUNT(*) FROM dm_threads t WHERE t.is_support = 1 AND t.updated_at < NOW() - INTERVAL 24 HOUR
                            AND (SELECT m.sender_id FROM dm_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) IN (SELECT id FROM users WHERE role = "creator")', [], 0);
    if ($help >= 3) {
        $out[] = ['key' => 'work:help', 'area' => 'Waiting for you', 'title' => $help . ' people asked for help more than a day ago and have no answer',
            'people' => $help, 'weight' => 1.2, 'trend' => '', 'evidence' => ['Counted from the help conversations; the messages themselves are not read by the Brain'],
            'cause' => 'Questions the automatic answers cannot handle.', 'fix' => 'Reply in Private messages, then add the answer to the fixed help answers so it is automatic next time.'];
    }
    return $out;
}

/** Sign-ups against the usual pace for this time of day (spots a broken ad or a broken sign-up page). */
function brain_look_signups(): array
{
    $out = [];
    $h = (int)date('G');
    if ($h < 10) return $out;
    $today = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND created_at >= CURDATE()', [], 0);
    $avg = (float)fetch_val('SELECT COUNT(*) / 7 FROM users WHERE role = "creator" AND created_at >= CURDATE() - INTERVAL 7 DAY AND created_at < CURDATE() AND HOUR(created_at) < ?', [$h], 0);
    if ($avg >= 5 && $today <= $avg * 0.5) {
        $out[] = ['key' => 'signups:drop', 'area' => 'Sign-ups', 'title' => 'Sign-ups are down: ' . $today . ' today against about ' . round($avg) . ' by this hour normally',
            'people' => (int)round($avg - $today), 'weight' => 1.5, 'trend' => 'getting worse', 'trend_factor' => 1.3, 'evidence' => ['Same hours of the day, last 7 days'],
            'cause' => 'The ad stopped or changed, or the sign-up page has a problem.', 'fix' => 'Check the ad is running, then sign up once yourself on a phone. The Bug list shows sign-up errors if there are any.'];
    }
    if ($avg >= 3 && $today >= $avg * 2.5) {
        $out[] = ['key' => 'signups:surge', 'area' => 'Sign-ups', 'title' => 'Sign-ups are up: ' . $today . ' today against about ' . round($avg) . ' by this hour normally',
            'people' => $today, 'weight' => 0.6, 'trend' => 'new', 'evidence' => ['Same hours of the day, last 7 days'],
            'cause' => 'The ad or a post is working, or a group is making many accounts.', 'fix' => 'Good news if real. Make sure there are enough free clips; look at Checks for many accounts on one phone.'];
    }
    return $out;
}

/** Payouts: disputes and waiting money (trust). */
function brain_look_payouts(): array
{
    $out = [];
    try {
        $disp = (int)fetch_val('SELECT COUNT(*) FROM withdrawals WHERE receipt = "not_received" AND receipt_at >= NOW() - INTERVAL 7 DAY', [], 0);
        if ($disp >= 1) {
            $out[] = ['key' => 'pay:dispute', 'area' => 'Money', 'title' => $disp . ' creator' . ($disp === 1 ? ' says a payout' : 's say payouts') . ' did not arrive',
                'people' => $disp, 'weight' => 2.0, 'trend' => '', 'evidence' => ['In the last 7 days, see Pay withdrawals > Paid'],
                'cause' => 'A wrong number, a delayed transfer, or a false claim.', 'fix' => 'Check each transaction ID with your mobile money statement, add the screenshot, and reply to the creator.'];
        }
    } catch (Throwable $e) {
    }
    $wait = (int)fetch_val('SELECT COUNT(*) FROM withdrawals WHERE status = "pending" AND requested_at < NOW() - INTERVAL 48 HOUR', [], 0);
    if ($wait >= 1) {
        $out[] = ['key' => 'pay:wait', 'area' => 'Money', 'title' => $wait . ' withdrawal' . ($wait === 1 ? ' has' : 's have') . ' waited more than 2 days',
            'people' => $wait, 'weight' => 2.0, 'trend' => '', 'evidence' => ['Waiting money is the fastest way to lose trust'],
            'cause' => 'Payouts are sent by hand.', 'fix' => 'Use the Fast pay list in Pay withdrawals.'];
    }
    return $out;
}

/** Read new PHP error lines from the log into the counters (once per run). */
function brain_read_php_errors(): void
{
    $f = (string)ini_get('error_log');
    if ($f === '' || !is_file($f)) return;
    $size = filesize($f);
    $pos = (int)setting('brain_log_pos', '0');
    if ($pos > $size || $pos < 0) $pos = max(0, $size - 1048576);
    if ($size - $pos > 2097152) $pos = $size - 2097152;
    $h = @fopen($f, 'r');
    if (!$h) return;
    fseek($h, $pos);
    $n = 0;
    // Only errors newer than the last 24 hours and newer than `brain_errors_since` count: an old, already-fixed error in the
    // log must not be reported as if it happened now (2026-11-12, the first run read the whole log).
    $since = max(time() - 86400, (int)setting('brain_errors_since', '0'));
    while (($line = fgets($h)) !== false && $n < 4000) {
        $n++;
        if (!preg_match('~PHP (Fatal error|Warning|Parse error|Uncaught)|HPL database connection failed~', $line)) continue;
        if (preg_match('~^\[([^\]]+)\]~', $line, $tm)) {
            $ts = strtotime(preg_replace('~\s+[A-Za-z_]+/[A-Za-z_]+$~', '', $tm[1]));
            if ($ts !== false && $ts < $since) continue;
        }
        if (preg_match('~Duplicate column|already exists~', $line) && strpos($line, 'migration step') !== false) continue; // harmless: two updates at once
        $msg = preg_replace('~^\[[^\]]+\]\s*~', '', trim($line));
        $page = preg_match('~ in (/\S+?\.php)~', $msg, $mm) ? basename(dirname($mm[1])) . '/' . basename($mm[1]) : '';
        brain_count('phperror', preg_replace('~ in /\S+ on line \d+~', '', $msg), $page);
    }
    set_setting('brain_log_pos', (string)ftell($h));
    fclose($h);
}

/** One full run: read the logs, think, remember. At most once an hour unless forced. Returns the findings. */
function brain_run(bool $force = false): array
{
    if (!brain_ready()) return [];
    $last = (int)setting('brain_last_run', '0');
    if (!$force && time() - $last < 3600) return [];
    set_setting('brain_last_run', (string)time());
    require_once __DIR__ . '/brain_voice.php';
    brain_heal();
    brain_read_php_errors();
    $t0 = microtime(true);
    $found = brain_analyse($force);
    set_setting('brain_run_ms', (string)(int)round((microtime(true) - $t0) * 1000));
    $seen = [];
    foreach ($found as $f) {
        $seen[] = $f['key'];
        q('INSERT INTO brain_findings (fkey, area, title, score, people, trend, evidence, cause, fix, status, first_seen, last_seen)
           VALUES (?,?,?,?,?,?,?,?,?, "open", NOW(), NOW())
           ON DUPLICATE KEY UPDATE area = VALUES(area), title = VALUES(title), score = VALUES(score), people = VALUES(people), trend = VALUES(trend),
             evidence = VALUES(evidence), cause = VALUES(cause), fix = VALUES(fix), last_seen = NOW(),
             status = IF(status = "muted", "muted", "open"), resolved_at = IF(status = "muted", resolved_at, NULL)',
          [mb_substr($f['key'], 0, 64), $f['area'], mb_substr($f['title'], 0, 255), $f['score'], $f['people'], (string)($f['trend'] ?? ''),
           json_encode($f['evidence'], JSON_UNESCAPED_UNICODE), $f['cause'], $f['fix']]);
    }
    // Not seen for a day: solved.
    q('UPDATE brain_findings SET status = "solved", resolved_at = NOW() WHERE status = "open" AND last_seen < NOW() - INTERVAL 1 DAY');
    q('DELETE FROM brain_counts WHERE day < CURDATE() - INTERVAL 30 DAY');
    try { brain_voice_tick(); } catch (Throwable $e) { error_log('brain voice: ' . $e->getMessage()); }
    // Twice a day the mind thinks everything through in words (morning and evening), and the owner gets it on Telegram.
    try {
        $slot = date('Y-m-d') . (date('G') >= 18 ? 'pm' : (date('G') >= 8 ? 'am' : ''));
        if (strlen($slot) > 10 && (string)setting('mind_thought_slot', '') !== $slot) {
            set_setting('mind_thought_slot', $slot);
            $thought = mind_think(true);
            if ($thought !== null) {
                require_once __DIR__ . '/telegram.php';
                if (function_exists('tg_ready') && tg_ready() && tg_kind_on('brain')) {
                    tg_alert('brain', '🧠 <b>The Brain thought it through</b>' . "\n\n" . tg_h($thought), [[['Open the Brain', abs_url('admin/brain.php')]]]);
                }
            }
        }
    } catch (Throwable $e) { error_log('brain think: ' . $e->getMessage()); }
    return $found;
}

/** One Telegram message a day (after 9:00) with the top 3 open problems. */
function brain_brief_maybe(): void
{
    try {
        if ((int)date('G') < 9 || setting('tg_owner_chat_id', '') === '') return;
        $today = date('Y-m-d');
        $last = (string)setting('brain_brief_day', '');
        if ($last === $today) return;
        if (setting('brain_brief_day', null) === null) q('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ("brain_brief_day", "")');
        if (q('UPDATE settings SET setting_value = ? WHERE setting_key = "brain_brief_day" AND setting_value = ?', [$today, $last])->rowCount() !== 1) return;
        $top = fetch_all('SELECT * FROM brain_findings WHERE status = "open" ORDER BY score DESC LIMIT 3');
        if (!$top) return;
        require_once __DIR__ . '/telegram.php';
        $h = '🧠 <b>Brain: the 3 things that matter most today</b>';
        foreach ($top as $i => $f) {
            $h .= "\n\n" . ($i + 1) . '. <b>' . tg_h($f['title']) . "</b>\n" . tg_h($f['fix']);
        }
        tg_alert('brain', $h, [[['Open the Brain', abs_url('admin/brain.php')]]]);
    } catch (Throwable $e) {
    }
}
