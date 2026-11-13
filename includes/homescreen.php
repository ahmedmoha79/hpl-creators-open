<?php
/**
 * "Add to home screen" app (2026-11-05, owner decision: no app-store app, it must work on iPhone and Android).
 * The site installs as an icon that opens full screen like an app (manifest.webmanifest + sw.js).
 * Anything with class "hide-in-app" disappears when the site is opened from the home-screen icon.
 */
function homescreen_head(): void
{
    $v = '20261110';
    ?>
    <link rel="manifest" href="<?= e(u('manifest.webmanifest')) ?>">
    <link rel="apple-touch-icon" href="<?= e(u('assets/img/app-180.png')) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="HPL Creators">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="hpl-push" content="<?= e(u('api/push.php')) ?>">
    <meta name="hpl-csrf" content="<?= e(function_exists('csrf_token') && current_user() ? csrf_token() : '') ?>">
    <style>@media (display-mode: standalone){.hide-in-app{display:none!important}}</style>
    <script>
    if ('serviceWorker' in navigator) { window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js?v=<?= $v ?>').catch(function () {}); }); }
    // Chrome on Android: keep the install offer so our own "Add to home screen" buttons can show it.
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); window.hplInstall = e; document.documentElement.classList.add('can-install'); });
    window.hplInApp = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
    // The app opened from the home-screen icon is for signing in, not the advert page (2026-11-11).
    if (window.hplInApp && /^\/(index\.php)?$/.test(location.pathname)) { location.replace(<?= json_encode(u('login.php')) ?>); }
    </script>
    <?php
}
