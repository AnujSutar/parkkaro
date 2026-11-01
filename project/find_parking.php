<?php
// Find parking: admin-registered (bookable) places AND local free parking for Kolhapur, Ajara and Gadhinglaj,
// on one Leaflet/OpenStreetMap map with the visitor's own location (only after the browser grants permission),
// straight-line distances and Google Maps directions links.
// Booking is NOT done here: bookable places link to the one existing reservation flow (reserve.php).
// Free places are informational only - no price, no Reserve button, availability unknown.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/locations.php';
require_once __DIR__ . '/src/free_parking.php';
require_once __DIR__ . '/src/ui.php';

function fp_badge(string $text, string $class): string
{
    return '<span class="badge ' . e($class) . '">' . e($text) . '</span>';
}

$now = app_now();
$ready = new_schema_ready();

$q = trim((string) ($_GET['q'] ?? ''));
$kind = (string) ($_GET['kind'] ?? '');            // '' | private | public | free
$cityIn = trim((string) ($_GET['city'] ?? ''));
$city = isset(FP_CITIES[$cityIn]) ? $cityIn : '';  // only the three known cities are accepted
$startIn = trim((string) ($_GET['start'] ?? ''));
$endIn = trim((string) ($_GET['end'] ?? ''));

$errors = [];
$window = null;
$bookable = [];
if ($ready) {
    if ($startIn !== '' || $endIn !== '') {
        [$errors, $window] = validate_reservation_window(['start' => $startIn, 'end' => $endIn], ['open_from' => null, 'open_until' => null], $now);
    }
    if (!$errors && $kind !== 'free') {
        // Full places are included so they are shown as "Full" instead of silently disappearing.
        $bookable = search_locations(pdo(), ['city' => $q, 'kind' => $kind], $window, $now, true, 200);
        if ($city !== '') {
            $bookable = array_values(array_filter($bookable, static fn ($r) => fp_location_city($r) === $city));
        }
    }
}

// Free local parking does not need the database, so it is shown even before the migration is applied.
$free = [];
if (!$errors && ($kind === '' || $kind === 'free')) {
    $free = fp_without_bookable_duplicates(fp_free_spots(), $ready ? $bookable : []);
    $free = array_values(array_filter($free, static function ($f) use ($city, $q) {
        if ($city !== '' && $f['city'] !== $city) {
            return false;
        }
        if ($q !== '' && stripos($f['name'] . ' ' . $f['city'], $q) === false) {
            return false;
        }
        return true;
    }));
}

// Data for the map script. Only what the browser needs to draw; never a price or capacity decision.
$mapItems = [];
foreach ($bookable as $b) {
    if (fp_valid_coords($b['lat'], $b['lng'])) {
        $mapItems[] = ['key' => 'b' . (int) $b['id'], 'type' => 'bookable', 'name' => (string) $b['name'], 'lat' => (float) $b['lat'], 'lng' => (float) $b['lng']];
    }
}
foreach ($free as $f) {
    $mapItems[] = ['key' => $f['id'], 'type' => 'free', 'name' => $f['name'], 'lat' => (float) $f['lat'], 'lng' => (float) $f['lng']];
}
$view = $city !== '' ? FP_CITIES[$city] : null;
$mapCfg = ['items' => $mapItems, 'view' => $view, 'cities' => FP_CITIES];

