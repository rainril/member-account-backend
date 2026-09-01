<?php

// Get a free key at: https://fdc.nal.usda.gov/api-key-signup.html
// Until you have your own, USDA's public 'DEMO_KEY' works for testing
// but is rate-limited to 30 requests/hour and 50/day -- replace it
// with your real key before shipping.

require_once __DIR__ . '/config.php';

define('USDA_API_KEY', $_ENV['USDA_API_KEY']);

?>