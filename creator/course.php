<?php
require_once __DIR__ . '/../config.php';
$user = require_creator();

$id = (int)get('id');
$course = null;
if ($id > 0) {
    $course = fetch_one('SELECT * FROM courses WHERE id = ? AND published = 1', [$id]);
}
if (!$course) {
    // No/invalid id: fall back to the first published course so the
    // sidebar "Free Clipping Course" link opens the player directly.
    $course = fetch_one('SELECT * FROM courses WHERE published = 1 ORDER BY sort_order ASC, id ASC LIMIT 1');
    if ($course) { $id = (int)$course['id']; }
}

if (!$course) {
    $pageTitle = 'Free clipping course';
    require __DIR__ . '/../includes/creator_header.php';
    echo '<section class="panel"><div class="panel__body empty"><h1>Free clipping course</h1><p>The course is being prepared. Published lessons will appear here as soon as the team makes them available.</p><a class="btn btn--primary" href="' . e(u('creator/support.php')) . '">Contact support</a></div></section>';
    require __DIR__ . '/../includes/creator_footer.php';
    exit;
}
$chapters = json_decode($course['chapters'] ?: '[]', true) ?: [];
// Use the HPL Creator Academy videos when the saved course has no playable videos.
require_once __DIR__ . '/../includes/academy.php';
if (!hpl_course_has_videos($chapters)) {
    $chapters = hpl_academy_chapters();
    $course['title'] = 'HPL Creator Academy';
    $course['description'] = 'Nine short lessons that take you from sign-up to your first payout: how the program works, getting approved, what makes a clip work, what to clip from HPL footage, editing on your phone, posting the HPL way and getting paid.';
    $course['thumbnail_url'] = u('assets/media/tutorial-poster.svg?v=9');
}
// flatten lessons
$lessons = [];
foreach ($chapters as $ci => $ch) {
    foreach (($ch['lessons'] ?? []) as $li => $l) {
        $lessons[] = ['ci'=>$ci,'li'=>$li,'chapter'=>$ch['title'] ?? 'Chapter','title'=>$l['title'] ?? 'Lesson','type'=>$l['type'] ?? 'Multimedia','durationSec'=>(int)($l['durationSec'] ?? 0),'videoUrl'=>$l['videoUrl'] ?? '','thumb'=>$l['thumbnailUrl'] ?? '','desc'=>$l['desc'] ?? '','links'=>$l['links'] ?? []];
    }
}
$totalLessons = count($lessons);
$progressRow = fetch_one('SELECT completed_lessons, last_lesson_id FROM course_progress WHERE user_id = ? AND course_id = ?', [(int)$user['id'], $id]);
$completed = $progressRow ? (json_decode($progressRow['completed_lessons'] ?: '[]', true) ?: []) : [];
$completedMap = array_flip($completed);
$lastLesson = $progressRow ? (int)($progressRow['last_lesson_id'] ?? 0) : 0;
if (isset($_GET['lesson']) && ctype_digit((string)$_GET['lesson'])) { $lastLesson = (int)$_GET['lesson']; }
if ($lastLesson >= count($lessons) || $lastLesson < 0) { $lastLesson = 0; }
$pct = $totalLessons > 0 ? (int)round((count($completed) / $totalLessons) * 100) : 0;
$done = ($totalLessons > 0 && count($completed) === $totalLessons);

function fmtDur($s){ if($s>=60) return floor($s/60).':'.str_pad($s%60,2,'0',STR_PAD_LEFT); return '0:'.str_pad($s,2,'0',STR_PAD_LEFT); }

$pageTitle = $course['title'];
require __DIR__ . '/../includes/creator_header.php';
?>
<details class="panel" style="margin-bottom:12px"><summary class="panel__head" style="cursor:pointer"><h3>Start here: the whole programme in 2 short videos</h3></summary>
    <div class="panel__body" style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
        <video controls playsinline preload="none" poster="<?= e(u('assets/media/home/how-to-start-6.jpg')) ?>" src="<?= e(u('assets/media/home/how-to-start-6.mp4?v=20261113a')) ?>" style="width:min(100%,260px);aspect-ratio:9/16;border-radius:14px;background:#000"></video>
        <video controls playsinline preload="none" poster="<?= e(u('assets/media/home/start-tonight.jpg')) ?>" src="<?= e(u('assets/media/home/start-tonight.mp4?v=20261113a')) ?>" style="width:min(100%,260px);aspect-ratio:9/16;border-radius:14px;background:#000"></video>
    </div></details>
