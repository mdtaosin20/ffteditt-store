<?php
/** Territory War settings, shared by Admin → Territory War and /api/war.php. */
declare(strict_types=1);

const WAR_PRESETS = [
    'popular20' => ['bd', 'in', 'pk', 'us', 'br', 'ar', 'sa', 'tr', 'id', 'ng', 'ru', 'cn', 'jp', 'de', 'fr', 'gb', 'np', 'eg', 'ph', 'my'],
    'popular' => ['bd', 'in', 'pk', 'us', 'br', 'ar', 'sa', 'tr', 'id', 'ng', 'ru', 'cn', 'jp', 'de', 'fr', 'gb'],
    'asia'    => ['bd', 'in', 'pk', 'np', 'lk', 'af', 'cn', 'jp', 'kr', 'id', 'my', 'th', 'vn', 'ph', 'sa', 'ae'],
    'football'=> ['ar', 'br', 'fr', 'gb', 'es', 'pt', 'de', 'it', 'nl', 'be', 'hr', 'uy', 'ma', 'jp', 'us', 'mx'],
    'world150' => ['bd', 'in', 'pk', 'us', 'br', 'ar', 'sa', 'tr', 'id', 'ng', 'ru', 'cn', 'jp', 'de', 'fr', 'gb', 'af', 'al', 'dz', 'ao', 'am', 'au', 'at', 'az', 'by', 'be', 'bj', 'bt', 'bo', 'ba', 'bw', 'bg', 'bf', 'bi', 'kh', 'cm', 'ca', 'cf', 'td', 'cl', 'co', 'cg', 'cd', 'cr', 'ci', 'hr', 'cu', 'cz', 'dk', 'do', 'ec', 'eg', 'sv', 'ee', 'et', 'fi', 'ga', 'ge', 'gh', 'gr', 'gt', 'gn', 'ht', 'hn', 'hu', 'is', 'ir', 'iq', 'ie', 'il', 'it', 'jm', 'jo', 'kz', 'ke', 'kp', 'kr', 'kw', 'kg', 'la', 'lv', 'lb', 'lr', 'ly', 'lt', 'mg', 'mw', 'my', 'ml', 'mr', 'mx', 'md', 'mn', 'ma', 'mz', 'mm', 'na', 'np', 'nl', 'nz', 'ni', 'ne', 'mk', 'no', 'om', 'pa', 'pg', 'py', 'pe', 'ph', 'pl', 'pt', 'qa', 'ro', 'rw', 'sn', 'rs', 'sl', 'sg', 'sk', 'si', 'so', 'za', 'ss', 'es', 'lk', 'sd', 'se', 'ch', 'sy', 'tj', 'tz', 'th', 'tg', 'tt', 'tn', 'tm', 'ug', 'ua', 'ae', 'uy', 'uz', 've', 'vn', 'ye', 'zm', 'zw', 'ps', 'lu', 'cy'],
    'muslim'  => ['bd', 'pk', 'sa', 'tr', 'id', 'my', 'eg', 'ma', 'ae', 'qa', 'ir', 'iq', 'jo', 'dz', 'ps', 'af'],
];

function war_defaults(): array
{
    return [
        'title'    => 'TERRITORY WAR',
        'teams'    => WAR_PRESETS['popular20'],
        'grid'     => 56,      // squares across the map (more = longer game)
        'speed'    => 100,     // ball speed, % of normal
        'sudden'   => 60,      // minutes before Sudden Death (smallest country out every 90 s)
        'boost'    => true,    // a comment with a country name speeds up its ball for a few seconds
        'fill'     => 'color',  // land shows the country's flag ('flag') or a plain colour ('color')
        'ball_chats'   => 15,  // chats for one extra ball (lasts 3 minutes); 0 = off
        'revive_chats' => 30,  // chats that bring a country that is out back; 0 = off
        'like_step'    => 20,  // every this many new likes: one more ball (or a comeback); 0 = off
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
    $c = is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
    if (setting_get('mig_war150') === null) {   // once: the war grows to 150 countries (owner's wish, Oct 2026)
        setting_set('mig_war150', '1');
        $c['teams'] = WAR_PRESETS['world150'];
        $c['rev'] = bin2hex(random_bytes(4));
        $c['reset'] = bin2hex(random_bytes(4));
        war_save($c);
    }
    if (setting_get('mig_war20') === null) {   // once: 20 countries, chat balls + revive (owner's wish)
        setting_set('mig_war20', '1');
        $c['teams'] = WAR_PRESETS['popular20'];
        $c['fill'] = 'color';
        $c['rev'] = bin2hex(random_bytes(4));
        $c['reset'] = bin2hex(random_bytes(4));
        war_save($c);
    }
    if (setting_get('mig_war_grid56') === null) {   // once: smaller squares (owner's wish)
        setting_set('mig_war_grid56', '1');
        if ((int) $c['grid'] < 56) $c['grid'] = 56;
        $c['rev'] = bin2hex(random_bytes(4));
        $c['reset'] = bin2hex(random_bytes(4));
        war_save($c);
    }
    return $c;
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
