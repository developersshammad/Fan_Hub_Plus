<?php
/**
 * ORA chatbot engine — v2 ("advanced").
 *
 * ora_reply($message, $user) returns
 *   [
 *     'reply'    => string   (plain text, **bold**, *italic*, line breaks and "• " bullet lines allowed),
 *     'cards'    => [ ['type'=>'character|article|media|merch|event', 'id'=>int, 'title'=>..., 'sub'=>..., 'image'=>...], ... ],
 *     'actions'  => [ ['label'=>..., 'send'=>'text'] | ['label'=>..., 'go'=>'#events'] | ['label'=>..., 'search'=>'q']
 *                     | ['label'=>..., 'open'=>['view'=>..,'id'=>..]] | ['label'=>..., 'calendar'=>eventId]
 *                     | ['label'=>..., 'do'=>'auth|dashboard|feedback'] ],
 *     'intent'   => string,
 *     'answered' => bool,
 *     'lang'     => 'en' | 'ur'   (Roman Urdu / Urdu detected → replies switch to Roman Urdu)
 *   ]
 *
 * What's new in v2
 *   • Conversation memory: follow-ups like "tell me more about him", "his timeline", "show more", "uske baare mein batao"
 *   • Typo tolerance (fuzzy matching) for characters, fandoms, cities and event names
 *   • Roman Urdu / Urdu understanding + Roman Urdu replies
 *   • Event filters by date ("this weekend", "next month", "in December"), countdowns ("when is Karachi Fandom Fest?")
 *   • Character role filters (villain / hero / singer …), fandom filters and side-by-side comparisons ("Kaien vs Nyx")
 *   • Merch price filters ("merch under $100", "cheapest merch")
 *   • Trivia quiz built from the site's own data, fun facts, jokes, "surprise me", site stats ("how many events?")
 *   • "Did you mean …?" suggestions instead of a dead-end fallback
 *   • AI mode (Gemini, Claude, OpenAI, Groq, OpenRouter or any OpenAI-compatible server): answers ANY question with streaming,
 *     Markdown, code, images and conversation memory — the rules above keep the site questions they are sure about (ora_is_sure)
 */

/* ======================================================================
 *  Text helpers
 * ====================================================================== */
function ora_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = str_replace(['k-pop', 'k pop', '’'], ['kpop', 'kpop', "'"], $s);
    $s = preg_replace('/[^\p{L}\p{N}\s\'$-]+/u', ' ', $s);
    $s = str_replace("'", '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Whole-word / whole-phrase match on normalised text */
function ora_has(string $text, $words): bool
{
    foreach ((array)$words as $w) {
        $w = ora_norm((string)$w);
        if ($w !== '' && preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($w, '/') . '($|[^\p{L}\p{N}])/u', $text)) return true;
    }
    return false;
}

function ora_tokens(string $text): array
{
    return array_values(array_filter(preg_split('/\s+/u', $text), fn($w) => $w !== ''));
}

/** Two words are "close" when they differ by a small typo (1 edit for short words, 2 for long ones) */
function ora_close(string $a, string $b): bool
{
    if ($a === $b) return true;
    $la = mb_strlen($a); $lb = mb_strlen($b);
    if ($la < 4 || $lb < 4) return false;
    $max = min($la, $lb) >= 7 ? 2 : 1;
    if (abs($la - $lb) > $max) return false;
    return levenshtein($a, $b) <= $max;
}

/**
 * How well does $text mention $phrase?  10 exact phrase · 8 every word (typos ok) · 6 a distinctive word · 5 a distinctive word with a typo · 0 none
 */
function ora_score(string $text, string $phrase): int
{
    $phrase = ora_norm($phrase);
    if ($phrase === '') return 0;
    if (ora_has($text, $phrase)) return 10;
    $tokens = ora_tokens($text);
    $words = array_values(array_filter(ora_tokens($phrase), fn($w) => mb_strlen($w) >= 3 && !in_array($w, ORA_STOP, true)));
    if (!$words) return 0;
    $all = true; $exact = false; $fuzzy = false;
    foreach ($words as $w) {
        $found = false;
        foreach ($tokens as $t) {
            if ($t === $w) { $found = true; $exact = true; break; }
            if (ora_close($t, $w)) { $found = true; if (mb_strlen($w) >= 4) $fuzzy = true; }
        }
        if (!$found) $all = false;
    }
    if ($all && count($words) > 1) return 8;
    if ($all) return $exact ? 9 : 7;
    if ($exact) return 6;
    if ($fuzzy) return 5;
    return 0;
}

const ORA_STOP = ['the', 'and', 'for', 'with', 'about', 'what', 'when', 'where', 'which', 'who', 'whos', 'there', 'their', 'have', 'does',
    'show', 'tell', 'find', 'give', 'please', 'want', 'know', 'this', 'that', 'from', 'your', 'you', 'are', 'is', 'me', 'my', 'some',
    'kya', 'hai', 'hain', 'batao', 'dikhao', 'mujhe', 'koi', 'kuch', 'aur', 'ka', 'ki', 'ke', 'ko', 'se', 'mein', 'main', 'wala', 'wali',
    'season', 'part', 'live', 'night', 'edition', 'event', 'events', 'fandom', 'universe', 'how', 'many', 'much', 'any', 'all', 'more',
    'like', 'something', 'similar', 'recommend', 'suggest', 'compare', 'versus'];

/** Roman Urdu / Urdu → English hints (appended to the text so the English rules also fire) */
function ora_expand(string $text): string
{
    static $phrases = [
        'aur dikhao' => 'show more', 'aur batao' => 'tell me more', 'mazeed batao' => 'tell me more', 'kuch aur' => 'show more',
        'kya haal' => 'how are you', 'kaise ho' => 'how are you', 'kesy ho' => 'how are you', 'kaisay ho' => 'how are you', 'kaisi ho' => 'how are you',
        'kya dekhun' => 'what should i watch', 'kya dekhu' => 'what should i watch', 'kya parhun' => 'what to read', 'kuch acha' => 'recommend',
        'is hafte' => 'this week', 'iss hafte' => 'this week', 'is weekend' => 'this weekend', 'is mahine' => 'this month', 'iss mahine' => 'this month',
        'agle mahine' => 'next month', 'agle hafte' => 'next week', 'sab se' => 'most', 'sabse' => 'most', 'kon ho' => 'who are you', 'kaun ho' => 'who are you',
        'tum kya kar sakte' => 'what can you do', 'ap kya kar sakte' => 'what can you do', 'aap kya kar sakte' => 'what can you do',
        'se kam' => 'under', 'tak' => 'under', 'kab hai' => 'when is', 'kab hoga' => 'when is', 'kab ayega' => 'when is', 'kab aayega' => 'when is',
    ];
    static $words = [
        'dikhao' => 'show', 'dikha' => 'show', 'dikhado' => 'show', 'batao' => 'tell', 'btao' => 'tell', 'bataen' => 'tell', 'bataiye' => 'tell',
        'kirdar' => 'character', 'kirdaar' => 'character', 'kirdaron' => 'characters', 'کردار' => 'character',
        'taqreeb' => 'event', 'tagreeb' => 'event', 'mela' => 'event', 'ایونٹ' => 'event', 'ایونٹس' => 'events', 'تقریب' => 'event',
        'kab' => 'when', 'kahan' => 'where', 'kaun' => 'who', 'kon' => 'who', 'kaunsa' => 'which', 'konsa' => 'which', 'konsi' => 'which',
        'mashhoor' => 'popular', 'mashoor' => 'popular', 'maqbool' => 'popular', 'naya' => 'new', 'nayi' => 'new', 'naye' => 'new', 'nai' => 'new',
        'khareedna' => 'buy', 'kharidna' => 'buy', 'kharidu' => 'buy', 'khareedu' => 'buy', 'saman' => 'merch', 'samaan' => 'merch',
        'gaana' => 'song', 'gana' => 'song', 'gaane' => 'songs', 'ganay' => 'songs', 'filmein' => 'movies', 'filmon' => 'movies', 'film' => 'movie',
        'sasta' => 'cheap', 'sasti' => 'cheap', 'saste' => 'cheap', 'mehnga' => 'expensive', 'mehngi' => 'expensive', 'mehngay' => 'expensive',
        'aaj' => 'today', 'kal' => 'tomorrow', 'hafte' => 'week', 'mahine' => 'month', 'agla' => 'next', 'agle' => 'next', 'agli' => 'next',
        'mazaq' => 'joke', 'lateefa' => 'joke', 'latifa' => 'joke', 'chutkula' => 'joke', 'imtihan' => 'quiz', 'paheli' => 'quiz',
        'madad' => 'help', 'shukriya' => 'thanks', 'shukria' => 'thanks', 'meherbani' => 'thanks', 'sifarish' => 'recommend',
        'kitne' => 'how many', 'kitni' => 'how many', 'kitnay' => 'how many', 'muqabla' => 'compare', 'farq' => 'difference',
        'uske' => 'him', 'uska' => 'him', 'uski' => 'him', 'iske' => 'him', 'iska' => 'him', 'iski' => 'him', 'unka' => 'him', 'unki' => 'him', 'unke' => 'him',
        'kahani' => 'story', 'السلام' => 'salam', 'آداب' => 'salam', 'tareekh' => 'history', 'shehar' => 'city', 'شہر' => 'city', 'سلام' => 'salam', 'شکریہ' => 'thanks',
        'khalnayak' => 'villain', 'bura' => 'villain', 'heero' => 'hero', 'gulukar' => 'singer', 'jadugarni' => 'sorceress',
    ];
    $add = [];
    foreach ($phrases as $p => $en) if (ora_has($text, $p)) $add[] = $en;
    foreach (ora_tokens($text) as $t) if (isset($words[$t])) $add[] = $words[$t];
    return $add ? $text . ' ' . implode(' ', array_unique($add)) : $text;
}

/** 'ur' when the visitor writes in Urdu script or Roman Urdu, otherwise 'en' */
function ora_lang(string $raw): string
{
    if (preg_match('/\p{Arabic}/u', $raw)) return 'ur';
    // words that only appear in Roman Urdu count double, ambiguous short ones count once
    $strong = ['kya', 'hai', 'hain', 'mujhe', 'batao', 'btao', 'dikhao', 'karo', 'kaun', 'kab', 'kahan', 'nahi', 'nahin', 'kaise', 'kesy', 'chahiye',
        'chahye', 'sakte', 'uske', 'uska', 'uski', 'iska', 'iske', 'iski', 'unka', 'unki', 'shukriya', 'kirdar', 'sasta', 'sasti', 'acha', 'accha',
        'yaar', 'bhai', 'kuch', 'koi', 'wala', 'wali', 'raha', 'rahi', 'hoga', 'mera', 'meri', 'salam', 'aaj', 'mein', 'dekhun', 'khelo', 'karao', 'konsa', 'kitne'];
    $weak = ['ho', 'aur', 'haan', 'tum', 'aap', 'ap', 'ka', 'ki', 'ke', 'ko', 'se', 'hy', 'yar', 'kal', 'main'];
    $hits = 0;
    foreach (ora_tokens(ora_norm($raw)) as $t) { if (in_array($t, $strong, true)) $hits += 2; elseif (in_array($t, $weak, true)) $hits++; }
    return $hits >= 2 ? 'ur' : 'en';
}

function ora_excerpt(?string $t, int $len = 170): string
{
    $t = trim(preg_replace('/\s+/', ' ', (string)$t));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len - 1)) . '…' : $t;
}

function ora_price(string $p): float { return (float)preg_replace('/[^0-9.]/', '', $p); }

function ora_days_until(string $date): int
{
    $today = new DateTimeImmutable('today');
    return (int)$today->diff(new DateTimeImmutable($date))->format('%r%a');
}
function ora_when(int $days, string $lang): string
{
    if ($days === 0) return $lang === 'ur' ? 'aaj' : 'today';
    if ($days === 1) return $lang === 'ur' ? 'kal' : 'tomorrow';
    if ($days < 0) return $lang === 'ur' ? (-$days) . ' din pehle' : (-$days) . ' days ago';
    return $lang === 'ur' ? "$days din baad" : "in $days days";
}

/* ======================================================================
 *  Cards
 * ====================================================================== */
