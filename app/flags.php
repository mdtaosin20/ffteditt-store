<?php
/** Flag Arena settings (the game at /flags/), shared by Admin → Flag Arena and /api/flags.php. */
declare(strict_types=1);

function flags_defaults(): array
{
    return [
        'title'    => 'FLAG ARENA',
        'count'    => 64,      // countries in one round
        'gap'      => 5,       // seconds between the black hole's openings (longer = longer rounds)
        'minutes'  => 55,      // (old setting, not used)
        'shield'   => 20,      // chats for a country -> it gets a shield
        'revive'   => 40,      // chats for a country that is out -> it comes back
        'revives'  => 0,       // revives allowed in one round
        'likes'    => 50,      // every this many new likes: the hole closes for 30 s
        'viewers'  => 100,     // people watching reach this: top 3 chat countries get shields
        'events'   => true,    // mystery boxes (double draw, lucky shields, …)
        'music'    => true,    // background music made in code (copyright free)
        'music_src'   => 'songs',   // 'songs' = NCS songs from the panel (live stream only), 'own' = made in code
        'music_style' => 'upbeat',
        'music_vol'   => 35,   // %
        'autoplay' => true,
        'pace'     => 'normal',
        'rev'      => '1',
        'reset'    => '1',
    ];
}

function flags_config(): array
{
    $d = flags_defaults();
    $c = json_decode(setting_get('flags') ?? '', true);
    $c = is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
    if (setting_get('mig_flag_arena') === null) {   // once: Flag Battle became Flag Arena
        setting_set('mig_flag_arena', '1');
        $c['title'] = $d['title'];
        $c['reset'] = bin2hex(random_bytes(4));
        $c['rev'] = bin2hex(random_bytes(4));
        flags_save($c);
    }
    if (setting_get('mig_flag_unlim') === null) {   // once: revives with no limit (the owner asked)
        setting_set('mig_flag_unlim', '1');
        $c['revives'] = 0;
        $c['rev'] = bin2hex(random_bytes(4));
        flags_save($c);
    }
    return $c;
}

function flags_save(array $c): void
{
    setting_set('flags', json_encode(array_intersect_key(array_merge(flags_defaults(), $c), flags_defaults())));
}
