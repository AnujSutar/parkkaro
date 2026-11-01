<?php
/**
 * Page frame for the sign-in / registration pages, matching home.html (same header, fonts and colours from site.css).
 * auth_page_start() prints everything up to the form column; auth_page_end() closes it. Pages put their own forms between.
 */
declare(strict_types=1);

/**
 * @param string $title    browser tab title
 * @param array  $side     ['heading' => ..., 'text' => ..., 'points' => [..]] for the left panel, or [] for a wide, single-column page
 */
function auth_page_start(string $title, array $side = []): void
{
    $wide = $side === [];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . e($title) . ' - Parkaro</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&amp;family=Hanken+Grotesk:wght@400;600;700&amp;display=swap">'
        . '<link rel="stylesheet" href="' . e(app_url('site.css')) . '"></head><body>';
    echo '<header class="site-head"><div class="wrap"><a class="brand" href="home.html"><span class="p" aria-hidden="true">P</span>Parkaro</a>'
        . '<nav class="site-nav" aria-label="Main"><a href="home.html">Home</a><a href="find_parking.php">Find parking</a><a href="booking.html">Live map</a>'
        . '<a href="register.php">Register</a><details><summary class="cta">Log in</summary><div class="menu">'
        . '<a href="index.php?login=true">User Login</a><a href="login1.php">Admin Login</a></div></details></nav></div></header>';
    echo '<main class="wrap auth"><div class="auth-card' . ($wide ? ' wide' : '') . '">';
    if (!$wide) {
        echo '<aside class="auth-side"><h1>' . e((string) $side['heading']) . '</h1><p>' . e((string) $side['text']) . '</p><ul>';
        foreach ($side['points'] as $pt) {
            echo '<li>' . e((string) $pt) . '</li>';
        }
        echo '</ul></aside>';
    }
    echo '<section class="auth-form">';
}

function auth_page_end(): void
{
    echo '</section></div></main><footer class="site-foot"><div class="wrap"><span>Parkaro &ndash; parking for Kolhapur, Ajara and Gadhinglaj</span>'
        . '<span><a href="home.html">Home</a> &middot; <a href="find_parking.php">Find parking</a> &middot; <a href="booking.html">Live map</a></span></div></footer></body></html>';
}

/** Segmented switch between two related pages. $tabs = [[label, href, isCurrent], ...] */
function auth_tabs(array $tabs): string
{
    $out = '<div class="tabs">';
    foreach ($tabs as [$label, $href, $cur]) {
        $out .= '<a href="' . e($href) . '"' . ($cur ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
    }
    return $out . '</div>';
}
