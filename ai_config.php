<?php

// Get a free key at: https://console.groq.com/keys

require_once __DIR__ . '/config.php';

define('GROQ_API_KEY', $_ENV['GROQ_API_KEY']);
define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY']);

?>