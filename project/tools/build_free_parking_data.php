<?php
/**
 * One-off builder for the free/local parking list used by find_parking.php.
 *
 *   php tools/build_free_parking_data.php
 *
 * It READS the coordinates that are hard-coded in the old pages (nothing is invented), merges
 * them into ONE list, and writes:
 *   src/free_parking_data.php         the list the app uses (spots) + the entries left out (flagged)
 *   docs/PARKING_DATA_FLAGS.md        a human-readable list of what needs correcting
 *
 * Rules (the same ones the tests check):
 *   1. City = the city of the source page (kolhapur.html, ajara.php ...), else the city named in the
 *      spot's name, else the nearest of the three cities.
 *   2. A spot is only kept when it lies inside that city's area (radius below). Anything else is
 *      FLAGGED and not shown on the map: it is not moved or guessed.
 *   3. Duplicates (same city and same normalised name, or within 30 m of an existing spot) are
 *      merged: the first source in priority order wins. If the duplicate's coordinates disagree by
 *      more than 150 m the disagreement is FLAGGED too so a person can decide which is right.
 *   Coordinates are exactly as found in the old pages; they have NOT been checked against a survey.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
// priority order: earlier wins when two sources describe the same place
$sources = [
    ['legacy/nearby.html', null],       // 80 spots, consistent coordinates for all three cities
    ['live_map.php', null],
    ['kolhapur.php', 'Kolhapur'], ['ajara.php', 'Ajara'], ['gad.php', 'Gadhinglaj'],
    ['kolhapur.html', 'Kolhapur'], ['ajara.html', 'Ajara'], ['gadhinglaj.html', 'Gadhinglaj'],
    ['confirm.php', null],
];
// City centres (the median of the old pages' clusters) and the radius a spot may be from it.
$cities = [
    'Kolhapur'   => ['lat' => 16.704, 'lng' => 74.243, 'radius_km' => 12.0],
    'Ajara'      => ['lat' => 16.116, 'lng' => 74.211, 'radius_km' => 6.0],
    'Gadhinglaj' => ['lat' => 16.226, 'lng' => 74.350, 'radius_km' => 6.0],
];

function hav_m(float $a, float $b, float $c, float $d): float
{
    $r = 6371000.0;
    $p1 = deg2rad($a); $p2 = deg2rad($c);
    $dp = $p2 - $p1; $dl = deg2rad($d - $b);
    $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h)));
}

function norm_name(string $n): string
{
    $n = strtolower($n);
    $n = str_replace(['gadhinjlaj', 'gadhinglaj', '&'], ['gadhinglaj', 'gadhinglaj', ' and '], $n);
    $n = preg_replace('/\b(parking|area|spot|kolhapur|ajara|gadhinglaj|shree|shri|sri)\b/', ' ', $n);
    $n = str_replace(['mandir', 'mandar'], 'temple', $n);
    $n = preg_replace('/\b(stop|station)\b/', ' ', $n) ?? $n; // "bus stop" == "bus stand" == "bus"
    $n = str_replace(['stand', 'depot'], ' ', $n);
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? $n;
    return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
}

$raw = [];
$re = '/["\']?name["\']?\s*(?::|=>)\s*["\']([^"\']+)["\']\s*,\s*(?:["\']admin_zone["\']\s*=>\s*["\'][^"\']*["\']\s*,\s*)?["\']?lat["\']?\s*(?::|=>)\s*(-?\d+\.\d+)\s*,\s*["\']?(?:lng|lon)["\']?\s*(?::|=>)\s*(-?\d+\.\d+)/';
foreach ($sources as $prio => [$file, $fixedCity]) {
    $text = (string) file_get_contents($root . '/' . $file);
    preg_match_all($re, $text, $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        $raw[] = ['name' => trim($x[1]), 'lat' => (float) $x[2], 'lng' => (float) $x[3], 'source' => $file, 'fixed' => $fixedCity, 'prio' => $prio];
    }
}

$kept = [];
$flagged = [];
foreach ($raw as $r) {
    // 1. city
    $city = $r['fixed'];
    if ($city === null) {
        foreach (['Kolhapur' => 'kolhapur', 'Ajara' => 'ajara', 'Gadhinglaj' => 'gadhinglaj'] as $c => $needle) {
            if (preg_match('/\\b' . $needle . '\\b/', str_replace('gadhinjlaj', 'gadhinglaj', strtolower($r['name']))) === 1) { $city = $c; break; } // whole word: "Rajarampuri" is not "Ajara"
        }
    }
    if ($city === null) { // nearest centre
        $best = null;
        foreach ($cities as $c => $cc) {
            $d = hav_m($r['lat'], $r['lng'], $cc['lat'], $cc['lng']);
            if ($best === null || $d < $best[1]) { $best = [$c, $d]; }
        }
        $city = $best[0];
    }
    // 2. inside the city area?
    $cc = $cities[$city];
    $dist = hav_m($r['lat'], $r['lng'], $cc['lat'], $cc['lng']);
    $r['city'] = $city;
    if ($dist > $cc['radius_km'] * 1000) {
        $r['reason'] = sprintf('outside the %s area (%.1f km from the centre; limit %.0f km) - coordinates look wrong or it is a different town', $city, $dist / 1000, $cc['radius_km']);
        $flagged[] = $r;
        continue;
    }
    // 3. duplicates
    $key = norm_name($r['name']);
    $dup = null;
    foreach ($kept as $k) {
        if ($k['city'] !== $city) { continue; }
        $d = hav_m($r['lat'], $r['lng'], $k['lat'], $k['lng']);
        if (($key !== '' && $k['key'] === $key) || $d <= 30) { $dup = [$k, $d]; break; }
    }
    if ($dup !== null) {
        [$k, $d] = $dup;
        if ($d > 150) {
            $r['reason'] = sprintf('same place as "%s" (%s) but the coordinates differ by %d m - kept the first source, please check which is right', $k['name'], $k['source'], (int) round($d));
            $flagged[] = $r;
        }
        continue; // merged into the earlier entry
    }
    $r['key'] = $key;
    $kept[] = $r;
}

// stable ids and output
$spots = [];
$seen = [];
foreach ($kept as $k) {
    $slug = strtolower($k['city']) . '-' . (preg_replace('/[^a-z0-9]+/', '-', strtolower($k['name'])) ?: 'spot');
    $slug = trim($slug, '-');
    $id = $slug; $i = 2;
    while (isset($seen[$id])) { $id = $slug . '-' . $i++; }
    $seen[$id] = true;
    $spots[] = ['id' => 'fp-' . $id, 'name' => $k['name'], 'city' => $k['city'], 'lat' => round($k['lat'], 6), 'lng' => round($k['lng'], 6), 'source' => $k['source']];
}
usort($spots, static fn($a, $b) => [$a['city'], $a['name']] <=> [$b['city'], $b['name']]);

$out = "<?php\n// GENERATED by tools/build_free_parking_data.php from the old pages' hard-coded lists. Do not edit by hand;\n// fix the coordinates here only after checking them, and note it in docs/PARKING_DATA_FLAGS.md.\n// 'spots' are shown on the map. 'flagged' were NOT shown: see docs/PARKING_DATA_FLAGS.md.\nreturn " . var_export(['spots' => $spots, 'flagged' => array_map(static fn($f) => ['name' => $f['name'], 'city' => $f['city'], 'lat' => $f['lat'], 'lng' => $f['lng'], 'source' => $f['source'], 'reason' => $f['reason']], $flagged)], true) . ";\n";
file_put_contents($root . '/src/free_parking_data.php', $out);

$md = "# Free / local parking data: what needs correcting\n\nGenerated by `php tools/build_free_parking_data.php` from the coordinates hard-coded in the old pages.\nNothing was moved or invented. **None of the coordinates were checked against a survey or a map service**; they are exactly what the old pages contained.\n\n";
$counts = [];
foreach ($spots as $s) { $counts[$s['city']] = ($counts[$s['city']] ?? 0) + 1; }
$md .= "## Shown on the map\n\n" . count($spots) . " spots (" . implode(', ', array_map(static fn($c, $n) => "$c: $n", array_keys($counts), $counts)) . "). Raw entries read: " . count($raw) . ".\n\n";
$md .= "## Not shown (flagged for correction): " . count($flagged) . "\n\n| Name | City | Lat | Lng | Source | Why |\n|---|---|---|---|---|---|\n";
foreach ($flagged as $f) {
    $md .= sprintf("| %s | %s | %s | %s | %s | %s |\n", str_replace('|', '/', $f['name']), $f['city'], $f['lat'], $f['lng'], $f['source'], $f['reason']);
}
$md .= "\nTo fix an entry: find the true coordinates (e.g. right-click the spot in Google Maps), then add it to `src/free_parking_data.php` under `spots` with the correct city.\n";
file_put_contents($root . '/docs/PARKING_DATA_FLAGS.md', $md);
printf("raw %d, shown %d, flagged %d\n", count($raw), count($spots), count($flagged));
foreach ($counts as $c => $n) { echo "  $c: $n\n"; }
