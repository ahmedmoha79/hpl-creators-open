<?php
/*
 * Homepage: "What is clipping?" shown with pictures, not paragraphs.
 * Real HPL TikTok pages as examples. Admin can replace the list with the
 * `landing_examples` setting (JSON list of {name, handle, url, image, note}).
 */
$exRate = (float)setting('rate_per_1000', 10);
$exList = json_decode((string)setting('landing_examples', ''), true);
if (!is_array($exList) || !$exList) {
    $exList = [
        [
            'name'   => 'HPL Insights',
            'handle' => 'hpl_miningtips',
            'url'    => 'https://www.tiktok.com/@hpl_miningtips',
            'image'  => 'assets/media/examples/tiktok-hpl-insights.webp',
            'note'   => 'Short gold-detecting tips, cut from long HPL videos.',
        ],
        [
            'name'   => 'HPL Clips',
            'handle' => 'hplclips',
            'url'    => 'https://www.tiktok.com/@hplclips',
            'image'  => 'assets/media/examples/tiktok-hpl-clips.webp',
            'note'   => 'One exciting moment from the field, posted as its own video.',
        ],
    ];
}
$exViews = 5064; // the middle clip in the HPL Insights picture
$exSteps = [
    ['icon' => 'film',     'photo' => 'assets/media/home/step-film.jpg', 'big' => 'HPL films',     'small' => 'a long video in the field'],
    ['icon' => 'video',    'photo' => 'assets/media/home/step-cut.jpg', 'big' => 'You cut',       'small' => 'the best 15 to 60 seconds'],
    ['icon' => 'tiktok',   'photo' => 'assets/media/home/step-post.jpg', 'big' => 'You post',      'small' => 'it on your own TikTok page'],
    ['icon' => 'banknote', 'photo' => 'assets/media/home/step-paid.jpg', 'big' => 'You get paid',  'small' => 'when people watch it'],
];
?>
<section class="section ex" id="example" aria-labelledby="exTitle">
    <div class="container">
        <div class="section__head">
            <h2 id="exTitle">What is clipping? Look.</h2>
            <p>Clipping means cutting a short, exciting part from a long video and posting it on your page.</p>
            <button type="button" class="ex__listen" data-ex-listen aria-pressed="false">
                <?= icon('play', 'ico ico--sm') ?><span>Listen instead of reading</span>
            </button>
        </div>

        <ol class="ex__flow" aria-label="Clipping in 4 pictures">
            <?php foreach ($exSteps as $i => $s): ?>
                <li class="ex__step">
                    <span class="ex__num"><?= $i + 1 ?></span>
                    <?php if (!empty($s['photo'])): ?><img class="ex__photo" src="<?= e(u($s['photo'])) ?>" alt="" loading="lazy" width="360" height="360"><?php else: ?><span class="ex__ico"><?= icon($s['icon']) ?></span><?php endif; ?>
                    <strong><?= e($s['big']) ?></strong>
                    <span><?= e($s['small']) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="ex__pages">
            <?php foreach ($exList as $ex): if (empty($ex['image'])) continue; ?>
                <figure class="ex__card">
                    <a class="ex__phone" href="<?= e($ex['url'] ?? '#') ?>" target="_blank" rel="noopener">
                        <img src="<?= e(u($ex['image'])) ?>" alt="TikTok page <?= e($ex['name'] ?? '') ?> (@<?= e($ex['handle'] ?? '') ?>) showing its short clips" loading="lazy" width="440" height="603">
                    </a>
                    <figcaption>
                        <strong><?= e($ex['name'] ?? '') ?></strong> <span class="muted">@<?= e($ex['handle'] ?? '') ?></span>
                        <p><?= e($ex['note'] ?? '') ?></p>
                        <a class="btn btn--ghost btn--sm" href="<?= e($ex['url'] ?? '#') ?>" target="_blank" rel="noopener"><?= icon('tiktok', 'ico ico--sm') ?> Open on TikTok</a>
                    </figcaption>
                </figure>
            <?php endforeach; ?>

            <aside class="ex__read">
                <h3>How to read these pages</h3>
                <ul>
                    <li><span class="ex__pill">1 square</span> = 1 clip</li>
                    <li><span class="ex__pill">&#9655; <?= e(num($exViews)) ?></span> <span>= <?= e(num($exViews)) ?> people watched that clip</span></li>
                    <li><span class="ex__pill">More views</span> = more money for you</li>
                </ul>
                <div class="ex__sum">
                    <span><?= e(num($exViews)) ?> views on one short clip</span>
                    <b>= paid <?= e(num((int)floor($exViews / 1000))) ?> times the campaign's rate</b>
                    <small class="muted">You are paid for every 1,000 views. Each campaign shows its own rate.</small>
                </div>
                <p class="ex__you"><?= icon('user', 'ico ico--sm') ?><span>Your page will look like this. You post on <strong>your own</strong> TikTok.</span></p>
                <a class="btn btn--primary" href="<?= e(u(current_user() ? 'creator/index.php' : 'register.php')) ?>">Start clipping &rarr;</a>
                <small class="muted ex__date">Pictures taken from TikTok on 30 Oct 2026. Numbers there keep growing.</small>
            </aside>
        </div>
    </div>
