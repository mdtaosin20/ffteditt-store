<?php
/** Snake Live settings, shared by Admin → Snake Live and /api/snake.php. */
declare(strict_types=1);

function snake_defaults(): array
{
    return [
        'title'    => 'SNAKE LIVE',
        'speed'    => 9,       // moves a second (3–20)
        'grid'     => 20,      // board squares on each side (12–30)
        'chat'     => true,    // every chat message drops a fruit with the viewer's name
        'cooldown' => 8,       // seconds: one fruit per viewer every this many seconds
        'named'    => 6,       // fruits with names on the board at once (1–20)
        'gold'     => 3,       // a subscriber's golden fruit makes the snake this much longer
        'eat_sound' => true,
        'music'    => true,
        'chan'     => '',      // text at the bottom (channel name)
        'autoplay' => true,
        'pace'     => 'normal',
        'rev'      => '1',
        'reset'    => '1',
    ];
}

function snake_config(): array
{
    $d = snake_defaults();
    $c = json_decode(setting_get('snake') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function snake_save(array $c): void
{
    setting_set('snake', json_encode(array_intersect_key(array_merge(snake_defaults(), $c), snake_defaults()), JSON_UNESCAPED_UNICODE));
}
