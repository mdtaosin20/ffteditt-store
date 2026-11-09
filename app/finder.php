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
