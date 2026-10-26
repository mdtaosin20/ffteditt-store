<?php
/** Last Flag Standing settings (the game at /lastflag/): the neon Flag Arena with a turning bearing.
 *  Own settings, so it can run next to Flag Arena with different numbers. Admin → Last Flag Standing. */
declare(strict_types=1);

function lastflag_defaults(): array
{
    return array_merge(flags_defaults(), [
        'title' => 'LAST FLAG STANDING',
        'count' => 50,
        'shield' => 10,
        'revive' => 20,
        'revives' => 0,
        'rev' => '1',
        'reset' => '1',
    ]);
}

function lastflag_config(): array
{
    $d = lastflag_defaults();
    $c = json_decode(setting_get('lastflag') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function lastflag_save(array $c): void
{
    setting_set('lastflag', json_encode(array_intersect_key(array_merge(lastflag_defaults(), $c), lastflag_defaults())));
}
