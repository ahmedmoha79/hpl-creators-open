<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Tutorials';
$user = current_user();

$tutorials = [];
foreach (tutorials_catalogue() as $t) {
    $t = array_merge($t, tutorial_video($t));
    $tutorials[] = $t;
}

$active = null;
foreach ($tutorials as $i => $t) {
    if ($t['has_video']) {
        $active = $i;
        break;
    }
}
$hasAny = $active !== null;

require __DIR__ . '/includes/header.php';
?>
<section class="hero hero--short">
    <div class="container hero__content" style="text-align:center;max-width:none">
        <span class="eyebrow"><?= icon('film') ?> Tutorials</span>
        <h1>Learn how to earn</h1>
        <p class="hero__lead">Short videos that walk you through the program, step by step.</p>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="container">
        <div class="tutorial-player">
            <div class="video-frame video-frame--tutorial">
                <?php if ($hasAny): ?>
                    <video id="tutVideo" controls playsinline preload="metadata"
                           poster="<?= e(u('assets/media/tutorial-poster.svg?v=9')) ?>">
                    </video>
                <?php else: ?>
                    <div class="tutorial-empty" id="tutEmpty">
                        <div class="tutorial-empty__icon"><?= icon('film') ?></div>
                        <h3>Tutorial videos coming soon</h3>
                        <p class="muted">Tutorial clips will appear here as they're published.</p>
                    </div>
                <?php endif; ?>
            </div>
            <aside class="tutorial-playlist">
                <div class="tutorial-playlist__head">
                    <h3>All tutorials</h3>
                    <span class="badge"><?= count($tutorials) ?> clips</span>
                </div>
                <ul class="tutorial-list" id="tutList">
                    <?php foreach ($tutorials as $i => $t): ?>
                        <li data-src="<?= $t['has_video'] ? e($t['src']) : '' ?>"
                            data-title="<?= e($t['title']) ?>"
                            class="<?= ($hasAny && $i === $active) ? 'is-active' : '' ?><?= $t['has_video'] ? '' : ' is-locked' ?>" tabindex="0">
                            <span class="tutorial-list__num"><?= sprintf('%02d', $i + 1) ?></span>
                            <span class="tutorial-list__body">
                                <strong><?= e($t['title']) ?></strong>
                                <span class="muted small"><?= e($t['desc']) ?></span>
                            </span>
                            <span class="tutorial-list__state small">
                                <?php if ($t['has_video']): ?>&#9654;
                                <?php elseif ($t['drive']): ?><span class="badge warn">Drive link set</span>
                                <?php else: ?><span class="badge">Soon</span><?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </aside>
        </div>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="container">
        <div class="cta-band">
            <h2>Ready to try it yourself?</h2>
            <div class="flex flex-wrap" style="justify-content:center;gap:12px">
                <?php if ($user): ?>
                    <a class="btn btn--primary" href="<?= e(u($user['role'] === 'admin' ? 'admin/index.php' : 'creator/index.php')) ?>">Open dashboard</a>
                <?php else: ?>
                    <a class="btn btn--primary" href="<?= e(u('register.php')) ?>">Create free account</a>
                    <a class="btn btn--ghost" href="<?= e(u('login.php')) ?>">Sign in</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>