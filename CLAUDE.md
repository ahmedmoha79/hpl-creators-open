# Notes for Claude

## How to work here (owner's standing instructions, 2026-11-10)
- **"Fix X" means fix X.** Find the cause, fix that thing, test it, put it live, and say in plain words what was wrong and
  what changed. Do not redesign something else on the way, and do not come back with a plan instead of a fix. If the fix
  touches money rules (below) or deletes data, ask first; otherwise just do it. The owner has said: do not wait for a "go".
- **Check before saying "done".** Run the page on the local copy (phone width first, most creators are on phones), read the
  PHP error log, and after the deploy look at the live page. Never report a number or a result you did not see.
- **Own up fast.** If something you said earlier was wrong, say so first, then fix it.
- **Commits.** Commit as the session identity (already set in git config, signed). Merge by pushing your own commits to
  `main` (fast-forward), not with GitHub's "squash and merge" button or API: those make commits a local check flags.
  A pull request is still opened for anything bigger than a small fix, as the record of what changed and how it was tested.
- **Do not fetch a new `?v=` asset address before the deploy has finished**: the hosting's cache keeps whatever it got
  first for a week (it happened with chat.js). After a deploy, bump the `?v=` of every CSS/JS file you changed.
- **Phones first, one thing at a time on screen.** No floating button over content. A banner waits while a pop-up window is
  open; an offer card waits for both; Home asks at most one question at a time (town, then home screen, then notifications).
- **Words.** Plain, simple English for creators. No "rooms/channels/hashtags", no all-caps labels, no jargon.

