<?php
/**
 * Support knowledge base for the creator Help/Chatbot.
 * Each entry: question (label), keywords (for matching), answer (markdown-ish HTML).
 * Answers can reference live settings via support_faq_answer().
 */

require_once __DIR__ . '/earnings.php';

function support_rate(): string { return money((float)setting('rate_per_1000', 10)); }
function support_min_payout(): string { return money(payout_min()); }
function support_max_payout(): string { return money(payout_max()); }

function support_faq(): array
{
    $rate = support_rate();
    $min = support_min_payout();
    $max = support_max_payout();
    $email = setting('payout_contact_email', '') ?: ('support@' . preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com')));
    $general = general_clips_enabled();
    $campaignsUrl = e(u('creator/index.php?tab=campaigns'));
    $phone = setting('support_phone', '');
    $phoneLine = $phone ? '<br>Or call/WhatsApp <strong>' . e($phone) . '</strong>.' : '';

    $entries = [
        [
            'question' => 'How do I earn money?',
            'keywords' => ['earn', 'earn money', 'paid', 'get paid', 'make money', 'how do i make', 'income', 'start', 'begin', 'new here', 'what do i do', 'what should i do', 'first step', 'how does it work', 'how it works'],
            'answer' => 'It takes three steps:<ol><li>Watch the free lessons and pass the short <a href="' . e(u('creator/assessment.php')) . '">HPL test</a>.</li>'
                . '<li><a href="' . e(u('creator/connect_tiktok.php')) . '">Connect your TikTok</a> and get it ready.</li>'
                . '<li>Open <a href="' . $campaignsUrl . '"><em>Campaigns</em></a>, take a <strong>ready clip</strong>, post it on TikTok and send us the link.</li></ol>'
                . 'You earn the campaign\'s rate for the views your clip gets <strong>after</strong> you send the link. The money unlocks when our team approves the clip. '
                . 'Want to edit your own clips from our raw footage? Send one video you edited from <a href="' . e(u('creator/portfolio.php')) . '"><em>Get qualified</em></a>.'
                . ($general ? '<br><br>You can also earn <strong>' . $rate . '</strong> per 1,000 views on clips you send from <a href="' . e(u('creator/submit_links.php')) . '"><em>My clips</em></a>.' : '')
                . '<br><br>Your earnings show on your <a href="' . e(u('creator/earnings.php')) . '">Earnings</a> page as you go.',
            'path' => 'creator/index.php?tab=campaigns',
        ],
        [
            'question' => 'When can I withdraw my money?',
            'keywords' => ['withdraw', 'withdrawal', 'cash out', 'cashout', 'payout', 'pay out', 'when can i withdraw'],
            'answer' => 'You can request a withdrawal once you have passed the test, confirmed your email, connected your TikTok and your available balance reaches the <strong>' . $min . '</strong> minimum. '
                . 'Withdrawals are capped at <strong>' . $max . '</strong> per request, so bigger balances are paid out in multiple requests.'
                . '<br><br>Go to the <a href="' . e(u('creator/withdraw.php')) . '"><em>Withdraw</em></a> page to request one.',
            'path' => 'creator/withdraw.php',
        ],
        [
            'question' => 'How do withdrawals get paid?',
            'keywords' => ['how is it paid', 'payment method', 'mobile money', 'mtn', 'airtel', 'zamtel', 'paid', 'how do i receive'],
            'answer' => 'Payouts are sent to your chosen <strong>mobile money</strong> number (MTN, Airtel, or Zamtel). '
                . 'Enter the number you registered with when you request a withdrawal, then the team reviews and records the payment, including a transaction reference.',
        ],
        [
            'question' => 'How are views counted?',
            'keywords' => ['views', 'count views', 'view count', 'how are views', 'views are counted', 'traffic', 'plays'],
            'answer' => 'TikTok views are read automatically, about every 30 minutes. First tap <em>Connect TikTok</em> on your Home page and allow everything, because we read views through your own account. Instagram, Facebook and YouTube are coming soon. '
                . 'Only views on the links you sent us count, and only the views your clip gets after you sent the link.',
        ],
        [
            'question' => 'How do I become qualified?',
            'keywords' => ['qualified', 'qualify', 'unlock', 'become qualified', 'get started', 'locked'],
            'answer' => 'Pass the short test: then you can join campaigns and post <strong>ready clips</strong> (get your TikTok ready first). '
                . 'To also download raw footage and send clips you edit yourself, send one video you edited from <a href="' . e(u('creator/portfolio.php')) . '"><em>Get qualified</em></a>.',
            'path' => 'creator/portfolio.php',
        ],
        [
            'question' => 'My work was rejected. Can I submit again?',
            'keywords' => ['rejected', 'not approved', 'failed', 'retry', 'resubmit', 'submit again'],
            'answer' => 'Yes. If it was your edited video, open <a href="' . e(u('creator/portfolio.php')) . '"><em>Get qualified</em></a> and send a better one. If it was the test, take it again as many times as you like. '
                . 'Our team reviews videos and tells you on your dashboard.',
            'path' => 'creator/portfolio.php',
        ],
        [
            'question' => 'How many links can I submit per day?',
            'keywords' => ['limit', 'links per day', 'daily links', 'max links', 'submit links', 'how many'],
            'answer' => $general
                ? 'On <a href="' . e(u('creator/submit_links.php')) . '"><em>My clips</em></a> you can send up to <strong>' . (int)setting('max_daily_links', 20) . '</strong> links per day. Campaign clips are sent on each campaign\'s page.'
                : 'Clips are sent on each campaign\'s page. Open <a href="' . $campaignsUrl . '"><em>Campaigns</em></a> and follow the rules of the campaign you joined.',
            'path' => $general ? 'creator/submit_links.php' : 'creator/index.php?tab=campaigns',
        ],
        [
            'question' => 'I forgot my password',
            'keywords' => ['forgot password', 'reset', 'password', 'forgot'],
            'answer' => 'No problem — on the sign-in page tap <em>Forgot your password?</em> and enter the email you registered with. '
                . 'You\'ll receive a one-time reset link that is valid for 1 hour.',
            'path' => 'forgot_password.php',
        ],
        [
            'question' => 'Who can I talk to for help?',
            'keywords' => ['contact', 'support', 'help me', 'someone', 'talk to', 'human', 'reach', 'message'],
            'answer' => 'You can reach the team a few ways:'
                . '<ul><li><strong>This chat</strong> — a real person from the team can join right here</li>'
                . '<li><a href="' . e(u('creator/messages.php')) . '">Messages</a> — direct messages inside the dashboard</li>'
                . '<li>Email <strong>' . e($email) . '</strong></li>' . $phoneLine . '</ul>'
                . 'We usually reply within a day — or check this chat for a quicker answer.',
            'path' => 'creator/messages.php',
        ],
        [
            'question' => 'Why are the library and payouts locked?',
            'keywords' => ['locked', 'dashboard', 'can\'t access', 'not available', 'unlock', 'library', 'payouts'],
            'answer' => 'Passing the test unlocks campaigns, ready clips and withdrawals. Downloading raw footage and sending clips you edited yourself unlock after we approve one video you edited. '
                . 'Your dashboard, the course and the campaign list are open right away.',
            'path' => 'creator/portfolio.php',
        ],
        [
            'question' => 'Where do I find footage to use?',
            'keywords' => ['footage', 'library', 'videos to use', 'clips', 'download footage', 'content'],
            'answer' => 'Ready clips (finished clips you just post) are on each campaign page. Raw footage to edit yourself is in <a href="' . e(u('creator/library.php')) . '"><em>Footage to download</em></a> in the menu, after your edited video is approved.',
            'path' => 'creator/library.php',
        ],
        [
            'question' => 'How do I update my profile?',
            'keywords' => ['profile', 'name', 'email', 'phone', 'country', 'update profile', 'edit profile'],
            'answer' => 'Open <em>Profile</em> in the sidebar to update your name, phone, country, and social handle. '
                . 'Keeping these current helps the team approve you faster and send payments to the right number.',
            'path' => 'creator/profile.php',
        ],
        [
            'question' => 'When are earnings updated?',
            'keywords' => ['earnings update', 'when updated', 'balance', 'how often', 'refresh'],
            'answer' => 'TikTok views are read about every 30 minutes while your TikTok is connected. Check the <a href="' . e(u('creator/earnings.php')) . '"><em>Earnings</em></a> page for your latest totals.',
            'path' => 'creator/earnings.php',
        ],
        [
            'question' => 'My payment is late',
            'keywords' => ['late', 'payment late', 'not paid', 'where is my money', 'pending withdrawal'],
            'answer' => 'If your withdrawal was approved but the money hasn\'t arrived, it\'s usually a mobile money delay. '
                . 'Check the transaction reference on your withdrawal and message the team in <a href="' . e(u('creator/messages.php')) . '">Messages</a> who can chase it up.',
            'path' => 'creator/messages.php',
        ],
    ];
    $custom = json_decode((string)setting('dash_faq_overrides', '[]'), true) ?: [];
    foreach ($custom as $index => $entry) {
        if (!isset($entries[$index]) || !is_array($entry)) { continue; }
        $entries[$index]['question'] = (string)($entry['question'] ?? $entries[$index]['question']);
        $entries[$index]['answer'] = nl2br(e((string)($entry['answer'] ?? '')));
        $entries[$index]['keywords'] = array_values(array_filter(array_map('trim', explode(',', (string)($entry['keywords'] ?? '')))));
    }
    return $entries;
}

/**
 * Match a free-text query against the FAQ. Returns the best answer or null.
 */
function support_match(string $query): ?array
{
    $q = mb_strtolower(trim($query));
    if ($q === '') {
        return null;
    }
    $best = null;
    $bestScore = 0;
    foreach (support_faq() as $entry) {
        $score = 0;
        foreach ($entry['keywords'] as $kw) {
            if (mb_strpos($q, mb_strtolower($kw)) !== false) {
                $score += mb_strlen($kw);
            }
        }
        // Also check the question title itself.
        if (mb_strpos($q, mb_strtolower($entry['question'])) !== false) {
            $score += 40;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $entry;
        }
    }
    return $best && $bestScore > 0 ? $best : null;
}