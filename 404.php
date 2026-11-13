<?php
http_response_code(404);
$pageTitle = 'Page not found';
$metaDesc = 'This page does not exist.';
require __DIR__ . '/includes/header.php';
?>
<section class="section">
    <div class="narrow legal" style="text-align:center;padding:48px 0">
        <p class="muted" style="font-size:.9rem;letter-spacing:.08em;text-transform:uppercase;margin:0 0 8px">Error 404</p>
        <h1 style="margin:0 0 12px">We could not find that page</h1>
        <p class="muted" style="max-width:460px;margin:0 auto 24px">The link may be old or mistyped. Use one of these instead.</p>
        <p style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <a class="btn btn--primary" href="<?= e(u('')) ?>">Go to the homepage</a>
            <?php if (!empty($user)): ?>
                <a class="btn btn--ghost" href="<?= e(u($user['role'] === 'admin' ? 'admin/index.php' : 'creator/index.php')) ?>">Open my dashboard</a>
            <?php else: ?>
                <a class="btn btn--ghost" href="<?= e(u('login.php')) ?>">Sign in</a>
            <?php endif; ?>
        </p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
