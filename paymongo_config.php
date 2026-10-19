<?php

// Get your keys at: https://dashboard.paymongo.com/developers
//   PAYMONGO_SECRET_KEY     -> "sk_test_..." / "sk_live_..." secret key. Used as the
//                              HTTP Basic auth username (with an empty password) on
//                              every PayMongo API call.
//   PAYMONGO_WEBHOOK_SECRET -> "whsk_..." signing secret shown once when you create
//                              the webhook endpoint. Used to verify the
//                              Paymongo-Signature header on incoming webhook calls.

require_once __DIR__ . '/config.php';

define('PAYMONGO_SECRET_KEY', $_ENV['PAYMONGO_SECRET_KEY'] ?? '');
define('PAYMONGO_WEBHOOK_SECRET', $_ENV['PAYMONGO_WEBHOOK_SECRET'] ?? '');

?>
