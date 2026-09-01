<?php
// ============================================================
// attendance_stats_api.php
// GET ?member_id=X -> real attendance history, used to power the
// Dashboard's "This Week" tracker + visits-this-week count, and
// the Progress page's Current Streak + Monthly Attendance chart.
//
// Sourced from AttendanceLogs, which BOTH the member's own "Check
// In Now" button AND the admin's front-desk QR scanner write to --
// so this always reflects real, combined attendance.
//
// IMPORTANT: Only counts check-ins from the member's CURRENT active
// membership's CreatedAt timestamp onward. Date-only comparison isn't
// enough -- a same-day renewal would have the same date as an older
// check-in on that day, so we need full timestamp precision to avoid
// showing stale visit history/streaks from a previous, now-expired
// membership after a renewal or upgrade.
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

// 1. Find the current active membership's created_at TIMESTAMP -- this
//    is the precise "reset point" for attendance stats. StartDate alone
//    is date-only, so a same-day renewal can't be distinguished from an
//    older check-in on that same date -- CreatedAt has full precision.
$membershipStmt = $conn->prepare(
    "SELECT CreatedAt FROM Memberships
     WHERE MemberID = ? AND Status = 'Active'
     ORDER BY MembershipID DESC LIMIT 1"
);
$membershipStmt->bind_param("i", $memberId);
$membershipStmt->execute();
$membershipRow = $membershipStmt->get_result()->fetch_assoc();
$membershipStmt->close();

$sixMonthsAgo = date('Y-m-d 00:00:00', strtotime('-6 months', strtotime(date('Y-m-01'))));

$membershipCreatedAt = $membershipRow['CreatedAt'] ?? $sixMonthsAgo;
$effectiveStartTimestamp = max($membershipCreatedAt, $sixMonthsAgo);

// 2. Get all check-in dates from this membership period onward.
$stmt = $conn->prepare(
    "SELECT Date FROM AttendanceLogs
     WHERE MemberID = ? AND CONCAT(Date, ' ', CheckInTime) >= ?
     ORDER BY Date ASC"
);
$stmt->bind_param("is", $memberId, $effectiveStartTimestamp);
$stmt->execute();
$result = $stmt->get_result();

$dates = [];
while ($row = $result->fetch_assoc()) {
    $dates[] = $row["Date"];
}
$stmt->close();

// 3. Total sessions THIS membership period (matches SessionsUsed logic).
$totalStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM AttendanceLogs
     WHERE MemberID = ? AND CONCAT(Date, ' ', CheckInTime) >= ?"
);
$totalStmt->bind_param("is", $memberId, $effectiveStartTimestamp);
$totalStmt->execute();
$totalRow = $totalStmt->get_result()->fetch_assoc();
$totalStmt->close();

$conn->close();

echo json_encode([
    "success"        => true,
    "visit_dates"    => $dates,
    "total_sessions" => intval($totalRow["cnt"] ?? 0),
]);
?>