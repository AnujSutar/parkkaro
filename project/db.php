<?php
// Single place that opens the database connection ($conn).
// Credentials come from .env (see .env.example), never from this file.
require_once __DIR__ . '/src/bootstrap.php';
$conn = db();