## Maintenance ("run maintenance", "do the maintenance schedule", "check the site")
When the owner asks for maintenance, do ALL of this, in order, and finish with a short report (what was fine, what you
fixed, what needs the owner):
1. **Health page**: open `admin/health.php` on the live site (the owner's browser is signed in as admin). Every line that is
   not "ok" is a job: do what the line says or fix the cause. Read "Today's error list" and fix every serious error.
2. **Deploy state**: `git status`, `git log origin/main -3`, last GitHub Actions run is green, live `?v=` files match the repo.
3. **Money check** (read only unless the owner agrees): withdrawals waiting more than 2 days, clips pending more than 2 days,
   clips held in Checks, any creator whose balance is negative, campaigns whose `paid_amount` exceeds `total_budget`.
4. **Checks**: open `admin/risk.php`; summarise each held clip for the owner (never reject for them).
5. **Clips**: free ready clips versus creators who can take one; missing clip pictures (opening Ready clips repairs them);
   "Fast downloads" still on.
6. **TikTok**: sign-in configured, accounts needing reconnect, bios readable.
7. **Speed and size**: run the local load test (224 creators: home, campaign, community, chat polls, take) and compare with
   the last numbers in the pull requests; server space; `clip_stats` and `notifications` row counts (prune if huge).
8. **Phones**: screenshots at 360px of Home, a campaign, the chat list, an open chat with the keyboard, Wallet, Profile;
   look for anything covering anything.
9. **Security**: no secrets in the repo (`git grep -n "sk-ant-\|password *=" -- . ':!*.md'`), uploads folder cannot run PHP,
   admin pages refuse a creator session, dependencies in `.github/workflows` still pinned to commit SHAs.
10. **Notes**: update this file when a rule changed, and delete notes that are no longer true.
A weekly run is a good habit; the owner can ask for a scheduled one.


- This repo is the live code of golddetectorinzambia.com (HPL Creators). Pushing to `main` deploys to
  production within about a minute. Work on a branch and open a pull request for anything bigger
  than a small fix; merge only when the owner agrees.
- Stack: plain PHP 8 + PDO/MySQL. Helpers live in `includes/functions.php` (q, fetch_one, fetch_all,
  fetch_val, setting/set_setting, notif_add, notif_admins, money, e, abs_url, hpl_tx).
- Schema changes go in `includes/migrations.php`: append a step and bump `HPL_SCHEMA_VERSION`.
  Migrations run automatically on the next page load.
- Money rules (owner's decisions, do not change without asking): only views gained after submission
  are paid (`start_views`/`accrued`); campaign money stays locked until the admin approves the clip;
  approval (test + work video) is required before downloading raw footage or sending own edits.
  A TikTok campaign clip can only be sent when its TikTok account is connected (2026-11-06), so its starting
  view count is read at sending time and no views are lost. Money changes go through the ledgers
  (`earnings_adjust`, `credit_campaign_submission`); saving a campaign recounts its clips at once.
- Ready clips (owner decision 2026-11-05, `includes/ready_clips.php`, `admin/ready_clips.php`): finished clips the team
  uploads per campaign. One creator per clip. Passing the test is enough to join, take/post ready clips and withdraw.
  Same pay rate as edited clips (they are ordinary campaign submissions with `ready_clip_id`).
  A taken clip with no link after 3 days (`ready_clip_days`) goes back to the free list and the creator gets a
  warning (`ready_clips_expire()`, run by the background sync; reminder 1 day before).
  Anti-spam (owner decision 2026-11-07): one clip at a time (`ready_clip_hold_max`, default 1) and a cooldown
  between takes (`ready_clip_cooldown_mins`, 240 = 4h since 2026-11-12) so posts are spaced out for TikTok's algorithm.
  Two a day (owner, 2026-11-12, `ready_clips_per_day`, 2; 0 = no limit): links sent today + the held clip count; taking, sending
  any campaign link (`clips_sent_today()`, in `creator/campaign_submit.php`) and TikTok posts on the same day
  (`ready_post_gap_error()`) stop at 2, then "come back tomorrow" (`ready_day_full()`, Lusaka calendar day).
  See `ready_clip_next_take_at()`; the campaign page shows a live countdown. The wait counts from the LATER of taking a clip
  and sending a ready-clip link (2026-11-11: counting from the take let a creator post two clips an hour apart). Sending a link
  is refused when TikTok says the video was posted less than the cooldown from another of the creator's clips
  (`ready_post_gap_error()`, `campaign_submissions.posted_at` from TikTok's create_time).
  Quality (2026-11-11): `tiktok_video_info()` also reads width/height (`video_h`); under 1080 makes the automatic check
  "review" (`ready_low_quality()`); creators see `ready_quality_tip()` (our Download button, TikTok "Allow high-quality uploads").
  "TikTok ready" (owner decision 2026-11-07): to TAKE a ready clip the account must also follow some accounts
  (`ready_min_following`, 10) and be warmed up (`ready_warmup_days`, 3 days since connecting). Accounts in daily
  use skip the warm-up: following >= `ready_active_following` (50), or 10+ videos and 100+ followers. The FIRST ready clip skips the warm-up
  (owner, 2026-11-12, ad visitors left when told to wait 3 days): `ready_clips_ever()` 0 = no warm-up; it applies from the second clip. `ready_account_check()`, page `creator/tiktok_ready.php`, rules editable in Admin > Ready clips.
  Bio tag (owner decision 2026-11-10; tapped form required since 2026-11-13, see "Real tag" below): the TikTok bio must tag our page (a tag picked from TikTok's @ list arrives as "@HPL Gold Detectors Zambia", setting `ready_tag_names`, also accepted 2026-11-11; the word HPL or the
  WhatsApp number alone is not enough). Checked live when TAKING a clip and when SENDING its link (a missing tag blocks
  the link until it is added), and about once a day afterwards (`ready_bio_watch()`): first a warning; if the tag is not back
  after `ready_bio_grace_days` (2) the creator cannot take or send clips for `ready_bio_ban_days` (3) (`users.clip_ban_until`,
  `creator_clip_ban()`; Admin > Ready clips lists them and can lift it). Earned money is never touched by this.
  The follow / warm-up rules still never block sending. Ready clips are the main route on campaign pages;
  "Edit your own" (raw footage) is folded below.
  Launch scale (2026-11-10): clip videos and pictures are delivered by the web server from `uploads/rc/<hash>` (hard links,
  `ready_clip_public()`, setting `ready_fast_files`, self-check in Admin > Ready clips), not streamed through PHP. Each creator
  sees their own mix of 12 free clips (`ready_clips_for()`), so hundreds of people do not all tap the same clip.
  Automatic check (2026-11-10): every ready-clip link gets `auto_check` pass/review (`ready_clip_auto_check()`: own account,
  tags, posted after taking, same length as our clip via `mp4_duration()`). Setting `auto_approve_ready` (default OFF, owner's
  switch in Admin > Ready clips) approves a "pass" at once; withdrawals are still paid by hand.
- Clip styles (owner, 2026-11-11): every ready clip has a style (`ready_clips.style`: Podcast clip, Animation, Street interview...;
  list in setting `ready_clip_styles`, a new one typed while uploading joins it, `ready_clip_style_pick()`). Admin must pick
  the style before uploading (Admin > Ready clips); creators see it on top of each clip and can show one style
  (`?style=`, `ready_clip_style_counts()`).
- Sound rule (owner, 2026-11-11, after a creator laid music over a podcast clip): a ready clip is posted AS IT IS, no music
  or other sound added over it (`ready_sound_rule()`). Shown on the campaign page; sending a ready-clip link needs the
  "I posted the clip as it is" tick (`as_is`, checked server-side in `creator/campaign_submit.php`). It cannot be checked
  automatically, so the admin review screens remind the team to play pending ready clips with sound. Only a person rejects.
  Owner, 2026-11-12: the clip is posted EXACTLY as given: not cut, no filter, no music/sound added, highest quality, tag in bio AND
  caption. Breaking it can cost a pause: Admin > creator page > "Pause from clips" (hours or days + reason the creator reads, live countdown on Home and the campaign page via `creator_clip_ban_html()`,
  `creator_clip_pause()`, same `users.clip_ban_until` as the bio rule; money untouched; "Lift clip pause" ends it).
- No bio code for TikTok (owner decision 2026-11-08): with TikTok sign-in on, TikTok pages are verified only by
  connecting TikTok; the HPL-XXXXXX bio code is never shown or accepted for TikTok (`sp_creator.php`, `submit_social.php`).
  The ready-clip bio rule (the tag @hpldetectors.zambia in the bio, see above) is separate and stays.
- TikTok first (owner decision 2026-11-07): only `live_platforms()` (setting `live_platforms`, default "tiktok") are
  offered to creators; others show as "Coming soon" (`coming_soon_html()`). Add a platform to the setting to open it.
- Never commit `config.php`, uploads, storage, videos or database dumps. Never hardcode secrets.
- Payouts (owner, 2026-11-12, `includes/payproof.php`): sent BY HAND from the owner's mobile money (no payment API; the old
  Admin > Payment methods page stores keys nothing uses). Owner wants it FAST (2026-11-12): Admin > Pay withdrawals has a
  "Fast pay list" (tap to copy number/amount, paste the transaction ID, Paid saves without reloading), "Download ... list" CSV
  per network for bulk-payment services, and "Mark ticked as paid" with one bulk reference. All go through `payout_mark_paid()`.
  The transaction ID is required; the screenshot of the "sent" message is OPTIONAL (only when a creator disputes) (re-drawn
  JPEG in `storage/payproof`, never public; admin `withdrawals.php?proof=`, creator `withdraw.php?proof=` for their own). The creator is asked "I got it" / "I did not get it" (`withdrawals.receipt` waiting/received/not_received/auto);
  "not received" alerts the owner at once (`notif_admins('payout_dispute')`, instant on Telegram); no answer in 3 days = `auto`
  (`payproof_autoconfirm()`, background sync). Telegram "I paid it" works too and asks to add the screenshot in the admin.
- Telegram assistant: `includes/telegram.php`, `telegram_webhook.php`, `admin/telegram.php`. Money
  actions from Telegram always need the owner's Confirm tap.
  Summary alerts (owner, 2026-11-11, launch ads): busy kinds (default sign-ups, campaign clips, messages, feedback; setting
  `tg_summary_kinds`) are not sent one by one: `tg_alert()` keeps them in `tg_alerts` as `wait:<kind>` and `tg_summary_maybe()`
  (background sync) sends ONE summary every `tg_summary_hours` (3). Withdrawals, work videos and pages stay instant. Admin >
  Telegram and AI help has an "In a summary" box per alert.
- Home-screen app (owner decision 2026-11-05: no app-store/Android-only app; must work on iPhone too): the site
  installs via "Add to home screen" (`manifest.webmanifest`, `sw.js`, `includes/homescreen.php`, page `app.php`).
  The service worker never caches pages (only `offline.html`). Class `hide-in-app` hides things when opened from the icon.
- Write in plain, simple English for users (many are new to clipping, Zambia).

- App redesign (2026-11-08): creator Home is short on purpose: money, one next step, clips as pictures, this week
  (streak, own weekly goal, rank), community pulse (`includes/home_hooks.php`, `assets/css/home.css`, `assets/js/home.js`).
  TikTok accounts and page verification live on Profile (`includes/social_section.php`). Each campaign clip has
  `creator/clip.php` (views per day from `earning_days`). Clips crossing 1K/5K/10K... counted views notify the creator
  (`credit_campaign_submission`). Bottom bar: Home, Campaigns, Community, Wallet. No all-caps labels.

- Sticker packs (2026-11-09): `community_sticker_packs()` in `includes/community.php`. Artwork is Microsoft Fluent Emoji 3D
  (MIT, credit in `assets/img/stickers/LICENSE-Fluent-Emoji.txt`). Still file `<key>.webp` (or `<key>.svg` for labelled Zambia
  and glowing paid stickers, see `community_sticker_is_webp()`), animated `<key>.a.svg` (played on arrival and on tap by
  `assets/js/stickers.js`). The old drawn stickers sit in the hidden `legacy` pack so past messages still show them. Packs: free, `level`
  (Zambia needs Silver), `buy` (Gold VIP, Hype; paid once from the wallet, price setting `sticker_pack_price`, Admin >
  Community). Purchases live in `sticker_purchases` and `creator_stats()` takes them off the balance (`spent`).
  Sending a locked sticker is refused server-side (`sticker_allowed()`). Light animations: `assets/css/motion.css`.
  Admin > Community (`admin/community.php`, `assets/css/admin-community.css`): chat moderation, who is online, most helpful, pack sales, price.
- Chat groups (2026-11-10): the live chat is split into groups shown like WhatsApp groups (owner: creators do not know
  Discord, so NO hashtags or "rooms/channels" wording; plain names, round coloured icon, last message, time).
  `chat_rooms()` in `includes/community.php`: HPL News (team writes), General chat, Wins and payouts, Help, plus one group per open
  campaign `c<id>` (owner, 2026-11-13: "TikTok tips" and "Say hello" removed, also the Tips/Introduce yourself post types; people say
  hello in General). Leaderboard ("Top clippers", one ranked list with medals) and Members count clippers only, never the HPL team. Stickers button = smiley at the left of the message box.
  Phones: no floating buttons over content; the bottom bar hides only while a group is open (`body.hc-room-open`).
  `community_messages.channel/kind/mentions`. Activity lines (`chat_event()`: milestones and payouts in #wins, new ready clips
  in #announcements, passed test in General chat). @mentions notify the person. Unread dots are kept in the browser.
  Community opens on Chat (rooms list on phones); Posts is the old feed. One shared version file still drives all polls.
- Integrity (2026-11-10, `includes/integrity.php`, Admin > Checks = `admin/risk.php`): SERVER-SIDE ONLY, never show its
  rules, scores or reasons to creators (they only ever read "we are checking your clip"). Each views read also stores
  likes/comments/shares (`clip_stats`); `integrity_score()` is a pure function (0-100, reasons; the rules are private and not in the public copy). High risk = clip held
  (`held_by_system`, approved goes back to pending; setting `risk_auto_hold`), team decides in Checks (`risk_ok` = fine forever).
  Only a person rejects. Clips gone from TikTok for 2 hours are flagged (`miss_count` 1000 = told). A READY clip whose post is
  gone that long goes back to the free list (`ready_clips_free_deleted()`, also swept by `ready_clips_expire()`); the old link and
  its money stay for a person to decide. Admin > Ready clips can also "Make free again" a clip whose post is not on TikTok. Phones are remembered by a
  random cookie (`user_devices`) to spot one person with many accounts. Withdrawals show both warnings before paying.
- Home-screen notifications (2026-11-10, `includes/push.php`, `api/push.php`, `assets/js/push.js`, `sw.js`): Web Push with our own
  VAPID keys (settings, secret). The push is EMPTY; the service worker asks `api/push.php?latest=1` what to show. `notif_add()`
  marks `users.push_pending_at`; `push_flush()` sends after the response (and from the topbar poll), a few people per request.
- Profile pictures: `creator/avatar.php` (re-drawn to a 320px JPEG in `uploads/avatars`). Towns: `includes/towns.php`,
  `users.town` (asked at sign-up and on Home; internet addresses are NOT used for location: Zambian networks exit via Lusaka).
- Analytics: `admin/analytics.php` + `includes/charts.php` (server-drawn SVG, no chart library): funnel, per-day graphs, towns.
- Sign-up limits are per internet address and Zambian mobile networks share addresses: floor is 60 accounts/hour (`signup_guard.php`).
- Pop-ups (2026-11-10): the message banner (`showPopup` in `assets/js/topbar.js`) is small, the whole banner is the link, it
  closes itself after 7 seconds and waits while a pop-up window is open. Offers: `includes/offers.php`, `admin/offers.php`,
  `creator/offer.php` (a message with one in-site button, audience rules, card or line, once per creator; NEVER pays money).
- Help answers by AI (2026-11-10): `includes/help_ai.php`, used by `creator/dm_feed.php` only when no fixed answer fits.
  Same Anthropic key as the Telegram assistant, switch `help_ai_on` in Admin > Telegram and AI help. Text only, escaped,
  HANDOFF to the team when unsure, 10 answers a creator a day. Never let it explain the integrity checks.
- Clip pictures: every TikTok clip keeps TikTok's own cover (`campaign_submissions.cover_url`, refreshed at each views read,
  addresses expire after hours). Ready clips without a still picture show the video's first frame (`ready_clip_video_attrs()`),
  and opening Admin > Ready clips makes the missing pictures in the admin's browser (`action=poster`).
- New ready clips are announced to everyone (owner, 2026-11-10): creators in the campaign every time, all other active
  creators at most once a day per campaign (`ready_clips_notify()`), plus the small banner (type `footage`) and phones.
- Upload key (2026-11-10): to send ready clips straight from a folder on the owner's computer, an admin session makes a
  2-hour key (`admin/ready_clips.php` POST `action=upload_key`, returned once, only its sha256 is stored); pieces are then
  sent with the header `X-HPL-Upload-Key` to `action=chunk` (nothing else accepts the key). End it with `action=upload_key_off`
  when the upload is done. Send H.264 1080x1920 files: HEVC/4K phone exports do not preview in many phone browsers.
- TikTok page score (owner, 2026-11-10, `includes/page_score.php`): OPEN coaching score 0-100 on the creator's Profile (and in
  Admin > creator details and Analytics), from the page's last 20 TikTok videos (`tiktok_recent_videos`, read at most every
  6 hours, background refresh). Lines: views on a normal video 30, reactions per 100 views 25, videos past 300 views 15,
  posting rhythm 20 (bursts under 1 hour apart, more than 4 a day, days posted), page set-up 10. `page_score_calc()` is pure.
  It never changes pay or blocks anything. Keep it separate from the hidden integrity checks.
