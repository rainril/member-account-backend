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

if ($member && password_verify($password, $member["PasswordHash"])) {
    $response["success"] = true;
    $response["message"] = "Login successful.";
    $response["member"] = [
        "member_id"          => (int) $member["MemberID"],
        "MemberID"           => (int) $member["MemberID"], // Included for strict casing fallback
        "first_name"         => $member["FirstName"],
        "last_name"          => $member["LastName"],
        "email"              => $member["Email"],
        "phone"              => $member["Phone"],
        "profile_picture"    => $member["ProfilePictureURL"],
        "member_since"       => $member["MemberSince"],
        "membership_plan"    => $member["PlanLabel"],
        "membership_status"  => $member["MembershipStatus"],
        "next_renewal_date"  => $member["NextRenewalDate"],
        "plan_price"         => $member["PlanPrice"],
        "session_credits"    => $member["SessionCredits"],
        "sessions_used"      => $member["SessionsUsed"],
        "qr_code_data"       => $member["QRCodeData"],
    ];
} else {
    $response["message"] = "Invalid email or password.";
}

$conn->close();
echo json_encode($response);
?>