function ora_content_card(array $c): array
{
    return ['type' => $c['type'] === 'article' ? 'article' : 'media', 'id' => (int)$c['content_id'], 'title' => $c['title'],
            'sub' => content_type_label($c['type']) . ' · ' . ($c['category'] ?? ''), 'image' => media($c['image'])];
}
function ora_character_card(array $c): array
{
    return ['type' => 'character', 'id' => (int)$c['character_id'], 'title' => $c['name'], 'sub' => $c['fandom_label'], 'image' => media($c['image'])];
}
function ora_merch_card(array $m): array
{
    return ['type' => 'merch', 'id' => (int)$m['item_id'], 'title' => $m['name'], 'sub' => trim($m['item_type'] . ($m['price'] ? ' · ' . $m['price'] : '')), 'image' => media($m['image'])];
}
function ora_event_card(array $e): array
{
    return ['type' => 'event', 'id' => (int)$e['event_id'], 'title' => $e['title'], 'sub' => fmt_date($e['event_date'], 'M j') . ' · ' . $e['city'], 'image' => ''];
}

function ora_default_actions(string $lang = 'en'): array
{
    if ($lang === 'ur') return [
        ['label' => 'Trending kya hai?', 'send' => "What's trending?"],
        ['label' => 'Characters dikhao', 'send' => 'Show me characters'],
        ['label' => 'Events kab hain?', 'send' => 'Upcoming events'],
        ['label' => 'Quiz khelo', 'send' => 'Quiz me'],
    ];
    return [
        ['label' => 'Trending now', 'send' => "What's trending?"],
        ['label' => 'Meet the characters', 'send' => 'Show me characters'],
        ['label' => 'Upcoming events', 'send' => 'Upcoming events'],
        ['label' => 'Play trivia', 'send' => 'Quiz me'],
    ];
}

/* ======================================================================
 *  Data access (small, cached per request)
 * ====================================================================== */
function ora_chars(): array
{
    static $c = null;
    if ($c === null) $c = q_all('SELECT ch.*, cat.name AS category FROM characters ch JOIN categories cat USING(category_id) ORDER BY ch.views DESC');
    return $c;
}
function ora_upcoming_events(): array
{
    static $e = null;
    if ($e === null) $e = q_all('SELECT ev.*, cat.name AS category FROM events ev JOIN categories cat USING(category_id) WHERE ev.event_date >= CURDATE() ORDER BY ev.event_date');
    return $e;
}
function ora_content_sql(): string
{
    return "SELECT c.*, cat.name AS category FROM contents c JOIN categories cat USING(category_id) WHERE c.status='published'";
}

/** Best character mentioned in the text (score ≥ 5) */
function ora_find_characters(string $text): array
{
    $hits = [];
    foreach (ora_chars() as $c) {
        $s = ora_score($text, $c['name']);
        if ($s >= 5) $hits[] = ['score' => $s, 'c' => $c];
    }
    usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
    return $hits;
}

function ora_find_category(string $text): ?array
{
    $best = null; $bs = 0;
    foreach (categories() as $c) {
        $s = max(ora_score($text, $c['name']), ora_score($text, $c['slug']), ora_has($text, rtrim(mb_strtolower($c['name']), 's')) ? 9 : 0);
        if (mb_strlen($c['slug']) <= 3 && $s < 9) $s = 0;     // "tv" must match exactly
        if ($s > $bs && $s >= 7) { $bs = $s; $best = $c; }
    }
    return $best;
}

/** Date window from phrases like "this weekend", "next month", "in december", "today" → [from, to, label] */
function ora_date_range(string $text): ?array
{
    $t = new DateTimeImmutable('today');
    if (ora_has($text, ['today', 'tonight'])) return [$t, $t, 'today'];
    if (ora_has($text, 'tomorrow')) return [$t->modify('+1 day'), $t->modify('+1 day'), 'tomorrow'];
    if (ora_has($text, 'this weekend') || ora_has($text, 'weekend')) {
        $dow = (int)$t->format('N');
        $from = $dow >= 6 ? $t : $t->modify('next saturday');
        return [$from, $from->modify($dow === 7 ? '+0 day' : 'next sunday'), 'this weekend'];
    }
    if (ora_has($text, 'next week')) { $m = $t->modify('next monday'); return [$m, $m->modify('+6 days'), 'next week']; }
    if (ora_has($text, 'this week')) return [$t, (int)$t->format('N') === 7 ? $t : $t->modify('next sunday'), 'this week'];
    if (ora_has($text, 'next month')) { $f = $t->modify('first day of next month'); return [$f, $f->modify('last day of this month'), $f->format('F Y')]; }
    if (ora_has($text, 'this month')) return [$t, $t->modify('last day of this month'), 'this month'];
    $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
    foreach ($months as $i => $m) {
        if (ora_has($text, $m) || ($m !== 'may' && ora_has($text, mb_substr($m, 0, 3)))) {
            $y = (int)$t->format('Y');
            if (preg_match('/\b(20\d\d)\b/', $text, $mm)) $y = (int)$mm[1];
            elseif ($i + 1 < (int)$t->format('n')) $y++;
            $f = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $i + 1));
            return [$f, $f->modify('last day of this month'), $f->format('F Y')];
        }
    }
    return null;
}

/* ======================================================================
 *  Trivia quiz (built from the site's own data)
 * ====================================================================== */
function ora_quiz_question(array $asked): ?array
{
    $chars = ora_chars();
    $events = array_values(array_filter(ora_upcoming_events(), fn($e) => !in_array($e['city'], ['Online', 'Multiple Cities'], true)));
    $pool = [];
    foreach ($chars as $c) {
        $pool[] = ['key' => 'f' . $c['character_id'], 'kind' => 'fandom', 'c' => $c];
        $pool[] = ['key' => 'r' . $c['character_id'], 'kind' => 'role', 'c' => $c];
    }
    foreach ($events as $e) $pool[] = ['key' => 'e' . $e['event_id'], 'kind' => 'city', 'e' => $e];
    $pool = array_values(array_filter($pool, fn($p) => !in_array($p['key'], $asked, true)));
    if (!$pool || count($chars) < 3) return null;
    $p = $pool[array_rand($pool)];
    $pick = function (array $all, string $correct): array {
        $others = array_values(array_unique(array_filter($all, fn($x) => $x !== $correct && $x !== '')));
        shuffle($others);
        $opts = array_merge([$correct], array_slice($others, 0, 2));
        shuffle($opts);
        return $opts;
    };
    if ($p['kind'] === 'fandom') {
        $ans = character_fandom($p['c']);
        return ['key' => $p['key'], 'q' => "Which world does **{$p['c']['name']}** come from?", 'answer' => $ans,
                'options' => $pick(array_map('character_fandom', $chars), $ans), 'fact' => ora_excerpt($p['c']['short_desc'] ?: $p['c']['bio'], 120)];
    }
    if ($p['kind'] === 'role') {
        $ans = $p['c']['name'];
        return ['key' => $p['key'], 'q' => 'Who is the **' . $p['c']['role'] . '** of *' . character_fandom($p['c']) . '*?', 'answer' => $ans,
                'options' => $pick(array_column($chars, 'name'), $ans), 'fact' => ora_excerpt($p['c']['short_desc'] ?: $p['c']['bio'], 120)];
    }
    $ans = $p['e']['city'];
    $cities = array_column(q_all("SELECT DISTINCT city FROM events WHERE city NOT IN ('Online','Multiple Cities')"), 'city');
    return ['key' => $p['key'], 'q' => "Where is **{$p['e']['title']}** happening?", 'answer' => $ans,
            'options' => $pick($cities, $ans), 'fact' => "It's at {$p['e']['venue']} on " . fmt_date($p['e']['event_date'], 'M j, Y') . '.'];
}

/* ======================================================================
 *  Main entry
 * ====================================================================== */
