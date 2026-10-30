<?php
/**
 * Admin → My Voice: the owner types a line (Bangla or English); it comes out in ENGLISH in HIS OWN cloned voice
 * (ElevenLabs Text to Speech with his Voice ID). Bangla is first translated with the panel's saved OpenAI key.
 * The mp3 can be downloaded, or played once on every running live (the game pages pick it up with the chat).
 * The ElevenLabs key is saved here only and never shown again.
 */
declare(strict_types=1);

const MV_MODELS = [
    'eleven_multilingual_v2' => 'Multilingual v2 — best quality (1 credit per letter)',
    'eleven_v3'              => 'Eleven v3 — most expressive',
    'eleven_flash_v2_5'      => 'Flash v2.5 — fastest, about half the credits',
];

function mv_cfg(): array
{
    $d = ['voice' => '', 'model' => 'eleven_multilingual_v2', 'custom_model' => '', 'stability' => 50, 'similarity' => 80];
    $c = json_decode((string) (setting_get('mv_cfg') ?? ''), true);
    return array_merge($d, is_array($c) ? array_intersect_key($c, $d) : []);
}
function mv_save_cfg(array $c): void { setting_set('mv_cfg', json_encode(array_merge(mv_cfg(), $c))); }
function mv_key(): string { return (string) (setting_get('mv_key') ?? ''); }
function mv_model(): string { $c = mv_cfg(); return $c['custom_model'] !== '' ? $c['custom_model'] : $c['model']; }
function mv_ready(): bool { return mv_key() !== '' && mv_cfg()['voice'] !== ''; }

function mv_history(): array { return json_decode((string) (setting_get('mv_hist') ?? ''), true) ?: []; }

/** Bangla (or mixed) → natural spoken English for a live game stream. English stays as it is. */
function mv_english(string $text): array
{
    $text = trim($text);
    if (!preg_match('/[\x{0980}-\x{09FF}]/u', $text)) return [true, $text];   // no Bangla letters: already English
    if (ai_key() === '') return [false, 'To turn Bangla into English, save an OpenAI key in YouTube Manager → AI Replies (or type in English).'];
    $out = ai_call('You translate Bangla into natural, simple spoken English for a YouTube live game host talking to viewers. '
        . 'Keep the meaning and the energy. Keep names, numbers and emojis. Answer with the English sentence only — no quotes, no notes.', $text, 400, 20);
    if ($out === null) return [false, 'The translation did not work (OpenAI). Try again, or type in English.'];
    return [true, trim($out, " \t\n\r\"'“”")];
}

/** English text → mp3 in the owner's voice. Returns [ok, file name or error]. */
function mv_speak(string $en): array
{
    $c = mv_cfg();
    $en = trim(mb_substr($en, 0, 1000));
    if ($en === '') return [false, 'Nothing to say.'];
    $model = mv_model();
    $settings = ['stability' => max(0, min(100, (int) $c['stability'])) / 100, 'similarity_boost' => max(0, min(100, (int) $c['similarity'])) / 100];
    $h = substr(hash('sha256', 'mv1|' . $c['voice'] . '|' . $model . '|' . json_encode($settings) . '|' . $en), 0, 24);
    $file = $h . '.mp3';
    if (is_file(upload_dir() . '/' . $file)) return [true, $file];   // the same line again: no new credits
    $ch = curl_init((defined('MV_API_BASE') ? MV_API_BASE : 'https://api.elevenlabs.io') . '/v1/text-to-speech/' . rawurlencode($c['voice']) . '?output_format=mp3_44100_128');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['xi-api-key: ' . mv_key(), 'Content-Type: application/json', 'Accept: audio/mpeg'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $en, 'model_id' => $model, 'voice_settings' => $settings], JSON_UNESCAPED_UNICODE)]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code !== 200 || strlen($body) < 200) {
        $j = json_decode($body, true);
        $m = is_array($j) ? (string) ($j['detail']['message'] ?? (is_string($j['detail'] ?? null) ? $j['detail'] : '')) : '';
        $why = $code === 401 ? 'The ElevenLabs key is wrong or has no “Text to Speech” access (Settings).'
            : ($code === 404 ? 'Voice ID not found — copy it again from ElevenLabs → Voices → your voice → ID (Settings).'
            : ($code === 422 && stripos($m, 'model') !== false ? 'This model name is not accepted — choose another model in Settings.'
            : (stripos($m, 'quota') !== false || stripos($m, 'credit') !== false ? 'No ElevenLabs credits left this month.'
            : 'ElevenLabs did not answer (' . ($code ?: $err) . ($m ? ': ' . mb_substr($m, 0, 160) : '') . ')')));
        return [false, $why];
    }
    file_put_contents(upload_dir() . '/' . $file, $body);
    return [true, $file];
}

/** Make one line: translate when needed, then speak. Saved in the history (last 40). */
function mv_make(string $text, string $en = ''): array
{
    $text = trim(mb_substr($text, 0, 1000));
    if ($en === '') { [$ok, $en] = mv_english($text); if (!$ok) return ['error' => $en]; }
    [$ok, $file] = mv_speak($en);
    if (!$ok) return ['error' => $file, 'en' => $en];
    $item = ['id' => substr($file, 0, 24), 'ts' => time(), 'text' => $text, 'en' => $en, 'url' => upload_url($file), 'chars' => mb_strlen($en)];
    $h = array_values(array_filter(mv_history(), fn ($x) => $x['id'] !== $item['id']));
    array_unshift($h, $item);
    setting_set('mv_hist', json_encode(array_slice($h, 0, 40), JSON_UNESCAPED_UNICODE));
    return ['item' => $item];
}

/** Play it once on every running live (the game page plays it with the chat it reads). */
function mv_play_live(string $id): bool
{
    foreach (mv_history() as $x) {
        if ($x['id'] === $id) {
            setting_set('owner_voice', json_encode(['id' => $id . '-' . time(), 'url' => $x['url'], 'ts' => time()]));
            log_activity('My Voice played on the live: “' . mb_substr($x['en'], 0, 60) . '”', 'ok');
            return true;
        }
    }
    return false;
}

/** For the game pages (in chat_feed): the owner's line, during the 90 s after he pressed “Play on live”. */
function mv_live_now(): ?array
{
    $v = json_decode((string) (setting_get('owner_voice') ?? ''), true);
    return is_array($v) && (int) ($v['ts'] ?? 0) > time() - 90 ? ['id' => (string) $v['id'], 'url' => (string) $v['url']] : null;
}
