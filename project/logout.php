<?php
require_once __DIR__ . '/src/bootstrap.php';
destroy_session();
header('Location: home.html');
exit;