- Inactive creators (owner, 2026-11-11, `includes/inactivity.php`, Admin > Inactive creators = `admin/inactivity.php`): no real
  action (test, TikTok connected, clip taken, link sent; app visits do NOT count) for 5 days = reminder, 10 = last warning with the
  date, 15 = paused (`users.status` suspended, `suspend_reason` inactive; login explains it). Days editable; always 2 days then 3
  days between steps. Never auto-paused while money is waiting (balance, withdrawal, pending clip); no steps while there is no free
  ready clip; clock started at `inactivity_start`. "Open again" gives a fresh clock. Run by the background sync every 20 minutes,
  25 changes a run (each sends an email). Home shows the warning box (`.hk-warn`).
- Profile score card (2026-11-11): `page_score_card()` draws two animated circles (score, engagement: full at 8%, normal ~4%),
  one "Do this next", then "How your score is made" (one bar per line, tap for why). CSS `.ps2` in `assets/css/home.css`.
- Demo account (owner, 2026-11-11, `includes/demo.php`, Admin > Demo account = `admin/demo.php`): one creator with
  `users.is_demo = 1` (Mutale Banda, demo.creator@golddetectorinzambia.com, random password until the owner sets one on the page to film signing in) for screen recordings.
  Opened only from an admin session (`demo_enter`), "Back to admin" in the menus (`admin/demo.php?leave=1`). Never earns
  (`credit_campaign_submission` records views only, no budget), no bonus, no withdrawal, skipped by inactivity, phone not recorded.
  "Reset" gives its ready clips back and removes its own links. Its chat messages are seen by everyone.