<?php
?>

<div class="cp">
    <div class="cp-main">
        <div class="ui-video cp-video" id="playerWrap">
            <video id="lessonVideo" controls playsinline preload="metadata" poster="<?= e($course['thumbnail_url'] ?? '') ?>"></video>
            <div class="cp-pending" id="playerPoster" hidden><?= icon('clock', 'ico ico--lg') ?><span>This lesson's video is being prepared.</span></div>
        </div>

        <div class="cp-head">
            <div class="cp-crumb"><span id="breadcrumb"><?= e($lessons[$lastLesson]['chapter'] ?? '') ?></span> &middot; Lesson <span id="lessonNum"><?= $lastLesson + 1 ?></span> of <?= $totalLessons ?></div>
            <h1 class="cp-title" id="lessonTitle"><?= e($lessons[$lastLesson]['title'] ?? 'Select a lesson') ?></h1>
            <div class="cp-actions">
                <button type="button" class="btn btn--ghost btn--sm" id="prevBtn"><?= icon('arrow-left') ?><span>Previous</span></button>
                <button type="button" class="btn btn--ghost btn--sm cp-done" id="markCompleteBtn"><?= icon('check') ?><span>Mark as complete</span></button>
                <button type="button" class="btn btn--primary btn--sm" id="nextBtn"><span>Next lesson</span><?= icon('arrow-right') ?></button>
            </div>
        </div>

        <div class="tabs cp-tabs" id="lessonTabs" role="tablist">
            <button type="button" class="tab active" data-tab="overview" role="tab" aria-selected="true">Overview</button>
            <button type="button" class="tab cp-tab-lessons" data-tab="lessons" role="tab" aria-selected="false">Lessons</button>
            <button type="button" class="tab" data-tab="resources" role="tab" aria-selected="false">Resources</button>
            <button type="button" class="tab" data-tab="comments" role="tab" aria-selected="false">Comments</button>
        </div>
        <div class="lesson-panel cp-panel" data-panel="overview">
            <p id="lessonDesc" class="cp-desc"></p>
            <p class="cp-course-desc"><?= e($course['description']) ?></p>
        </div>
        <div class="lesson-panel cp-panel" data-panel="resources" hidden>
            <div class="lesson-res" id="lessonResources"></div>
        </div>
        <div class="lesson-panel cp-panel" data-panel="comments" id="comments" hidden>
            <form id="commentForm" class="lc-form">
                <div class="lc-avatar"><?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?></div>
                <div class="lc-form__main">
                    <textarea id="commentBody" maxlength="800" rows="2" placeholder="Ask a question or share a tip about this lesson"></textarea>
                    <div class="lc-form__row"><span id="commentMsg" class="lc-msg" role="status"></span><button type="submit" class="btn btn--primary btn--sm">Post</button></div>
                </div>
            </form>
            <div class="lc-head"><span id="commentCount">Comments</span><span class="lc-hint">The HPL team answers questions here.</span></div>
            <div id="commentList" class="lc-list"><p class="lc-empty">Loading comments...</p></div>
        </div>
    </div>

    <aside class="cp-side lesson-panel" data-panel="lessons" id="courseSidebar" hidden>
        <div class="cp-progress">
            <div class="cp-progress__top"><strong><?= e($course['title']) ?></strong><span id="progressPct"><?= $pct ?>%</span></div>
            <div class="ui-progress"><i id="progressFill" style="width:<?= $pct ?>%"></i></div>
            <div class="cp-progress__sub" id="progressSub"><?= count($completed) ?> of <?= $totalLessons ?> lessons completed</div>
        </div>
        <?php $idx = 0; foreach ($chapters as $ci => $ch): ?>
            <div class="cp-chapter">
                <div class="cp-chapter__title"><?= e($ch['title'] ?? 'Chapter') ?></div>
                <?php foreach (($ch['lessons'] ?? []) as $lesson): $isDone = isset($completedMap[$idx]); ?>
                    <button type="button" class="cp-lesson lesson-row<?= $idx === $lastLesson ? ' active' : '' ?><?= $isDone ? ' is-done' : '' ?>" data-idx="<?= $idx ?>">
                        <span class="cp-lesson__mark"><?= $isDone ? icon('check') : '<span>' . ($idx + 1) . '</span>' ?></span>
                        <span class="cp-lesson__text">
                            <span class="cp-lesson__title"><?= e(preg_replace('/^Lesson \d+ · /', '', (string)($lesson['title'] ?? 'Lesson'))) ?></span>
                            <span class="cp-lesson__meta"><?= icon('play', 'ico ico--sm') ?><?= fmtDur((int)($lesson['durationSec'] ?? 0)) ?></span>
                        </span>
                    </button>
                <?php $idx++; endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($done): ?>
            <?php $cpNext = (int)($user['qualified'] ?? 0) === 1 ? ['creator/index.php?tab=campaigns', 'Now join a campaign, clip its footage and send your first link.', 'Open campaigns']
                : (($user['assessment_status'] ?? '') !== 'passed' ? ['creator/assessment.php', 'Now take the short test. Then send us one video you edited.', 'Take the test'] : ((($user['portfolio_status'] ?? '') === 'pending') ? ['creator/index.php', 'We are checking your video now. You will get a notification soon, usually within 24 hours.', 'Go to my dashboard']
                : [onboarding_url($user), 'Now send us one video you edited, so we can approve you.', 'Send my video'])); ?>
            <div class="cp-finished"><?= icon('award', 'ico ico--lg') ?><strong>Course completed</strong><span><?= e($cpNext[1]) ?></span>
                <a class="btn btn--primary btn--sm" href="<?= e(u($cpNext[0])) ?>"><?= e($cpNext[2]) ?></a></div>
        <?php endif; ?>
    </aside>
