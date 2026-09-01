<?php

require_once __DIR__ . '/vendor/autoload.php';

// safeLoad() instead of load(): won't crash if there's no .env file.
// Locally (XAMPP/etc.) you can still use a .env file for testing.
// On Render, the real environment variables you set in the dashboard
// are used directly instead — this just makes sure both work.
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

?>
