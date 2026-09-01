<?php
// ============================================================
// payment_history_api.php
// GET ?member_id=X -> full payment history for this member,
// straight from the Payments table (not reconstructed client-side).
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";

$memberId = intval($_GET["member_id"] ?? 0);

if ($memberId <= 0) {
    echo json_encode(["success" => false, "message" => "Missing member_id."]);
    exit();
}

$stmt = $conn->prepare(
    "SELECT py.Date, py.Amount, py.Method, py.Status, pl.DurationLabel
     FROM Payments py
     LEFT JOIN Memberships ms ON ms.MembershipID = py.MembershipID
     LEFT JOIN Plans pl ON pl.PlanID = ms.PlanID
     WHERE py.MemberID = ?
     ORDER BY py.PaymentID DESC"
);
$stmt->bind_param("i", $memberId);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = [
        "date"   => $row["Date"],
        "plan"   => $row["DurationLabel"] ?? "—",
        "amount" => (float) $row["Amount"],
        "method" => $row["Method"],
        "status" => $row["Status"],
    ];
}
$stmt->close();
$conn->close();

echo json_encode(["success" => true, "history" => $history]);
?>