</div>

<script>
(function(){
    var lessons = <?= json_encode(array_values($lessons), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var completed = <?= json_encode(array_values(array_map('intval', $completed))) ?>;
    var completedSet = {}; completed.forEach(function(i){ completedSet[i] = true; });
    var currentIdx = <?= (int)$lastLesson ?>;
    var csrf = '<?= e(csrf_token()) ?>';
    var courseId = <?= (int)$id ?>;
    var total = lessons.length;
    var progressUrl = '<?= e(u('creator/course_progress.php')) ?>';
    var CHECK = '<?= str_replace("'", "\\'", icon('check')) ?>';

    var video = document.getElementById('lessonVideo');
    var pending = document.getElementById('playerPoster');
    var titleEl = document.getElementById('lessonTitle');
    var crumb = document.getElementById('breadcrumb');
    var numEl = document.getElementById('lessonNum');
    var prevBtn = document.getElementById('prevBtn');
    var nextBtn = document.getElementById('nextBtn');
    var markBtn = document.getElementById('markCompleteBtn');

    function save(idx, complete){
        var fd = new FormData(); fd.append('course_id', courseId); fd.append('lesson_idx', idx); fd.append('csrf', csrf); fd.append('complete', complete ? '1' : '0');
        return fetch(progressUrl, { method:'POST', credentials:'same-origin', body: fd }).then(function(r){ return r.json(); });
    }
    function paintDone(){
        markBtn.classList.toggle('is-done', !!completedSet[currentIdx]);
        markBtn.querySelector('span').textContent = completedSet[currentIdx] ? 'Completed' : 'Mark as complete';
        var n = Object.keys(completedSet).length, pct = Math.round(n / Math.max(total,1) * 100);
        document.getElementById('progressFill').style.width = pct + '%';
        document.getElementById('progressPct').textContent = pct + '%';
        document.getElementById('progressSub').textContent = n + ' of ' + total + ' lessons completed';
    }
    function loadLesson(idx, userAction){
        if (idx < 0 || idx >= total) return;
        currentIdx = idx;
        var l = lessons[idx];
        titleEl.textContent = l.title;
        crumb.textContent = l.chapter;
        numEl.textContent = idx + 1;
        if (l.videoUrl) { video.hidden = false; pending.hidden = true; if (video.getAttribute('src') !== l.videoUrl) { video.src = l.videoUrl; video.load(); } }
        else { video.removeAttribute('src'); video.load(); video.hidden = true; pending.hidden = false; }
        document.querySelectorAll('.cp-lesson').forEach(function(r){ r.classList.toggle('active', +r.dataset.idx === idx); });
        prevBtn.disabled = (idx === 0);
        nextBtn.disabled = (idx === total - 1);
        paintDone();
        try { history.replaceState(null, '', '?id=' + courseId + '&lesson=' + idx + location.hash); } catch(e){}
        try { document.dispatchEvent(new CustomEvent('hpl:lesson', {detail: idx})); } catch(e){}
        if (userAction) { save(idx, false).catch(function(){}); if (window.innerWidth < 1024) window.scrollTo({top:0, behavior:'smooth'}); }
    }
    function complete(){
        if (completedSet[currentIdx]) return;
        var idx = currentIdx;
        completedSet[idx] = true; paintDone();
        save(idx, true).then(function(d){
            if (!d || !d.ok) { delete completedSet[idx]; paintDone(); return; }
            var row = document.querySelector('.cp-lesson[data-idx="' + idx + '"]');
            if (row) { row.classList.add('is-done'); row.querySelector('.cp-lesson__mark').innerHTML = CHECK; }
        }).catch(function(){ delete completedSet[idx]; paintDone(); });
    }
    document.querySelectorAll('.cp-lesson').forEach(function(row){
        row.addEventListener('click', function(){ loadLesson(parseInt(row.dataset.idx, 10), true); if (window.innerWidth < 1024) { var t = document.querySelector('[data-tab="overview"]'); if (t) t.click(); } });
    });
    prevBtn.addEventListener('click', function(){ loadLesson(currentIdx - 1, true); });
    nextBtn.addEventListener('click', function(){ complete(); loadLesson(currentIdx + 1, true); });
    markBtn.addEventListener('click', complete);
    video.addEventListener('timeupdate', function(){ if (video.duration && video.currentTime / video.duration >= 0.9) complete(); });
    document.addEventListener('keydown', function(e){
        if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName) || e.target.isContentEditable) return;
        if (e.key === 'n' || e.key === 'N') loadLesson(currentIdx + 1, true);
        if (e.key === 'p' || e.key === 'P') loadLesson(currentIdx - 1, true);
    });
    window.__cpLoad = function(){ loadLesson(currentIdx, false); };
})();
</script>

