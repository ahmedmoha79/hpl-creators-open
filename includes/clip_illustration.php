<?php
/*
 * "How clipping works" illustration, drawn as SVG (no photo, no AI image).
 * Four picture panels: HPL films -> HPL edits -> you post -> you get paid.
 * The pay example uses the live rate from Settings.
 *
 * clip_illustration('row')  wide, 4 panels in a line (desktop)
 * clip_illustration('grid') 2 x 2 panels (phones)
 */
function clip_illustration(string $layout = 'row'): string
{
    $rate  = (float)setting('rate_per_1000', 10);
    $views = 5000;
    $pay   = 'Paid'; // no fixed rate on public pages: each campaign sets its own (owner, 2026-11-12)
    $uid   = 'ci' . $layout;

    // One small field scene (sky, sun, hill, person with a detector) reused in every frame.
    $defs = '<defs>'
        . '<symbol id="' . $uid . 'scene" viewBox="0 0 56 58">'
        . '<rect width="56" height="58" fill="#f7dcb6"/>'
        . '<circle cx="44" cy="13" r="6" fill="#f2a93b"/>'
        . '<path d="M0 40 Q16 30 30 36 T56 34 V58 H0Z" fill="#c98a4b"/>'
        . '<circle cx="22" cy="24" r="3.4" fill="#2a1d14"/>'
        . '<path d="M22 27.5 V38 M22 31 L29 35 M22 38 L19 46 M22 38 L25 46" stroke="#2a1d14" stroke-width="2.4" stroke-linecap="round" fill="none"/>'
        . '<path d="M29 35 L35 45" stroke="#2a1d14" stroke-width="2" stroke-linecap="round"/>'
        . '<ellipse cx="36.5" cy="46.5" rx="4.5" ry="2" fill="#2a1d14"/>'
        . '</symbol>'
        . '<symbol id="' . $uid . 'strip" viewBox="0 0 260 96">'
        . '<rect width="260" height="96" rx="8" fill="#2a1d14"/>'
        . implode('', array_map(fn($i) => '<rect x="' . (10 + $i * 16) . '" y="6" width="8" height="6" rx="1.5" fill="#fbf4ea"/><rect x="' . (10 + $i * 16) . '" y="84" width="8" height="6" rx="1.5" fill="#fbf4ea"/>', range(0, 15)))
        . '</symbol>'
        . '</defs>';

    $panel = function (int $n, string $title, string $sub, string $art): string {
        return '<rect x="0" y="0" width="300" height="240" rx="18" fill="#ffffff" stroke="#ecdcc8" stroke-width="2"/>'
            . '<circle cx="30" cy="32" r="17" fill="#2a1d14"/><text x="30" y="39" text-anchor="middle" font-size="20" font-weight="800" fill="#fff">' . $n . '</text>'
            . '<text x="56" y="40" font-size="23" font-weight="800" fill="#2a1d14">' . e($title) . '</text>'
            . $art
            . '<text x="150" y="228" text-anchor="middle" font-size="18" fill="#6b5a4b">' . e($sub) . '</text>';
    };

    // 1. A long video: a film strip with many frames.
    $a1 = '<use href="#' . $uid . 'strip" x="20" y="70" width="260" height="96"/>';
    for ($i = 0; $i < 4; $i++) {
        $a1 .= '<use href="#' . $uid . 'scene" x="' . (30 + $i * 62) . '" y="89" width="56" height="58"/>';
    }
    $a1 .= '<rect x="96" y="176" width="108" height="26" rx="13" fill="#f3e3cf"/><text x="150" y="194" text-anchor="middle" font-size="15" font-weight="700" fill="#8a5a2b">20 minutes</text>';

    // 2. Cut the best part: one frame lifted out of the strip, scissors.
    $a2 = '<g opacity=".35"><use href="#' . $uid . 'strip" x="20" y="96" width="260" height="96"/>';
    foreach ([0, 1, 3] as $i) {
        $a2 .= '<use href="#' . $uid . 'scene" x="' . (30 + $i * 62) . '" y="115" width="56" height="58"/>';
    }
    $a2 .= '</g>'
        . '<rect x="148" y="112" width="64" height="66" rx="4" fill="none" stroke="#c98a4b" stroke-width="2" stroke-dasharray="5 4"/>'
        . '<rect x="146" y="58" width="68" height="70" rx="6" fill="#fff" stroke="#c98a4b" stroke-width="4"/>'
        . '<use href="#' . $uid . 'scene" x="152" y="64" width="56" height="58"/>'
        // scissors
        . '<g transform="translate(232 70) rotate(35)" stroke="#2a1d14" stroke-width="3" fill="none" stroke-linecap="round">'
        . '<circle cx="-8" cy="22" r="7"/><circle cx="8" cy="22" r="7"/><path d="M-4 16 L10 -18 M4 16 L-10 -18"/></g>'
        . '<rect x="98" y="194" width="104" height="24" rx="12" fill="#f3e3cf"/><text x="150" y="211" text-anchor="middle" font-size="15" font-weight="700" fill="#8a5a2b">30 seconds</text>';

    // 3. Post it on your own page: a phone playing the clip.
    $a3 = '<rect x="104" y="54" width="92" height="152" rx="16" fill="#2a1d14"/>'
        . '<rect x="111" y="66" width="78" height="122" rx="6" fill="#f7dcb6"/>'
        . '<svg x="111" y="66" width="78" height="122" viewBox="0 0 56 58" preserveAspectRatio="xMidYMid slice"><use href="#' . $uid . 'scene"/></svg>'
        . '<circle cx="150" cy="127" r="15" fill="rgba(0,0,0,.45)"/><path d="M145 119 L159 127 L145 135Z" fill="#fff"/>'
        . '<path d="M181 98 c-3-4-9-1-6 3 l6 6 6-6 c3-4-3-7-6-3z" fill="#e2445c"/>'
        . '<rect x="116" y="172" width="44" height="8" rx="4" fill="rgba(255,255,255,.85)"/>'
        . '<rect x="138" y="194" width="24" height="4" rx="2" fill="#6b5a4b"/>'
        . '<text x="236" y="134" text-anchor="middle" font-size="17" font-weight="800" fill="#8a5a2b">TikTok</text>';

    // 4. People watch, you get paid: views -> money.
    $a4 = '<g transform="translate(46 70)"><path d="M0 14 Q22 -6 44 14 Q22 34 0 14Z" fill="#fff" stroke="#2a1d14" stroke-width="3"/><circle cx="22" cy="14" r="7" fill="#2a1d14"/></g>'
        . '<text x="104" y="92" font-size="25" font-weight="800" fill="#2a1d14">' . e(num($views)) . ' views</text>'
        . '<path d="M150 106 V128 M141 120 L150 130 L159 120" stroke="#c98a4b" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<rect x="58" y="138" width="184" height="64" rx="10" fill="#2f8f5b"/>'
        . '<circle cx="84" cy="170" r="12" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="3"/>'
        . '<text x="160" y="181" text-anchor="middle" font-size="28" font-weight="800" fill="#fff">' . e($pay) . '</text>';

    $panels = [
        $panel(1, 'HPL films', 'a long video in the field', $a1),
        $panel(2, 'We edit', 'a ready clip for you', $a2),
        $panel(3, 'You post', 'on your own TikTok', $a3),
        $panel(4, 'You get paid', 'to mobile money', $a4),
    ];

    $arrow = fn(float $x, float $y, float $rot = 0) => '<g transform="translate(' . $x . ' ' . $y . ') rotate(' . $rot . ')"><circle r="17" fill="#c98a4b"/><path d="M-7 0 H6 M1 -6 L7 0 L1 6" stroke="#fff" stroke-width="3.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></g>';

    if ($layout === 'grid') {
        $w = 640; $h = 520;
        $pos = [[10, 10], [330, 10], [10, 270], [330, 270]];
        $arrows = $arrow(320, 130) . $arrow(320, 260, 135) . $arrow(320, 390);
    } else {
        $w = 1290; $h = 260;
        $pos = [[10, 10], [330, 10], [650, 10], [970, 10]];
        $arrows = $arrow(320, 130) . $arrow(640, 130) . $arrow(960, 130);
    }

    $body = '';
    foreach ($panels as $i => $p) {
        $body .= '<g transform="translate(' . $pos[$i][0] . ' ' . $pos[$i][1] . ')">' . $p . '</g>';
    }

    $label = 'How clipping works: 1, HPL films a long video. 2, HPL edits the best 30 seconds into a ready clip. 3, you post it on your own TikTok. 4, for example '
        . num($views) . ' views gets paid to your mobile money.';

    return '<svg class="clip-art clip-art--' . $layout . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($label) . '" '
        . 'font-family="Inter, system-ui, sans-serif" xmlns="http://www.w3.org/2000/svg">'
        . $defs . '<rect width="' . $w . '" height="' . $h . '" fill="#fbf4ea"/>' . $body . $arrows . '</svg>';
}
