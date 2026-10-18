<?php
/** Tug of War settings, shared by Admin → Tug of War and /api/tug.php. */
declare(strict_types=1);

const TUG_PRESETS = [
    'rivals'   => [['bd', 'in'], ['in', 'pk'], ['br', 'ar'], ['us', 'ru'], ['id', 'my'], ['bd', 'pk'], ['sa', 'ae'], ['gb', 'fr']],
    'asia'     => [['bd', 'in'], ['in', 'pk'], ['bd', 'pk'], ['np', 'lk'], ['id', 'my'], ['cn', 'jp'], ['kr', 'jp'], ['ph', 'vn']],
    'football' => [['br', 'ar'], ['fr', 'gb'], ['es', 'pt'], ['de', 'it'], ['nl', 'be'], ['ma', 'eg'], ['us', 'mx'], ['jp', 'kr']],
];

function tug_defaults(): array
{
    return [
        'title'    => 'TUG OF WAR',
        'pairs'    => TUG_PRESETS['rivals'],
        'round'    => 180,     // seconds in a round
        'power'    => 100,     // how far one comment pulls the rope, % of normal
        'autoplay' => true,
        'pace'     => 'normal',
        'rev'      => '1',
        'reset'    => '1',
    ];
}

function tug_config(): array
{
    $d = tug_defaults();
    $c = json_decode(setting_get('tug') ?? '', true);
    return is_array($c) ? array_merge($d, array_intersect_key($c, $d)) : $d;
}

function tug_save(array $c): void
{
    setting_set('tug', json_encode($c));
}

/** "bd-in, in-pk" -> [['bd','in'], ['in','pk']] (unknown codes are reported in $bad). */
function tug_parse_pairs(string $text, array &$bad): array
{
    $known = war_known_codes();
    $pairs = [];
    foreach (preg_split('/[\n,;]+/', strtolower($text)) ?: [] as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        $two = preg_split('/\s*-\s*|\s+(?:vs\.?|v)\s+|\s+/', $chunk) ?: [];   // bd-in, bd vs in, bd in
        $two = array_values(array_filter(array_map('trim', $two)));
        if (count($two) !== 2 || $two[0] === $two[1]) { $bad[] = $chunk; continue; }
        foreach ($two as $c) if (!isset($known[$c])) { $bad[] = $c; continue 2; }
        $pairs[] = $two;
    }
    return $pairs;
}

function tug_pairs_text(array $pairs): string
{
    return implode(', ', array_map(fn ($p) => $p[0] . '-' . $p[1], $pairs));
}