<script>
(function(){
    var data = <?= json_encode(array_values($lessons), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var courseId = <?= (int)$id ?>;
    var csrf = '<?= e(csrf_token()) ?>';
    var endpoint = '<?= e(u('creator/lesson_comments.php')) ?>';
    var current = <?= (int)$lastLesson ?>;
    var ICON = {
        up: '<?= str_replace("'", "\\'", icon('thumbs-up')) ?>',
        down: '<?= str_replace("'", "\\'", icon('thumbs-down')) ?>',
        reply: '<?= str_replace("'", "\\'", icon('reply')) ?>',
        trash: '<?= str_replace("'", "\\'", icon('trash')) ?>',
        go: '<?= str_replace("'", "\\'", icon('chevron-right')) ?>',
        dl: '<?= str_replace("'", "\\'", icon('download')) ?>'
    };
    var tabs = document.querySelectorAll('#lessonTabs .tab');
    var panels = document.querySelectorAll('.lesson-panel');
    var list = document.getElementById('commentList');
    var countEl = document.getElementById('commentCount');
    var active = 'overview';

    function esc(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
    function show(name){
        active = name;
        tabs.forEach(function(t){ var on = t.dataset.tab === name; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
        panels.forEach(function(p){ p.hidden = p.dataset.panel !== name; });
        if (name === 'comments') loadComments();
        try { history.replaceState(null, '', location.pathname + location.search + (name === 'comments' ? '#comments' : '')); } catch(e){}
    }
    tabs.forEach(function(t){ t.addEventListener('click', function(){ show(t.dataset.tab); }); });

    function renderLesson(idx){
        current = idx; var l = data[idx] || {};
        var desc = document.getElementById('lessonDesc'); if (desc) desc.textContent = l.desc || '';
        var res = document.getElementById('lessonResources');
        if (res) {
            var items = (l.links || []).slice();
            if (l.videoUrl) items.push({label:'Download this lesson video', note:'Watch it offline on your phone', url:l.videoUrl, download:true});
            res.innerHTML = !items.length ? '<p class="lc-empty">No extra resources for this lesson.</p>' : items.map(function(it){
                return '<a href="'+esc(it.url)+'"'+(it.download?' download':'')+'><div>'+esc(it.label)+(it.note?'<small>'+esc(it.note)+'</small>':'')+'</div><span class="go">'+(it.download?ICON.dl:ICON.go)+'</span></a>';
            }).join('');
        }
        if (active === 'comments') loadComments();
    }

    function commentHtml(c, isReply){
        var body = c.deleted ? '<p class="lc-deleted">This comment was deleted.</p>' : '<p class="lc-body">'+esc(c.body)+'</p>';
        var actions = c.deleted ? '' :
            '<div class="lc-actions">'+
              '<button type="button" class="lc-act'+(c.my_vote===1?' is-on':'')+'" data-vote="1" data-id="'+c.id+'" aria-pressed="'+(c.my_vote===1)+'" aria-label="Like">'+ICON.up+'<span>'+(c.likes||'')+'</span></button>'+
              '<button type="button" class="lc-act'+(c.my_vote===-1?' is-on is-down':'')+'" data-vote="-1" data-id="'+c.id+'" aria-pressed="'+(c.my_vote===-1)+'" aria-label="Dislike">'+ICON.down+'<span>'+(c.dislikes||'')+'</span></button>'+
              '<button type="button" class="lc-act" data-reply="'+(isReply ? c.parent : c.id)+'" data-to="'+esc(c.name)+'">'+ICON.reply+'<span>Reply</span></button>'+
              (c.can_delete ? '<button type="button" class="lc-act lc-act--muted" data-del="'+c.id+'" aria-label="Delete">'+ICON.trash+'</button>' : '')+
            '</div>';
        var replies = '';
        if (!isReply) {
            replies = '<div class="lc-replies">'+(c.replies||[]).map(function(r){ r.parent = c.id; return commentHtml(r, true); }).join('')+'</div>'+
                      '<div class="lc-replybox" data-replybox="'+c.id+'" hidden></div>';
        }
        return '<div class="lc-item'+(isReply?' lc-item--reply':'')+'" id="c'+c.id+'">'+
                 '<div class="lc-avatar'+(c.staff?' lc-avatar--staff':'')+'">'+esc(c.initial)+'</div>'+
                 '<div class="lc-main"><div class="lc-meta"><b>'+esc(c.name)+'</b>'+(c.staff?'<span class="lc-badge">Team</span>':'')+'<time>'+esc(c.ago)+'</time></div>'+body+actions+replies+'</div>'+
               '</div>';
    }

    function renderComments(items){
        var n = 0; items.forEach(function(c){ if (!c.deleted) n++; n += (c.replies||[]).filter(function(r){ return !r.deleted; }).length; });
        countEl.textContent = n === 1 ? '1 comment' : n + ' comments';
        if (!items.length) { list.innerHTML = '<p class="lc-empty">No comments yet. Ask the first question about this lesson.</p>'; return; }
        list.innerHTML = items.map(function(c){ return commentHtml(c, false); }).join('');
    }

    function api(params, method){
        if (method === 'POST') {
            var fd = new FormData(); fd.append('csrf', csrf); fd.append('course_id', courseId); fd.append('lesson_idx', current);
            Object.keys(params).forEach(function(k){ fd.append(k, params[k]); });
            return fetch(endpoint, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){ return r.json(); });
        }
        return fetch(endpoint+'?course_id='+courseId+'&lesson_idx='+current, {credentials:'same-origin'}).then(function(r){ return r.json(); });
    }

    function loadComments(){
        list.innerHTML = '<p class="lc-empty">Loading comments...</p>';
        api({}, 'GET').then(function(d){
            if (d && d.ok) renderComments(d.comments || []); else list.innerHTML = '<p class="lc-empty">'+esc((d && d.error) || 'Could not load comments.')+'</p>';
        }).catch(function(){ list.innerHTML = '<p class="lc-empty">Could not load comments. Check your connection.</p>'; });
    }

    function post(body, parentId, msgEl, done){
        msgEl.textContent = 'Posting...';
        api({action:'add', body:body, parent_id:parentId || 0}, 'POST').then(function(d){
            if (d && d.ok) { msgEl.textContent = ''; renderComments(d.comments || []); if (done) done(); }
            else msgEl.textContent = (d && d.error) || 'Could not post.';
        }).catch(function(){ msgEl.textContent = 'Could not post. Check your connection.'; });
    }

    document.getElementById('commentForm').addEventListener('submit', function(ev){
        ev.preventDefault();
        var ta = document.getElementById('commentBody');
        post(ta.value, 0, document.getElementById('commentMsg'), function(){ ta.value = ''; });
    });

    list.addEventListener('click', function(ev){
        var b = ev.target.closest('button'); if (!b) return;
        if (b.dataset.vote) {
            var id = b.dataset.id, want = parseInt(b.dataset.vote, 10);
            var vote = b.classList.contains('is-on') ? 0 : want;
            var row = b.parentNode, btns = row.querySelectorAll('[data-vote]');
            btns.forEach(function(x){ x.disabled = true; });
            api({action:'vote', comment_id:id, vote:vote}, 'POST').then(function(d){
                btns.forEach(function(x){ x.disabled = false; });
                if (!d || !d.ok) return;
                btns[0].classList.toggle('is-on', d.my_vote === 1); btns[0].setAttribute('aria-pressed', d.my_vote === 1);
                btns[1].classList.toggle('is-on', d.my_vote === -1); btns[1].classList.toggle('is-down', d.my_vote === -1); btns[1].setAttribute('aria-pressed', d.my_vote === -1);
                btns[0].querySelector('span').textContent = d.likes || '';
                btns[1].querySelector('span').textContent = d.dislikes || '';
            }).catch(function(){ btns.forEach(function(x){ x.disabled = false; }); });
            return;
        }
        if (b.dataset.reply) {
            var box = list.querySelector('[data-replybox="'+b.dataset.reply+'"]'); if (!box) return;
            if (!box.hidden && box.dataset.to === b.dataset.to) { box.hidden = true; return; }
            box.dataset.to = b.dataset.to; box.hidden = false;
            box.innerHTML = '<form class="lc-form lc-form--reply"><div class="lc-form__main"><textarea maxlength="800" rows="2">'+(b.dataset.to && b.closest('.lc-item--reply') ? '@'+esc(b.dataset.to)+' ' : '')+'</textarea>'+
                '<div class="lc-form__row"><span class="lc-msg" role="status"></span><span><button type="button" class="btn btn--ghost btn--sm" data-cancel>Cancel</button> <button type="submit" class="btn btn--primary btn--sm">Reply</button></span></div></div></form>';
            var ta = box.querySelector('textarea'); ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
            box.querySelector('[data-cancel]').addEventListener('click', function(){ box.hidden = true; });
            box.querySelector('form').addEventListener('submit', function(e){ e.preventDefault(); post(ta.value, b.dataset.reply, box.querySelector('.lc-msg')); });
            return;
        }
        if (b.dataset.del) {
            var delId = b.dataset.del;
            hplConfirm('Delete this comment?').then(function(yes){
                if (!yes) return;
                api({action:'delete', comment_id:delId}, 'POST').then(function(d){ if (d && d.ok) { renderComments(d.comments || []); hplToast('Comment deleted', 'ok'); } else hplToast((d && d.error) || 'Could not delete.', 'error'); });
            });
        }
    });

    document.addEventListener('hpl:lesson', function(e){ renderLesson(e.detail); });
    renderLesson(current);
    if (window.__cpLoad) window.__cpLoad();
    if (location.hash === '#comments') show('comments');
})();
</script>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
