<?php
/** Flag Race settings, shared by Admin → Flag Race and /api/race.php. */
declare(strict_types=1);

function race_defaults(): array
{
    return [
        'title'    => 'FLAG RACE',
        'racers'   => 10,      // flags in a race (6–12)
        'secs'     => 60,      // how long a race takes when nobody chats; chat boosts make it faster
        'boost'    => 100,     // how strong one chat boost is, % of normal
        'autoplay' => true,
        'pace'     => 'normal',
        'rev'      => '1',
        'reset'    => '1',
    ];
}

function race_config(): array
{
    $d = race_defaults();
    $c = json_decode(setting_get('race') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function race_save(array $c): void
{
    setting_set('race', json_encode($c));
}
