<?php
// ============================================================
// complete_registration_api.php
// Creates the Member + Membership + Payment records TOGETHER,
// in a single database transaction.
//
// If anything fails (duplicate email, invalid plan, payment
// insert error, etc.) the WHOLE transaction rolls back -- no
// Member row is created unless the plan + payment also succeed.
// This means a member account only exists in the database once
// they've actually completed a paid signup.
//
// UPDATED: now reads multipart/form-data (instead of raw JSON) so
// the member's payment receipt image can be uploaded alongside the
// registration data. The receipt is saved under uploads/receipts/
// and its path is stored in Payments.ReceiptURL. The new Payment
// row is created with Status = 'Pending' -- admin must manually
// confirm it (see pending_payments_api.php / confirm_payment_api.php)
// before the payment counts as "Paid".
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
require "email_helper.php";

$response = ["success" => false, "message" => ""];

// ------------------------------------------------------------
// UPDATED: read from $_POST (multipart/form-data fields) instead of
// the raw JSON body, since the request now also carries a file.
// ------------------------------------------------------------
$firstName     = trim($_POST["first_name"] ?? "");
$lastName      = trim($_POST["last_name"] ?? "");
$email         = trim($_POST["email"] ?? "");
$phone         = trim($_POST["phone"] ?? "");
$dob           = $_POST["date_of_birth"] ?? null;
$address       = trim($_POST["address"] ?? "");
$password      = $_POST["password"] ?? "";
$planId        = intval($_POST["plan_id"] ?? 0);
$paymentMethod = trim($_POST["payment_method"] ?? "");

if ($dob === "") { $dob = null; }
if ($address === "") { $address = null; }
if ($phone === "") { $phone = null; }

// 1. Validate that everything needed for a COMPLETE signup is present --
//    account details AND plan AND payment method. No partial accounts.
if (empty($firstName) || empty($lastName) || empty($email) || empty($password) || $planId <= 0 || empty($paymentMethod)) {
    $response["message"] = "Missing account, plan, or payment details.";
    echo json_encode($response);
    exit();
}

// 1b. NEW: require a receipt image -- this is the proof of payment that
//     admin will review before confirming.
if (!isset($_FILES["receipt"]) || $_FILES["receipt"]["error"] !== UPLOAD_ERR_OK) {
    $response["message"] = "Please attach your payment receipt.";
    echo json_encode($response);
    exit();
}

// 2. Reject duplicate emails before starting the transaction
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

// 3. Look up the selected plan (never trust a price from the client)
$planStmt = $conn->prepare("SELECT DurationMonths, Price FROM Plans WHERE PlanID = ?");
$planStmt->bind_param("i", $planId);
$planStmt->execute();
$plan = $planStmt->get_result()->fetch_assoc();
$planStmt->close();

if (!$plan) {
    $response["message"] = "Selected plan not found.";
    echo json_encode($response);
    exit();
}

$durationMonths = intval($plan["DurationMonths"]);
$price = $plan["Price"];

// Session credits scale with plan length (30 credits per month).
$creditsPerMonth = 30;
$sessionCredits = $durationMonths * $creditsPerMonth;

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// ------------------------------------------------------------
// NEW: save the uploaded receipt to uploads/receipts/ BEFORE starting
// the transaction. If the file can't be saved, we bail out early
// without touching the database at all.
// ------------------------------------------------------------
$uploadDir = __DIR__ . "/uploads/receipts/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = strtolower(pathinfo($_FILES["receipt"]["name"], PATHINFO_EXTENSION));
$safeExt = preg_match('/^(jpg|jpeg|png|webp)$/', $ext) ? $ext : 'jpg';
$receiptFilename = "receipt_" . preg_replace('/[^a-zA-Z0-9]/', '_', $email) . "_" . time() . "." . $safeExt;
$receiptDestPath = $uploadDir . $receiptFilename;

if (!move_uploaded_file($_FILES["receipt"]["tmp_name"], $receiptDestPath)) {
    $response["message"] = "Failed to save receipt image. Please try again.";
    echo json_encode($response);
    exit();
}

// Relative path stored in the DB / returned to the app so it can be
// viewed later (e.g. http://localhost/memberaccount/uploads/receipts/xxx.jpg)
$receiptRelativePath = "uploads/receipts/" . $receiptFilename;