function ora_reply(string $msg, ?array $user): array
{
    if (!isset($_SESSION['ora_ctx']) || !is_array($_SESSION['ora_ctx'])) $_SESSION['ora_ctx'] = [];
    $ctx = &$_SESSION['ora_ctx'];

    $uid   = $user ? (int)$user['user_id'] : null;
    $lang  = ora_lang($msg);
    $plain = ora_norm($msg);
    $text  = ora_expand($plain);
    $ur    = $lang === 'ur';
    $L     = fn(string $en, string $urdu) => $ur ? $urdu : $en;
    $first = $user ? explode(' ', trim($user['name']))[0] : '';
    $name  = setting('chatbot_name', 'ORA');
    $out   = ['reply' => '', 'cards' => [], 'actions' => [], 'intent' => 'fallback', 'answered' => true, 'lang' => $lang];
    $set   = function (string $intent, string $reply, array $cards = [], array $actions = []) use (&$out, &$ctx, $lang) {
        $out = ['reply' => $reply, 'cards' => $cards, 'actions' => $actions, 'intent' => $intent, 'answered' => true, 'lang' => $lang];
        $ctx['last_intent'] = $intent;
    };
    /** remember a list so "show more" can page through it */
    $remember = function (string $kind, array $ids, int $shown) use (&$ctx) {
        $ctx['list'] = ['kind' => $kind, 'ids' => array_values(array_map('intval', $ids)), 'offset' => $shown];
    };
    $focusChar = function (array $c) use (&$ctx) { $ctx['char'] = (int)$c['character_id']; $ctx['cat'] = (int)$c['category_id']; };

    if ($plain === '') {
        $set('empty', $L('That looks like an emoji or symbol — love it! ✦ Ask me anything about the Fandom Universe.', 'Emoji pasand aaya! ✦ Fandom Universe ke baare mein kuch bhi poochiye.'), [], ora_default_actions($lang));
        return $out;
    }

    /* ---------- 1. Running trivia quiz ---------- */
    if (!empty($ctx['quiz'])) {
        $qz = &$ctx['quiz'];
        if (preg_match('/\b(stop|quit|exit|end|cancel|band|bas)\b/u', $text)) {
            $s = $qz['score']; $n = $qz['n'] - 1;
            unset($ctx['quiz']);
            $set('quiz', $L("Quiz ended — you scored **$s/$n**. Come back for a rematch anytime!", "Quiz khatam — aap ka score **$s/$n** raha. Phir kabhi rematch karte hain!"), [], ora_default_actions($lang));
            return $out;
        }
        $cur = $qz['current'];
        $picked = null;
        foreach ($cur['options'] as $i => $o) {
            $on = ora_norm($o);
            if ($plain === $on || ora_has($plain, $on) || $plain === (string)($i + 1) || $plain === chr(97 + $i) || ora_score($plain, $o) >= 8) { $picked = $o; break; }
        }
        if ($picked !== null) {
            $right = ora_norm($picked) === ora_norm($cur['answer']);
            if ($right) $qz['score']++;
            $verdict = $right
                ? $L('✅ **Correct!** ', '✅ **Bilkul sahi!** ')
                : $L("❌ Not quite — it's **{$cur['answer']}**. ", "❌ Ghalat — sahi jawab **{$cur['answer']}** hai. ");
            $verdict .= $cur['fact'];
            if ($qz['n'] >= $qz['total']) {
                $s = $qz['score']; $tot = $qz['total'];
                $badge = $s === $tot ? '🏆 Legendary fan!' : ($s >= $tot - 1 ? '🌟 True fan!' : ($s >= 2 ? '✨ Rising fan!' : '🌱 Fresh recruit!'));
                unset($ctx['quiz']);
                $set('quiz', $verdict . "\n\n" . $L("**Final score: $s/$tot** — $badge", "**Final score: $s/$tot** — $badge"),
                    [], [['label' => $L('Play again', 'Dobara khelo'), 'send' => 'Quiz me'], ['label' => $L('Meet the characters', 'Characters dikhao'), 'send' => 'Show me characters']]);
                return $out;
            }
            $next = ora_quiz_question($qz['asked']);
            if (!$next) { unset($ctx['quiz']); $set('quiz', $verdict, [], ora_default_actions($lang)); return $out; }
            $qz['asked'][] = $next['key']; $qz['n']++; $qz['current'] = $next;
            $acts = array_map(fn($o) => ['label' => $o, 'send' => $o], $next['options']);
            $acts[] = ['label' => $L('Stop quiz', 'Quiz band karo'), 'send' => 'stop quiz'];
            $set('quiz', $verdict . "\n\n**Q{$qz['n']}/{$qz['total']}** · " . $next['q'], [], $acts);
            return $out;
        }
        unset($ctx['quiz']);   // the visitor moved on to another question
    }

    /* ---------- 2. Guided tour (multi-step) ---------- */
    $TOUR = $ur ? [
        ['**Step 1/5 — Fandoms.** Fandom Universe Explorer tak scroll karein aur kisi bhi world par hover/tap karein. Click karne par us fandom ki search khul jaati hai.', '#fandoms'],
        ['**Step 2/5 — Search.** Magnifier dabayein (ya Ctrl + K) — category, type, saal aur genre se filter karein.', null],
        ['**Step 3/5 — Characters & Media.** Character spotlight swipe karein, full profiles kholein, aur Watch & Listen mein trailers aur soundtracks chalayein.', '#characters'],
        ['**Step 4/5 — Events.** Shehar se filter karein ya "Near me" dabayein, phir calendar mein add karein.', '#events'],
        ['**Step 5/5 — Account.** Sign in kar ke bookmarks, pasandeeda fandoms aur fan articles apne Dashboard mein dekhein. Tour khatam — ab kuch bhi poochiye!', null],
    ] : [
        ['**Step 1 of 5 — Fandoms.** Scroll to the Fandom Universe Explorer and hover (or tap) any of the eight worlds. Clicking one opens search filtered to that fandom.', '#fandoms'],
        ['**Step 2 of 5 — Search.** Tap the magnifier (or press Ctrl + K) to search everything. Filter by category, type, year and genre, and sort by popular, latest or A–Z.', null],
        ['**Step 3 of 5 — Characters & Media.** Swipe the character spotlight, open full profiles, then stream trailers, videos and soundtracks in Watch & Listen — you can rate each one.', '#characters'],
        ['**Step 4 of 5 — Events.** Filter events by city or tap "Near me" to sort them by distance, then add them to your calendar.', '#events'],
        ['**Step 5 of 5 — Your account.** Sign in to bookmark items with notes, pick favourite fandoms, submit fan articles and see everything in your Dashboard. That\'s the tour — ask me anything!', null],
    ];
    $tourStep = function (int $i) use ($TOUR, $set, $L, $lang) {
        [$t, $go] = $TOUR[$i];
        $acts = [];
        if ($go) $acts[] = ['label' => $L('Take me there', 'Wahan le chalo'), 'go' => $go];
        if ($i < count($TOUR) - 1) { $acts[] = ['label' => $L('Next step', 'Agla step'), 'send' => 'next']; $acts[] = ['label' => $L('Stop tour', 'Tour band karo'), 'send' => 'stop tour']; }
        else $acts = array_merge($acts, array_slice(ora_default_actions($lang), 0, 3));
        $set('tour', $t, [], $acts);
    };
    if (preg_match('/\b(tour|guide me|get started|getting started|how does (this|it|the site) work|new here|onboard|how to use|site kaise)\b/u', $text)) {
        $_SESSION['chat_tour'] = 0;
        $tourStep(0);
        $out['reply'] = $L("Let's take a quick tour! ", 'Chaliye ek chhota sa tour karte hain! ') . $out['reply'];
        return $out;
    }
    if (isset($_SESSION['chat_tour'])) {
        if (preg_match('/\b(stop|exit|quit|end|cancel|band)\b/u', $text)) {
            unset($_SESSION['chat_tour']);
            $set('tour', $L('Tour ended. Ask me anything about the Fandom Universe whenever you like!', 'Tour khatam. Jab chahein kuch bhi poochiye!'), [], ora_default_actions($lang));
            return $out;
        }
        if (preg_match('/^(next|continue|go on|yes|ok|okay|sure|yep|haan|han|ji|next step|agla|aage)\b/u', $plain)) {
            $step = $_SESSION['chat_tour'] + 1;
            if ($step >= count($TOUR) - 1) unset($_SESSION['chat_tour']); else $_SESSION['chat_tour'] = $step;
            $tourStep(min($step, count($TOUR) - 1));
            return $out;
        }
        unset($_SESSION['chat_tour']);
    }

    /* ---------- 3. Exact suggested question from the knowledge base ---------- */
    $faqs = q_all('SELECT * FROM chatbot_faq ORDER BY sort_order, faq_id');
    foreach ($faqs as $faq) {
        if ($faq['question'] !== '' && ora_norm($faq['question']) === $plain) { $set('faq', $faq['answer'], [], array_slice(ora_default_actions($lang), 0, 3)); return $out; }
    }

    /* ---------- 4. Small talk ---------- */
    $short = mb_strlen($plain) < 45;
    if ($short && (preg_match('/^(hi+|hey+|hello+|helo|hiya|yo|sup|salam|salaam|slm|assalam|assalamualaikum|asalamualaikum|aoa|namaste|adaab|good (morning|afternoon|evening))\b/u', $plain) || ora_has($text, ['salam', 'assalam o alaikum', 'السلام علیکم']))) {
        $h = (int)date('G');
        $greet = $ur ? 'Assalam o Alaikum' : ($h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening'));
        $set('greeting', $greet . ($first ? ", $first" : '') . '! ' . $L(
            "I'm **$name**, your Fandom Universe guide. I can find characters, trending content, events, merch and releases, recommend something new — or challenge you to a trivia quiz.",
            "Main **$name** hoon, aap ka Fandom Universe guide. Characters, trending content, events, merch aur releases dhoondh sakta hoon, kuch naya recommend kar sakta hoon — ya trivia quiz khel sakte hain."), [], ora_default_actions($lang));
        return $out;
    }
    if ($short && ora_has($text, ['how are you', 'how r u', 'how are u', 'hows it going', 'whats up', 'wassup'])) {
        $set('smalltalk', $L("Running at full power and ready to explore! ⚡ What are you in the mood for — characters, trailers or events?", "Bilkul fit aur tayyar! ⚡ Aaj kis cheez ka mood hai — characters, trailers ya events?"), [], ora_default_actions($lang));
        return $out;
    }
    if ($short && preg_match('/\b(thanks|thank you|thx|ty|tysm|awesome|great|cool|nice|perfect|love it|amazing|zabardast|kamaal)\b/u', $text) && !preg_match('/\b(show|recommend|event|character)\b/u', $text)) {
        $set('thanks', $L('Anytime! Anything else you want to explore?', 'Koi baat nahi! Aur kya dekhna chahenge?'), [], ora_default_actions($lang));
        return $out;
    }
    if (preg_match('/^(bye|goodbye|good night|see you|see ya|cya|khuda hafiz|allah hafiz|alvida)\b/u', $plain)) {
        $set('bye', $L('See you in the Fandom Universe! I\'ll be right here if you need me. ✦', 'Allah Hafiz! Jab zaroorat ho, main yahin hoon. ✦'));
        return $out;
    }
    if (mb_strlen($plain) < 60 && preg_match('/\b(who are you|what are you|your name|what can you do|help|commands|menu|options|features)\b/u', $text)) {
        $set('help', $L("I'm **$name** — here's what I can do:", "Main **$name** hoon — yeh sab kar sakta hoon:") . "\n"
            . "• " . $L('Find **characters**, compare them and show timelines ("Kaien vs Nyx")', '**Characters** dhoondna, compare karna aur timeline dikhana ("Kaien vs Nyx")') . "\n"
            . "• " . $L('**Trending** content and personal **recommendations**', '**Trending** content aur **recommendations**') . "\n"
            . "• " . $L('**Events** by city or date ("events in Lahore", "this weekend")', 'Shehar ya tareekh se **events** ("Lahore events", "is weekend")') . "\n"
            . "• " . $L('Upcoming **releases** and **merch** with price filters ("merch under $100")', 'Aane wali **releases** aur **merch** ("$100 se kam merch")') . "\n"
            . "• " . $L('A **trivia quiz**, fun facts, jokes and "surprise me"', '**Trivia quiz**, fun facts, jokes aur "surprise me"') . "\n"
            . "• " . $L('A **guided tour** — and I understand English, Urdu and Roman Urdu', '**Guided tour** — aur main English, Urdu aur Roman Urdu samajhta hoon'),
            [], [['label' => $L('Give me a tour', 'Tour karao'), 'send' => 'Give me a tour'], ['label' => 'Quiz me', 'send' => 'Quiz me'], ['label' => 'Surprise me', 'send' => 'Surprise me']]);
        return $out;
    }
    if (ora_has($text, ['joke', 'jokes', 'make me laugh', 'something funny', 'funny'])) {
        $jokes = [
            'Why did the anime hero bring a ladder to the final arc? Because the stakes kept getting higher. 🪜',
            'My power level is over 9000… but only after my third cup of chai. ☕',
            "Why don't mech pilots ever get lost? They always follow the main *quest*-ion. 🤖",
            'I tried to binge a whole season in one night. Now I have a cliffhanger *and* a headache. 📺',
            "A K-Pop fan's favourite exercise? Lightstick curls. 💪✨",
            'Why did the villain join the fan club? He wanted a better *arc*. 😈',
        ];
        $set('joke', $jokes[array_rand($jokes)], [], [['label' => $L('Another one', 'Ek aur'), 'send' => 'Tell me a joke'], ['label' => 'Fun fact', 'send' => 'Tell me a fun fact'], ['label' => 'Quiz me', 'send' => 'Quiz me']]);
        return $out;
    }

    /* ---------- 5. Quiz start / fun facts / surprise ---------- */
    if (ora_has($text, ['quiz', 'trivia', 'test me', 'quiz me', 'play a game', 'game khelo', 'quiz khelo'])) {
        $q = ora_quiz_question([]);
        if ($q) {
            $ctx['quiz'] = ['score' => 0, 'n' => 1, 'total' => 5, 'asked' => [$q['key']], 'current' => $q];
            $acts = array_map(fn($o) => ['label' => $o, 'send' => $o], $q['options']);
            $acts[] = ['label' => $L('Stop quiz', 'Quiz band karo'), 'send' => 'stop quiz'];
            $set('quiz', $L('🎮 **Fandom Trivia** — 5 questions, tap an answer!', '🎮 **Fandom Trivia** — 5 sawal, jawab par tap karein!') . "\n\n**Q1/5** · " . $q['q'], [], $acts);
            return $out;
        }
    }
    if (ora_has($text, ['fun fact', 'fact', 'did you know', 'trivia fact', 'interesting'])) {
        $chars = ora_chars();
        if ($chars) {
            $c = $chars[array_rand($chars)];
            $tl = parse_timeline($c['timeline']);
            $fact = $tl ? $tl[array_rand($tl)] : null;
            $line = $fact ? "**{$c['name']}** — {$fact['year']}: {$fact['event']}" : "**{$c['name']}** — " . ora_excerpt($c['short_desc'], 140);
            $focusChar($c);
            $set('fact', $L('💡 Did you know? ', '💡 Kya aap jaante hain? ') . $line, [ora_character_card($c)],
                [['label' => $L('Another fact', 'Ek aur fact'), 'send' => 'Tell me a fun fact'], ['label' => $L('Full timeline', 'Poori timeline'), 'send' => "{$c['name']} timeline"], ['label' => 'Quiz me', 'send' => 'Quiz me']]);
            return $out;
        }
    }
    if (ora_has($text, ['surprise me', 'random', 'anything', 'surprise', 'feeling lucky', 'kuch bhi'])) {
        $row = q_one(ora_content_sql() . ' ORDER BY RAND() LIMIT 1');
        $ch = ora_chars() ? ora_chars()[array_rand(ora_chars())] : null;
        $cards = [];
        if ($ch) { $cards[] = ora_character_card($ch); $focusChar($ch); }
        if ($row) $cards[] = ora_content_card($row);
        $set('surprise', $L('🎲 Rolling the dice… here\'s a random pick from the multiverse:', '🎲 Dice ghumaya… multiverse se yeh random picks:') . ($row ? "\n**{$row['title']}** — " . ora_excerpt($row['excerpt'], 120) : ''), $cards,
            [['label' => $L('Roll again', 'Phir se'), 'send' => 'Surprise me'], ['label' => $L('Recommend me something', 'Kuch recommend karo'), 'send' => 'Recommend me something']]);
        return $out;
    }

    /* ---------- 6. Stats ---------- */
    if (preg_match('/\bhow many\b/u', $text) || ora_has($text, ['count', 'total number', 'stats', 'statistics'])) {
        $parts = [];
        $all = !preg_match('/\b(character|event|article|video|trailer|song|music|audio|merch|fandom|categor|member|user|release)/u', $text);
        if ($all || preg_match('/\bcharacter/u', $text)) $parts[] = '**' . count(ora_chars()) . '** ' . $L('character profiles', 'character profiles');
        if ($all || preg_match('/\bevent/u', $text)) $parts[] = '**' . count(ora_upcoming_events()) . '** ' . $L('upcoming events', 'aane wale events');
        foreach (['article' => 'article', 'video' => 'video', 'trailer' => 'trailer', 'audio' => '(song|music|audio)'] as $type => $re)
            if ($all || preg_match("/\\b$re/u", $text)) $parts[] = '**' . (int)q_val("SELECT COUNT(*) FROM contents WHERE status='published' AND type=?", [$type]) . '** ' . strtolower(content_type_label($type)) . ($type === 'audio' ? ' tracks' : 's');
        if ($all || preg_match('/\bmerch/u', $text)) $parts[] = '**' . (int)q_val('SELECT COUNT(*) FROM merchandise') . '** ' . $L('merch items', 'merch items');
        if ($all || preg_match('/\b(fandom|categor)/u', $text)) $parts[] = '**' . count(categories()) . '** fandoms';
        if ($all || preg_match('/\brelease/u', $text)) $parts[] = '**' . (int)q_val('SELECT COUNT(*) FROM releases WHERE release_date >= CURDATE()') . '** ' . $L('upcoming releases', 'aane wali releases');
        if (preg_match('/\b(member|user)/u', $text)) $parts[] = '**' . (int)q_val('SELECT COUNT(*) FROM users') . '** ' . $L('registered fans', 'registered fans');
        $set('stats', $L('📊 Right now the Fandom Universe has:', '📊 Is waqt Fandom Universe mein:') . "\n• " . implode("\n• ", $parts), [], array_slice(ora_default_actions($lang), 0, 3));
        return $out;
    }

    /* ---------- 7. Account helpers ---------- */
    if (ora_has($text, ['my bookmarks', 'my saved', 'my dashboard', 'my account', 'my profile', 'saved items', 'my calendar', 'my events', 'mere bookmarks', 'mera account'])) {
        if (!$uid) { $set('account', $L('Sign in first and I\'ll show you everything you have saved. Your bookmarks, notes, favourite fandoms and submissions live in the Dashboard.', 'Pehle sign in karein — phir aap ke bookmarks, notes aur pasandeeda fandoms Dashboard mein nazar aayenge.'), [], [['label' => 'Sign in', 'do' => 'auth']]); return $out; }
        $n = (int)q_val('SELECT COUNT(*) FROM bookmarks WHERE user_id=?', [$uid]);
        $ev = q_all('SELECT e.* FROM event_saves s JOIN events e USING(event_id) WHERE s.user_id=? AND e.event_date >= CURDATE() ORDER BY e.event_date LIMIT 4', [$uid]);
        $reply = $L("You have **$n** bookmarked item" . ($n === 1 ? '' : 's') . '.', "Aap ke **$n** bookmarks hain.");
        if ($ev) $reply .= "\n" . $L('Events on your calendar:', 'Aap ke calendar ke events:') . "\n" . implode("\n", array_map(fn($e) => '• **' . $e['title'] . '** — ' . fmt_date($e['event_date'], 'D, M j') . ' (' . ora_when(ora_days_until($e['event_date']), $lang) . ')', $ev));
        $set('account', $reply, array_map('ora_event_card', $ev), [['label' => $L('Open dashboard', 'Dashboard kholo'), 'do' => 'dashboard']]);
        return $out;
    }

    /* ---------- 8. Follow-ups using conversation memory ---------- */
    // "show more" → next page of the last list
    if (!empty($ctx['list']) && (preg_match('/^(more|show more|load more|next page|see more|more please|aur|aur dikhao|kuch aur|mazeed)$/u', $plain) || ora_has($text, 'show more'))) {
        $ls = $ctx['list'];
        $ids = array_slice($ls['ids'], $ls['offset'], 6);
        if (!$ids) { unset($ctx['list']); $set('more', $L("That's everything I have for that — try another topic!", 'Is topic par bas itna hi tha — kuch aur poochiye!'), [], ora_default_actions($lang)); return $out; }
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($ls['kind'] === 'character') $cards = array_map('ora_character_card', q_all("SELECT ch.*, cat.name AS category FROM characters ch JOIN categories cat USING(category_id) WHERE ch.character_id IN ($in) ORDER BY FIELD(ch.character_id,$in)", array_merge($ids, $ids)));
        elseif ($ls['kind'] === 'merch') $cards = array_map('ora_merch_card', q_all("SELECT * FROM merchandise WHERE item_id IN ($in) ORDER BY FIELD(item_id,$in)", array_merge($ids, $ids)));
        else $cards = array_map('ora_content_card', q_all(ora_content_sql() . " AND c.content_id IN ($in) ORDER BY FIELD(c.content_id,$in)", array_merge($ids, $ids)));
        $ctx['list']['offset'] += count($ids);
        $left = count($ls['ids']) - $ctx['list']['offset'];
        $set('more', $L('Here\'s more:', 'Yeh rahe mazeed:'), $cards, $left > 0 ? [['label' => $L("Show more ($left left)", "Aur dikhao ($left baqi)"), 'send' => 'show more']] : array_slice(ora_default_actions($lang), 0, 3));
        return $out;
    }
    // pronoun follow-ups about the character we just talked about
    $mentioned = ora_find_characters($text);
    if (!$mentioned && !empty($ctx['char']) && preg_match('/\b(him|her|he|she|his|hers|they|them|their|this character|that character|more about|tell me more|timeline|history|story|backstory|related|powers?|abilities|bio|biography|profile|role)\b/u', $text)) {
        foreach (ora_chars() as $c) if ((int)$c['character_id'] === (int)$ctx['char']) { $mentioned = [['score' => 10, 'c' => $c]]; break; }
    }

    /* ---------- 9. Compare two characters ---------- */
    if (count($mentioned) >= 2 && preg_match('/\b(vs|versus|compare|comparison|or|ya|difference|better|stronger|who wins|kaun behtar)\b/u', $text)) {
        [$a, $b] = [$mentioned[0]['c'], $mentioned[1]['c']];
        $row = fn($c) => "**{$c['name']}**\n• " . $L('Role', 'Role') . ": {$c['role']}\n• " . $L('World', 'Duniya') . ': ' . character_fandom($c) . " ({$c['category']})\n• " . $L('Fan views', 'Fan views') . ': ' . number_format((int)$c['views']) . "\n• " . ora_excerpt($c['short_desc'] ?: $c['bio'], 110);
        $lead = (int)$a['views'] >= (int)$b['views'] ? $a : $b;
        $set('compare', "⚔️ " . $L('Head to head:', 'Muqabla:') . "\n\n" . $row($a) . "\n\n" . $row($b) . "\n\n" . $L("Fan favourite right now: **{$lead['name']}**.", "Is waqt fans ki pasand: **{$lead['name']}**."),
            [ora_character_card($a), ora_character_card($b)],
            [['label' => "{$a['name']} " . $L('profile', 'profile'), 'open' => ['view' => 'character', 'id' => (int)$a['character_id']]], ['label' => "{$b['name']} " . $L('profile', 'profile'), 'open' => ['view' => 'character', 'id' => (int)$b['character_id']]]]);
        return $out;
    }

    /* ---------- 10. A specific character ---------- */
    if ($mentioned && !preg_match('/\b(recommend|suggest|something like|similar to|like)\b/u', $text)) {
        $hit = $mentioned[0]['c'];
        $focusChar($hit);
        $tl = parse_timeline($hit['timeline']);
        $profile = ['label' => $L('Open full profile', 'Poora profile kholo'), 'open' => ['view' => 'character', 'id' => (int)$hit['character_id']]];
        if (preg_match('/\b(timeline|history|story|backstory|journey|arc|kahani)\b/u', $text) && $tl) {
            $set('character', "📜 **{$hit['name']}** — " . $L('timeline', 'timeline') . ":\n" . implode("\n", array_map(fn($t) => "• **{$t['year']}** — {$t['event']}", $tl)),
                [ora_character_card($hit)], [$profile, ['label' => $L('Related', 'Related'), 'send' => "{$hit['name']} related"]]);
            return $out;
        }
        if (preg_match('/\b(related|similar|more like|connected)\b/u', $text)) {
            $rel = parse_lines($hit['related']);
            $same = q_all(ora_content_sql() . ' AND c.category_id=? ORDER BY c.popularity DESC LIMIT 4', [$hit['category_id']]);
            $set('character', $L("Everything connected to **{$hit['name']}**:", "**{$hit['name']}** se jura sab kuch:") . ($rel ? "\n" . implode("\n", array_map(fn($r) => "• $r", $rel)) : ''),
                array_map('ora_content_card', $same), array_merge([$profile], array_map(fn($r) => ['label' => $r, 'search' => $r], array_slice($rel, 0, 2))));
            return $out;
        }
        if (preg_match('/\b(more about|tell me more|bio|biography|everything|in detail|detail)\b/u', $text)) {
            $reply = "**{$hit['name']}** · {$hit['role']} · *" . character_fandom($hit) . "*\n" . trim($hit['bio']) . "\n👁 " . number_format((int)$hit['views']) . ' ' . $L('fan views', 'fan views');
            $set('character', $reply, [ora_character_card($hit)], [$profile, ['label' => $L('Timeline', 'Timeline'), 'send' => "{$hit['name']} timeline"], ['label' => $L('Related content', 'Related content'), 'send' => "{$hit['name']} related"]]);
            return $out;
        }
        $reply = "**{$hit['name']}** — {$hit['role']} " . $L('from', '') . ' *' . character_fandom($hit) . "* ({$hit['category']})" . ($ur ? ' se' : '') . ".\n" . ora_excerpt($hit['bio'], 230);
        if ($tl) $reply .= "\n" . $L('Key moment', 'Aham lamha') . ': ' . $tl[count($tl) - 1]['year'] . ' — ' . $tl[count($tl) - 1]['event'];
        $acts = [$profile, ['label' => $L('Timeline', 'Timeline'), 'send' => "{$hit['name']} timeline"], ['label' => $L('Related content', 'Related content'), 'send' => "{$hit['name']} related"]];
        $other = array_values(array_filter(ora_chars(), fn($c) => $c['character_id'] !== $hit['character_id']));
        if ($other) $acts[] = ['label' => "vs {$other[0]['name']}", 'send' => "{$hit['name']} vs {$other[0]['name']}"];
        $set('character', $reply, [ora_character_card($hit)], $acts);
        return $out;
    }

    $cat = ora_find_category($text);

    /* ---------- 11. Character lists (role / fandom filters) ---------- */
    if (ora_has($text, ['character', 'characters', 'hero', 'heroes', 'villain', 'villains', 'antagonist', 'protagonist', 'anti hero', 'antihero', 'who is', 'who are', 'cast', 'icons', 'singer', 'sorceress', 'pilot'])) {
        $chars = ora_chars();
        $roles = [
            'villain' => ['villain', 'villains', 'antagonist', 'evil', 'bad guy'],
            'anti' => ['anti hero', 'antihero', 'anti-hero'],
            'hero' => ['hero', 'heroes', 'protagonist', 'main character', 'lead'],
            'singer' => ['singer', 'vocalist', 'idol', 'kpop idol'],
            'magic' => ['sorceress', 'sorcerer', 'mage', 'witch', 'magic', 'wizard'],
            'pilot' => ['pilot', 'mech'],
        ];
        $roleRe = ['villain' => '/villain|antagonist|anti-?hero/i', 'anti' => '/anti-?hero/i', 'hero' => '/protagonist|hero|lead/i', 'singer' => '/vocal|singer|idol/i', 'magic' => '/sorce|mage|witch|wizard|magic/i', 'pilot' => '/pilot|mech/i'];
        $role = null;
        foreach ($roles as $k => $kw) if (ora_has($text, $kw)) { $role = $k; break; }
        $list = $chars; $intro = $L('Here are the icons of the universe — tap any card for a full cinematic profile.', 'Yeh rahe universe ke icons — kisi bhi card par tap kar ke poora profile dekhein.');
        if ($cat) {
            $list = array_values(array_filter($list, fn($c) => (int)$c['category_id'] === (int)$cat['category_id']));
            $intro = $list ? $L("Characters from **{$cat['name']}**:", "**{$cat['name']}** ke characters:") : $L("No **{$cat['name']}** characters yet — here's the full line-up:", "**{$cat['name']}** ka abhi koi character nahi — yeh poori line-up hai:");
            if (!$list) $list = $chars;
        }
        if ($role) {
            $f = array_values(array_filter($list, fn($c) => preg_match($roleRe[$role], $c['role'] . ' ' . $c['short_desc'])));
            if ($f) { $list = $f; $intro = count($f) === 1 ? $L("That would be **{$f[0]['name']}** ({$f[0]['role']}, *" . character_fandom($f[0]) . '*).', "Woh **{$f[0]['name']}** hai ({$f[0]['role']}, *" . character_fandom($f[0]) . '*).') : $L('These fit the bill:', 'Yeh match karte hain:'); if (count($f) === 1) $focusChar($f[0]); }
            elseif ($role === 'villain') {
                $anti = array_values(array_filter($chars, fn($c) => preg_match('/anti|dark|shadow/i', $c['role'] . ' ' . $c['short_desc'])));
                $intro = $L('There\'s no pure villain in the line-up yet' . ($anti ? " — the closest is **{$anti[0]['name']}** ({$anti[0]['role']})." : '.'), 'Line-up mein abhi koi pakka villain nahi' . ($anti ? " — sab se qareeb **{$anti[0]['name']}** ({$anti[0]['role']}) hai." : '.'));
                if ($anti) { $list = $anti; $focusChar($anti[0]); }
            }
        }
        if (!$role && $chars && ora_has($text, ['popular', 'famous', 'top', 'best', 'favourite', 'favorite', 'most'])) {
            $intro = $L("The most viewed character right now is **{$list[0]['name']}** (" . number_format((int)$list[0]['views']) . ' views). Here\'s the line-up:', "Is waqt sab se zyada dekha gaya character **{$list[0]['name']}** hai (" . number_format((int)$list[0]['views']) . ' views):');
            $focusChar($list[0]);
        }
        if (!$chars) $intro = $L('No character profiles have been published yet.', 'Abhi koi character profile publish nahi hua.');
        $remember('character', array_column($list, 'character_id'), 6);
        $acts = [['label' => $L('Go to characters', 'Characters section'), 'go' => '#characters']];
        if (count($chars) >= 2) $acts[] = ['label' => "{$chars[0]['name']} vs {$chars[1]['name']}", 'send' => "{$chars[0]['name']} vs {$chars[1]['name']}"];
        $acts[] = ['label' => 'Quiz me', 'send' => 'Quiz me'];
        if (count($list) > 6) $acts[] = ['label' => $L('Show more', 'Aur dikhao'), 'send' => 'show more'];
        $set('characters', $intro, array_map('ora_character_card', array_slice($list, 0, 6)), $acts);
        return $out;
    }

    /* ---------- 12. Events (city, date window, countdown) ---------- */
    $events = ora_upcoming_events();
    $evHit = null; $evScore = 0;
    foreach ($events as $e) {
        $tw = array_values(array_filter(ora_tokens(ora_norm($e['title'])), fn($w) => mb_strlen($w) >= 4 && !in_array($w, ORA_STOP, true) && !preg_match('/^\d+$/', $w)));
        $s = 0;
        foreach ($tw as $w) foreach (ora_tokens($text) as $t) if (ora_close($t, $w)) { $s++; break; }
        if ($s >= 2 && $s > $evScore) { $evScore = $s; $evHit = $e; }
    }
    $isEventQ = ora_has($text, ['event', 'events', 'convention', 'conventions', 'con', 'meetup', 'meet up', 'fest', 'festival', 'screening', 'concert', 'tour dates', 'watch party', 'near me', 'happening', 'premiere', 'expo']);
    if ($evHit && !$isEventQ && ora_find_characters($text)) $evHit = null;
    if ($evHit) {
        $d = ora_days_until($evHit['event_date']);
        $reply = "📅 **{$evHit['title']}** — " . fmt_date($evHit['event_date'], 'l, F j, Y') . ' (' . ora_when($d, $lang) . ")\n📍 {$evHit['venue']}, {$evHit['city']}\n" . ora_excerpt($evHit['description'], 160);
        $acts = [['label' => $L('Add to my calendar', 'Calendar mein add karo'), 'calendar' => (int)$evHit['event_id']], ['label' => $L('Open events & map', 'Events & map'), 'go' => '#events']];
        if (!empty($evHit['ticket_url'])) $acts[] = ['label' => $L('Tickets', 'Tickets'), 'url' => $evHit['ticket_url']];
        $ctx['event'] = (int)$evHit['event_id'];
        $set('event', $reply, [ora_event_card($evHit)], $acts);
        return $out;
    }
    $cities = array_values(array_unique(array_column($events, 'city')));
    $city = null;
    foreach ($cities as $ct) if (ora_score($text, $ct) >= 7) { $city = $ct; break; }
    $range = ora_date_range($text);
    if ($isEventQ || ($city && !$cat) || ($range && preg_match('/\b(what|whats|anything|kya|happening|going on|plans)\b/u', $text))) {
        $rows = $events;
        if ($city) $rows = array_values(array_filter($rows, fn($e) => $e['city'] === $city));
        if ($cat) { $f = array_values(array_filter($rows, fn($e) => (int)$e['category_id'] === (int)$cat['category_id'])); if ($f) $rows = $f; }
        if ($range) $rows = array_values(array_filter($rows, fn($e) => $e['event_date'] >= $range[0]->format('Y-m-d') && $e['event_date'] <= $range[1]->format('Y-m-d')));
        $nextOnly = preg_match('/\b(next event|next one|nearest event|soonest|agla event)\b/u', $text) || (ora_has($text, 'next') && !$range && !$city);
        if ($nextOnly && $rows) $rows = [$rows[0]];
        if (!$rows) {
            $where = trim(($city ? $L(" in $city", " $city mein") : '') . ($range ? ' ' . $range[2] : ''));
            $set('events', $L("No upcoming events$where right now.", "Abhi$where koi event nahi.") . ($events ? ' ' . $L('The next one is', 'Agla event') . " **{$events[0]['title']}** — " . fmt_date($events[0]['event_date'], 'M j') . " ({$events[0]['city']})." : ''),
                $events ? [ora_event_card($events[0])] : [], [['label' => $L('Open events', 'Events kholo'), 'go' => '#events'], ['label' => $L('All upcoming events', 'Saare events'), 'send' => 'Upcoming events']]);
            return $out;
        }
        $show = array_slice($rows, 0, 5);
        $lines = array_map(fn($e) => '• **' . $e['title'] . '** — ' . fmt_date($e['event_date'], 'D, M j') . ' · ' . $e['venue'] . ($e['city'] !== $e['venue'] && !str_contains($e['venue'], $e['city']) ? ', ' . $e['city'] : '') . ' (' . ora_when(ora_days_until($e['event_date']), $lang) . ')', $show);
        $label = trim(($cat ? $cat['name'] . ' ' : '') . ($city ? $L("in **$city**", "**$city** mein") : '') . ($range ? ' ' . $range[2] : ''));
        $intro = $nextOnly ? $L('The next event is:', 'Agla event:') : ($label !== '' ? $L("Upcoming $label:", "Aane wale events $label:") : $L('Here\'s what\'s coming up:', 'Yeh aane wale events hain:'));
        if (ora_has($text, ['near me', 'nearby', 'close to me', 'qareeb'])) $intro = $L('Tap **Near me** in the Events section and I\'ll sort by distance using your location. Meanwhile, the next events are:', 'Events section mein **Near me** dabayein — location se distance ke hisaab se sort ho jayenge. Filhaal agle events:');
        $acts = [['label' => $L('Open events & map', 'Events & map'), 'go' => '#events']];
        if (count($show) === 1) { array_unshift($acts, ['label' => $L('Add to my calendar', 'Calendar mein add karo'), 'calendar' => (int)$show[0]['event_id']]); $ctx['event'] = (int)$show[0]['event_id']; }
        foreach (array_slice(array_values(array_diff($cities, [$city, 'Online', 'Multiple Cities'])), 0, 2) as $ct) $acts[] = ['label' => $L("Events in $ct", "$ct events"), 'send' => "Events in $ct"];
        if (!$range) $acts[] = ['label' => $L('This month', 'Is mahine'), 'send' => 'Events this month'];
        $set('events', $intro . "\n" . implode("\n", $lines), array_map('ora_event_card', $show), $acts);
        return $out;
    }

    /* ---------- 13. Releases ---------- */
    if (ora_has($text, ['release', 'releases', 'releasing', 'coming soon', 'upcoming', 'new season', 'next season', 'launch', 'premiere', 'comeback', 'drop date', 'out when', 'aane wali'])) {
        $params = []; $where = 'r.release_date >= CURDATE()';
        if ($cat) { $where .= ' AND r.category_id = ?'; $params[] = $cat['category_id']; }
        if ($range) { $where .= ' AND r.release_date BETWEEN ? AND ?'; $params[] = $range[0]->format('Y-m-d'); $params[] = $range[1]->format('Y-m-d'); }
        $rows = q_all("SELECT r.*, cat.name AS category FROM releases r JOIN categories cat USING(category_id) WHERE $where ORDER BY r.release_date LIMIT 6", $params);
        if (!$rows && ($cat || $range)) $rows = q_all('SELECT r.*, cat.name AS category FROM releases r JOIN categories cat USING(category_id) WHERE r.release_date >= CURDATE() ORDER BY r.release_date LIMIT 6');
        if ($rows) {
            $lines = array_map(fn($r) => '• **' . $r['title'] . '** (' . $r['kind'] . ') — ' . fmt_date($r['release_date']) . ' · ' . ora_when(ora_days_until($r['release_date']), $lang), $rows);
            $set('releases', $L('🚀 Upcoming releases' . ($cat ? " in **{$cat['name']}**" : '') . ':', '🚀 Aane wali releases' . ($cat ? " (**{$cat['name']}**)" : '') . ':') . "\n" . implode("\n", $lines), [],
                [['label' => $L('See the release calendar', 'Release calendar'), 'go' => '#merch'], ['label' => $L('Upcoming events', 'Aane wale events'), 'send' => 'Upcoming events']]);
            return $out;
        }
    }

    /* ---------- 14. Merch (price filters) ---------- */
    if (ora_has($text, ['merch', 'merchandise', 'buy', 'shop', 'store', 'figure', 'figures', 'hoodie', 'apparel', 'vinyl', 'poster', 'print', 'collectible', 'collectibles', 'product', 'products', 'wishlist', 'gift', 'pre order', 'preorder', 'cheap', 'expensive'])) {
        $rows = q_all('SELECT m.*, cat.name AS category FROM merchandise m JOIN categories cat USING(category_id) ORDER BY m.views DESC');
        $intro = $L('The Merchandise Showcase highlights collector pieces (showcase only — no checkout). Tap any item for details or save it to your wishlist:', 'Merchandise Showcase mein collector items hain (sirf showcase — checkout nahi). Kisi bhi item par tap karein:');
        if ($cat) { $f = array_values(array_filter($rows, fn($m) => (int)$m['category_id'] === (int)$cat['category_id'])); if ($f) { $rows = $f; $intro = $L("**{$cat['name']}** merch:", "**{$cat['name']}** merch:"); } }
        foreach (['figure' => 'figure', 'hoodie' => 'apparel', 'apparel' => 'apparel', 'vinyl' => 'vinyl', 'poster' => 'print', 'print' => 'print'] as $kw => $type) {
            if (ora_has($text, [$kw, $kw . 's'])) { $f = array_values(array_filter($rows, fn($m) => stripos($m['item_type'], $type) !== false)); if ($f) { $rows = $f; $intro = $L('Here you go:', 'Yeh lijiye:'); } break; }
        }
        if (preg_match('/\b(under|below|less than|cheaper than|max|upto|up to)\s*\$?\s*(\d+)/u', $text, $pm) || preg_match('/\$?\s*(\d+)\s*(se kam|tak)/u', $plain, $pm2)) {
            $lim = (float)($pm[2] ?? $pm2[1]);
            $f = array_values(array_filter($rows, fn($m) => ora_price($m['price']) > 0 && ora_price($m['price']) <= $lim));
            usort($f, fn($a, $b) => ora_price($a['price']) <=> ora_price($b['price']));
            $intro = $f ? $L("Merch under **\$$lim**:", "**\$$lim** se kam merch:") : $L("Nothing under **\$$lim** right now — the most affordable pieces are:", "**\$$lim** se kam kuch nahi — sab se sasti cheezein:");
            if ($f) $rows = $f; else usort($rows, fn($a, $b) => ora_price($a['price']) <=> ora_price($b['price']));
        } elseif (ora_has($text, ['cheap', 'cheapest', 'affordable', 'budget', 'lowest price'])) {
            usort($rows, fn($a, $b) => ora_price($a['price']) <=> ora_price($b['price']));
            $intro = $L("Most affordable first — starting at **{$rows[0]['price']}**:", "Sab se saste pehle — **{$rows[0]['price']}** se shuru:");
        } elseif (ora_has($text, ['expensive', 'premium', 'priciest', 'luxury', 'rare'])) {
            usort($rows, fn($a, $b) => ora_price($b['price']) <=> ora_price($a['price']));
            $intro = $L('Top-shelf collector pieces:', 'Premium collector items:');
        } elseif (ora_has($text, ['new', 'pre order', 'preorder', 'upcoming'])) {
            $f = array_values(array_filter($rows, fn($m) => (int)$m['is_upcoming'] === 1));
            if ($f) { $rows = $f; $intro = $L('Fresh drops & pre-orders:', 'Naye drops aur pre-orders:'); }
        }
        if (!$rows) $intro = $L('The merch showcase is empty right now.', 'Merch showcase abhi khaali hai.');
        $remember('merch', array_column($rows, 'item_id'), 6);
        $acts = [['label' => $L('Open merch showcase', 'Merch showcase'), 'go' => '#merch'], ['label' => $L('Under $100', '$100 se kam'), 'send' => 'Merch under $100'], ['label' => $L('Cheapest first', 'Saste pehle'), 'send' => 'Cheapest merch']];
        $set('merch', $intro, array_map('ora_merch_card', array_slice($rows, 0, 6)), $acts);
        return $out;
    }

    /* ---------- 15. Content by type (videos, trailers, music, articles) ---------- */
    $types = ['trailer' => ['trailer', 'trailers', 'teaser'], 'video' => ['video', 'videos', 'watch', 'clip', 'clips'], 'audio' => ['music', 'song', 'songs', 'soundtrack', 'ost', 'podcast', 'audio', 'listen'], 'article' => ['article', 'articles', 'read', 'news', 'blog', 'review', 'reviews']];
    $isReco = (bool)preg_match('/\b(recommend|recommendation|suggest|suggestion|what should i|something like|similar to|bored|what to watch|what to read|kya dekhun)\b/u', $text);
    foreach ($types as $type => $kw) {
        if (ora_has($text, $kw) && mb_strlen($plain) < 70 && !$isReco) {
            $params = [$type]; $w = '';
            if ($cat) { $w = ' AND c.category_id=?'; $params[] = $cat['category_id']; }
            $rows = q_all(ora_content_sql() . " AND c.type=?$w ORDER BY " . (ora_has($text, ['trending', 'popular', 'top', 'best', 'most']) ? 'c.popularity DESC' : 'c.release_date DESC'), $params);
            if (!$rows && $cat) $rows = q_all(ora_content_sql() . ' AND c.type=? ORDER BY c.release_date DESC', [$type]);
            if ($rows) {
                $lbl = strtolower(content_type_label($type)) . ($type === 'audio' ? '' : 's');
                $remember('content', array_column($rows, 'content_id'), 6);
                $acts = [['label' => $L("Browse all $lbl", "Saare $lbl"), 'searchType' => $type]];
                if (count($rows) > 6) $acts[] = ['label' => $L('Show more', 'Aur dikhao'), 'send' => 'show more'];
                $set('content', $L('Latest ' . $lbl . ($cat ? " in **{$cat['name']}**" : '') . ' in the library:', 'Library ke naye ' . $lbl . ($cat ? " (**{$cat['name']}**)" : '') . ':'), array_map('ora_content_card', array_slice($rows, 0, 6)), $acts);
                return $out;
            }
        }
    }

    /* ---------- 16. Trending ---------- */
    if (ora_has($text, ['trending', 'popular', 'hot', 'viral', 'top', 'buzz', 'this week', 'whats new', 'latest', 'most viewed'])) {
        $params = []; $w = '';
        if ($cat) { $w = ' AND c.category_id=?'; $params[] = $cat['category_id']; }
        $rows = q_all(ora_content_sql() . "$w ORDER BY c.is_trending DESC, c.popularity DESC LIMIT 18", $params);
        $remember('content', array_column($rows, 'content_id'), 6);
        $acts = [['label' => $L('Open trending', 'Trending kholo'), 'go' => '#explore'], ['label' => $L('Recommend me something', 'Kuch recommend karo'), 'send' => 'Recommend me something']];
        if (count($rows) > 6) $acts[] = ['label' => $L('Show more', 'Aur dikhao'), 'send' => 'show more'];
        $set('trending', $L('🔥 Here\'s what the community is buzzing about' . ($cat ? " in **{$cat['name']}**" : '') . ' right now:', '🔥 Community mein is waqt yeh trending hai' . ($cat ? " (**{$cat['name']}**)" : '') . ':'), array_map('ora_content_card', array_slice($rows, 0, 6)), $acts);
        return $out;
    }

    /* ---------- 17. Recommendations (similar-to, favourites, conversation context) ---------- */
    if ($isReco) {
        $ids = []; $why = '';
        // "something like <title or character>"
        if (!$cat) {
            $best = null; $bs = 0;
            foreach (q_all("SELECT content_id, title, category_id FROM contents WHERE status='published'") as $c) { $s = ora_score($text, $c['title']); if ($s > $bs && $s >= 6) { $bs = $s; $best = $c; } }
            foreach (ora_chars() as $c) { $s = max(ora_score($text, $c['name']), ora_score($text, character_fandom($c))); if ($s > $bs && $s >= 6) { $bs = $s; $best = ['title' => character_fandom($c), 'category_id' => $c['category_id'], 'content_id' => 0]; } }
            if ($best) { $ids = [(int)$best['category_id']]; $why = $L("If you like **{$best['title']}**, try these:", "Agar **{$best['title']}** pasand hai to yeh dekhein:"); $exclude = (int)$best['content_id']; }
        }
        if (!$ids && $cat) { $ids = [(int)$cat['category_id']]; $why = $L("Top picks in **{$cat['name']}**:", "**{$cat['name']}** ke behtareen picks:"); }
        if (!$ids && $uid) {
            $favs = q_all('SELECT c.category_id, c.name FROM user_categories uc JOIN categories c USING(category_id) WHERE uc.user_id=?', [$uid]);
            if ($favs) { $ids = array_map('intval', array_column($favs, 'category_id')); $why = $L('Based on your love for ' . implode(', ', array_column($favs, 'name')) . ', check these out:', implode(', ', array_column($favs, 'name')) . ' ke shauqeen ke liye:'); }
        }
        if (!$ids && !empty($ctx['cat'])) { $ids = [(int)$ctx['cat']]; $why = $L('Since we were just talking about it, you might enjoy:', 'Jis ki abhi baat ho rahi thi, us hisaab se:'); }
        $seen = $ctx['seen'] ?? [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = q_all(ora_content_sql() . " AND c.category_id IN ($in) ORDER BY c.popularity DESC LIMIT 12", $ids);
        } else {
            $rows = q_all(ora_content_sql() . ' ORDER BY c.popularity DESC LIMIT 12');
            $why = $L('Here are the community favourites right now:', 'Is waqt community ki pasandeeda cheezein:');
        }
        if (!empty($exclude)) $rows = array_values(array_filter($rows, fn($r) => (int)$r['content_id'] !== $exclude));
        $fresh = array_values(array_filter($rows, fn($r) => !in_array((int)$r['content_id'], $seen, true)));
        if (count($fresh) >= 3) $rows = $fresh;
        else {   // that shelf is exhausted → widen to the whole library, still skipping what was already shown
            $more = array_values(array_filter(q_all(ora_content_sql() . ' ORDER BY c.popularity DESC LIMIT 40'), fn($r) => !in_array((int)$r['content_id'], $seen, true) && !in_array($r['content_id'], array_column($fresh, 'content_id'))));
            $rows = array_merge($fresh, $more);
            if (count($rows) < 3) { $rows = q_all(ora_content_sql() . ' ORDER BY RAND() LIMIT 5'); $seen = []; }
            elseif ($ids) $why = $L('Branching out a little — fans of that also loved:', 'Thora alag — un fans ko yeh bhi pasand aaya:');
        }
        $rows = array_slice($rows, 0, 5);
        $ctx['seen'] = array_slice(array_merge($seen, array_map('intval', array_column($rows, 'content_id'))), -30);
        $acts = [];
        if (!$uid) $acts[] = ['label' => $L('Sign in for personal picks', 'Personal picks ke liye sign in'), 'do' => 'auth'];
        $acts[] = ['label' => $L('More picks', 'Aur picks'), 'send' => 'Recommend me something'];
        foreach (array_slice(categories(), 0, 3) as $c) if (!$cat || $c['category_id'] !== $cat['category_id']) $acts[] = ['label' => $L('More ', 'Aur ') . $c['name'], 'send' => 'Recommend ' . $c['name']];
        $set('recommend', $rows ? '✨ ' . $why : $L('I don\'t have enough published content to recommend yet.', 'Abhi recommend karne ke liye content kam hai.'), array_map('ora_content_card', $rows), array_slice($acts, 0, 5));
        return $out;
    }

    /* ---------- 18. Fandom / category overview ---------- */
    if ($cat && mb_strlen($plain) < 70) {
        $ctx['cat'] = (int)$cat['category_id'];
        $rows = q_all(ora_content_sql() . ' AND c.category_id=? ORDER BY c.popularity DESC LIMIT 5', [$cat['category_id']]);
        $cc = array_values(array_filter(ora_chars(), fn($c) => (int)$c['category_id'] === (int)$cat['category_id']));
        $ev = array_values(array_filter($events, fn($e) => (int)$e['category_id'] === (int)$cat['category_id']));
        $reply = "**{$cat['name']}** — {$cat['tagline']}. " . ora_excerpt($cat['description'], 140);
        if ($ev) $reply .= "\n📅 " . $L('Next event', 'Agla event') . ": **{$ev[0]['title']}** — " . fmt_date($ev[0]['event_date'], 'M j') . " ({$ev[0]['city']})";
        $set('category', $reply, array_merge(array_map('ora_character_card', array_slice($cc, 0, 2)), array_map('ora_content_card', $rows)),
            [['label' => $L("Search {$cat['name']}", "{$cat['name']} search"), 'searchCategory' => $cat['name']], ['label' => $L("Recommend {$cat['name']}", "{$cat['name']} recommend karo"), 'send' => "Recommend {$cat['name']}"], ['label' => "{$cat['name']} " . $L('trailers', 'trailers'), 'send' => "{$cat['name']} trailers"]]);
        return $out;
    }

    /* ---------- 19. Knowledge base (admin-managed keywords) ---------- */
    $best = null; $bestScore = 0;
    foreach ($faqs as $faq) {
        $score = 0;
        foreach (explode(',', $faq['keywords']) as $kw) if (trim($kw) !== '' && ora_has($text, $kw)) $score += 2 + substr_count(trim($kw), ' ');
        if ($faq['question'] !== '' && ora_score($plain, $faq['question']) >= 8) $score += 3;
        if ($score > $bestScore) { $bestScore = $score; $best = $faq; }
    }
    if ($best) {
        $set('faq', $best['answer'], [], array_slice(ora_default_actions($lang), 0, 3));
        return $out;
    }

    /* ---------- 20. Library search (content, merch, typo tolerant) ---------- */
    $words = array_values(array_filter(ora_tokens($plain), fn($w) => mb_strlen($w) > 3 && !in_array($w, ORA_STOP, true)));
    if ($words) {
        $conds = []; $params = [];
        foreach (array_slice($words, 0, 5) as $i => $w) { $conds[] = "(c.title LIKE :w$i OR c.genre LIKE :g$i OR c.excerpt LIKE :x$i)"; $params["w$i"] = "%$w%"; $params["g$i"] = "%$w%"; $params["x$i"] = "%$w%"; }
        $rows = q_all(ora_content_sql() . ' AND (' . implode(' OR ', $conds) . ') ORDER BY c.popularity DESC LIMIT 5', $params);
        $mconds = []; $mparams = [];
        foreach (array_slice($words, 0, 5) as $i => $w) { $mconds[] = "name LIKE :m$i"; $mparams["m$i"] = "%$w%"; }
        $merch = q_all('SELECT * FROM merchandise WHERE ' . implode(' OR ', $mconds) . ' LIMIT 3', $mparams);
        if ($rows || $merch) {
            $set('search', $L('🔎 I found these in the library:', '🔎 Library mein yeh mila:'), array_merge(array_map('ora_content_card', $rows), array_map('ora_merch_card', $merch)), [['label' => $L('Search "', 'Search "') . ora_excerpt($msg, 28) . '"', 'search' => $msg]]);
            return $out;
        }
    }

    /* ---------- 21. AI mode takes over from here (see ora_is_sure + api/ora.php) ---------- */

    /* ---------- 22. "Did you mean…?" fallback ---------- */
    $vocab = [];
    foreach (ora_chars() as $c) { $vocab[] = [$c['name'], 'Who is ' . $c['name'] . '?']; $vocab[] = [character_fandom($c), 'Who is ' . $c['name'] . '?', $c['name']]; }
    foreach (q_all('SELECT title FROM releases WHERE release_date >= CURDATE()') as $r) $vocab[] = [$r['title'], 'Upcoming releases'];
    foreach (q_all('SELECT name FROM merchandise') as $m) $vocab[] = [$m['name'], 'Show me merch'];
    foreach (categories() as $c) $vocab[] = [$c['name'], $c['name']];
    foreach ($events as $e) $vocab[] = [$e['title'], $e['title']];
    foreach (q_all("SELECT title FROM contents WHERE status='published'") as $c) $vocab[] = [$c['title'], 'Search ' . $c['title']];
    $sugs = [];
    foreach ($vocab as $v) {
        [$label, $send] = $v;
        $s = ora_score($plain, $label);
        if ($s < 5) { similar_text(ora_norm($label), $plain, $pct); if ($pct >= 60) $s = 5; }
        if ($s >= 5) $sugs[$send] = ['s' => max($s, $sugs[$send]['s'] ?? 0), 'label' => $v[2] ?? $label, 'send' => $send];
    }
    $sugs = array_values($sugs);
    usort($sugs, fn($a, $b) => $b['s'] <=> $a['s']);
    if ($sugs) {
        $acts = array_map(fn($x) => str_starts_with($x['send'], 'Search ') ? ['label' => $x['label'], 'search' => $x['label']] : ['label' => $x['label'], 'send' => $x['send']], array_slice($sugs, 0, 3));
        $set('didyoumean', $L("I'm not 100% sure what you meant — did you mean one of these?", 'Main poori tarah samajh nahi paya — kya aap ka matlab in mein se koi hai?'), [], $acts);
        $out['answered'] = false;
        return $out;
    }
    $set('fallback', $L("I'm still learning that one! I can help with characters, trending content, events, releases, merch and recommendations — or say \"give me a tour\".",
        'Yeh abhi seekh raha hoon! Main characters, trending content, events, releases, merch aur recommendations mein madad kar sakta hoon — ya "tour karao" likhein.'),
        [], array_merge(ora_default_actions($lang), [['label' => $L('Search the site', 'Site search'), 'search' => $msg], ['label' => $L('Send feedback', 'Feedback dein'), 'do' => 'feedback']]));
    $out['answered'] = false;
    return $out;
}

/* ======================================================================
 *  Router — when does the rule engine answer, and when does the AI?
 *  Rules are instant and show rich cards, so they keep the questions they
 *  understand for sure (site entities, chips, quiz, tour…). Everything else
 *  — general knowledge, coding, homework, writing, advice, open questions —
 *  goes to the AI when AI mode is on.
 * ====================================================================== */
function ora_is_sure(array $r, string $msg): bool
{
    static $commands = ['whats trending', 'trending', 'trending now', 'show me characters', 'meet the characters', 'upcoming events', 'quiz me', 'play trivia', 'surprise me',
        'give me a tour', 'tour', 'tell me a fun fact', 'fun fact', 'tell me a joke', 'recommend me something', 'cheapest merch', 'merch under 100', 'merch under $100',
        'events this month', 'show more', 'next', 'stop quiz', 'stop tour', 'stop', 'upcoming releases', 'show me merch', 'merch', 'characters', 'events',
        'trending kya hai', 'characters dikhao', 'events kab hain', 'quiz khelo', 'tour karao', 'aur dikhao', 'help', 'menu', 'who are you', 'what can you do'];
    $plain = ora_norm($msg);
    if (in_array($plain, $commands, true)) return true;
    $text  = ora_expand($plain);
    $words = count(ora_tokens($plain));
    $intent = $r['intent'] ?? 'fallback';
    $cat = ora_find_category($text) !== null;
    $siteThing = $cat;
    if (!$siteThing) {
        foreach (ora_chars() as $c) if (max(ora_score($text, $c['name']), ora_score($text, character_fandom($c))) >= 6) { $siteThing = true; break; }
    }
    if (!$siteThing) {
        foreach (q_all("SELECT title FROM contents WHERE status='published'") as $c) if (ora_score($text, $c['title']) >= 8) { $siteThing = true; break; }
    }
    switch ($intent) {
        case 'quiz': case 'tour': case 'more': case 'empty': case 'account': case 'compare': case 'event':
            return true;
        case 'character':  return $words <= 8 || $siteThing;
        case 'characters': return $cat || $words <= 4;
        case 'events':
            $cityHit = false;
            foreach (array_unique(array_column(ora_upcoming_events(), 'city')) as $ct) if (ora_score($text, $ct) >= 7) $cityHit = true;
            return $cityHit || $cat || ($words <= 5 && ora_has($text, ['event', 'events', 'convention', 'meetup', 'fest', 'festival', 'screening', 'watch party', 'cosplay', 'expo']));
        case 'releases':  return $cat || $siteThing || $words <= 3;
        case 'merch':     return ora_has($text, ['merch', 'merchandise', 'collectible', 'collectibles', 'wishlist', 'showcase']) || ($siteThing && $words <= 6);
        case 'content':   return $cat || $siteThing || (bool)preg_match('/^((show|give) me |latest |new |top |best )?(the )?(videos?|trailers?|music|songs?|soundtracks?|articles?|podcasts?|clips?)$/u', $plain);
        case 'trending':  return $cat || $words <= 3;
        case 'recommend': return $cat || $siteThing || $words <= 3;
        case 'category':  return $words <= 3;
        case 'stats':     return (bool)preg_match('/\b(character|event|article|video|trailer|song|music|audio|merch|fandom|categor|member|user|release|site|fan hub)/u', $text);
        case 'greeting':  return $words <= 3;
        case 'thanks': case 'bye': case 'smalltalk': return $words <= 4;
        case 'joke':      return $words <= 4;
        case 'fact': case 'surprise': return $words <= 2;
        case 'help':      return $words <= 2 || (bool)preg_match('/^(who are you|what are you|whats your name|your name|what can you do)$/u', $plain);
        case 'faq':
            foreach (q_all("SELECT question FROM chatbot_faq WHERE question <> ''") as $f) if (ora_norm($f['question']) === $plain) return true;
            return $words <= 3;
        default:          return false;   // search, didyoumean, fallback → AI
    }
}

/* ======================================================================
 *  AI mode — any question, like ChatGPT / Claude / Gemini.
 *  Providers: Gemini, Claude, OpenAI and any OpenAI-compatible server.
 * ====================================================================== */
function ora_ai_providers(): array
{
    return [
        'gemini'     => ['label' => 'Google Gemini', 'kind' => 'gemini', 'model' => 'gemini-2.5-flash', 'base' => 'https://generativelanguage.googleapis.com/v1beta', 'keys' => 'https://aistudio.google.com/apikey', 'note' => 'Free tier available', 'web' => true],
        'anthropic'  => ['label' => 'Anthropic Claude', 'kind' => 'anthropic', 'model' => 'claude-haiku-5-5', 'base' => 'https://api.anthropic.com/v1', 'keys' => 'https://console.anthropic.com/settings/keys', 'note' => 'Paid', 'web' => true],
        'openai'     => ['label' => 'OpenAI', 'kind' => 'openai', 'model' => 'gpt-4o-mini', 'base' => 'https://api.openai.com/v1', 'keys' => 'https://platform.openai.com/api-keys', 'note' => 'Paid', 'web' => false],
        'groq'       => ['label' => 'Groq (Llama)', 'kind' => 'openai', 'model' => 'llama-3.3-70b-versatile', 'base' => 'https://api.groq.com/openai/v1', 'keys' => 'https://console.groq.com/keys', 'note' => 'Free tier available', 'web' => false],
        'openrouter' => ['label' => 'OpenRouter', 'kind' => 'openai', 'model' => 'openrouter/auto', 'base' => 'https://openrouter.ai/api/v1', 'keys' => 'https://openrouter.ai/keys', 'note' => 'Many models, some free', 'web' => false],
        'custom'     => ['label' => 'Custom (OpenAI-compatible)', 'kind' => 'openai', 'model' => 'llama3.1', 'base' => 'http://localhost:11434/v1', 'keys' => '', 'note' => 'e.g. a local Ollama server', 'web' => false],
    ];
}

/** Effective AI settings: config.php / environment first, then Admin → Chatbot */
function ora_ai_config(): array
{
    $key = CHAT_AI_KEY !== '' ? CHAT_AI_KEY : setting('chatbot_ai_key', '');
    $provider = CHAT_AI_PROVIDER !== '' ? CHAT_AI_PROVIDER : setting('chatbot_ai_provider', '');
    if ($provider === '') {   // guess from the key format
        $provider = str_starts_with($key, 'sk-ant-') ? 'anthropic' : (str_starts_with($key, 'AIza') ? 'gemini' : (str_starts_with($key, 'gsk_') ? 'groq'
            : (str_starts_with($key, 'sk-or-') ? 'openrouter' : (str_starts_with($key, 'sk-') ? 'openai' : 'gemini'))));
    }
    $all = ora_ai_providers();
    $p = $all[$provider] ?? $all['custom'];
    $model = CHAT_AI_MODEL !== '' ? CHAT_AI_MODEL : (setting('chatbot_ai_model', '') ?: $p['model']);
    $base  = rtrim(CHAT_AI_BASE_URL !== '' ? CHAT_AI_BASE_URL : (setting('chatbot_ai_base', '') ?: $p['base']), '/');
    return ['provider' => $provider, 'label' => $p['label'], 'kind' => $p['kind'], 'key' => $key, 'model' => $model, 'base' => $base,
            'web' => $p['web'] && setting('chatbot_ai_web', '0') === '1'];
}

/** True when AI mode is configured and switched on */
function ora_ai_enabled(): bool
{
    if (!function_exists('curl_init') || setting('chatbot_ai', '1') !== '1') return false;
    $c = ora_ai_config();
    return $c['key'] !== '' || $c['provider'] === 'custom';
}

function ora_ai_system_prompt(?array $user, array $ctx, string $lang): string
{
    $name  = setting('chatbot_name', 'ORA');
    $site  = setting('platform_name', 'Fan Hub Plus');
    $cats  = implode(', ', array_column(categories(), 'name'));
    $chars = implode("\n", array_map(fn($c) => "- {$c['name']} ({$c['fandom_label']}, {$c['role']}, " . number_format((int)$c['views']) . " views): " . ora_excerpt($c['bio'] ?: $c['short_desc'], 260), array_slice(ora_chars(), 0, 20)));
    $cont  = implode("\n", array_map(fn($c) => "- {$c['title']} [" . content_type_label($c['type']) . ", {$c['category']}" . ($c['genre'] ? ", {$c['genre']}" : '') . ']: ' . ora_excerpt($c['excerpt'], 110), q_all(ora_content_sql() . ' ORDER BY c.popularity DESC LIMIT 30')));
    $events = implode("\n", array_map(fn($e) => "- {$e['title']} — {$e['event_date']} (" . fmt_date($e['event_date'], 'l') . "), {$e['venue']}, {$e['city']}: " . ora_excerpt($e['description'], 100), array_slice(ora_upcoming_events(), 0, 12)));
    $rels  = implode("\n", array_map(fn($r) => "- {$r['title']} ({$r['kind']}, {$r['category']}) — {$r['release_date']}", q_all('SELECT r.*, cat.name AS category FROM releases r JOIN categories cat USING(category_id) WHERE r.release_date >= CURDATE() ORDER BY r.release_date LIMIT 10')));
    $merch = implode("\n", array_map(fn($m) => "- {$m['name']} ({$m['item_type']}, {$m['fandom_label']}) {$m['price']}" . ($m['tag'] ? " — {$m['tag']}" : ''), q_all('SELECT * FROM merchandise ORDER BY views DESC LIMIT 12')));
    $faq   = implode("\n", array_map(fn($f) => '- ' . ($f['question'] ? $f['question'] . ' → ' : '') . $f['answer'], q_all('SELECT question, answer FROM chatbot_faq ORDER BY sort_order LIMIT 20')));
    $focus = '';
    if (!empty($ctx['char'])) foreach (ora_chars() as $c) if ((int)$c['character_id'] === (int)$ctx['char']) $focus = "The conversation was recently about the character {$c['name']}.\n";
    $web = ora_ai_config()['web'];

    return "You are $name, the AI assistant built into $site — a fandom portal covering $cats. Today is " . date('l, F j, Y') . ', ' . date('g:i A') . " (Pakistan time).\n\n"
        . "You are a fully capable general-purpose assistant, like ChatGPT, Claude or Gemini. Answer ANY question the user asks: general knowledge, science, history, geography, maths (show the steps), "
        . "programming (complete, working code in fenced blocks with the language name), writing and editing, emails and essays, studies and homework, translation, explanations, summaries, advice, recipes, "
        . "health and fitness basics, brainstorming and creative writing. Never say you can only talk about fandoms or this website.\n\n"
        . "Formatting: use Markdown — short paragraphs, **bold** for key words, bullet or numbered lists, ### headings for longer answers, tables when comparing, fenced code blocks for code. "
        . "Match the length to the question: a quick fact gets one or two sentences; a how-to, explanation or code request gets a complete, well-structured answer. Don't pad.\n\n"
        . 'Language: ' . ($lang === 'ur' ? 'the user is writing in Urdu / Roman Urdu — reply in the same script they used (Roman Urdu if they typed Urdu in English letters).' : 'reply in the language the user writes in — English, Urdu (Urdu script) or Roman Urdu (Urdu in English letters).') . "\n\n"
        . "About this website: when a question is about $site — its characters, articles, videos, trailers, music, events, releases, merch, features or account help — use the SITE DATA below as the source of truth, "
        . "and never invent site events, dates, prices or features. Write site items by their exact names so the site can show cards for them. For questions unrelated to the site, just answer normally.\n\n"
        . ($web ? "You can search the web: use it for news, live scores, prices, weather and anything recent, and mention the source.\n\n"
                : "You have no live internet access. For very recent events, prices or news, share what you know and say it may be out of date.\n\n")
        . "Be honest about uncertainty, politely decline harmful or dangerous requests, and suggest a professional for serious medical, legal or financial decisions.\n"
        . ($user ? 'The user is a signed-in member named ' . explode(' ', trim($user['name']))[0] . ".\n" : "The user is a visitor who is not signed in (they can sign in from the navbar to bookmark and personalise).\n")
        . $focus
        . "\n=== SITE DATA ===\nSite features: fandom explorer (8 fandoms), advanced search (Ctrl+K) with filters, character profiles with timelines, Watch & Listen (videos, trailers, soundtracks, ratings), events with map, 'Near me' and calendar saving, "
        . "release calendar, merchandise showcase (display only — no checkout), member dashboard (bookmarks with notes, favourite fandoms, fan article submissions), light/dark theme and font size controls, feedback form.\n"
        . "Characters:\n$chars\nPopular content:\n$cont\nUpcoming events:\n$events\nUpcoming releases:\n$rels\nMerch:\n$merch\nHelp answers:\n$faq\n=== END SITE DATA ===";
}

/** Earlier turns of this conversation (oldest first) for context */
function ora_ai_history(?array $user, int $turns = 8): array
{
    $after = (int)($_SESSION['chat_cleared_after'] ?? 0);
    $rows = $user
        ? q_all('SELECT message, response FROM (SELECT query_id, message, response FROM chatbot_queries WHERE user_id=? AND query_id > ? ORDER BY query_id DESC LIMIT ' . $turns . ') t ORDER BY query_id', [(int)$user['user_id'], $after])
        : q_all('SELECT message, response FROM (SELECT query_id, message, response FROM chatbot_queries WHERE session_id=? AND query_id > ? ORDER BY query_id DESC LIMIT ' . $turns . ') t ORDER BY query_id', [$_SESSION['chat_sid'] ?? '', $after]);
    $out = [];
    foreach ($rows as $h) {
        if (trim($h['message']) === '' || trim($h['response']) === '') continue;
        $out[] = ['role' => 'user', 'content' => mb_substr($h['message'], 0, 4000)];
        $out[] = ['role' => 'assistant', 'content' => mb_substr($h['response'], 0, 6000)];
    }
    return $out;
}

/**
 * Ask the configured AI and stream the answer.
 *   $image   = null | ['mime' => 'image/png', 'data' => base64]
 *   $onDelta = function (string $delta) — called as text arrives
 * Returns ['ok' => bool, 'text' => string, 'error' => string]
 */
function ora_ai_stream(string $msg, ?array $user, array $ctx, string $lang, ?array $image, callable $onDelta): array
{
    $c = ora_ai_config();
    $system = ora_ai_system_prompt($user, $ctx, $lang);
    $turns = ora_ai_history($user);
    $prompt = $msg !== '' ? $msg : 'Describe this image and answer anything useful about it.';
    $maxTokens = 2500;

    if ($c['kind'] === 'anthropic') {
        $last = $image ? [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data']]], ['type' => 'text', 'text' => $prompt]] : $prompt;
        $messages = array_merge($turns, [['role' => 'user', 'content' => $last]]);
        $body = ['model' => $c['model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $messages, 'stream' => true];
        if ($c['web']) $body['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]];
        $url = $c['base'] . '/messages';
        $headers = ['content-type: application/json', 'x-api-key: ' . $c['key'], 'anthropic-version: 2023-06-01'];
    } elseif ($c['kind'] === 'gemini') {
        $contents = array_map(fn($t) => ['role' => $t['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $t['content']]]], $turns);
        $parts = [['text' => $prompt]];
        if ($image) array_unshift($parts, ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['data']]]);
        $contents[] = ['role' => 'user', 'parts' => $parts];
        $body = ['system_instruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.7]];
        if ($c['web']) $body['tools'] = [['google_search' => new stdClass()]];
        $url = $c['base'] . '/models/' . rawurlencode($c['model']) . ':streamGenerateContent?alt=sse';
        $headers = ['content-type: application/json', 'x-goog-api-key: ' . $c['key']];
    } else {   // OpenAI and compatible servers
        $last = $image ? [['type' => 'text', 'text' => $prompt], ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . $image['data']]]] : $prompt;
        $messages = array_merge([['role' => 'system', 'content' => $system]], $turns, [['role' => 'user', 'content' => $last]]);
        $body = ['model' => $c['model'], 'messages' => $messages, 'stream' => true, 'max_tokens' => $maxTokens, 'temperature' => 0.7];
        $url = $c['base'] . '/chat/completions';
        $headers = ['content-type: application/json'];
        if ($c['key'] !== '') $headers[] = 'Authorization: Bearer ' . $c['key'];
        if ($c['provider'] === 'openrouter') { $headers[] = 'HTTP-Referer: ' . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : 'http://localhost'); $headers[] = 'X-Title: Fan Hub Plus'; }
    }

    $status = 0; $buf = ''; $text = ''; $errBody = ''; $streamErr = '';
    $handle = function (string $payload) use ($c, &$text, &$streamErr, $onDelta) {
        if ($payload === '' || $payload === '[DONE]') return;
        $j = json_decode($payload, true);
        if (!is_array($j)) return;
        $d = '';
        if ($c['kind'] === 'anthropic') {
            if (($j['type'] ?? '') === 'content_block_delta' && ($j['delta']['type'] ?? '') === 'text_delta') $d = (string)$j['delta']['text'];
            elseif (($j['type'] ?? '') === 'error') $streamErr = (string)($j['error']['message'] ?? 'error');
        } elseif ($c['kind'] === 'gemini') {
            foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) if (isset($p['text']) && empty($p['thought'])) $d .= $p['text'];
            if (isset($j['error'])) $streamErr = (string)($j['error']['message'] ?? 'error');
        } else {
            $d = (string)($j['choices'][0]['delta']['content'] ?? '');
            if (isset($j['error'])) $streamErr = is_array($j['error']) ? (string)($j['error']['message'] ?? 'error') : (string)$j['error'];
        }
        if ($d !== '') {
            $text .= $d;
            $onDelta($d);
        }
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 150,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$status, &$buf, &$errBody, $handle) {
            if (connection_aborted()) return 0;              // visitor pressed Stop → stop paying for tokens
            if ($status >= 400) { $errBody .= $chunk; return strlen($chunk); }
            $buf .= $chunk;
            while (($p = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $p), "\r");
                $buf = substr($buf, $p + 1);
                if (str_starts_with($line, 'data:')) $handle(trim(substr($line, 5)));
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($buf !== '' && str_starts_with(trim($buf), 'data:')) $handle(trim(substr(trim($buf), 5)));

    if ($status >= 400 || ($ok === false && $text === '')) {
        $detail = $errBody !== '' ? (json_decode($errBody, true)['error']['message'] ?? (json_decode($errBody, true)[0]['error']['message'] ?? mb_substr($errBody, 0, 300))) : $curlErr;
        error_log("ORA AI error ({$c['provider']}): HTTP $status " . $detail);
        return ['ok' => false, 'text' => $text, 'error' => ora_ai_error_message($status, (string)$detail)];
    }
    if ($text === '' && $streamErr !== '') { error_log("ORA AI stream error: $streamErr"); return ['ok' => false, 'text' => '', 'error' => ora_ai_error_message(500, $streamErr)]; }
    if (trim($text) === '') return ['ok' => false, 'text' => '', 'error' => 'The AI returned an empty answer — please rephrase and try again.'];
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

/** Visitor-friendly wording for provider errors (details go to the PHP error log) */
function ora_ai_error_message(int $status, string $detail): string
{
    if ($status === 401 || $status === 403) return 'The AI key was rejected — the site admin needs to check the API key in Admin → Chatbot.';
    if ($status === 429) return 'The AI is getting too many requests right now. Please wait a moment and try again.';
    if ($status === 404) return 'The AI model name looks wrong — the site admin can fix it in Admin → Chatbot.';
    if ($status >= 500) return 'The AI service is having trouble right now. Please try again in a moment.';
    if ($status === 0) return 'Could not reach the AI service. Please try again.';
    return 'The AI could not answer that (' . ora_excerpt($detail, 120) . ').';
}

/** Cards for site items the AI mentioned by name */
function ora_cards_from_text(string $text): array
{
    $found = [];
    $add = function (string $name, array $card) use ($text, &$found) {
        if (mb_strlen($name) < 4) return;
        $pos = mb_stripos($text, $name);
        if ($pos === false) { $short = trim(preg_split('/\s*[:—–(]\s*/u', $name)[0]); if (mb_strlen($short) >= 8 && $short !== $name) $pos = mb_stripos($text, $short); }
        if ($pos !== false) $found[$card['type'] . $card['id']] = ['pos' => $pos, 'card' => $card];
    };
    foreach (ora_chars() as $c) $add($c['name'], ora_character_card($c));
    foreach (ora_upcoming_events() as $e) $add($e['title'], ora_event_card($e));
    foreach (q_all('SELECT * FROM merchandise') as $m) $add($m['name'], ora_merch_card($m));
    foreach (q_all(ora_content_sql()) as $c) $add($c['title'], ora_content_card($c));
    uasort($found, fn($a, $b) => $a['pos'] <=> $b['pos']);
    return array_slice(array_column(array_values($found), 'card'), 0, 6);
}
