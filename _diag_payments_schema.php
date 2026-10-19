<?php
// Temporary read-only diagnostic. Safe to delete after use.
require __DIR__ . "/db_connect.php";
header("Content-Type: application/json");

$out = [];

$result = $conn->query("DESCRIBE payments");
$cols = [];
while ($row = $result->fetch_assoc()) {
    $cols[] = $row['Field'] . ' (' . $row['Type'] . ')';
}
$out['columns'] = $cols;

$result2 = $conn->query(
    "SELECT PaymentID, MemberID, Date, Amount, Method, Status, Purpose, ReceiptURL
     FROM payments
     ORDER BY PaymentID DESC
     LIMIT 10"
);
$rows = [];
while ($row = $result2->fetch_assoc()) {
    $rows[] = $row;
}
$out['recent_payments'] = $rows;

echo json_encode($out, JSON_PRETTY_PRINT);
