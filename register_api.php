<?php
// ============================================================
// register_api.php
// JSON REST API endpoint for member registration
// Called by Flutter Web (or any frontend) via HTTP POST
// ============================================================

// Allow requests from your Flutter Web app (CORS)
// For development, "*" is fine. For production, replace with your actual domain.
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle CORS preflight request (browsers send this automatically before POST)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";

// 1. Read the JSON body sent by Flutter
$data = json_decode(file_get_contents("php://input"), true);

$response = ["success" => false, "message" => ""];

// 2. Validate that data was received
if (!$data) {
    $response["message"] = "No data received.";
    echo json_encode($response);
    exit();
}

$firstName = trim($data["first_name"] ?? "");
$lastName  = trim($data["last_name"] ?? "");
$email     = trim($data["email"] ?? "");
$phone     = trim($data["phone"] ?? "");
$dob       = $data["date_of_birth"] ?? null;   // expected format: "YYYY-MM-DD"
$address   = trim($data["address"] ?? "");
$password  = $data["password"] ?? "";

// Convert empty strings to NULL so the DATE column doesn't reject them
if ($dob === "") { $dob = null; }
if ($address === "") { $address = null; }
if ($phone === "") { $phone = null; }

// 3. Basic validation
if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
    $response["message"] = "Please fill out all required fields.";
    echo json_encode($response);
    exit();
}

// 4. Check if email already exists
$checkStmt = $conn->prepare("SELECT MemberID FROM Members WHERE Email = ?");
$checkStmt->bind_param("s", $email);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows > 0) {
    $response["message"] = "That email is already registered.";
    echo json_encode($response);
    $checkStmt->close();
    $conn->close();
    exit();
}
$checkStmt->close();

// 5. Hash the password -- NEVER store plain text passwords
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// 6. Insert into Members table
$stmt = $conn->prepare(
    "INSERT INTO Members (FirstName, LastName, Email, PasswordHash, Phone, DateOfBirth, Address)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param("sssssss", $firstName, $lastName, $email, $passwordHash, $phone, $dob, $address);

if ($stmt->execute()) {
    $response["success"] = true;
    $response["message"] = "Registration successful.";
    $response["member_id"] = $stmt->insert_id;
} else {
    $response["message"] = "Something went wrong: " . $stmt->error;
}

$stmt->close();
$conn->close();

// 7. Send the JSON response back to Flutter
echo json_encode($response);
?>
