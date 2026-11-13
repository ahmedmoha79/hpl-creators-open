<?php
require_once __DIR__ . '/../config.php';
$user = require_creator();

$id = (int)get('id');
$course = null;
if ($id > 0) {
    $course = fetch_one('SELECT * FROM courses WHERE id = ? AND published = 1', [$id]);
}
if (!$course) {
    $course = fetch_one('SELECT * FROM courses WHERE published = 1 ORDER BY sort_order ASC, id ASC LIMIT 1');
    if ($course) {
        $id = (int)$course['id'];
    }
}
if (!$course) {
    $pageTitle = 'Free clipping course';
    require __DIR__ . '/../includes/creator_header.php';
    echo '<section class="panel"><div class="panel__body empty"><h1>Free clipping course</h1><p>The course is being prepared. Published lessons will appear here as soon as the team makes them available.</p><a class="btn btn--primary" href="' . e(u('creator/support.php')) . '">Contact support</a></div></section>';
    require __DIR__ . '/../includes/creator_footer.php';
    exit;
}

$chapters = json_decode($course['chapters'] ?: '[]', true) ?: [];
$lessons = [];
$totalSeconds = 0;
foreach ($chapters as $ch) {
    foreach (($ch['lessons'] ?? []) as $l) {
        $lessons[] = $l;
        $totalSeconds += (int)($l['durationSec'] ?? 0);
    }
}
$totalLessons = count($lessons);
$chapterCount = count($chapters);
if ($totalLessons === 0) {
    redirect('creator/course.php'); // an empty course would show "0 lessons"; the Academy has the real lessons
}

$progressRow = fetch_one('SELECT completed_lessons FROM course_progress WHERE user_id = ? AND course_id = ?', [(int)$user['id'], $id]);
$completed = $progressRow ? (json_decode($progressRow['completed_lessons'] ?: '[]', true) ?: []) : [];
$completedCount = min($totalLessons, count($completed));
$pct = $totalLessons > 0 ? (int)round(($completedCount / $totalLessons) * 100) : 0;

$poster = trim((string)($course['thumbnail_url'] ?? ''));
if ($poster === '') {
    foreach ($lessons as $l) {
        if (!empty($l['thumbnailUrl'])) { $poster = (string)$l['thumbnailUrl']; break; }
    }
}

$mins = (int)floor($totalSeconds / 60);
$durationLabel = $mins >= 60 ? floor($mins / 60) . 'h ' . ($mins % 60) . 'm' : $mins . ' min';
$playerUrl = u('creator/course.php?id=' . $id);
$ctaLabel = $completedCount > 0 ? 'Continue watching' : 'Start watching';

$pageTitle = $course['title'];
require __DIR__ . '/../includes/creator_header.php';
?>
<style>
.ci-wrap { max-width: 980px; }
.ci-kicker { color: var(--primary); font-size: .8125rem; font-weight: 800; letter-spacing: .12em; text-transform: none; margin-bottom: 8px; }
.ci-title { font-size: clamp(1.7rem, 3vw, 2.35rem); line-height: 1.15; margin: 0 0 10px; }
.ci-lede { color: var(--muted); font-size: 1.02rem; line-height: 1.6; max-width: 66ch; margin: 0 0 24px; }
.ci-player {
    position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden;
    border-radius: 20px; background: #101010; text-decoration: none;
    box-shadow: 0 26px 60px rgba(20, 18, 12, .2);
}
.ci-player__img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
.ci-player:hover .ci-player__img { transform: scale(1.03); }
.ci-player__shade { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(8,8,8,.05) 20%, rgba(8,8,8,.66) 100%); }
.ci-player__play {
    position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
    width: 90px; height: 90px; border-radius: 50%;
    display: grid; place-items: center;
    background: rgba(255,255,255,.94); color: #1a1a1a;
    box-shadow: 0 10px 34px rgba(0,0,0,.4);
    transition: transform .18s ease, background .18s ease;
}
.ci-player:hover .ci-player__play { transform: translate(-50%, -50%) scale(1.06); background: #fff; }
.ci-player__play svg { width: 34px; height: 34px; margin-left: 4px; }
.ci-player__caption {
    position: absolute; left: 24px; right: 24px; bottom: 20px;
    display: flex; justify-content: space-between; align-items: flex-end; gap: 16px;
    color: #fff;
}
.ci-player__caption strong { display: block; font-size: 1.05rem; font-weight: 700; }
.ci-player__caption small { display: block; margin-top: 2px; color: rgba(255,255,255,.78); font-size: .82rem; }
.ci-player__badge {
    flex: 0 0 auto; padding: 7px 11px; border-radius: 999px;
    background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.28);
    color: #fff; font-size: .8125rem; font-weight: 700; letter-spacing: .04em; backdrop-filter: blur(6px);
}
.ci-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
.ci-chip { padding: 7px 12px; border-radius: 999px; border: 1px solid var(--line); background: var(--panel); color: var(--muted); font-size: .8125rem; font-weight: 600; }
.ci-actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 22px; }
.ci-progress { flex: 1 1 220px; min-width: 180px; }
.ci-progress__text { color: var(--muted); font-size: .82rem; margin-bottom: 6px; }
.ci-progress__bar { height: 6px; border-radius: 999px; background: #e9e3d2; overflow: hidden; }
.ci-progress__fill { height: 100%; border-radius: 999px; background: var(--primary); }
@media (max-width: 640px) {
    .ci-player { border-radius: 14px; }
    .ci-player__play { width: 64px; height: 64px; }
    .ci-player__play svg { width: 24px; height: 24px; }
    .ci-player__caption { left: 16px; right: 16px; bottom: 14px; }
    .ci-player__caption strong { font-size: .92rem; }
    .ci-player__badge { display: none; }
}
</style>

<div class="ci-wrap">
    <div class="ci-kicker">Free clipping course</div>
    <h1 class="ci-title"><?= e($course['title']) ?></h1>
    <p class="ci-lede"><?= e($course['description'] ?: 'Learn how to become a successful clipper and start getting paid to do something you love.') ?></p>

    <a class="ci-player" href="<?= e($playerUrl) ?>" aria-label="Watch <?= e($course['title']) ?>">
        <?php if ($poster !== ''): ?>
            <img class="ci-player__img" src="<?= e($poster) ?>" alt="">
        <?php endif; ?>
        <span class="ci-player__shade" aria-hidden="true"></span>
        <span class="ci-player__play" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg>
        </span>
        <span class="ci-player__caption">
            <span>
                <strong>Watch the course</strong>
                <small><?= (int)$totalLessons ?> lessons &middot; <?= e($durationLabel) ?></small>
            </span>
            <span class="ci-player__badge">Tap to open</span>
        </span>
    </a>

    <div class="ci-meta">
        <span class="ci-chip"><?= (int)$chapterCount ?> chapters</span>
        <span class="ci-chip"><?= (int)$totalLessons ?> lessons</span>
        <span class="ci-chip"><?= e($durationLabel) ?> total</span>
    </div>

    <div class="ci-actions">
        <a class="btn btn--primary" href="<?= e($playerUrl) ?>"><?= e($ctaLabel) ?></a>
        <?php if ($totalLessons > 0): ?>
        <div class="ci-progress">
            <div class="ci-progress__text"><?= (int)$completedCount ?> of <?= (int)$totalLessons ?> lessons completed &middot; <?= (int)$pct ?>%</div>
            <div class="ci-progress__bar"><div class="ci-progress__fill" style="width:<?= (int)$pct ?>%"></div></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
