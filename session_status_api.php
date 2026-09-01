<?php
// ============================================================
// session_status_api.php
// GET ?member_id=X -> fetch the member's LIVE session credit status
// (SessionCredits, SessionsUsed) from their active membership.
//
// Used by the Flutter app to poll for real-time updates after an
// admin scans the member's QR code at the front desk -- so the
// numbers update automatically without the member needing to
// manually refresh the page.
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
    "SELECT SessionCredits, SessionsUsed
     FROM Memberships
     WHERE MemberID = ? AND Status = 'Active'
     ORDER BY StartDate DESC LIMIT 1"
);
$stmt->bind_param("i", $memberId);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Count check-ins so far this week (Monday to today), for the
// dashboard's "Visits this week" stat.
$weekStart = date('Y-m-d', strtotime('monday this week'));
$visitStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM AttendanceLogs WHERE MemberID = ? AND Date >= ?"
);
$visitStmt->bind_param("is", $memberId, $weekStart);
$visitStmt->execute();
$visitRow = $visitStmt->get_result()->fetch_assoc();
$visitStmt->close();

$conn->close();

if (!$membership) {
    echo json_encode(["success" => false, "message" => "No active membership found."]);
    exit();
}

echo json_encode([
    "success"          => true,
    "session_credits"  => intval($membership["SessionCredits"]),
    "sessions_used"    => intval($membership["SessionsUsed"]),
    "visits_this_week" => intval($visitRow["cnt"] ?? 0),
]);
?>