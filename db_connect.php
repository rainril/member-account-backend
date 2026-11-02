<?php

require_once __DIR__ . '/config.php';

$host     = $_ENV['DB_HOST'];
$port     = $_ENV['DB_PORT'] ?? 3306;
$username = $_ENV['DB_USERNAME'];
$password = $_ENV['DB_PASSWORD'];
$database = $_ENV['DB_NAME'];
$ca_cert  = __DIR__ . '/certs/aiven-ca.pem';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = mysqli_init();

// Aiven (remote) requires SSL; local XAMPP does not support it.
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
$flags = 0;

if (!$isLocal) {
    $conn->ssl_set(null, null, $ca_cert, null, null);
    $flags = MYSQLI_CLIENT_SSL;
}

$connected = $conn->real_connect($host, $username, $password, $database, (int)$port, null, $flags);

if (!$connected) {
    die("Connection failed: " . mysqli_connect_error());
}

$conn->set_charset("utf8mb4");
?>
