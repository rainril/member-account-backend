<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
require __DIR__ . '/db_connect.php';

$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$phone = trim($_POST['phone'] ?? '') ?: null;
$dob = trim($_POST['date_of_birth'] ?? '') ?: null;
$gender = trim($_POST['gender'] ?? '');
$gender = in_array($gender, ['Male', 'Female', 'Other'], true) ? $gender : null;
$address = trim($_POST['address'] ?? '') ?: null;
$planId = (int) ($_POST['plan_id'] ?? 0);
$method = trim($_POST['payment_method'] ?? '');
$receipt = $_FILES['receipt'] ?? null;

if (!$first || !$last || strlen($first) > 50 || strlen($last) > 50 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 ||
    strlen($password) < 6 || strlen($password) > 72 ||
    ($phone !== null && strlen($phone) > 20) || ($address !== null && strlen($address) > 255) ||
    $planId <= 0 || !in_array($method, ['GCash', 'Maya'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please complete your account, plan and payment details.']);
    exit;
}
if ($dob !== null) {
    $parsedDob = DateTime::createFromFormat('!Y-m-d', $dob);
    if (!$parsedDob || $parsedDob->format('Y-m-d') !== $dob) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid date of birth.']);
        exit;
    }
}
if (!$receipt || $receipt['error'] !== UPLOAD_ERR_OK || $receipt['size'] > 5 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please attach a receipt image under 5 MB.']);
    exit;
}
$info = @getimagesize($receipt['tmp_name']);
$mime = $info['mime'] ?? '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please attach a JPEG, PNG or WebP receipt.']);
    exit;
}
$image = file_get_contents($receipt['tmp_name']);
try {
    $check = $conn->prepare('SELECT MemberID FROM Members WHERE Email = ?');
    $check->bind_param('s', $email);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if ($exists) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email is already registered.']);
        exit;
    }
    $planStmt = $conn->prepare('SELECT Price FROM Plans WHERE PlanID = ?');
    $planStmt->bind_param('i', $planId);
    $planStmt->execute();
    $plan = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();
    if (!$plan) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Selected plan not found.']);
        exit;
    }
    $price = (float) $plan['Price'];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO PendingRegistrations (FirstName, LastName, Email, PasswordHash, Phone, DateOfBirth, Gender, Address, PlanID, Amount, PaymentMethod, Receipt, ReceiptMime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ssssssssidsss', $first, $last, $email, $hash, $phone, $dob, $gender, $address, $planId, $price, $method, $image, $mime);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    echo json_encode(['success' => true, 'status' => 'Pending', 'registration_id' => $id, 'message' => 'Your registration has been submitted for admin review.']);
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This email already has a registration request. Please contact the gym.']);
    } else {
        error_log('Pending registration failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to submit registration. Please try again.']);
    }
}
$conn->close();
