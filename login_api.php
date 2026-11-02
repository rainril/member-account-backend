<?php
// ============================================================
// login_api.php
// Verifies a member's email + password against the Members table
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
header("Cache-Control: no-store");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";
require_once "member_session_helpers.php";

$data = json_decode(file_get_contents("php://input"), true);
$response = ["success" => false, "message" => ""];

if (!$data) {
    $response["message"] = "No data received.";
    echo json_encode($response);
    exit();
}

$email    = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

if (empty($email) || empty($password)) {
    $response["message"] = "Please enter both email and password.";
    echo json_encode($response);
    exit();
}

$stmt = $conn->prepare(
    "SELECT m.MemberID, m.FirstName, m.LastName, m.Email, m.PasswordHash,
            m.Phone, m.ProfilePictureURL, m.MemberSince, m.QRCodeData,
            p.DurationLabel AS PlanLabel, p.Price AS PlanPrice,
            ms.MembershipID, ms.StartDate, ms.NextRenewalDate, ms.Status AS MembershipStatus,
            ms.SessionCredits, ms.SessionsUsed
     FROM Members m
     LEFT JOIN Memberships ms ON ms.MemberID = m.MemberID
     LEFT JOIN Plans p ON p.PlanID = ms.PlanID
     WHERE m.Email = ?
     ORDER BY (ms.Status = 'Active') DESC, ms.MembershipID DESC
     LIMIT 1"
);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$member = $result->fetch_assoc();
$stmt->close();

if ($member && password_verify($password, $member["PasswordHash"])) {
    // Older signup records may already have an active membership even though
    // its initial receipt is still awaiting review. Do not allow login until
    // that membership has an approved payment.
    if (!member_session_payment_approved($conn, $member)) {
        echo json_encode(['success' => false, 'message' => 'Your payment has not been approved. Please contact the gym.']);
        $conn->close();
        exit();
    }
    try {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $memberId = (int) $member['MemberID'];
        $cleanup = $conn->prepare('DELETE FROM MemberSessions WHERE MemberID = ? AND ExpiresAt <= UTC_TIMESTAMP()');
        $cleanup->bind_param('i', $memberId);
        $cleanup->execute();
        $cleanup->close();
        $stmt = $conn->prepare('INSERT INTO MemberSessions (MemberID, TokenHash, CreatedAt, ExpiresAt) VALUES (?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))');
        $stmt->bind_param('is', $memberId, $hash);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Could not create member session: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Sign-in is temporarily unavailable. Please try again.']);
        $conn->close();
        exit();
    }
    $response["success"] = true;
    $response["message"] = "Login successful.";
    $response["member"] = member_session_payload($member);
    $response["session_token"] = $token;
} else {
    $response["message"] = "Invalid email or password.";
    if (!$member) {
        $pending = $conn->prepare("SELECT PasswordHash FROM PendingRegistrations WHERE Email = ? AND Status = 'Pending' LIMIT 1");
        $pending->bind_param('s', $email);
        $pending->execute();
        $registration = $pending->get_result()->fetch_assoc();
        $pending->close();
        if ($registration && password_verify($password, $registration['PasswordHash'])) {
            $response['message'] = 'Your registration is pending admin confirmation.';
        }
    }
}

$conn->close();
echo json_encode($response);
?>
