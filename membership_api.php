<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/sync_debug.log');
error_log("=== membership_api.php CALLED at " . date('Y-m-d H:i:s') . " ===");

// ============================================================
// membership_api.php
// Creates a Memberships row + a matching Payments row.
// Called after a member picks a plan and confirms payment.
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

error_log("Raw input: " . file_get_contents("php://input"));
error_log("Decoded data: " . print_r($data, true));

if (!$data) {
    $response["message"] = "No data received.";
    error_log("FAILED: No data received.");
    echo json_encode($response);
    exit();
}

$memberId      = intval($data["member_id"] ?? 0);
$planId        = intval($data["plan_id"] ?? 0);
$paymentMethod = trim($data["payment_method"] ?? "");

error_log("Parsed: memberId=$memberId, planId=$planId, paymentMethod=$paymentMethod");

if ($memberId <= 0 || $planId <= 0 || empty($paymentMethod)) {
    $response["message"] = "Missing member, plan, or payment method.";
    error_log("FAILED: Missing member, plan, or payment method.");
    echo json_encode($response);
    exit();
}

// 1. Look up the plan's real price/duration server-side.
$planStmt = $conn->prepare("SELECT DurationLabel, DurationMonths, Price FROM Plans WHERE PlanID = ?");
$planStmt->bind_param("i", $planId);
$planStmt->execute();
$plan = $planStmt->get_result()->fetch_assoc();
$planStmt->close();

if (!$plan) {
    $response["message"] = "Plan not found.";
    error_log("FAILED: Plan not found for planId=$planId");
    echo json_encode($response);
    exit();
}

error_log("Plan found: " . print_r($plan, true));

$durationMonths = intval($plan["DurationMonths"]);
$durationLabel  = $plan["DurationLabel"];
$price = $plan["Price"];

$creditsPerMonth = 30;
$sessionCredits = $durationMonths * $creditsPerMonth;

$conn->begin_transaction();
try {
    // 2. Expire any existing Active membership(s) for this member first --
    //    a renewal or upgrade should replace the current plan, not stack
    //    on top of it. This keeps exactly one Active membership per member
    //    at any time, so "ORDER BY StartDate DESC LIMIT 1" queries
    //    elsewhere (check-in, session status, attendance) stay accurate
    //    and the data doesn't accumulate stale "Active" rows.
    $expireStmt = $conn->prepare(
        "UPDATE Memberships SET Status = 'Expired' WHERE MemberID = ? AND Status = 'Active'"
    );
    $expireStmt->bind_param("i", $memberId);
    $expireStmt->execute();
    $expiredCount = $expireStmt->affected_rows;
    $expireStmt->close();

    error_log("Expired $expiredCount previous active membership(s) for memberId=$memberId");

    // 3. Create the new membership
    $stmt = $conn->prepare(
        "INSERT INTO Memberships (MemberID, PlanID, StartDate, NextRenewalDate, Status, SessionCredits, SessionsUsed)
         VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'Active', ?, 0)"
    );
    $stmt->bind_param("iiii", $memberId, $planId, $durationMonths, $sessionCredits);
    $stmt->execute();
    $membershipId = $stmt->insert_id;
    $stmt->close();

    error_log("Membership created: membershipId=$membershipId");

    // 3. Record the payment tied to that membership
    $payStmt = $conn->prepare(
        "INSERT INTO Payments (MemberID, MembershipID, Date, Amount, Method, Status)
         VALUES (?, ?, CURDATE(), ?, ?, 'Paid')"
    );
    $payStmt->bind_param("iids", $memberId, $membershipId, $price, $paymentMethod);
    $payStmt->execute();
    $paymentId = $payStmt->insert_id;
    $payStmt->close();

    error_log("Payment recorded: paymentId=$paymentId");

    $conn->commit();
    error_log("Transaction committed.");

    $nextRenewalDate = date('Y-m-d', strtotime("+$durationMonths months"));
    $startDate = date('Y-m-d');

    $response["success"]           = true;
    $response["message"]           = "Membership and payment recorded.";
    $response["membership_id"]     = $membershipId;
    $response["payment_id"]        = $paymentId;
    $response["amount"]            = $price;
    $response["next_renewal_date"] = $nextRenewalDate;
    $response["session_credits"]   = $sessionCredits;

    // ------------------------------------------------------------
    // 4. Notify the Admin/Owner app (Laravel) in real time
    // ------------------------------------------------------------
    $memberStmt = $conn->prepare("SELECT FirstName, LastName, Email FROM Members WHERE MemberID = ?");
    $memberStmt->bind_param("i", $memberId);
    $memberStmt->execute();
    $memberInfo = $memberStmt->get_result()->fetch_assoc();
    $memberStmt->close();

    error_log("memberInfo lookup for memberId=$memberId: " . print_r($memberInfo, true));

    if ($memberInfo) {
        $syncPayload = json_encode([
            "memberId"        => $memberId,
            "firstName"       => $memberInfo["FirstName"],
            "lastName"        => $memberInfo["LastName"],
            "email"           => $memberInfo["Email"],
            "planLabel"       => $durationLabel,
            "planPrice"       => (int) $price,
            "planMonths"      => $durationMonths,
            "startDate"       => $startDate,
            "nextRenewalDate" => $nextRenewalDate,
            "status"          => "active",
            "paymentMethod"   => $paymentMethod,
        ]);

        error_log("Sync payload: " . $syncPayload);

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
    } else {
        error_log("SKIPPED sync: memberInfo was empty/null for memberId=$memberId");
    }
} catch (Exception $e) {
    $conn->rollback();
    $response["message"] = "Error: " . $e->getMessage();
    error_log("EXCEPTION CAUGHT: " . $e->getMessage());
}

$conn->close();
echo json_encode($response);
?>