ui_page_start('Find parking', 'user', 'find');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
#fp-map { height: 380px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 8px; background: #eef2f5; }
.fp-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 8px; }
.fp-legend span { display: inline-block; width: 11px; height: 11px; border-radius: 50%; margin: 0 4px 0 12px; vertical-align: middle; }
.fp-legend span:first-child { margin-left: 0; }
.fp-status { margin: 0 0 12px; }
.card.place.fp-sel { outline: 2px solid #2563eb; }
.fp-dist { font-weight: 600; }
.place .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; align-items: center; }
@media (max-width: 600px) { #fp-map { height: 300px; } }
</style>
<h1>Find a parking place</h1>

<?php if (!$ready) { echo ui_schema_missing_notice(); } ?>

<form class="card" method="get" action="find_parking.php" id="fp-form">
    <div class="row">
        <div><label for="city">City</label>
            <select id="city" name="city">
                <option value="">All cities</option>
                <?php foreach (FP_CITIES as $cn => $_): ?>
                    <option value="<?= e($cn) ?>"<?= $city === $cn ? ' selected' : '' ?>><?= e($cn) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label for="q">Area or place name</label>
            <input type="text" id="q" name="q" maxlength="60" value="<?= e($q) ?>" placeholder="e.g. Station"></div>
        <div><label for="kind">Type</label>
            <select id="kind" name="kind">
                <option value="">Bookable and free</option>
                <?php foreach (LOCATION_KINDS as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($label) ?> (bookable) only</option>
                <?php endforeach; ?>
                <option value="free"<?= $kind === 'free' ? ' selected' : '' ?>>Free parking only</option>
            </select></div>
        <div><label for="start">From (optional)</label>
            <input type="datetime-local" id="start" name="start" value="<?= e($startIn) ?>"></div>
        <div><label for="end">Until (optional)</label>
            <input type="datetime-local" id="end" name="end" value="<?= e($endIn) ?>"></div>
    </div>
    <p class="muted">Choose a start and end time to see only the bookable places that have a free space for the <em>whole</em> stay.
        Without times, bookable places with a free space right now are shown. Free parking spots are public and cannot be reserved here.</p>
    <button class="btn" type="submit">Search</button>
</form>

<?php foreach ($errors as $err) { echo ui_msg('err', $err); } ?>

<?php if (!$errors): ?>
    <div class="fp-bar">
        <button type="button" class="btn alt" id="fp-locate">Use my location</button>
        <span class="fp-legend muted">
            <span style="background:#16a34a"></span>Bookable
            <span style="background:#2563eb"></span>Free parking
            <span style="background:#dc2626"></span>You
        </span>
    </div>
    <div id="fp-map" role="region" aria-label="Map of parking places"></div>
    <p class="muted fp-status" id="fp-status" role="status" aria-live="polite">Your location is requested once when the page opens and is never tracked in the background. You can also pick a city above.</p>
    <p class="muted">Distances are approximate straight-line distances, not road distance or travel time; Google Maps works out the driving route.</p>

    <h2><?= $window ? 'Bookable places available ' . ui_dt($window['start']) . ' &ndash; ' . ui_dt($window['end']) : 'Parking places' ?><?= $city !== '' ? ' in ' . e($city) : '' ?></h2>
    <?php if (!$bookable && !$free): ?>
        <div class="card">No parking places match<?= $city !== '' ? ' in ' . e($city) : '' ?>. Try another city, area or type<?= $window ? ' or different times' : '' ?>.</div>
    <?php endif; ?>

    <div id="fp-list">
    <?php foreach ($bookable as $loc):
        $hasCoords = fp_valid_coords($loc['lat'], $loc['lng']);
        $closedNote = '';
        $canReserve = true;
        if ($window) {
            [$hoursErr] = validate_reservation_window(['start' => $startIn, 'end' => $endIn], $loc, $now);
            if ($hoursErr) {
                $canReserve = false;
                $closedNote = 'Closed during those times.';
            }
        }
        $isFull = (int) $loc['free_spaces'] <= 0;
        if ($isFull && $closedNote === '') {
            $closedNote = $window ? 'Full for those times.' : 'Full right now.';
        }
        $open = ($loc['open_from'] && $loc['open_until']) ? substr((string) $loc['open_from'], 0, 5) . ' &ndash; ' . substr((string) $loc['open_until'], 0, 5) : 'Open 24 hours';
        $reserveUrl = 'reserve.php?' . http_build_query(['location' => (int) $loc['id'], 'start' => $startIn, 'end' => $endIn]);
        $dir = $hasCoords ? fp_directions_url($loc['lat'], $loc['lng']) : null;
    ?>
        <div class="card place" data-key="b<?= (int) $loc['id'] ?>" data-type="bookable"<?= $hasCoords ? ' data-lat="' . e((string) (float) $loc['lat']) . '" data-lng="' . e((string) (float) $loc['lng']) . '"' : '' ?>>
            <div>
                <h3><?= e($loc['name']) ?> <?= ui_badge((string) $loc['kind']) ?> <?= fp_badge('Bookable', 'ok') ?><?= $isFull ? ' ' . fp_badge('Full', 'bad') : '' ?></h3>
                <div><?= e($loc['address']) ?>, <?= e($loc['city']) ?> <?= ui_map_link($loc['lat'], $loc['lng']) ?></div>
                <?php if ($loc['description'] !== ''): ?><div class="muted"><?= e($loc['description']) ?></div><?php endif; ?>
                <div class="muted">Pay: <?= e(implode(' or ', array_filter([!empty($loc['accepts_online'] ?? 1) ? 'online (UPI, card, net banking)' : '', !empty($loc['accepts_cash'] ?? 1) ? 'at the place' : '']))) ?></div>
                <div class="muted"><?= $open ?> &middot; <?= (int) $loc['free_spaces'] ?> of <?= (int) $loc['capacity'] ?> spaces free<?= $window ? ' for the whole stay' : ' now' ?></div>
                <?php if ($hasCoords): ?><div class="fp-dist muted" data-dist></div><?php else: ?><div class="muted">No map location registered for this place.</div><?php endif; ?>
            </div>
            <div class="right">
                <div><strong><?= ui_money((int) $loc['hourly_rate']) ?></strong> / hour</div>
                <div class="actions">
                <?php if ($canReserve && !$isFull): ?>
                    <a class="btn" href="<?= e($reserveUrl) ?>">Book Parking</a>
                <?php else: ?>
                    <span class="muted"><?= e($closedNote) ?></span>
                <?php endif; ?>
                <?php if ($dir): ?><a class="btn alt" data-dir href="<?= e($dir) ?>" target="_blank" rel="noopener noreferrer">Get Directions</a><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($free as $f):
        $dir = fp_directions_url($f['lat'], $f['lng']);
    ?>
        <div class="card place" data-key="<?= e($f['id']) ?>" data-type="free" data-lat="<?= e((string) $f['lat']) ?>" data-lng="<?= e((string) $f['lng']) ?>">
            <div>
                <h3><?= e($f['name']) ?> <?= fp_badge('Free Parking', 'info') ?></h3>
                <div><?= e($f['city']) ?> <?= ui_map_link($f['lat'], $f['lng']) ?></div>
                <div class="muted">Public parking, no booking and no price here. Availability is unknown.</div>
                <div class="fp-dist muted" data-dist></div>
            </div>
            <div class="right">
                <div class="actions"><?php if ($dir): ?><a class="btn alt" data-dir href="<?= e($dir) ?>" target="_blank" rel="noopener noreferrer">Get Directions</a><?php endif; ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <script type="application/json" id="fp-data"><?= json_encode($mapCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function () {
        'use strict';
        var cfg = JSON.parse(document.getElementById('fp-data').textContent);
        var statusEl = document.getElementById('fp-status');
        var locateBtn = document.getElementById('fp-locate');
        var list = document.getElementById('fp-list');
        var cards = Array.prototype.slice.call(list.querySelectorAll('.card.place'));
        var user = null;          // {lat, lng} - only ever set from a successful browser geolocation result
        var markers = {};
        var map = null, userLayers = [];

        function setStatus(t) { statusEl.textContent = t; }
        function validCoord(la, lo) { return typeof la === 'number' && typeof lo === 'number' && isFinite(la) && isFinite(lo) && Math.abs(la) <= 90 && Math.abs(lo) <= 180; }
        function distM(a, b, c, d) {
            var R = 6371000, r = Math.PI / 180, dp = (c - a) * r, dl = (d - b) * r;
            var h = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(dl / 2) * Math.sin(dl / 2);
            return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
        }
        function fmt(m) { return m < 1000 ? (Math.round(m / 10) * 10) + ' m' : (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km'; }
        function dirUrl(lat, lng) {
            var u = 'https://www.google.com/maps/dir/?api=1';
            if (user) { u += '&origin=' + encodeURIComponent(user.lat + ',' + user.lng); }
            return u + '&destination=' + encodeURIComponent(lat + ',' + lng) + '&travelmode=driving';
        }

        function refreshCards() {
            cards.forEach(function (c) {
                var la = parseFloat(c.getAttribute('data-lat')), lo = parseFloat(c.getAttribute('data-lng'));
                var d = c.querySelector('[data-dist]'), a = c.querySelector('a[data-dir]');
                c._d = null;
                if (!validCoord(la, lo)) { return; }
                if (a) { a.setAttribute('href', dirUrl(la, lo)); }
                if (user && d) { c._d = distM(user.lat, user.lng, la, lo); d.textContent = 'About ' + fmt(c._d) + ' away (straight line)'; }
                else if (d) { d.textContent = ''; }
            });
            if (user) {   // nearest first; places without coordinates last
                cards.sort(function (x, y) { return (x._d === null ? 1e12 : x._d) - (y._d === null ? 1e12 : y._d); })
                     .forEach(function (c) { list.appendChild(c); });
            }
        }

        function select(key, pan) {
            cards.forEach(function (c) { c.classList.toggle('fp-sel', c.getAttribute('data-key') === key); });
            var card = list.querySelector('[data-key="' + key + '"]');
            if (card && pan) { card.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
            var m = markers[key];
            if (m && map) { if (!pan) { /* from marker click */ } else { map.panTo(m.getLatLng()); } m.openPopup(); }
        }
        cards.forEach(function (c) {
            c.addEventListener('click', function (ev) { if (ev.target.closest('a,button')) { return; } select(c.getAttribute('data-key'), true); });
        });

        if (window.L) {
            map = L.map('fp-map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
            var pts = [];
            cfg.items.forEach(function (it) {
                if (!validCoord(it.lat, it.lng)) { return; }
                var color = it.type === 'bookable' ? '#16a34a' : '#2563eb';
                var m = L.circleMarker([it.lat, it.lng], { radius: 8, color: '#fff', weight: 2, fillColor: color, fillOpacity: 0.95 }).addTo(map);
                var box = document.createElement('div');
                var b = document.createElement('strong'); b.textContent = it.name; box.appendChild(b);
                box.appendChild(document.createElement('br'));
                box.appendChild(document.createTextNode(it.type === 'bookable' ? 'Bookable parking' : 'Free parking'));
                m.bindPopup(box);
                m.on('click', function () { select(it.key, false); var c = list.querySelector('[data-key="' + it.key + '"]'); if (c) { c.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } });
                markers[it.key] = m;
                pts.push([it.lat, it.lng]);
            });
            if (cfg.view) { map.setView([cfg.view.lat, cfg.view.lng], cfg.view.zoom); }
            else if (pts.length) { map.fitBounds(L.latLngBounds(pts), { padding: [24, 24] }); }
            else { map.setView([cfg.cities.Kolhapur.lat, cfg.cities.Kolhapur.lng], 12); }
        } else {
            document.getElementById('fp-map').textContent = 'The map could not be loaded (it needs an internet connection). The list below still works.';
        }

        function onFix(pos, centre) {
            var la = pos && pos.coords ? pos.coords.latitude : NaN, lo = pos && pos.coords ? pos.coords.longitude : NaN;
            if (!validCoord(la, lo)) { setStatus('Your browser returned an invalid location. Pick a city above instead.'); return; }
            user = { lat: la, lng: lo };
            if (map) {
                userLayers.forEach(function (l) { map.removeLayer(l); });
                userLayers = [];
                var acc = pos.coords.accuracy;
                if (typeof acc === 'number' && isFinite(acc) && acc > 0) { userLayers.push(L.circle([la, lo], { radius: acc, color: '#dc2626', weight: 1, fillOpacity: 0.08 }).addTo(map)); }
                var um = L.circleMarker([la, lo], { radius: 9, color: '#fff', weight: 3, fillColor: '#dc2626', fillOpacity: 1 }).addTo(map);
                um.bindPopup('You are here');
                userLayers.push(um);
                if (centre) { map.setView([la, lo], 15); }
            }
            refreshCards();
            setStatus('Showing your location. Distances are straight-line estimates, nearest first.');
        }
        function onErr(err) {
            var code = err && err.code;
            if (code === 1) { setStatus('Location permission was denied. You can still choose a city above and browse the map.'); }
            else if (code === 3) { setStatus('Finding your location timed out. Try "Use my location" again, or choose a city above.'); }
            else { setStatus('Your location is not available right now. Choose a city above to browse manually.'); }
        }
        function locate(centre) {
            if (!navigator.geolocation) { setStatus('This browser does not support location. Choose a city above to browse manually.'); return; }
            if (window.isSecureContext === false) { setStatus('Location only works on HTTPS or localhost. Choose a city above to browse manually.'); return; }
            setStatus('Finding your location… please allow location access in your browser.');
            navigator.geolocation.getCurrentPosition(function (p) { onFix(p, centre); }, onErr, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
        }
        locateBtn.addEventListener('click', function () { locate(true); });
        document.getElementById('city').addEventListener('change', function () { document.getElementById('fp-form').submit(); });
        locate(!cfg.view);   // a city picked on purpose is not overridden by the automatic location fix
    })();
    </script>
<?php endif; ?>
<?php ui_page_end();
