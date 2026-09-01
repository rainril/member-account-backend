<?php
// ============================================================
// checkin_api.php
// Logs a gym visit: inserts an AttendanceLogs row and increments
// the member's SessionsUsed on their active Membership.
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";
require "qr_helper.php";

$data = json_decode(file_get_contents("php://input"), true);
$response = ["success" => false, "message" => ""];

// Accept either a signed QR token (preferred, tamper-proof) or a raw
// member_id (kept for backward compatibility with the in-app "Check In
// Now" button, which isn't a real front-desk scan).
$qrToken = $data["qr_data"] ?? null;

if ($qrToken !== null) {
    $memberId = verifyQrToken($qrToken);
    if ($memberId === false) {
        $response["message"] = "Invalid or tampered QR code.";
        echo json_encode($response);
        exit();
    }
} else {
    $memberId = intval($data["member_id"] ?? 0);
}

if ($memberId <= 0) {
    $response["message"] = "Missing member ID.";
    echo json_encode($response);
    exit();
}

// 1. Find the member's current active membership
$stmt = $conn->prepare(
    "SELECT MembershipID, SessionCredits, SessionsUsed
     FROM Memberships
     WHERE MemberID = ? AND Status = 'Active'
     ORDER BY StartDate DESC LIMIT 1"
);
$stmt->bind_param("i", $memberId);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$membership) {
    $response["message"] = "No active membership found for this member.";
    echo json_encode($response);
    exit();
}

$sessionCredits = intval($membership["SessionCredits"]);
$sessionsUsed   = intval($membership["SessionsUsed"]);

if ($sessionsUsed >= $sessionCredits) {
    $response["message"] = "No session credits left this period.";
    echo json_encode($response);
    exit();
}

$conn->begin_transaction();
try {
    // 2. Log the attendance
    $logStmt = $conn->prepare(
        "INSERT INTO AttendanceLogs (MemberID, Date, CheckInTime, SessionCreditUsed)
         VALUES (?, CURDATE(), CURTIME(), 1)"
    );
    $logStmt->bind_param("i", $memberId);
    $logStmt->execute();
    $logStmt->close();

    // 3. Deduct a session credit (increment SessionsUsed)
    $updateStmt = $conn->prepare(
        "UPDATE Memberships SET SessionsUsed = SessionsUsed + 1 WHERE MembershipID = ?"
    );
    $updateStmt->bind_param("i", $membership["MembershipID"]);
    $updateStmt->execute();
    $updateStmt->close();

    // 4. Bump this month's MonthlyGoals.CompletedSessions (create the
    //    row with a default target if this is the member's first
    //    check-in this month).
    $currentMonth = date("Y-m");
    $goalCheck = $conn->prepare("SELECT GoalID FROM MonthlyGoals WHERE MemberID = ? AND Month = ?");
    $goalCheck->bind_param("is", $memberId, $currentMonth);
    $goalCheck->execute();
    $existingGoal = $goalCheck->get_result()->fetch_assoc();
    $goalCheck->close();

    if ($existingGoal) {
        $goalUpdate = $conn->prepare("UPDATE MonthlyGoals SET CompletedSessions = CompletedSessions + 1 WHERE GoalID = ?");
        $goalUpdate->bind_param("i", $existingGoal["GoalID"]);
        $goalUpdate->execute();
        $goalUpdate->close();
    } else {
        $defaultTarget = 20;
        $goalInsert = $conn->prepare(
            "INSERT INTO MonthlyGoals (MemberID, Month, TargetSessions, CompletedSessions) VALUES (?, ?, ?, 1)"
        );
        $goalInsert->bind_param("isi", $memberId, $currentMonth, $defaultTarget);
        $goalInsert->execute();
        $goalInsert->close();
    }

    $conn->commit();

    $newSessionsUsed = $sessionsUsed + 1;
    $response["success"]       = true;
    $response["message"]       = "Checked in successfully.";
    $response["sessions_used"] = $newSessionsUsed;
    $response["credits_total"] = $sessionCredits;
    $response["credits_left"]  = $sessionCredits - $newSessionsUsed;
    $response["check_in_date"] = date("F j, Y");
    $response["check_in_time"] = date("h:i A");
} catch (Exception $e) {
    $conn->rollback();
    $response["message"] = "Error: " . $e->getMessage();
}

$conn->close();
echo json_encode($response);
?>
