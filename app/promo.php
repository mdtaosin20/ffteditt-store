<?php
/**
 * On-screen notification on the lives (Admin → Screen Notification): a card over the game that reminds
 * viewers to subscribe / share / like. Shown when the admin presses "Show now", and (if wanted) by itself
 * every N minutes. The game pages read it through /api/page.php (see /game/switcher.js).
 */
declare(strict_types=1);

function promo_defaults(): array
{
    return [
        'title' => 'SAVE YOUR COUNTRY!',
        'sub'   => 'IN THE ARENA = SHIELD · OUT = COMES BACK',
        'lines' => [
            ['i' => '🔔', 'b' => 'SUBSCRIBE', 's' => 'Then type “subscribed” + your country', 'r' => '= 🛡 / ♻', 'c' => '#ff2d55'],
            ['i' => '📢', 'b' => 'SHARE', 's' => 'Share the live, type “shared” + your country', 'r' => '= 🛡 / ♻', 'c' => '#33d17a'],
            ['i' => '👍', 'b' => 'LIKE', 's' => 'Like goals stop the black hole', 'r' => '= 🛑 30s', 'c' => '#33f0ff'],
        ],
        'secs'  => 7,
        'every' => 0,      // minutes between automatic showings (0 = only when the admin presses "Show now")
        'push'  => 0,      // number of the last "Show now" (the pages show each number once)
        'push_stream' => 0, // 0 = all lives, else only this stream
    ];
}

function promo_get(): array
{
    $d = promo_defaults();
    $c = json_decode((string) (setting_get('promo') ?? ''), true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function promo_save(array $c): void
{
    setting_set('promo', json_encode(array_intersect_key(array_merge(promo_defaults(), $c), promo_defaults()), JSON_UNESCAPED_UNICODE));
}

/** What one stream's page needs (no secrets). */
function promo_for_stream(int $id): array
{
    $c = promo_get();
    return ['title' => $c['title'], 'sub' => $c['sub'], 'lines' => $c['lines'], 'secs' => (int) $c['secs'], 'every' => (int) $c['every'],
            'push' => ((int) $c['push_stream'] === 0 || (int) $c['push_stream'] === $id) ? (int) $c['push'] : 0];
}
