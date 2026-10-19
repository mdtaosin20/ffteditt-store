<?php
/** Beat The Keeper settings, shared by Admin → Beat The Keeper and /api/keeper.php. */
declare(strict_types=1);

const KEEPER_TEAMS = ['ph', 'my', 'us', 'in', 'bd', 'br', 'pk', 'id', 'gb', 'jp', 'ar', 'np', 'sa', 'tr', 'fr', 'de', 'ng', 'eg',
    'cn', 'vn', 'au', 'ca', 'mx', 'ru', 'pt', 'es', 'it', 'kr', 'th', 'ae', 'ma', 'nl', 'lk', 'za', 'ir', 'pl'];

function keeper_defaults(): array
{
    return [
        'title'       => 'BEAT THE KEEPER',
        'cup'         => 'WORLD CUP',
        'teams'       => KEEPER_TEAMS,   // 36 countries: 6 groups of 6
        'goals'       => 5,              // goals to go through in groups, quarterfinals and semifinals
        'final_goals' => 10,             // goals to win the final
        'match_secs'  => 150,            // time limit of one match (then the ones with most goals go through)
        'bonus_chats' => 8,              // every this many chats for a country = one bonus ball (0 = off)
        'autoplay'    => true,
        'pace'        => 'normal',
        'rev'         => '1',
        'reset'       => '1',
    ];
}

function keeper_config(): array
{
    $d = keeper_defaults();
    $c = json_decode(setting_get('keeper') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function keeper_save(array $c): void
{
    setting_set('keeper', json_encode(array_intersect_key(array_merge(keeper_defaults(), $c), keeper_defaults())));
}
