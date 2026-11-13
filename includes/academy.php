<?php
/**
 * HPL Creator Academy course content (2026-10-26).
 * The course player reads its lessons from tutorials_catalogue() so the
 * course and the public Tutorials page always show the same videos.
 */

/** Lesson length in seconds, by tutorial id (used for the duration labels). */
function hpl_academy_durations(): array
{
    return [
        'welcome' => 214, 'what-is-clipping' => 38, 'get-approved' => 47, 'three-things' => 66,
        'what-to-clip' => 71, 'first-clip' => 308, 'phone-editing' => 66, 'posting-basics' => 351, 'get-paid' => 63,
    ];
}

/** Chapters in the format course.php expects: [['title'=>..., 'lessons'=>[[title,type,durationSec,videoUrl,thumbnailUrl]]]]. */
function hpl_academy_chapters(): array
{
    $groups = [
        'Getting started'          => ['welcome', 'what-is-clipping', 'get-approved'],
        'Making great clips'       => ['three-things', 'what-to-clip', 'first-clip', 'phone-editing'],
        'Posting and getting paid' => ['posting-basics', 'get-paid'],
    ];
    $byId = [];
    foreach (tutorials_catalogue() as $t) {
        $byId[$t['id']] = $t;
    }
    $dur = hpl_academy_durations();
    $thumb = u('assets/media/tutorial-poster.svg?v=9');
    $chapters = [];
    foreach ($groups as $title => $ids) {
        $lessons = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $t = $byId[$id];
            $v = tutorial_video($t);
            $lessons[] = [
                'title'        => $t['tag'] . ' · ' . $t['title'],
                'type'         => 'Video',
                'durationSec'  => (int)($dur[$id] ?? 0),
                'videoUrl'     => $v['has_video'] ? (string)$v['src'] : '',
                'thumbnailUrl' => $thumb,
                'desc'         => (string)($t['desc'] ?? ''),
                'links'        => hpl_academy_links($t),
            ];
        }
        if ($lessons) {
            $chapters[] = ['title' => $title, 'lessons' => $lessons];
        }
    }
    return $chapters;
}

/** Resource links shown under a lesson (Resources tab). */
function hpl_academy_links(array $t): array
{
    $links = [];
    if (!empty($t['link'])) {
        $links[] = ['label' => (string)($t['link_label'] ?? 'Open'), 'note' => 'Put this lesson into practice', 'url' => u((string)$t['link'])];
    }
    if (in_array($t['id'], ['three-things', 'what-to-clip', 'first-clip', 'phone-editing'], true)) {
        $links[] = ['label' => 'Footage library', 'note' => 'Download real HPL footage to clip', 'url' => u('creator/library.php')];
    }
    if (in_array($t['id'], ['posting-basics', 'get-paid', 'get-approved'], true)) {
        $links[] = ['label' => 'Connect your TikTok', 'note' => 'Sign in with TikTok to verify your page. No code needed', 'url' => u('creator/connect_tiktok.php')];
    }
    $links[] = ['label' => 'All tutorial videos', 'note' => 'Watch the full HPL Creator Academy', 'url' => u('tutorial.php')];
    $links[] = ['label' => 'Ask the HPL team', 'note' => 'Support chat', 'url' => u('creator/support.php')];
    $seen = [];
    return array_values(array_filter($links, function ($l) use (&$seen) { if (isset($seen[$l['url']])) { return false; } $seen[$l['url']] = true; return true; }));
}

/** True when a course's own chapters contain at least one playable video URL. */
function hpl_course_has_videos(array $chapters): bool
{
    foreach ($chapters as $ch) {
        foreach (($ch['lessons'] ?? []) as $l) {
            if (trim((string)($l['videoUrl'] ?? '')) !== '') {
                return true;
            }
        }
    }
    return false;
}
