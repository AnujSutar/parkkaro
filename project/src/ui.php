<?php
/**
 * Page layout helpers for the new user and admin pages (find parking, reservations,
 * payments, dashboards, earnings). The look follows the existing pages: the dark
 * sidebar and green accent of dashboard1.php for admins, the white card on a light
 * background used by the user pages and tickets for visitors.
 *
 * Every value printed through these helpers is escaped with e().
 */
declare(strict_types=1);

function ui_money(int $rupees): string
{
    return '&#8377;' . number_format($rupees);
}

/** Format a stored 'Y-m-d H:i:s' text for display ("20 Oct 2026, 14:00"). Empty for null. */
function ui_dt(?string $dt, string $format = 'd M Y, H:i'): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dt);
    return $d === false ? e($dt) : e($d->format($format));
}

/** Coloured label for a reservation / payment status. */
function ui_badge(string $status): string
{
    $labels = [
        'pending_payment' => ['Awaiting payment', 'warn'], 'confirmed' => ['Confirmed', 'ok'],
        'cancelled' => ['Cancelled', 'muted'], 'expired' => ['Expired', 'muted'],
        'created' => ['Not paid', 'warn'], 'succeeded' => ['Paid', 'ok'], 'failed' => ['Failed', 'bad'], 'refunded' => ['Refunded', 'muted'],
        'public' => ['Public', 'info'], 'private' => ['Private', 'priv'], 'active' => ['Active', 'ok'], 'inactive' => ['Switched off', 'muted'],
    ];
    [$text, $class] = $labels[$status] ?? [$status, 'muted'];
    return '<span class="badge ' . e($class) . '">' . e($text) . '</span>';
}