$conn->begin_transaction();
try {
    // 4. Create the member
    $stmt = $conn->prepare(
        "INSERT INTO Members (FirstName, LastName, Email, PasswordHash, Phone, DateOfBirth, Address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssssss", $firstName, $lastName, $email, $passwordHash, $phone, $dob, $address);
    $stmt->execute();
    $memberId = $stmt->insert_id;
    $stmt->close();

    // 5. Create the membership
    $msStmt = $conn->prepare(
        "INSERT INTO Memberships (MemberID, PlanID, StartDate, NextRenewalDate, Status, SessionCredits, SessionsUsed)
         VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'Active', ?, 0)"
    );
    $msStmt->bind_param("iiii", $memberId, $planId, $durationMonths, $sessionCredits);
    $msStmt->execute();
    $membershipId = $msStmt->insert_id;
    $msStmt->close();

    // 6. Record the payment -- UPDATED: Status is now 'Pending' (not
    //    'Paid') and ReceiptURL stores the uploaded proof of payment.
    //    Admin must review the receipt and confirm before this becomes
    //    'Paid' (see confirm_payment_api.php, not yet built).
    $payStatus = 'Pending';
    $payStmt = $conn->prepare(
        "INSERT INTO Payments (MemberID, MembershipID, Date, Amount, Method, Status, ReceiptURL)
         VALUES (?, ?, CURDATE(), ?, ?, ?, ?)"
    );
    $payStmt->bind_param("iidsss", $memberId, $membershipId, $price, $paymentMethod, $payStatus, $receiptRelativePath);
    $payStmt->execute();
    $paymentId = $payStmt->insert_id;
    $payStmt->close();

    // Everything succeeded -- commit all three inserts together.
    $conn->commit();

    // ------------------------------------------------------------
    // 6b. Notify the Admin/Owner app (Laravel) in real time, so this
    //     new member + membership shows up on their dashboard.
    //     Fire and forget -- won't undo the registration if it fails.
    //     NOTE: status stays "active" here for the membership record
    //     itself; the pending/unconfirmed state lives on the Payment,
    //     not the membership. Revisit this if you'd rather the admin
    //     dashboard also flag the membership as unconfirmed.
    // ------------------------------------------------------------
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/sync_debug.log');

    $planLabelStmt = $conn->prepare("SELECT DurationLabel FROM Plans WHERE PlanID = ?");
    $planLabelStmt->bind_param("i", $planId);
    $planLabelStmt->execute();
    $planLabelResult = $planLabelStmt->get_result()->fetch_assoc();
    $planLabelStmt->close();
    $planLabelForSync = $planLabelResult["DurationLabel"] ?? "";

    $syncStartDate = date('Y-m-d');
    $syncNextRenewalDate = date('Y-m-d', strtotime("+$durationMonths months"));

    $syncPayload = json_encode([
        "memberId"        => $memberId,
        "firstName"       => $firstName,
        "lastName"        => $lastName,
        "email"           => $email,
        "planLabel"       => $planLabelForSync,
        "planPrice"       => (int) $price,
        "planMonths"      => $durationMonths,
        "startDate"       => $syncStartDate,
        "nextRenewalDate" => $syncNextRenewalDate,
        "status"          => "active",
        "paymentMethod"   => $paymentMethod,
        "paymentStatus"   => $payStatus,
        "receiptUrl"      => $receiptRelativePath,
    ]);

    error_log("Sync payload (from complete_registration): " . $syncPayload);

    $ch = curl_init("http://127.0.0.1:8000/api/sync-membership");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $syncPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Accept: application/json",
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $syncResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        error_log("sync-membership CURL ERROR: " . curl_error($ch));
    } else {
        error_log("sync-membership HTTP $httpCode RESPONSE: " . $syncResponse);
    }
    curl_close($ch);

    // 7. Generate a tamper-proof QR token now that the member truly
    //    exists (post-commit), and save it to their Members row.
    $qrToken = generateQrToken($memberId);
    $qrStmt = $conn->prepare("UPDATE Members SET QRCodeData = ? WHERE MemberID = ?");
    $qrStmt->bind_param("si", $qrToken, $memberId);
    $qrStmt->execute();
    $qrStmt->close();

    $nextRenewalDate = date('Y-m-d', strtotime("+$durationMonths months"));

    // 8. Email the QR code to the member. This never blocks the
    //    signup -- if the email fails to send (e.g. SMTP not
    //    configured yet), the account and payment are still valid.
    $memberIdLabel = 'PF-' . str_pad($memberId, 5, '0', STR_PAD_LEFT);
    $planStmt2 = $conn->prepare("SELECT DurationLabel FROM Plans WHERE PlanID = ?");
    $planStmt2->bind_param("i", $planId);
    $planStmt2->execute();
    $planLabelRow = $planStmt2->get_result()->fetch_assoc();
    $planStmt2->close();
    $planLabel = $planLabelRow["DurationLabel"] ?? "";

    $emailResult = sendQrCodeEmail($email, $firstName . ' ' . $lastName, $memberIdLabel, $planLabel, $qrToken);

    $response["success"]           = true;
    // UPDATED: message + status now reflect pending admin review
    // instead of an immediately-confirmed payment.
    $response["message"]           = "Account created. Your receipt was sent for admin confirmation.";
    $response["status"]            = $payStatus; // "Pending" -- read by the Flutter app's Done screen
    $response["member_id"]         = $memberId;
    $response["membership_id"]     = $membershipId;
    $response["payment_id"]        = $paymentId;
    $response["amount"]            = $price;
    $response["session_credits"]   = $sessionCredits;
    $response["next_renewal_date"] = $nextRenewalDate;
    $response["qr_code_data"]      = $qrToken;
    $response["receipt_url"]       = $receiptRelativePath;
    $response["email_sent"]        = $emailResult === true;
    if ($emailResult !== true) {
        $response["email_error"] = $emailResult;
    }
} catch (Exception $e) {
    // Anything failed -- roll back ALL of it. No Member, no Membership,
    // no Payment row is left behind.
    $conn->rollback();
    // Clean up the receipt file too, since nothing was actually saved.
    if (file_exists($receiptDestPath)) {
        @unlink($receiptDestPath);
    }
    $response["message"] = "Registration failed: " . $e->getMessage();
}

$conn->close();
echo json_encode($response);
?>