</section>
<style>
.ex { background: var(--bg, #fff); }
.ex .section__head p { font-size: 18px; max-width: 620px; margin-left: auto; margin-right: auto; }
.ex__listen { margin-top: 14px; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 10px 18px; border-radius: 999px; border: 1px solid var(--line, #e5e5e5); background: #fff; font: inherit; font-weight: 600; cursor: pointer; }
.ex__listen[aria-pressed="true"] { background: #1a1a1a; color: #fff; }
.ex__flow { list-style: none; margin: 28px 0 36px; padding: 0; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; counter-reset: none; }
.ex__step { position: relative; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 6px; padding: 22px 12px 18px; border-radius: 18px; background: var(--bg-soft, #faf6f1); border: 1px solid var(--line, #eee); }
.ex__step:not(:last-child)::after { content: "\2192"; position: absolute; right: -14px; top: 50%; transform: translate(50%, -50%); font-size: 22px; font-weight: 700; color: var(--hpl-caramel-deep, #b8742f); z-index: 1; }
.ex__num { position: absolute; top: 10px; left: 12px; width: 26px; height: 26px; border-radius: 50%; background: #1a1a1a; color: #fff; font-weight: 700; font-size: 14px; display: grid; place-items: center; }
.ex__ico { width: 56px; height: 56px; border-radius: 16px; display: grid; place-items: center; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
.ex__ico .ico { width: 28px; height: 28px; }
.ex__step strong { font-size: 18px; }
.ex__step span:last-child { font-size: 14px; color: var(--muted, #666); line-height: 1.35; }
.ex__pages { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.05fr); gap: 22px; align-items: start; }
.ex__card { margin: 0; display: flex; flex-direction: column; gap: 12px; }
.ex__phone { display: block; border-radius: 28px; padding: 10px; background: #111; box-shadow: 0 18px 40px rgba(0,0,0,.18); transition: transform .2s; }
.ex__phone:hover { transform: translateY(-3px); }
.ex__phone img { display: block; width: 100%; height: auto; border-radius: 20px; }
.ex__card figcaption p { margin: 4px 0 10px; color: var(--muted, #555); font-size: 15px; }
.ex__read { padding: 22px; border-radius: 20px; background: var(--bg-soft, #faf6f1); border: 1px solid var(--line, #eee); display: flex; flex-direction: column; gap: 14px; }
.ex__read h3 { margin: 0; }
.ex__read ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; font-size: 16px; }
.ex__read li { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ex__pill { display: inline-block; padding: 4px 10px; border-radius: 8px; background: #111; color: #fff; font-weight: 700; font-size: 14px; white-space: nowrap; }
.ex__sum { display: flex; flex-direction: column; gap: 2px; padding: 14px 16px; background: #fff; border-radius: 14px; border: 1px dashed var(--hpl-caramel-deep, #b8742f); }
.ex__sum b { font-size: 26px; }
.ex__you { margin: 0; display: flex; gap: 8px; align-items: flex-start; }
.ex__read .btn { min-height: 48px; justify-content: center; }
.ex__date { font-size: 13px; }
@media (max-width: 980px) {
  .ex__pages { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
  .ex__read { grid-column: 1 / -1; }
}
@media (max-width: 720px) {
  .ex__flow { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px 14px; }
  .ex__step:nth-child(2)::after { display: none; }
  .ex__pages { gap: 16px; }
  .ex__phone { border-radius: 20px; padding: 6px; }
  .ex__phone img { border-radius: 15px; }
}
@media (max-width: 420px) {
  .ex__pages { grid-template-columns: 1fr; }
  .ex__card { max-width: 340px; margin: 0 auto; width: 100%; }
}
</style>
<script>
(function () {
  var b = document.querySelector('[data-ex-listen]');
  if (!b) return;
  if (!('speechSynthesis' in window)) { b.hidden = true; return; }
  var text = <?= json_encode(
      'Clipping means cutting a short, exciting part from a long video, and posting it on your own page. '
      . 'Step one: H P L films a long video in the field. Step two: you cut the best fifteen to sixty seconds. '
      . 'Step three: you post it on your own TikTok page. Step four: people watch it, and you get paid. '
      . 'Look at the two TikTok pages below. Each square is one clip. The number on the clip shows how many people watched it. '
      . 'You are paid for every thousand views. Each campaign shows its own rate. '
      . 'Your page will look like this. Tap Start clipping to begin.'
  ) ?>;
  b.addEventListener('click', function () {
    var s = window.speechSynthesis;
    if (b.getAttribute('aria-pressed') === 'true') { s.cancel(); b.setAttribute('aria-pressed', 'false'); return; }
    s.cancel();
    var u = new SpeechSynthesisUtterance(text);
    u.lang = 'en-GB'; u.rate = 0.92;
    u.onend = u.onerror = function () { b.setAttribute('aria-pressed', 'false'); b.querySelector('span').textContent = 'Listen instead of reading'; };
    b.setAttribute('aria-pressed', 'true'); b.querySelector('span').textContent = 'Stop';
    s.speak(u);
  });
})();
</script>
