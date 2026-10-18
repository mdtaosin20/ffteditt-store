<?php
/** Territory War settings, shared by Admin → Territory War and /api/war.php. */
declare(strict_types=1);

const WAR_PRESETS = [
    'popular' => ['bd', 'in', 'pk', 'us', 'br', 'ar', 'sa', 'tr', 'id', 'ng', 'ru', 'cn', 'jp', 'de', 'fr', 'gb'],
    'asia'    => ['bd', 'in', 'pk', 'np', 'lk', 'af', 'cn', 'jp', 'kr', 'id', 'my', 'th', 'vn', 'ph', 'sa', 'ae'],
    'football'=> ['ar', 'br', 'fr', 'gb', 'es', 'pt', 'de', 'it', 'nl', 'be', 'hr', 'uy', 'ma', 'jp', 'us', 'mx'],
    'muslim'  => ['bd', 'pk', 'sa', 'tr', 'id', 'my', 'eg', 'ma', 'ae', 'qa', 'ir', 'iq', 'jo', 'dz', 'ps', 'af'],
];

function war_defaults(): array
{
    return [
        'title'    => 'TERRITORY WAR',
        'teams'    => WAR_PRESETS['popular'],
        'grid'     => 40,      // squares across the map (more = longer game)
        'speed'    => 100,     // ball speed, % of normal
        'sudden'   => 60,      // minutes before Sudden Death (smallest country out every 90 s)
        'boost'    => true,    // a comment with a country name speeds up its ball for a few seconds
        'autoplay' => true,
        'pace'     => 'normal',
        'rev'      => '1',
        'reset'    => '1',
    ];
}

function war_config(): array
{
    $d = war_defaults();
    $c = json_decode(setting_get('war') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function war_save(array $c): void
{
    setting_set('war', json_encode($c));
}

/** All country codes the game knows (from the flag picture list). */
function war_known_codes(): array
{
    static $codes = null;
    if ($codes === null) {
        $codes = [];
        foreach ([rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/flags/flags.json', dirname(APP_DIR) . '/public/flags/flags.json'] as $f) {
            if (is_file($f)) {
                foreach ((array) (json_decode((string) file_get_contents($f), true)['countries'] ?? []) as $c) {
                    $codes[$c['code']] = $c['name'];
                }
                break;
            }
        }
    }
    return $codes;
}
