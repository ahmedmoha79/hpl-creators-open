<?php
/**
 * Email to every creator, sent in the background (owner, 2026-11-13: "a notification and an email to all of them").
 * One job at a time (setting broadcast_job: id, title, body, url, created). The background sync sends 40 emails a run
 * (hosting mail limits), remembering who got it in broadcast_sent, so a 1,000-creator email never blocks a page.
 */
function broadcast_email_queue(string $title, string $body, string $url = 'creator/index.php'): string
{
    $id = date('YmdHis');
    q('CREATE TABLE IF NOT EXISTS broadcast_sent (job VARCHAR(20) NOT NULL, user_id INT UNSIGNED NOT NULL, at DATETIME NOT NULL, PRIMARY KEY (job, user_id)) ENGINE=InnoDB');
    set_setting('broadcast_job', json_encode(['id' => $id, 'title' => mb_substr($title, 0, 150), 'body' => mb_substr($body, 0, 1500), 'url' => $url, 'created' => time()]));
    return $id;
}

function broadcast_email_run(int $max = 40): int
{
    $job = json_decode((string)setting('broadcast_job', ''), true) ?: [];
    if (empty($job['id']) || (int)($job['created'] ?? 0) < time() - 3 * 86400 || !hpl_table_exists('broadcast_sent')) {
        return 0;
    }
    require_once __DIR__ . '/inactivity.php';
    $rows = fetch_all('SELECT u.* FROM users u WHERE u.role = "creator" AND u.status = "active" AND u.email <> ""'
        . (hpl_column_exists('users', 'is_demo') ? ' AND u.is_demo = 0' : '')
        . ' AND NOT EXISTS (SELECT 1 FROM broadcast_sent b WHERE b.job = ? AND b.user_id = u.id) ORDER BY u.id LIMIT ' . max(1, $max), [(string)$job['id']]);
    foreach ($rows as $u) {
        q('INSERT IGNORE INTO broadcast_sent (job, user_id, at) VALUES (?, ?, NOW())', [(string)$job['id'], (int)$u['id']]);
        inactivity_email($u, (string)$job['title'], (string)$job['body']);
    }
    return count($rows);
}
