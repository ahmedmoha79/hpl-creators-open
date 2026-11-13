<?php
/** Visitor analytics endpoint (see inc/track.php). Always answers fast and never shows errors. */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/store.php';
require dirname(__DIR__) . '/inc/track.php';
header('Cache-Control: no-store');
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$a = (string)($in['a'] ?? '');
try {
    if ($a === 'pv') {
        $path = (string)($in['path'] ?? '');
        if ($path === '' || $path[0] !== '/') json_out(['ok' => false]);
        $id = track_pageview($in);
        json_out(['ok' => true, 'pv' => $id]);
    } elseif ($a === 'time') { // time spent on a page (sent when the visitor leaves it)
        $pv = (int)($in['pv'] ?? 0);
        $secs = max(0, min(3600, (int)($in['secs'] ?? 0)));
        $v = track_visitor();
        if ($pv && $v) q('UPDATE shop_pageviews SET secs = GREATEST(secs, ?) WHERE id = ? AND visitor_id = ?', [$secs, $pv, $v['id']]);
        http_response_code(204);
        exit;
    } elseif ($a === 'ev') {
        $type = preg_replace('~[^a-z_]~', '', (string)($in['type'] ?? ''));
        if ($type !== '' && rate_ok('track_ev', 300, 3600)) track_event($type, (string)($in['label'] ?? ''));
        http_response_code(204);
        exit;
    }
} catch (Throwable $e) {
    error_log('track: ' . $e->getMessage());
}
json_out(['ok' => false]);
