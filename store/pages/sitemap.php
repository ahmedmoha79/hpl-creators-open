<?php
/** Every public page in all three languages, with product pictures and videos (Google reads these). */
header('Content-Type: application/xml; charset=utf-8');
$pages = ['' => null, 'shop' => null, 'videos' => null, 'how-to-buy' => null, 'contact' => null, 'verify' => null, 'delivery' => null];
foreach (categories() as $c) if ((int)$c['n'] > 0) $pages['shop/' . $c['slug']] = null;
foreach (products() as $p) $pages['product/' . $p['slug']] = $p;
require_once STORE_ROOT . '/inc/guides.php';
$pages['guides'] = null;
foreach (array_keys(guides()) as $g) $pages['guides/' . $g] = null;
$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";
foreach ($pages as $u => $p) {
    $vids = $p ? videos(['product' => $p['id']]) : ($u === 'videos' ? videos() : []);
    foreach (array_keys(STORE_LANGS) as $l) {
        echo '<url><loc>' . $x(abs_url_in($l, $u)) . '</loc>';
        foreach (array_keys(STORE_LANGS) as $l2) echo '<xhtml:link rel="alternate" hreflang="' . $l2 . '" href="' . $x(abs_url_in($l2, $u)) . '"/>';
        echo '<xhtml:link rel="alternate" hreflang="x-default" href="' . $x(abs_url_in('en', $u)) . '"/>';
        if ($p) {
            foreach (array_filter(array_merge([$p['image']], json_decode((string)$p['gallery'], true) ?: [])) as $img) {
                echo '<image:image><image:loc>' . $x('https://' . site_host() . img_url($img)) . '</image:loc></image:image>';
            }
        }
        foreach ($vids as $v) {
            if (!$v['file'] || !$v['poster']) continue;
            echo '<video:video><video:thumbnail_loc>' . $x('https://' . site_host() . upload_url($v['poster'])) . '</video:thumbnail_loc>'
                . '<video:title>' . $x(tr($v['title'], $l)) . '</video:title>'
                . '<video:description>' . $x(tr($v['title'], $l) . ' – HPL Gold Detectors, ' . ($v['place'] ?: 'Zambia')) . '</video:description>'
                . '<video:content_loc>' . $x('https://' . site_host() . upload_url($v['file'])) . '</video:content_loc>'
                . (!empty($v['duration']) ? '<video:duration>' . (int)$v['duration'] . '</video:duration>' : '')
                . '</video:video>';
        }
        echo "</url>\n";
    }
}
echo '</urlset>';