- Visitors (2026-11-10, `includes/visits.php`, table `site_visits`): one row per browser per day (random cookie `hpl_v`, no IP),
  counted on public pages (`includes/header.php`) and creator pages; robots and admins left out. `?utm_source=` names a source.
  Shown in Admin > Analytics (today, per day, sources, first page, hour of day, creators coming back). Kept 120 days.
- Launch scale (2026-11-11, `includes/fastlane.php`): the checks every open page makes every few seconds are answered
  WITHOUT a database connection when nothing changed (shared hosting allowed ~30 connections; "Too many connections" was seen).
  DM badge: `api/ping.php` returns a version from `storage/pulse/dm.ver`; every `INSERT INTO dm_messages` must call
  `fl_bump('dm')` or replies show up only after a minute. Chat polls: `creator/community_feed.php` answers "same" from the
  shared snapshot (`chat_shared.json`, its build time is `built`; `at` is the per-group last-message times). `includes/db.php`
  retries a busy connect 4 times; Admin > Health shows "Database connections". Load test: 2,240 open screens used 19 connections
  (old code: all 30, 3 s per poll).
- HPL Brain (owner, 2026-11-12, `includes/brain.php`, Admin > Brain = `admin/brain.php`): thinks like the app's developer;
  READS and REPORTS only (never messages creators, never changes money). Eyes: `brain_count()` daily counters (`brain_counts`):
  every "no" said to a creator (hooked in `flash()` error/warning and `json_response()` ok=false, normalised), phone errors and
  failed requests (`assets/js/brain.js` -> `api/brain.php`, 5 a page, 20 a minute per connection, no personal data), PHP errors
  (error log read from `brain_log_pos`). Analyses: refusals + knowledge (`brain_knowledge()`: likely cause and fix per rule),
  errors, journey drop-offs, PUBLIC community chat problems (never private messages), clip supply forecast, waiting work,
  sign-ups against the usual pace, payout disputes. Findings ranked (people x weight x trend) in `brain_findings`, kept across
  runs, solved when not seen for a day. `brain_run()` hourly from the background sync. Voice and hands (`includes/brain_voice.php`):
  Telegram updates at ~08/13/18/22 (`brain_pulse()` facts, worded by Claude like the app's developer when the Anthropic key is set,
  else `brain_word_plain()`), instant "Needs you now" for serious new findings (bug >= 5 people, money, sign-ups stopped, clips out
  within 8 h, trust talk; once a day each), and safe self-repairs `brain_heal()` (hide free ready clips whose file is gone, switch
  fast downloads to the safe way when broken, clear a stuck chat snapshot), logged as kind `repair`. Code bugs are reported, not
  guessed at (alert kind `brain`). Speaking (`includes/brain_call.php`, owner 2026-11-12): updates and "Needs you now" also go as
  Telegram voice notes (ElevenLabs TTS, `elevenlabs_api_key`/`elevenlabs_voice_id`, sendVoice mp3) and "Needs you now" rings the
  owner (Twilio Calls API, `twilio_sid`/`twilio_auth_token`/`twilio_from`/`brain_call_to`; natural voice via a 2-hour mp3 in
  `uploads/brainvoice`, else Twilio <Say>); max `brain_call_max` (3) a day, quiet 22:00-07:00 unless money. Keys are typed by
  the owner in Admin > Telegram and AI help (secret fields), with "Send me a test voice note" and "Call me now to test". Briefing calls (`brain_briefing_call()`): now, in N minutes, every day at
  `brain_brief_daily`, or by typing /call (or /call 10) to the Telegram bot; scheduled ones fire from page views (`brain_brief_due()`
  in `hpl_autosync_maybe()`, no cron, no laptop needed). Personal nudges for creators: later, when there are enough people to learn from (owner, 2026-11-12).
