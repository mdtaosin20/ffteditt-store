<?php
/**
 * 🔎 Video Finder (Admin → Video Finder): pictures taken from a short video (in the owner's browser) are
 *  - 🤖 asked to Google Cloud Vision "Web Detection" (needs a Vision API key; about 1,000 pictures a month free) —
 *    which web pages show the same / a very similar picture; the page sorts them by platform, and
 *  - 🔍 offered as "Search in Google Lens" buttons (free; the owner looks at the results).
 * The pictures are kept on the panel for a day (Google Lens needs a link to them), then removed.
 */
declare(strict_types=1);

function vf_dir(): string { $d = DATA_DIR . '/finder'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function vf_key(): string { return (string) (setting_get('vision_key') ?? ''); }
function vf_sig(string $name): string { return substr(hash_hmac('sha256', 'finder:' . $name, app_secret()), 0, 24); }
function vf_url(string $name): string
{
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ffteditt.store'));
    return 'https://' . $host . '/api/finder.php?' . http_build_query(['f' => $name, 'sig' => vf_sig($name)]);
}
function vf_clean(): void
{
    foreach (glob(vf_dir() . '/*.jpg') ?: [] as $f) if (time() - (int) @filemtime($f) > 86400) @unlink($f);
}
function vf_used(): int { return (int) (setting_get('vision_used_' . date('Ym')) ?? 0); }

/** Google Cloud Vision Web Detection for one picture → pages, picture links and Google's best guess. */
function vf_vision(string $jpg): array
{
    $key = vf_key();
    if ($key === '') return ['error' => 'No Vision API key saved yet'];
    $body = json_encode(['requests' => [['image' => ['content' => base64_encode($jpg)], 'features' => [['type' => 'WEB_DETECTION', 'maxResults' => 40]]]]]);
    $c = curl_init((getenv('VF_TEST_BASE') ?: 'https://vision.googleapis.com') . '/v1/images:annotate?key=' . rawurlencode($key));
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
                           CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $raw = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    $j = json_decode((string) $raw, true) ?: [];
    if ($code !== 200) {
        $m = (string) ($j['error']['message'] ?? 'no answer');
        if (stripos($m, 'billing') !== false) $m = 'Google says billing is not turned on for this project (Google Cloud → Billing).';
        elseif (stripos($m, 'has not been used') !== false || stripos($m, 'disabled') !== false) $m = 'The Cloud Vision API is not turned on in this project (Google Cloud → APIs → Cloud Vision API → Enable).';
        elseif (stripos($m, 'API key not valid') !== false) $m = 'The Vision API key is not valid — copy it again.';
        return ['error' => 'Google Vision: ' . mb_substr($m, 0, 200)];
    }
    setting_set('vision_used_' . date('Ym'), (string) (vf_used() + 1));
    $w = $j['responses'][0]['webDetection'] ?? [];
    $pages = [];
    foreach ((array) ($w['pagesWithMatchingImages'] ?? []) as $p) {
        $pages[] = ['url' => (string) ($p['url'] ?? ''), 'title' => mb_substr(strip_tags(html_entity_decode((string) ($p['pageTitle'] ?? ''))), 0, 140),
                    'full' => !empty($p['fullMatchingImages'])];
    }
    $imgs = [];
    foreach (['fullMatchingImages' => 'full', 'partialMatchingImages' => 'part'] as $k => $t) foreach ((array) ($w[$k] ?? []) as $im) $imgs[] = ['url' => (string) ($im['url'] ?? ''), 'kind' => $t];
    return ['pages' => array_slice($pages, 0, 40), 'images' => array_slice($imgs, 0, 40),
            'guess' => (string) ($w['bestGuessLabels'][0]['label'] ?? ''),
            'similar' => count((array) ($w['visuallySimilarImages'] ?? []))];
}

/* ---------------------------------------------------------------- 🤖 Apify (a Google Lens "Actor" — free monthly credit, no Google billing) */
function vf_apify_token(): string { return (string) (setting_get('apify_token') ?? ''); }
function vf_apify_actor(): string { return (string) (setting_get('apify_actor') ?: 'curious_coder~google-lens-scraper'); }
function vf_apify(string $method, string $path, ?array $body = null): array
{
    $base = getenv('VF_APIFY_BASE') ?: 'https://api.apify.com';
    $c = curl_init($base . $path . (str_contains($path, '?') ? '&' : '?') . 'token=' . rawurlencode(vf_apify_token()));
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]
        + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES)] : []));
    $raw = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$code, json_decode((string) $raw, true)];
}
/** Start the Google Lens Actor for the pictures (their links on this panel). → run id or error */
function vf_apify_start(array $urls): array
{
    if (vf_apify_token() === '') return ['error' => 'No Apify token saved yet'];
    [$code, $j] = vf_apify('POST', '/v2/acts/' . rawurlencode(vf_apify_actor()) . '/runs', ['urls' => array_values($urls), 'searchType' => 'findImageSources']);
    if ($code !== 201 && $code !== 200) {
        $m = (string) ($j['error']['message'] ?? 'no answer (' . $code . ')');
        if ($code === 401) $m = 'The Apify token is not valid — copy it again (Apify → Settings → API & Integrations).';
        if ($code === 402 || stripos($m, 'credit') !== false || stripos($m, 'usage') !== false) $m = 'Apify: the free monthly credit is used up (it comes back next month).';
        if ($code === 404) $m = 'Apify: the Google Lens Actor was not found (' . vf_apify_actor() . ').';
        return ['error' => 'Apify: ' . mb_substr($m, 0, 200)];
    }
    return ['run' => (string) ($j['data']['id'] ?? ''), 'dataset' => (string) ($j['data']['defaultDatasetId'] ?? '')];
}
/** Ask how the run is; when it is done: every web page it found (link + title), and for which picture. */
function vf_apify_poll(string $run, array $names): array
{
    [$code, $j] = vf_apify('GET', '/v2/actor-runs/' . rawurlencode($run));
    if ($code !== 200) return ['error' => 'Apify did not answer (' . $code . ')'];
    $st = (string) ($j['data']['status'] ?? '');
    if (in_array($st, ['READY', 'RUNNING'], true)) return ['status' => 'running'];
    if ($st !== 'SUCCEEDED') return ['error' => 'Apify: the search ended with “' . $st . '”' . ($st === 'FAILED' ? ' (the Actor could not read the pictures — try again, or use the 🔍 Lens buttons)' : '')];
    [$code, $items] = vf_apify('GET', '/v2/datasets/' . rawurlencode((string) $j['data']['defaultDatasetId']) . '/items?clean=true&limit=2000');
    if ($code !== 200 || !is_array($items)) return ['error' => 'Apify: could not read the results'];
    $out = [];   // [frame index (-1 = not known), url, title]
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $flat = json_encode($it, JSON_UNESCAPED_SLASHES);
        $fi = -1;
        foreach ($names as $i => $n) if (str_contains((string) $flat, $n)) { $fi = $i; break; }   // which of our pictures this result is for
        $walk = function ($v) use (&$walk, &$out, $fi): void {
            if (!is_array($v)) return;
            $u = '';
            foreach (['link', 'url', 'pageUrl', 'sourceUrl', 'source_url', 'page_url', 'website'] as $k) if (is_string($v[$k] ?? null) && preg_match('#^https?://#', $v[$k])) { $u = $v[$k]; break; }
            if ($u !== '' && !preg_match('#(^https?://([a-z0-9-]+\.)*(google|gstatic|googleusercontent)\.[a-z.]+/)|/api/finder\.php#i', $u)) {
                $t = '';
                foreach (['title', 'pageTitle', 'name', 'source'] as $k) if (is_string($v[$k] ?? null) && $v[$k] !== '') { $t = $v[$k]; break; }
                $out[] = [$fi, $u, mb_substr(strip_tags($t), 0, 140)];
            }
            foreach ($v as $x) if (is_array($x)) $walk($x);
        };
        $walk($it);
    }
    $seen = []; $res = [];
    foreach ($out as [$fi, $u, $t]) { $k = $fi . '|' . $u; if (isset($seen[$k])) continue; $seen[$k] = 1; $res[] = ['f' => $fi, 'url' => $u, 'title' => $t]; }
    setting_set('apify_used_' . date('Ym'), (string) ((int) (setting_get('apify_used_' . date('Ym')) ?? 0) + count($names)));
    return ['status' => 'done', 'pages' => array_slice($res, 0, 400)];
}
