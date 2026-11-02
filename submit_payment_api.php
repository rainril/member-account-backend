<?php
// ============================================================
// submit_payment_api.php
// POST (multipart/form-data): member_id, membership_id, amount, method, receipt (file)
// Saves the uploaded receipt and creates a Payment row with Status = 'Pending'
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";
require_once __DIR__ . '/member_session_helpers.php';

$memberId     = member_session_resolve_member_id($conn, intval($_POST["member_id"] ?? 0));
$membershipId = intval($_POST["membership_id"] ?? 0);
$amount       = floatval($_POST["amount"] ?? 0);
$method       = $_POST["method"] ?? "GCash";

if ($memberId <= 0 || $amount <= 0) {
    echo json_encode(["success" => false, "message" => "Missing required fields."]);
    exit();
}

if ($membershipId > 0) {
    $membershipCheck = $conn->prepare('SELECT 1 FROM Memberships WHERE MembershipID = ? AND MemberID = ? LIMIT 1');
    $membershipCheck->bind_param('ii', $membershipId, $memberId);
    $membershipCheck->execute();
    $belongsToMember = (bool) $membershipCheck->get_result()->fetch_row();
    $membershipCheck->close();
    if (!$belongsToMember) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Membership does not belong to this account."]);
        exit();
    }
}

if (!isset($_FILES["receipt"]) || $_FILES["receipt"]["error"] !== UPLOAD_ERR_OK) {
    echo json_encode(["success" => false, "message" => "Receipt image is required."]);
    exit();
}

// Prepare uploads/receipts folder
$uploadDir = __DIR__ . "/uploads/receipts/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Build a unique filename
$ext = pathinfo($_FILES["receipt"]["name"], PATHINFO_EXTENSION);
$filename = "receipt_" . $memberId . "_" . time() . "." . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($_FILES["receipt"]["tmp_name"], $destPath)) {
    echo json_encode(["success" => false, "message" => "Failed to save receipt."]);
    exit();
}

// Relative path to store in DB (so it can be served back via URL)
$relativePath = "uploads/receipts/" . $filename;

$stmt = $conn->prepare(
    "INSERT INTO Payments (MemberID, MembershipID, Date, Amount, Method, Status, ReceiptPath)
     VALUES (?, ?, NOW(), ?, ?, 'Pending', ?)"
);
$stmt->bind_param("iidss", $memberId, $membershipId, $amount, $method, $relativePath);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(["success" => true, "message" => "Payment submitted, waiting for admin confirmation."]);
?>