function ui_css(): string
{
    return <<<'CSS'
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #333; background: #f4f6f9; }
a { color: #0b7a3b; }
h1 { color: #1f2937; margin: 0 0 20px; font-size: 26px; }
h2 { color: #1f2937; font-size: 20px; margin: 28px 0 12px; }
.muted { color: #6b7280; font-size: 14px; }
/* layout: visitor/user */
.u-top { background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,.08); padding: 12px 24px; display: flex; flex-wrap: wrap; gap: 6px 20px; align-items: center; }
.u-top .brand { font-weight: 700; color: #28a745; font-size: 20px; margin-right: 12px; text-decoration: none; }
.u-top a.nav { text-decoration: none; color: #333; padding: 6px 2px; border-bottom: 2px solid transparent; }
.u-top a.nav.on, .u-top a.nav:hover { border-color: #28a745; color: #28a745; }
.u-top .sp { flex: 1; }
.u-main { max-width: 1000px; margin: 24px auto; padding: 0 16px; }
/* layout: admin */
.a-side { width: 250px; background: #1f2937; color: #fff; height: 100vh; padding: 20px 0; position: fixed; top: 0; left: 0; overflow-y: auto; }
.a-side h3 { text-align: center; margin: 0 0 24px; font-size: 18px; padding: 0 12px; word-break: break-word; }
.a-side a { display: block; color: #fff; text-decoration: none; padding: 13px 30px; transition: .3s; }
.a-side a:hover, .a-side a.on { background: #374151; }
.a-side .grp { padding: 14px 30px 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: #9ca3af; }
.a-main { margin-left: 250px; padding: 30px; }
@media (max-width: 800px) { .a-side { position: static; width: 100%; height: auto; } .a-main { margin-left: 0; padding: 16px; } }
/* components */
.card { background: #fff; border-radius: 12px; padding: 20px 24px; box-shadow: 0 0 10px rgba(0,0,0,.08); margin-bottom: 20px; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 8px; }
.stat { background: #fff; border-radius: 12px; padding: 18px; box-shadow: 0 0 10px rgba(0,0,0,.08); text-align: center; }
.stat .n { font-size: 26px; font-weight: 700; color: #10b981; }
.stat .l { font-size: 14px; color: #555; margin-top: 4px; }
.stat.warn .n { color: #d97706; }
table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 0 10px rgba(0,0,0,.08); }
th, td { padding: 11px 12px; text-align: left; border-bottom: 1px solid #eef0f3; vertical-align: top; font-size: 14px; }
th { background: #1f2937; color: #fff; font-weight: 600; }
tr:last-child td { border-bottom: 0; }
.tablewrap { overflow-x: auto; }
label { display: block; font-size: 14px; color: #555; margin: 12px 0 4px; }
input[type=text], input[type=number], input[type=email], input[type=date], input[type=time], input[type=datetime-local], select, textarea {
  width: 100%; padding: 9px 10px; border: 1px solid #ccc; border-radius: 6px; font: inherit; background: #fff; }
.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0 16px; }
.btn { display: inline-block; padding: 9px 16px; background: #28a745; color: #fff; border: 0; border-radius: 6px; font: inherit; cursor: pointer; text-decoration: none; }
.btn:hover { background: #1e8e38; }
.btn.alt { background: #1f2937; } .btn.alt:hover { background: #374151; }
.btn.danger { background: #c0392b; } .btn.danger:hover { background: #a93226; }
.btn.small { padding: 5px 10px; font-size: 13px; }
.btn[disabled] { background: #9ca3af; cursor: not-allowed; }
.msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.msg.ok { background: #e7f6ec; color: #145a2c; border: 1px solid #b7e1c4; }
.msg.err { background: #fdecea; color: #7a1f16; border: 1px solid #f5c2bd; }
.msg.warn { background: #fff7e0; color: #7a5a00; border: 1px solid #f0dc9b; }
.badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.badge.ok { background: #d9f3e3; color: #145a2c; } .badge.warn { background: #fff0c2; color: #7a5a00; }
.badge.bad { background: #fadbd8; color: #7a1f16; } .badge.muted { background: #e5e7eb; color: #4b5563; }
.badge.info { background: #dbeafe; color: #1e40af; } .badge.priv { background: #ede9fe; color: #5b21b6; }
.inline { display: inline; }
.place { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; }
.place h3 { margin: 0 0 4px; }
.right { text-align: right; }
CSS;
}

/**
 * Print the top of a page.
 * @param string $mode   'user' (visitor/customer pages) or 'admin' (sidebar layout)
 * @param string $active key of the highlighted menu entry
 * @param string $adminName shown in the admin sidebar
 */
function ui_page_start(string $title, string $mode, string $active = '', string $adminName = ''): void
{
    start_secure_session(); // must happen before any output (cookie headers)
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . e($title) . ' - Parkaro</title><style>' . ui_css() . '</style></head><body>';

    if ($mode === 'admin') {
        $items = [
            ['dashboard1.php', 'dash', 'Admin Dashboard'],
            ['admin_locations.php', 'locations', 'Manage Parking Locations'],
            ['admin_reservations.php', 'reservations', 'Reservations'],
            ['admin_earnings.php', 'earnings', 'Earnings'],
            ['admin_create.php', 'addadmin', 'Add Admin Account'],
        ];
        $legacy = [['view_bookings.php', 'View Bookings'], ['manage_bookings.php', 'Manage Bookings']];
        echo '<div class="a-side"><h3>Welcome, ' . e($adminName) . '</h3>';
        foreach ($items as [$href, $key, $label]) {
            echo '<a href="' . e($href) . '"' . ($active === $key ? ' class="on"' : '') . '>' . e($label) . '</a>';
        }
        echo '<div class="grp">Older pages</div>';
        foreach ($legacy as [$href, $label]) {
            echo '<a href="' . e($href) . '">' . e($label) . '</a>';
        }
        echo '<a href="logout.php">Logout</a></div><div class="a-main">';
        return;
    }

    $loggedIn = !empty($_SESSION['user_id']);
    $nav = [['home.html', 'home', 'Home'], ['find_parking.php', 'find', 'Find parking']];
    if ($loggedIn) {
        $nav[] = ['my_dashboard.php', 'mine', 'My dashboard'];
        $nav[] = ['index.php', 'support', 'Support'];
    }
    echo '<div class="u-top"><a class="brand" href="home.html">Parkaro</a>';
    foreach ($nav as [$href, $key, $label]) {
        echo '<a class="nav' . ($active === $key ? ' on' : '') . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '<span class="sp"></span>';
    if ($loggedIn) {
        echo '<span class="muted">Hi, ' . e($_SESSION['first_name'] ?? 'there') . '</span><a class="nav" href="logout.php">Logout</a>';
    } else {
        echo '<a class="nav" href="index.php?login=true">User login</a><a class="nav" href="index.php">Register</a><a class="nav" href="login1.php">Admin login</a>';
    }
    echo '</div><div class="u-main">';
}

function ui_page_end(): void
{
    echo '</div></body></html>';
}

/** A message box. $kind: ok | err | warn. Text is escaped. */
function ui_msg(string $kind, string $text): string
{
    return '<div class="msg ' . e($kind) . '">' . e($text) . '</div>';
}

/** Standard "run the migration first" notice (shown when the new tables do not exist yet). */
function ui_schema_missing_notice(): string
{
    return ui_msg('warn', 'The database has not been upgraded yet. Run database/migrations/001_parkkaro_core.mysql.sql (see docs/PARKKARO_UPGRADE.md), then reload this page.');
}

/** Link to an OpenStreetMap view of a place; empty string when there are no coordinates. */
function ui_map_link($lat, $lng): string
{
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return '';
    }
    $la = number_format((float) $lat, 6, '.', '');
    $lo = number_format((float) $lng, 6, '.', '');
    return '<a href="https://www.openstreetmap.org/?mlat=' . e($la) . '&amp;mlon=' . e($lo) . '#map=17/' . e($la) . '/' . e($lo) . '" target="_blank" rel="noopener noreferrer">Map</a>';
}