- Clip allowance (owner, 2026-11-12, `includes/clip_trust.php`): "jumping on clips, no engagement, bad score = 1 video a day".
  `clip_trust_calc()` (pure) reads OPEN numbers only (middle clip's views gained, reactions per 100 views from `clip_stats`, page
  score, rejected clips, `ready_warnings` = clips taken and lost). Two weak signs, or taking clips and not posting, = 1 a day;
  one clip with `clip_trust_recover_views` (1000) in 7 days = normal again; new creators (under 2 judged clips) never limited.
  `ready_clips_per_day_for()` is used everywhere the daily limit is checked. Judged every 3 h (`clip_trust_refresh()`), creator told
  on change, team override `users.clip_trust_override` (Admin > Ready clips > On 1 clip a day). Never uses the hidden integrity
  checks, never touches money. Brain `brain_look_quality()`: when most posters are weak at once, it blames the clips, not the people.
- Brain watch list and people (owner, 2026-11-12, `includes/brain_watch.php`): `brain_watch_list()` = everything that can go
  wrong (views read on time, TikTok connections, campaign budget running out, money adding up, email delivery, slow pages,
  background jobs, server space/log size, many accounts on one phone, people), each on its own schedule inside a 4-second budget,
  state in setting `brain_watch_state`, shown in Admin > Brain > "What the Brain watches" with its cost. Sensors: slow pages
  (`includes/auth.php` shutdown, kind `slow`), email sent/failed (`includes/mailer.php`, kind `mail`). Self-tuning:
  `brain_tune_sync()` sets `sync_batch` (20-150, used by `includes/autosync.php`) and `autosync_minutes` from the backlog and
  `autosync_last_ms`; logged as repairs. People: `brain_person_tag()` (pure) labels every creator (star, steady, chaser = posts the
  most allowed with weak results, grabber, stuck, quiet, new) in `users.brain_tag`/`brain_tag_why`, team only (Admin > Creators
  filter and chip, creator page). Admin lists scale: Creators 50 a page; TikTok accounts on their own page (`admin/tiktok_accounts.php`,
  search, 50 a page) and Settings > TikTok shows only those needing something; "Check connections" does 40 per press.
