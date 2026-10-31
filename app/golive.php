<?php
/**
 * "Join Live": the owner joins a running live from a phone (camera + voice, or voice only).
 * The phone and the stream's game page (on the VPS) connect directly with WebRTC;
 * this file only passes the two connection notes (offer / answer) between them.
 */
declare(strict_types=1);

const GOLIVE_STALE = 25;   // seconds without a sign of life from the phone = the call is over

function golive_get(int $sid): array
{
    $g = json_decode((string) (setting_get('golive_' . $sid) ?? ''), true);
    return array_merge(['on' => false, 'sess' => '', 'mode' => 'cam', 'offer' => '', 'answer' => '', 'seen' => 0, 'name' => '', 'state' => ''], is_array($g) ? $g : []);
}

function golive_put(int $sid, array $g): void
{
    setting_set('golive_' . $sid, json_encode($g));
}

/** What the game page needs (no answer, nothing secret). */
function golive_public(int $sid): array
{
    $g = golive_get($sid);
    $alive = $g['on'] && time() - (int) $g['seen'] <= GOLIVE_STALE;
    return ['on' => $alive, 'sess' => $alive ? $g['sess'] : '', 'mode' => $g['mode'], 'name' => $g['name'],
            'offer' => $alive && $g['answer'] === '' ? $g['offer'] : '', 'view' => $g['view'] ?? null];   // view: camera links only (turn / mirror)
}
