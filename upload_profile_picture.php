<?php
// ============================================================
// upload_profile_picture.php
// Receives a profile picture upload (multipart/form-data),
// saves it to /uploads/profile_pictures/, and updates
// Members.ProfilePictureURL with the public URL.
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

$response = ["success" => false, "message" => ""];

$memberId = intval($_POST["member_id"] ?? 0);
if ($memberId <= 0) {
    $response["message"] = "Missing member_id.";
    echo json_encode($response);
    exit();
}

if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
    $response["message"] = "No photo received, or the upload failed.";
    echo json_encode($response);
    exit();
}

// Detect the REAL file type by inspecting the uploaded file's content
// on disk -- don't trust $_FILES["photo"]["type"], since that's just
// whatever the client claims (browsers/HTTP clients often send a
// generic "application/octet-stream" regardless of the actual image).
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedType = finfo_file($finfo, $_FILES["photo"]["tmp_name"]);
finfo_close($finfo);

$allowedTypes = ["image/jpeg", "image/png", "image/webp"];
if (!in_array($detectedType, $allowedTypes)) {
    $response["message"] = "Only JPG, PNG, or WEBP images are allowed.";
    echo json_encode($response);
    exit();
}

// Limit to 5MB
$maxSize = 5 * 1024 * 1024;
if ($_FILES["photo"]["size"] > $maxSize) {
    $response["message"] = "Image must be smaller than 5MB.";
    echo json_encode($response);
    exit();
}

// Create the uploads folder if it doesn't exist yet
$uploadDir = __DIR__ . "/uploads/profile_pictures/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Build a unique filename so each upload doesn't overwrite another
$ext = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
$filename = "member_" . $memberId . "_" . time() . "." . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $destPath)) {
    $response["message"] = "Failed to save the uploaded file.";
    echo json_encode($response);
    exit();
}

// Build the public URL the Flutter app can load the image from
$protocol = isset($_SERVER["HTTPS"]) ? "https" : "http";
$host = $_SERVER["HTTP_HOST"];
$publicUrl = "$protocol://$host/memberaccount/uploads/profile_pictures/$filename";

// Save the URL to the member's row
$stmt = $conn->prepare("UPDATE Members SET ProfilePictureURL = ? WHERE MemberID = ?");
$stmt->bind_param("si", $publicUrl, $memberId);
$stmt->execute();
$stmt->close();
$conn->close();

$response["success"] = true;
$response["message"] = "Profile picture updated.";
$response["url"] = $publicUrl;
echo json_encode($response);
?>
