<?php
// ============================================================
// profile_api.php
// GET  ?member_id=X  -> fetch a member's profile fields
// POST { member_id, first_name, last_name, email, phone,
//        date_of_birth, address } -> update the profile
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";

$method = $_SERVER["REQUEST_METHOD"];

// ------------------------------------------------------------
// GET: fetch the member's current profile
// ------------------------------------------------------------
if ($method === "GET") {
    $memberId = intval($_GET["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT FirstName, LastName, Email, Phone, DateOfBirth, Address, ProfilePictureURL
         FROM Members WHERE MemberID = ?"
    );
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$member) {
        echo json_encode(["success" => false, "message" => "Member not found."]);
        exit();
    }

    echo json_encode(["success" => true, "profile" => $member]);
    exit();
}

// ------------------------------------------------------------
// POST: update the member's profile
// ------------------------------------------------------------
if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $memberId  = intval($data["member_id"] ?? 0);
    $firstName = trim($data["first_name"] ?? "");
    $lastName  = trim($data["last_name"] ?? "");
    $email     = trim($data["email"] ?? "");
    $phone     = trim($data["phone"] ?? "");
    $dob       = trim($data["date_of_birth"] ?? "");
    $address   = trim($data["address"] ?? "");

    if ($memberId <= 0 || empty($firstName) || empty($lastName) || empty($email)) {
        echo json_encode(["success" => false, "message" => "First name, last name, and email are required."]);
        exit();
    }

    if ($dob === "") { $dob = null; }
    if ($address === "") { $address = null; }
    if ($phone === "") { $phone = null; }

    // Make sure the new email isn't already used by a DIFFERENT member
    $checkStmt = $conn->prepare("SELECT MemberID FROM Members WHERE Email = ? AND MemberID != ?");
    $checkStmt->bind_param("si", $email, $memberId);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "That email is already used by another account."]);
        $checkStmt->close();
        $conn->close();
        exit();
    }
    $checkStmt->close();

    $stmt = $conn->prepare(
        "UPDATE Members
         SET FirstName = ?, LastName = ?, Email = ?, Phone = ?, DateOfBirth = ?, Address = ?
         WHERE MemberID = ?"
    );
    $stmt->bind_param("ssssssi", $firstName, $lastName, $email, $phone, $dob, $address, $memberId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(["success" => true, "message" => "Profile updated successfully."]);
    exit();
}

echo json_encode(["success" => false, "message" => "Unsupported request method."]);
?>