- Brain mind (owner, 2026-11-12, "very smart, like a human", `includes/brain_mind.php`): (1) normal is LEARNED: `mind_today()` compares
  each signal (`mind_signals()`: visitors, sign-up page, sign-ups, tests, TikTok, clips taken, links, chat, withdrawals) with the same
  hours of the last 14 days, median + MAD (`mind_judge()`, pure); `mind_site_percentile()` learns limits (weak clip = bottom quarter,
  star = top tenth) used by `clip_trust_cfg()` and `brain_person_tag()`. (2) `mind_diagnose()` (pure) weighs causes for unusual
  signals from the other signals and facts. (3) Memory: `brain_changes` (set_setting of tracked keys, site updates, "I fixed it");
  `mind_outcomes()` judges each change 3 days later (before/after per day). (4) `mind_forecast()`: cash for payouts in 7 days, clip
  days left, likely to leave (cached 1 h); `mind_next_step()` per creator on their admin page. (5) `mind_think()`: twice a day
  (08/18, `brain_run()`) and "Think it through now", the whole `mind_state()` goes to Claude (`brain_think_model`, default
  claude-sonnet-5-5) and the answer is shown on Admin > Brain and sent to Telegram.
- Sign-up page (owner, 2026-11-12, ad visitors: 344 in a day, 3 sign-ups): `register.php` has 4 boxes (name, mobile money number,
  email, password with Show); town and "how did you hear" are asked on Home after signing in (town first). Inside Facebook/TikTok/
  Instagram (`visit_inapp()`) the Google button is hidden: Google blocks its sign-in there. Real payouts and real earnings rotate at
  the top (`sp_signup_proof()`, never sample data). Public pages never state one rate: "paid for every 1,000 views; each campaign
  shows its rate" (owner). `site_visits.step` (1 opened sign-up, 2 signed up) and `inapp`; Brain `brain_look_visitors()` says which
  step loses new visitors per source; Analytics shows "Opened the sign-up page".
