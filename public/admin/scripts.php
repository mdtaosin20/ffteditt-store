<?php
// 🎬 Script Studio: voice-over scripts with a strong hook for the Shorts games (Cup Kick, Action Kick, Player Kick).
// Each script: HOOK (0–2 s) · BUILD · HIT (the ball touches the cup) · END (comment call). Made from ready-made lines
// (works without AI) or ✨ by the AI (the OpenAI key saved in YouTube Manager → AI Replies). A script is never suggested twice (remembered here).
// "Use in My Voice" puts it into the game's 🗣 My Voice on this phone / browser.
require __DIR__ . '/../_boot.php';
$admin = require_login();

$used = fn () => json_decode((string) (setting_get('scr_used') ?? ''), true) ?: [];
$saved = fn () => json_decode((string) (setting_get('scr_saved') ?? ''), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $do = (string) ($_POST['do'] ?? '');
    $j = json_decode((string) ($_POST['data'] ?? ''), true) ?: [];
    if ($do === 'used') {   // a script was copied / used: never suggest its hook again
        $u = $used(); $u[] = mb_substr((string) ($j['key'] ?? ''), 0, 160); setting_set('scr_used', json_encode(array_slice(array_values(array_unique($u)), -3000), JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true]); exit;
    }
    if ($do === 'save' || $do === 'unsave') {
        $s = array_values(array_filter($saved(), fn ($x) => ($x['id'] ?? '') !== ($j['id'] ?? '')));
        if ($do === 'save' && !empty($j['hook'])) array_unshift($s, array_intersect_key($j, array_flip(['id', 'style', 'hook', 'build', 'hit', 'end', 'lang', 'game', 'secs'])));
        setting_set('scr_saved', json_encode(array_slice($s, 0, 200), JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'saved' => array_slice($s, 0, 200)], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($do === 'ai') {
        // uses the OpenAI (or Anthropic) key saved in YouTube Manager → 🤖 AI Replies — even when LUNA's AI answers are switched off
        if (ai_key() === '') { echo json_encode(['error' => 'No AI key saved yet: YouTube Manager → 🤖 AI Replies → save your OpenAI key. The ready-made scripts work without it.']); exit; }
        $langs = ['en' => 'American English', 'es' => 'Spanish', 'pt' => 'Portuguese (Brazil)', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian'];
        $lang = $langs[$j['lang'] ?? 'en'] ?? 'American English';
        $games = ['cupkick' => 'country flag balls (six countries)', 'actionkick' => 'players holding SUBSCRIBE / LIKE / COMMENT / SHARE / BELL / SAVE signs', 'playerkick' => 'famous football players'];
        $what = $games[$j['game'] ?? 'cupkick'] ?? $games['cupkick'];
        $secs = max(6, min(25, (int) ($j['secs'] ?? 12)));
        $np = max(2, min(6, (int) ($j['np'] ?? 6)));
        $budget = (int) round($secs * 2.5);   // spoken words that fit (about 2.5 a second)
        $cap = ['hook' => max(5, (int) round($budget * 0.35)), 'build' => $budget <= 22 ? 0 : (int) round($budget * 0.3), 'hit' => min(5, max(2, (int) round($budget * 0.15))), 'end' => max(5, (int) round($budget * 0.3))];
        $styles = implode(', ', array_slice(array_map(fn ($x) => preg_replace('/[^a-z]/', '', (string) $x), is_array($j['styles'] ?? null) ? $j['styles'] : ['challenge']), 0, 8));
        $power = ['soft', 'strong', 'very strong (200%)', 'crazy strong but honest'][max(0, min(3, (int) ($j['power'] ?? 2)))];
        $names = mb_substr(preg_replace('/[^\p{L}\p{N} ,.\'\-]/u', '', (string) ($j['names'] ?? '')), 0, 120);
        $aud = ($j['aud'] ?? 'all') === 'adult' ? 'teens and adults' : 'everyone — families with kids and adults (keep it simple, kind and family-friendly)';
        $u = array_slice($used(), -40);
        $system = "You write voice-over scripts for 9:16 YouTube Shorts of a football puzzle game: {$what} kick balls at a golden trophy and only ONE ball touches it; viewers must guess which one. "
            . "Audience: viewers in the USA and Europe, {$aud}. Never mention kids or ask children for anything; no personal data, no scary or violent words. "
            . "Each script has 4 parts spoken in order: hook (first 2 seconds, must make people stop scrolling), build (keeps them watching), hit (said exactly when the ball touches the trophy), end (asks to comment the answer / follow). "
            . "There are exactly {$np} balls in the video — if you say a number, say {$np}, never another number. "
            . "Never reveal the answer. Write in {$lang}. The WHOLE script must fit in {$secs} seconds when spoken: at most {$budget} words in total. "
            . "Word limits per part: hook max {$cap['hook']} words, build max {$cap['build']} words" . ($cap['build'] === 0 ? ' (leave build empty "")' : '') . ", hit max {$cap['hit']} words, end max {$cap['end']} words. Count the words. Short and punchy beats long. "
            . "Hook strength: {$power}. Styles to use: {$styles}. No emojis, no hashtags. "
            . 'Reply ONLY with JSON: {"scripts":[{"style":"…","hook":"…","build":"…","hit":"…","end":"…"}]} with 8 scripts, each a different hook.';
        $user = ($names !== '' ? "Names in this video: {$names}.\n" : '') . 'Do not reuse these hooks: ' . implode(' | ', $u);
        @set_time_limit(90);
        $txt = ai_call($system, $user, 1800, 45);
        if ($txt === null) { echo json_encode(['error' => 'The AI did not answer. Check the key in YouTube Manager → 🤖 AI Replies.']); exit; }
        if (!preg_match('/\{.*\}/s', $txt, $m) || !is_array($r = json_decode($m[0], true)) || empty($r['scripts'])) { echo json_encode(['error' => 'The AI answer was not readable — press ✨ again.']); exit; }
        $out = [];
        foreach (array_slice($r['scripts'], 0, 8) as $s) {
            if (!is_array($s) || empty($s['hook'])) continue;
            $c = fn ($k) => mb_substr(trim(strip_tags((string) ($s[$k] ?? ''))), 0, 300);
            $x = ['style' => preg_replace('/[^a-z]/', '', strtolower($c('style'))) ?: 'challenge', 'hook' => $c('hook'), 'build' => $c('build'), 'hit' => $c('hit'), 'end' => $c('end')];
            $wc = fn ($a) => count(preg_split('/\s+/u', trim(implode(' ', [$a['hook'], $a['build'], $a['hit'], $a['end']])), -1, PREG_SPLIT_NO_EMPTY));
            if ($wc($x) > $budget + 2) $x['build'] = '';   // too long: the build-up goes first
            if ($wc($x) > $budget + 2) continue;            // still too long for this length: not shown
            $out[] = $x;
        }
        if (!$out) { echo json_encode(['error' => 'The AI wrote scripts that are too long for ' . $secs . ' seconds — press 🤖 again, or choose a longer length.']); exit; }
        echo json_encode(['scripts' => array_slice($out, 0, 6)], JSON_UNESCAPED_UNICODE); exit;
    }
    echo json_encode(['error' => 'unknown']); exit;
}

page_start('Script Studio', 'scripts', $admin);
?>
<div class="page-head">
  <div>
    <h1>🎬 Script Studio</h1>
    <p class="muted">Voice-over scripts with a strong hook for Cup Kick, Action Kick and Player Kick — made for viewers in the USA and Europe (families and adults). Every script has a HOOK for the first 2 seconds, a BUILD, a line for the moment the ball HITS the cup, and an END that asks for a comment. Press <b>🗣 Use in My Voice</b> and it is ready in the game on this phone.</p>
  </div>
</div>
<div class="callout">💡 Keep these videos <b>“Not made for kids”</b> in YouTube (general audience, family-friendly). Videos marked “made for kids” have comments turned off and earn much less from ads — and these videos live on comments.</div>
<div id="ss" class="ss"></div>
<style>
.ss{display:grid;grid-template-columns:340px 1fr;gap:18px;margin-top:14px}
@media (max-width:900px){.ss{grid-template-columns:1fr}}
.ss .box{background:var(--card,#0f1630);border:1px solid #2a3a6a;border-radius:14px;padding:14px}
.ss .l{font-size:13px;font-weight:700;color:#9fd0ff;margin:12px 0 6px}
.ss select,.ss input[type=text]{width:100%;padding:9px;border-radius:9px;border:1px solid #2a3a6a;background:#0b1230;color:#fff;font-size:14px;box-sizing:border-box}
.ss .chips{display:flex;flex-wrap:wrap;gap:6px}.ss .chip{padding:7px 11px;border-radius:99px;background:#1b2350;font-size:13px;color:#cfe0ff;cursor:pointer;border:0}.ss .chip.on{background:#7c5cff;color:#fff}
.ss .seg{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}.ss .seg button{padding:8px 0;border-radius:9px;background:#1b2350;color:#cfe0ff;font-size:13px;border:0;cursor:pointer}.ss .seg button.on{background:#ffd84a;color:#1a1200;font-weight:800}
.ss .go{width:100%;margin-top:14px;padding:13px;border-radius:12px;border:0;background:linear-gradient(90deg,#7c5cff,#c04cff);color:#fff;font-weight:800;font-size:16px;cursor:pointer}
.ss .go2{background:linear-gradient(90deg,#2563eb,#22c55e)}
.ss .go:disabled{opacity:.6}
.ss .small{font-size:12px;color:#8fa6d6;margin-top:8px;line-height:1.5}
.ss .tabs{display:flex;gap:6px;margin-bottom:10px}.ss .tabs button{padding:7px 12px;border-radius:9px;border:0;background:#1b2350;color:#cfe0ff;cursor:pointer}.ss .tabs button.on{background:#2a4a9a;color:#fff}
.ss .list{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px}
.ss .sc{background:#0f1630;border:1px solid #2a3a6a;border-radius:14px;padding:12px}
.ss .sc.best{border:2px solid #22c55e;box-shadow:0 0 18px #22c55e33}
.ss .sh{display:flex;align-items:center;gap:8px;font-weight:800}.ss .sh .s1{margin-left:auto;font-size:11px;background:#14532d;color:#bbf7d0;padding:3px 9px;border-radius:99px}
.ss .pt{display:flex;gap:8px;margin-top:7px;font-size:14px;line-height:1.4}
.ss .pt b{flex:0 0 70px;font-size:10.5px;padding:3px 0;border-radius:6px;text-align:center;height:fit-content;color:#fff}
.ss .h b{background:#ff1f3d}.ss .u b{background:#2563eb}.ss .x b{background:#f59e0b;color:#111}.ss .e b{background:#16a34a}
.ss .pt div[contenteditable]{flex:1;outline:none;border-radius:6px;padding:1px 3px}.ss .pt div[contenteditable]:empty::before{content:'— (not needed for this length · tap to add)';color:#5f74a6;font-size:12px}.ss .pt div[contenteditable]:focus{background:#0b1230}
.ss .meta{font-size:12px;color:#8fa6d6;margin-top:8px}
.ss .bt{display:flex;gap:6px;margin-top:10px}.ss .bt button{flex:1;padding:8px 0;border-radius:9px;border:0;background:#1f2b5c;color:#fff;font-size:13px;font-weight:700;cursor:pointer}.ss .bt .v{background:#7c5cff}.ss .bt .st{flex:0 0 44px}.ss .bt .st.on{background:#a16207}
.ss .note{font-size:13px;color:#fbbf24;margin-bottom:10px;min-height:16px}
.ss .ok{color:#4ade80}
</style>
<script>
window.SS = { csrf: <?= json_encode(csrf_token()) ?>, saved: <?= json_encode($saved(), JSON_UNESCAPED_UNICODE) ?>, used: <?= json_encode(array_slice($used(), -3000), JSON_UNESCAPED_UNICODE) ?>, ai: <?= ai_key() !== '' ? 'true' : 'false' ?> };
</script>
<script src="scripts.js?v=2"></script>
<?php page_end();
