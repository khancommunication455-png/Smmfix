<?php

define('LARAVEL_START', microtime(true));

// Register the auto loader as early as possible, so that we can use it in WP.
require __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
require_once __DIR__ . '/../bootstrap/app.php';
