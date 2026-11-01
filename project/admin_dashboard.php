<?php
// This file was an unfinished duplicate admin dashboard: it checked a session key that no login ever
// sets ($_SESSION['admin_name']), connected to a different database ("parking") and sent logged-out
// visitors to a page that did not exist. It could never be opened successfully.
// The admin dashboard is dashboard1.php (what login1.php redirects to). The original file is kept
// in legacy/admin_dashboard.php.
require_once __DIR__ . '/src/bootstrap.php';
header('Location: ' . app_url(ADMIN_HOME));
exit;
