<?php
/**
 * Small charts drawn by the server as SVG (2026-11-10). No chart library to download on slow connections,
 * and they follow light and dark mode because they use the site's colour tokens.
 */

/** Short number for an axis: 950, 1.2K, 3.4M. */
function chart_short(float $n): string
{
    $a = abs($n);
    if ($a >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; }
    if ($a >= 1000) { return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; }
    return (string)(floor($n) == $n ? (int)$n : round($n, 1));
}

/**
 * A bar chart of one number per day.
 * @param array  $series [label => value] in order (label e.g. "3 Nov")
 * @param string $unit   put before the value in the tooltip, e.g. "K"
 */
function chart_bars(array $series, string $unit = '', string $tone = 'accent'): string
{
    $n = count($series);
    if ($n === 0) {
        return '<p class="muted small">Nothing to show yet.</p>';
    }
    $max = max(1, max($series));
    $W = 640; $H = 180; $padL = 38; $padB = 22; $padT = 8;
    $plotW = $W - $padL - 6; $plotH = $H - $padB - $padT;
    $bw = $plotW / $n;
    $fill = $tone === 'green' ? 'var(--ui-ok, #2f9e62)' : ($tone === 'gold' ? '#f2c14e' : 'var(--ui-accent, #a8642c)');
    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" style="width:100%;height:auto;display:block">';
    foreach ([0, 0.5, 1] as $g) {
        $y = $padT + $plotH * (1 - $g);
        $svg .= '<line x1="' . $padL . '" x2="' . $W . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" stroke="var(--ui-line, #e6ded3)" stroke-width="1"/>';
        $svg .= '<text x="' . ($padL - 5) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="11" fill="var(--ui-muted, #8a7f73)">' . e(chart_short($max * $g)) . '</text>';
    }
    $i = 0;
    $every = max(1, (int)ceil($n / 8));
    foreach ($series as $label => $v) {
        $h = $v > 0 ? max(2, $plotH * $v / $max) : 0;
        $x = $padL + $i * $bw + $bw * 0.15;
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($padT + $plotH - $h, 1) . '" width="' . round($bw * 0.7, 1) . '" height="' . round($h, 1) . '" rx="2" fill="' . $fill . '"><title>' . e($label . ': ' . $unit . number_format((float)$v, floor($v) == $v ? 0 : 2)) . '</title></rect>';
        if ($i % $every === 0) {
            $svg .= '<text x="' . round($x + $bw * 0.35, 1) . '" y="' . ($H - 6) . '" text-anchor="middle" font-size="11" fill="var(--ui-muted, #8a7f73)">' . e((string)$label) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

/** A tiny line of a clip's views over time (no axes), for tables. $points = list of numbers. */
function chart_spark(array $points, int $w = 120, int $h = 30): string
{
    $n = count($points);
    if ($n < 2) {
        return '';
    }
    $min = min($points); $max = max($points); $range = max(1, $max - $min);
    $d = '';
    foreach (array_values($points) as $i => $p) {
        $d .= ($i ? 'L' : 'M') . round($i * ($w - 2) / ($n - 1) + 1, 1) . ' ' . round($h - 2 - ($p - $min) / $range * ($h - 4), 1) . ' ';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path d="' . trim($d) . '" fill="none" stroke="var(--ui-accent, #a8642c)" stroke-width="1.8" stroke-linejoin="round"/></svg>';
}

/** Horizontal bars for a ranked list: [[label, value, note?], ...]. */
function chart_rank(array $rows, string $unit = ''): string
{
    if (!$rows) {
        return '<p class="muted small">Nothing to show yet.</p>';
    }
    $max = max(1, max(array_column($rows, 1)));
    $h = '<div class="rank">';
    foreach ($rows as $r) {
        $h .= '<div class="rank__row"><span class="rank__label">' . e((string)$r[0]) . '</span><span class="rank__bar"><i style="width:' . max(2, round($r[1] / $max * 100)) . '%"></i></span><b class="rank__val">' . e($unit . number_format((float)$r[1])) . '</b>'
            . (isset($r[2]) && $r[2] !== '' ? '<small class="rank__note">' . e((string)$r[2]) . '</small>' : '') . '</div>';
    }
    return $h . '</div>';
}