- Front page (owner, 2026-11-12, "even a child should understand"): `index.php` + `assets/css/landing.css`, ~3,400px on a phone
  (was ~15,800): hero with one button, real payouts (`sp_signup_proof()`, paid only), 3 steps, the 1-minute video behind a
  "Watch it in 1 minute" button, what you need, 3 rules, 5 questions (tap to open), last button. Short sentences only; no
  footage gallery, levels, stat or feature cards (`includes/home_examples.php`/`home_proof.php` are no longer used there).
  Tone (owner, same night): NOT "free, start today" (people don't take it seriously). It sells a standard: "Apply to join",
  hero "We pay serious creators for real TikTok views." + "Serious doesn't mean famous" (owner: "not for everyone" made beginners think big accounts only), section "What \"serious\" means here", what we expect (engage with your account every day, reply to comments, post as it is, quality not
  spam), "How you get in" (apply, test, TikTok ready, first video, paid), what you get. Only true claims, no invented scarcity;
  "no fee" lives in the questions. `register.php` box title is "Apply to join".
  Owner, 2026-11-13 21:26 (REPLACES the tone above where they differ): hero "Your TikTok can pay you while Zambia watches." / "We make
  the videos. You post them." No "gold hunting only" claims: campaigns carry HPL podcast clips AND gold hunts, so "What you will post"
  shows both kinds, labelled, with frames from our own clips (`assets/media/home/clip-podcast-*.jpg`, `clip-gold-*.jpg`). The
  "Straight from the field" strip is gone. "Open right now" lists live campaigns with their own rate, money left and free clips
  (true scarcity: one creator per clip, closes when the budget is spent). Few words per block.
- Owner, 2026-11-13 ("people are not clipping"): the test is now 5 plain questions about the real job (old 12 jargon questions
  hidden, setting `test_v2_at`), pass with 4. The FIRST ready clip skips the follow rule as well as the warm-up (`ready_account_check()`).
  Clip deadline (`includes/clip_deadline.php`, table `clip_deadline_log`, settings `clip_deadline_start/end`): every creator who had an
  account at the start must send a clip link (not rejected) within 3 days: warning at once, reminder on the last day (bell, phone,
  email), then paused (`suspend_reason` no_clip; login explains it). Money untouched. Run by the background sync. A failed caption check
  now shows the caption TikTok gave us.
- Brain clip review (owner, 2026-11-13, `includes/clip_review.php`; a creator sent a video of himself as an own edit): every TikTok
  campaign link (ready clip or own edit) is reviewed right after sending (after the response; `review_due_at` + `clip_review_sweep()` in the
  background sync as the net). Facts (account, bio and caption tag read live, posted after taking, length, quality, the post's cover against
  our clip's still + 12-frame sheet `<name>.frames.jpg` made by the admin's browser on Admin > Ready clips, sound name from TikTok's oEmbed,
  creator record, hidden risk), EYES (Claude vision: cover next to our frames), JUDGE (second Claude call), then pure hard gates
  `clip_review_gates()`. The Brain APPROVES when everything is right (owner's switch `auto_approve_ready`, now ON; own edits only with
  `brain_approve_own_edits`, default off = a person). It never rejects: "reject recommended" = instant Telegram with Approve/Reject.
  Looked at again 6 h, 1 day, 3 days, 7 days after sending; an approved clip that no longer passes goes back to pending + `held_by_system`.
  Columns `review_verdict/note/at/stage/json/due_at/by_brain`. Shown in Admin > Clips cards and Admin > Brain ("Look at 3 more links now").
  This replaces "Brain reads and reports only" for clip approval; it still never pays, never rejects, never messages creators itself.
  TikTok gives nobody the video file: a custom cover over another video of the same length would pass the picture check.
- Brain rulebook (owner, evening of 2026-11-13: "why does it wait for me", "every error named with its procedure"; this REPLACES
  "the Brain never rejects" and the picture checks in the note above): `clip_rules()` in `includes/clip_review.php` names every problem
  with a class. cheat (not HPL footage, posted before taking, very different length, sent twice) = rejected at once + creator told why +
  1 strike. redo (cut, music added, low quality, wrong account) = rejected at once, "post it again as it is"; second redo in 7 days =
  strike (never for low quality). fix (tag missing in caption/bio) = creator told, 24 h, looked at again: fixed = approved, else rejected.
  person (risky views; own edit the eyes are unsure about from an untrusted creator) = waits in Admin > Clips, Telegram summary only.
  Strikes (`clip_strikes`, 30 days): 1 warning (bell + email), 2 = 3-day clip pause, 3 = account suspended (`suspend_reason` rules, owner
  told at once). Safety valve `clip_review_valve()`: 8+ rejections in an hour and over half of reviews = no rejecting for 6 h + alert.
  Covers are NOT used to judge ready clips (most posts use a designed cover): facts only (account, posted after taking, exact length,
  quality, tags, sound name, duplicate), no AI, ~2 s. Own edits: Claude vision says gold footage yes/no/unsure (20 an hour); yes or a
  trusted creator (2+ approved, no strikes) = approved (`brain_approve_own_edits` now on). One reviewer at a time (MySQL lock
  `hpl_clip_review`), after the response. Never touches a clip a person approved. `review_action` = approved/rejected/told/waiting.
  Tag check also accepts "@HPL Gold <word>" (the page's display name as TikTok writes a picked tag, renamed/mistyped over time).
- Clip path (owner, 2026-11-13): Home's first question "How do you want to earn?" sets `users.clip_path` ready|edit (Profile can change it).
  Ready-clip creators never see the Creator Academy (side menu, top menu, Home); it is for people who edit raw footage.
- Owner's explainer videos (2026-11-13, `assets/media/home/`): `lusaka-to-kitwe` = front page "Watch how it works"; `questions` = front
  page Questions; `same-clip` = "Get your TikTok ready" (why the steps matter); `how-to-start-6` and `start-tonight` = top of the Academy
  (they present the Academy as a step, so they are not on the public page).
- Owner, 2026-11-13 20:30: Lusaka campaign K10 per 1,000 views (was K5; set in Admin > Campaigns, which recounts its clips) and
  announced to everyone; its picture is the HPL podcast cover (`assets/media/home/lusaka-podcast.jpg`, uploaded through the form).
  Last TikTok warning (`tt_deadline_run()` in `includes/clip_deadline.php`, settings `tt_deadline_start/end` = Sat 14 Nov 16:00
  Lusaka): creators with no TikTok account get a bell + email now and a red Home box; at the time they are suspended
  (`suspend_reason` no_tiktok). Campaign page on phones: pay in the hero, no chips, routes and "Edit your own" only for editors,
  the long rule folded, pay/budget/requirements folded into "camp-more" boxes, "Links you sent".
- Flood guard (`includes/guard.php`, required first by `includes/db.php` and `includes/fastlane.php`): per session 300 requests and
  40 POSTs a minute, per address without a session 900; over = 429 before any database work; `storage/guard/blocked.log`, Brain
  watch `flood`. Brain also watches `reviews` (queue lag, harsh rejection rate) and `reach` (phone notifications on).
  `mind_think()` now defaults to claude-opus-5-5, gets `mind_load_facts()` (load, abuse, reviews, reach, supply, money) and must
  answer through seven lenses with a "Problems nobody reported yet" part.
- Notifications (2026-11-13): the in-app banner is a light WhatsApp-style card from the top (app name, bold title, 3 lines, held while
  touched); it pops for reminder/announcement/footage/chat/warning/earning with an icon per type. Chat groups notify their people
  (`chat_notify_room()` in `includes/community.php`, after the response): campaign group = everyone who joined it, HPL News = all,
  other groups = who wrote there in 7 days; at most one per person per group per 20 minutes (`chat_notify_last`). Pushes vibrate.
- Real tag, never pasted (owner, 2026-11-13 evening): no caption or tag text to copy anywhere (TikTok may treat pasted captions as a
  bot). A tag TAPPED from TikTok's @ list reaches the API as "@" + our page NAME ("@HPL Gold Detectors Zambia"); typed/pasted
  "@hpldetectors.zambia" is plain text, not a link (checked on live bios). `ready_tag_tapped()`/`ready_tag_typed()`/`ready_tag_how()`
  in `includes/ready_clips.php`. Since `tag_tap_from` + 48 h (`ready_tag_strict_at()`) the typed handle no longer passes the bio check;
  captions of clips taken before `tag_tap_from` keep the old rule (`ready_caption_ok($cap, $takenAt)`). Creators with typed bios were
  told once (migration 2026111306); Home/campaign/TikTok-ready pages show `ready_bio_hint()` while the bio is typed text.
- Admin without page jumps (owner, 2026-11-13): `assets/js/admin-smooth.js` (admin footer) sends every admin form in the background,
  then swaps in `.app__content`, the sidebar counts and the title from the answer; scroll stays, the flash shows as a toast
  (`window.hplToast`). Downloads still download; leaving the admin loads normally; `<form data-no-smooth>` opts out. ui.js "Are you
  sure?" calls `window.hplSmoothSubmit`. Inline scripts of the new body run again; page code that binds on load must also listen for
  `hpl:swapped` (main.js word counters, mobile.js table stacking do). Phones: stacked tables are compact (label | value grid, all
  buttons in one row, `td--actions`, `td__v`, `assets/css/mobile.css`).
- Site health: `admin/health.php` (read-only checks with what to do). First stop of every maintenance run.
- Dark mode (2026-11-09): follows the device setting; Profile has Auto / Light / Dark (saved in the browser, `includes/theme.php`
  sets `<html data-theme>` before paint). `assets/css/dark-auto.css` is GENERATED by `node tools/build_dark.js` (needs postcss) from the
  app CSS: re-run it after changing colours. Hand fixes go in `assets/css/dark.css`. Prefer `var(--ui-*)` tokens over hardcoded colours.
  Public pages (landing, login) stay light.

## HPL Store (`store/`, served at minelabzambia.com)
- Product + live-chat site for HPL Gold Detectors (Hayati Prospectors Limited). A separate business from
  HPL Creators: its own admin accounts (`shop_admins`, first account via `store/admin/setup.php` with the
  owner's one-time code, more in Admin > Team), its own alert settings (Telegram bot token/chat, email).
  Never link it to, or brand it with, HPL Creators.
- Own front controller (`store/index.php`), pages in `store/pages`, admin in `store/admin`, languages in
  `store/lang` (en/fr/sw). Public URLs have language folders: `/`, `/fr/...`, `/sw/...` (see `url()`, `lang()`).
- Deployed with this repo (FTP) into `golddetectorinzambiacom/store`; the minelabzambia.com document root points there.
- gpz7000africa.com shows the same store: its folder `public_html/gpz7000africacom` is a link to the store folder
  (made by a schema step). Canonical links, sitemap and robots always name minelabzambia.com.
- Only shares the hosting account: tables `shop_*` in the same database, DB login read from `../config.php`
  at run time. Schema changes: `store/inc/schema.php` (bump `STORE_SCHEMA`). Catalogue seed: `store/inc/seed.php`.
- Prices are in kwacha (`price_zmw`); a US dollar guide uses the `usd_rate` setting.
- Products sold: Nokta detectors (Magnetar 9000, Gold Finder 2000, Gold Kruzer) and HPL gold washers/sluices.
  Don't claim "official/authorised Nokta dealer" unless the owner confirms it.
- Videos are HPL's own TikTok videos, hosted as files in `store/uploads/videos/tt/` (TikTok embeds fail).
- Never commit `store/config.local.php` or `store/uploads/`.
- Security (2026-11-08): secret settings (API keys, tokens, passwords) are never printed into pages: use
  `secret_setting_input()` / `secret_setting_posted()` (empty keeps the saved value) and list new ones in
  `secret_setting_keys()`. PHP errors are never shown to visitors (`includes/auth.php`); a local test copy defines
  `HPL_DEV` in its own config.php. A password change signs out other devices (`pw_tag` in the session).
  Reset links are stored as sha256 fingerprints. Uploads folder can never run scripts (`HPL-UPLOAD-GUARD`
  migration step). Deploy actions are pinned to commit SHAs.
