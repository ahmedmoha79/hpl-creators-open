<?php
/*
 * Creator steps (signed up -> test -> work sample -> approved -> sending clips)
 * and sending messages/reminders to creators. Used by admin/creators.php and admin/send.php.
 */

/**
 * Where each creator is on the road to earning, in the order they go through it.
 * 'next' is the creator's own next step (used in reminders).
 */
function creator_stages(): array
{
    return [
        'no_test'   => ['label' => 'Signed up', 'hint' => 'Has not passed the test yet', 'tone' => 'muted',
                        'sql' => 'status = "active" AND qualified = 0 AND assessment_status = "not_taken" AND portfolio_status NOT IN ("pending","rejected")',
                        'next' => ['Take the short HPL test', 'You signed up but have not taken the short test yet. It takes a few minutes and unlocks the next step.', 'creator/assessment.php']],
        'failed'    => ['label' => 'Failed test', 'hint' => 'Can try the test again', 'tone' => 'bad',
                        'sql' => 'status = "active" AND qualified = 0 AND assessment_status = "failed" AND portfolio_status NOT IN ("pending","rejected")',
                        'next' => ['Try the HPL test again', 'You were close. Watch the lessons in the Creator Academy, then try the short test again.', 'creator/assessment.php']],
        'no_work'   => ['label' => 'Passed test', 'hint' => 'Has not sent a work sample', 'tone' => 'warn',
                        'sql' => 'status = "active" AND qualified = 0 AND assessment_status = "passed" AND portfolio_status = "none"',
                        'next' => ['Send one video you made', 'You passed the test. Now send a link to one video you edited, so we can approve your account and you can start earning.', 'creator/portfolio.php']],
        'review'    => ['label' => 'Work sent', 'hint' => 'Waiting for YOU to approve', 'tone' => 'warn',
                        'sql' => 'status = "active" AND qualified = 0 AND portfolio_status = "pending"', 'next' => null],
        'rejected'  => ['label' => 'Work rejected', 'hint' => 'Must send better work', 'tone' => 'bad',
                        'sql' => 'status = "active" AND qualified = 0 AND portfolio_status = "rejected"',
                        'next' => ['Send a new work sample', 'Your last sample was not approved. Send a link to another video you edited, and we will look again.', 'creator/portfolio.php']],
        'approved'  => ['label' => 'Approved, no clips', 'hint' => 'Approved but has not sent a clip', 'tone' => 'warn',
                        'sql' => 'qualified = 1 AND status = "active" AND NOT EXISTS (SELECT 1 FROM content_links cl WHERE cl.user_id = users.id) AND NOT EXISTS (SELECT 1 FROM campaign_submissions cs WHERE cs.user_id = users.id)',
                        'next' => ['Post your first clip and start earning', 'Your account is approved. Open Campaigns, join one, download the footage, post a short clip on your page and send us the link. You earn from its views.', 'creator/index.php?tab=campaigns']],
        'earning'   => ['label' => 'Sending clips', 'hint' => 'Has sent at least one clip', 'tone' => 'ok',
                        'sql' => 'qualified = 1 AND status = "active" AND (EXISTS (SELECT 1 FROM content_links cl WHERE cl.user_id = users.id) OR EXISTS (SELECT 1 FROM campaign_submissions cs WHERE cs.user_id = users.id))', 'next' => null],
        'suspended' => ['label' => 'Suspended', 'hint' => 'Blocked', 'tone' => 'bad',
                        'sql' => 'status = "suspended"', 'next' => null],
    ];
}

/** Which stage a creator row is in. */
function creator_stage_of(array $c): string
{
    if ($c['status'] === 'suspended') { return 'suspended'; }
    if ((int)$c['qualified'] === 1) { return (int)($c['links'] ?? 0) > 0 ? 'earning' : 'approved'; }
    if ($c['portfolio_status'] === 'pending') { return 'review'; }
    if ($c['portfolio_status'] === 'rejected') { return 'rejected'; }
    if ($c['assessment_status'] === 'passed') { return 'no_work'; }
    if ($c['assessment_status'] === 'failed') { return 'failed'; }
    return 'no_test';
}

/**
 * Remind every creator in a stage about their next step (notification + email).
 * Returns how many were reminded, or null if this group was reminded in the last 24 hours.
 */
function creators_send_reminders(string $stage): ?int
{
    $stages = creator_stages();
    if (empty($stages[$stage]['next'])) {
        return 0;
    }
    $key = 'remind_' . $stage . '_at';
    if ((int)setting($key, 0) > time() - 86400) {
        return null;
    }
    set_setting($key, (string)time());
    [$title, $body, $path] = $stages[$stage]['next'];
    $n = creators_send_message($stage, $title, $body, $path, true);
    send_log_add('Reminder: ' . $stages[$stage]['label'], $title, $n, true);
    return $n;
}

/** The creators in an audience: 'all', a stage key, or 'user:<id>'. */
function creators_in_audience(string $audience, int $limit = 1000): array
{
    if (strpos($audience, 'user:') === 0) {
        return fetch_all('SELECT id, full_name, email FROM users WHERE role = "creator" AND id = ?', [(int)substr($audience, 5)]);
    }
    $stages = creator_stages();
    $where = $audience === 'all' ? 'status = "active"' : ($stages[$audience]['sql'] ?? '0');
    return fetch_all('SELECT id, full_name, email FROM users WHERE role = "creator" AND ' . $where . ' ORDER BY id LIMIT ' . (int)$limit);
}

/**
 * Send one message to every creator in an audience: always as a notification in the app
 * (bell + pop-up), and by email when $email is true. Returns how many creators got it.
 */
function creators_send_message(string $audience, string $title, string $body, string $path, bool $email): int
{
    $rows = creators_in_audience($audience);
    require_once __DIR__ . '/mailer.php';
    $url = abs_url($path);
    @set_time_limit(300);
    $n = 0;
    foreach ($rows as $r) {
        notif_add((int)$r['id'], 'reminder', $title, $body, $path);
        if ($email && !empty($r['email'])) {
            $first = trim(explode(' ', (string)$r['full_name'])[0]);
            @app_mail((string)$r['email'], $title . ' - ' . APP_NAME,
                '<p>Hi ' . e($first) . ',</p><p>' . e($body) . '</p>'
                . '<p><a href="' . e($url) . '" style="display:inline-block;background:#e2ad7b;color:#1e140f;padding:12px 20px;border-radius:10px;font-weight:700;text-decoration:none">' . e($title) . '</a></p>',
                "Hi $first,\n\n$body\n\n$url");
        }
        $n++;
    }
    return $n;
}

/** Remember what was sent (newest first, last 30) so the admin can see the history. */
function send_log_add(string $audience, string $title, int $count, bool $email): void
{
    $log = json_decode((string)setting('send_log', '[]'), true) ?: [];
    array_unshift($log, ['at' => time(), 'to' => $audience, 'title' => $title, 'n' => $count, 'email' => $email]);
    set_setting('send_log', json_encode(array_slice($log, 0, 30)));
}

function send_log(): array
{
    return json_decode((string)setting('send_log', '[]'), true) ?: [];
}

