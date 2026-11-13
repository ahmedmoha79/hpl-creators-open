</main>
<footer class="site-footer">
    <div class="container site-footer__inner">
        <div>
            <strong><?= e(APP_NAME) ?></strong> &mdash; <?= e(APP_TAGLINE) ?>
            <?php $footPhone = trim((string)setting('support_phone', '')); if ($footPhone !== ''): ?>
                <div class="site-footer__contact">Support: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $footPhone)) ?>"><?= e($footPhone) ?></a></div>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap">
            <a href="<?= e(u('index.php#how')) ?>">How it works</a>
            <a href="<?= e(u('index.php#faq')) ?>">FAQ</a>
            <a href="<?= e(u('tutorial.php')) ?>">Tutorials</a>
            <a href="<?= e(u('login.php')) ?>">Sign in</a>
            <a href="<?= e(u('register.php')) ?>">Join</a>
            <a href="<?= e(u('privacy.php')) ?>">Privacy</a>
            <a href="<?= e(u('terms.php')) ?>">Terms</a>
        </div>
        <div>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</div>
    </div>
</footer>
<script src="<?= e(u('assets/js/main.js?v=20261113a')) ?>"></script>
</body>
</html>
