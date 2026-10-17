<?php
/** Country Flag Battle settings, shared by Admin → Flags and /api/flags.php. */
declare(strict_types=1);

function flags_defaults(): array
{
    return [
        'title'     => 'COUNTRY FLAG BATTLE',
        'target'    => 70,     // countries that qualify for the final
        'ring'      => 100,    // ring turning speed, % of normal
        'speed'     => 100,    // flag speed, % of normal
        'gap'       => 100,    // gap size, % of normal
        'rev'       => '1',
        'reset'     => '1',
    ];
}

function flags_config(): array
{
    $d = flags_defaults();
    $c = json_decode(setting_get('flags') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function flags_save(array $c): void
{
    setting_set('flags', json_encode($c));
}
