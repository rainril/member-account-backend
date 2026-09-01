<?php
// ============================================================
// login_api.php
// Verifies a member's email + password against the Members table
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

// 1. Look up the member by email, along with their most recent membership
//    (plan name, dates, status) so the app has everything it needs for
//    the dashboard right after login.
$stmt = $conn->prepare(
    "SELECT m.MemberID, m.FirstName, m.LastName, m.Email, m.PasswordHash,
            m.Phone, m.ProfilePictureURL, m.MemberSince, m.QRCodeData,
            p.DurationLabel AS PlanLabel, p.Price AS PlanPrice,
            ms.StartDate, ms.NextRenewalDate, ms.Status AS MembershipStatus,
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

// 2. Verify the member exists AND the password matches the stored hash
if ($member && password_verify($password, $member["PasswordHash"])) {
    $response["success"] = true;
    $response["message"] = "Login successful.";
    $response["member"] = [
        "member_id"          => (int) $member["MemberID"],
        "first_name"         => $member["FirstName"],
        "last_name"          => $member["LastName"],
        "email"              => $member["Email"],
        "phone"              => $member["Phone"],
        "profile_picture"    => $member["ProfilePictureURL"],
        "member_since"       => $member["MemberSince"],
        "membership_plan"    => $member["PlanLabel"],       // e.g. "7 Months", or null if no membership yet
        "membership_status"  => $member["MembershipStatus"], // "Active", "Expired", etc., or null
        "next_renewal_date"  => $member["NextRenewalDate"],
        "plan_price"         => $member["PlanPrice"],        // e.g. 3500.00, or null
        "session_credits"    => $member["SessionCredits"],   // e.g. 30, or null
        "sessions_used"      => $member["SessionsUsed"],     // e.g. 5, or null
        "qr_code_data"       => $member["QRCodeData"],       // the signed QR token
    ];
} else {
    // Same generic message whether the email doesn't exist or the
    // password is wrong -- don't reveal which one it was.
    $response["message"] = "Invalid email or password.";
}

$conn->close();
echo json_encode($response);
?>
