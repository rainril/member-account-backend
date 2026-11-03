<?php
// ============================================================
// billing_api.php
// PayMongo (GCash / Maya) billing for gym plan payments, using the
// current PaymentIntent + PaymentMethod workflow (the Sources API is
// deprecated).
//
// Routes on "action" -- taken from the query string on GET calls, or
// from the JSON body (falling back to a POST field) on POST calls,
// matching how the Flutter app talks to the other *_api.php endpoints.
//
//   create_payment   POST  member_id, plan_id, method ("gcash"|"paymaya")
//                           -> creates a PaymentIntent, a PaymentMethod,
//                              attaches them, writes a Pending payments
//                              row, returns { checkout_url, payment_intent_id }
//   webhook          POST  PayMongo event callback. Handles payment.paid
//                           (mark Paid + activate/extend membership) and
//                           payment.failed (mark Failed). Always HTTP 200.
//   confirm_payment  POST  payment_id -> polls the PaymentIntent directly
//                           and runs the payment.paid activation if it has
//                           succeeded. Webhook-independent fallback.
//   payment_status   GET   payment_id (our PaymentID) -> { status }
//   billing_history  GET   member_id -> that member's payments joined
//                           with memberships + plans, newest first
//
// Manual receipt flow (member pays via GCash/Maya outside the app, then
// uploads a screenshot for an admin to approve):
//   submit_receipt        POST (multipart) member_id, plan_id, method,
//                           purpose ("New Registration"|"Renewal"|"Upgrade",
//                           optional, defaults to "New Registration"),
//                           receipt(file) -> saves the image, OCRs it for
//                           an amount + reference, writes a Pending payment
//   list_pending_receipts GET  -> admin queue: Pending payments with a
//                           receipt, + member/plan/OCR/expected-price info
//   approve_receipt       POST payment_id -> mark Paid + activate membership
//   reject_receipt        POST payment_id, [reason] -> mark Failed
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Paymongo-Signature");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";
require "paymongo_config.php";

// PayMongo wraps every request/response in { "data": { "attributes": {...} } }
// and authenticates with HTTP Basic: secret key as the username, empty password.
define("PAYMONGO_API_BASE", "https://api.paymongo.com/v1");
define("PAYMONGO_RETURN_URL", "http://localhost/primefit/payment-success.html");

$rawBody = file_get_contents("php://input");
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    $body = [];
}

// "action" -- query string (GET) or JSON body / form field (POST)
$action = $_GET["action"] ?? $body["action"] ?? $_POST["action"] ?? "";

// Admin receipt review is served by the authenticated admin backend.
if (in_array($action, ['list_pending_receipts', 'approve_receipt', 'reject_receipt'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin authentication required.']);
    exit;
}

$authenticatedMemberId = null;
if (in_array($action, ['create_payment', 'confirm_payment', 'submit_receipt', 'payment_status', 'billing_history'], true)) {
    require_once __DIR__ . '/member_session_helpers.php';
    $authenticatedMemberId = member_session_auth_member_id($conn);
}

// ------------------------------------------------------------
// Call the PayMongo REST API. Returns [http_code, data, error, raw].
// $attributes (if given) is wrapped as { "data": { "attributes": {...} } }.
// Same curl style as chatbot_api.php / membership_api.php.
// ------------------------------------------------------------
function paymongo_call($method, $path, $attributes = null)
{
    $ch = curl_init(PAYMONGO_API_BASE . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Accept: application/json",
        "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":"),
    ]);
    if ($attributes !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["data" => ["attributes" => $attributes]]));
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        "http_code" => $httpCode,
        "data"      => json_decode($raw, true),
        "error"     => $curlError,
        "raw"       => $raw,
    ];
}

// ------------------------------------------------------------
// Mark a Pending payment Paid and activate / extend the member's
// membership. Used by the payment.paid webhook AND by create_payment
// when an attach comes back already "succeeded". Idempotent: a payment
// that is already 'Paid' is left alone. Returns true if it changed
// anything (payment marked Paid / membership touched), false otherwise.
// ------------------------------------------------------------
function has_unapproved_initial_receipt($conn, $memberId)
{
    $stmt = $conn->prepare("SELECT 1 FROM Memberships ms WHERE ms.MemberID = ? AND EXISTS (SELECT 1 FROM Payments p WHERE p.MembershipID = ms.MembershipID AND p.Status IN ('Pending', 'Failed') AND p.ReceiptURL IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM Payments paid WHERE paid.MembershipID = ms.MembershipID AND paid.Status = 'Paid') LIMIT 1");
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

function activate_membership_for_payment($conn, $paymentId)
{
    $payStmt = $conn->prepare(
        "SELECT PaymentID, MemberID, Amount, Status, ReceiptURL FROM Payments WHERE PaymentID = ? LIMIT 1"
    );
    $payStmt->bind_param("i", $paymentId);
    $payStmt->execute();
    $payment = $payStmt->get_result()->fetch_assoc();
    $payStmt->close();

    if (!$payment || $payment["Status"] === "Paid") {
        return false; // unknown, or already processed
    }

    // Manual receipts may only be approved by an authenticated admin.
    if (!empty($payment['ReceiptURL'])) {
        return false;
    }

    $memberId   = (int) $payment["MemberID"];
    $amountPaid = (float) $payment["Amount"];
    if (has_unapproved_initial_receipt($conn, $memberId)) return false;

    // Match the plan by price. The current plans all have distinct prices
    // (2400 / 2800 / 3500 / 4800), so this is unambiguous. If two plans
    // ever share a price, store a PlanID on the payments row instead.
    $planStmt = $conn->prepare(
        "SELECT PlanID, DurationMonths FROM Plans WHERE Price = ? ORDER BY DurationMonths ASC LIMIT 1"
    );
    $planStmt->bind_param("d", $amountPaid);
    $planStmt->execute();
    $plan = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();

    if (!$plan) {
        // Still mark it Paid so it isn't stuck Pending forever.
        $mark = $conn->prepare("UPDATE Payments SET Status = 'Paid' WHERE PaymentID = ?");
        $mark->bind_param("i", $paymentId);
        $mark->execute();
        $mark->close();
        error_log("billing_api: payment $paymentId paid but no plan matches amount $amountPaid");
        return true;
    }

    $planId         = (int) $plan["PlanID"];
    $durationMonths = (int) $plan["DurationMonths"];
    $sessionCredits = $durationMonths * 30; // same rule as membership_api.php

    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE Payments SET Status = 'Paid' WHERE PaymentID = ?");
        $upd->bind_param("i", $paymentId);
        $upd->execute();
        $upd->close();

        // Extend the member's Active membership, or create one.
        $msStmt = $conn->prepare(
            "SELECT MembershipID FROM Memberships
             WHERE MemberID = ? AND Status = 'Active'
             ORDER BY MembershipID DESC LIMIT 1"
        );
        $msStmt->bind_param("i", $memberId);
        $msStmt->execute();
        $membership = $msStmt->get_result()->fetch_assoc();
        $msStmt->close();

        if ($membership) {
            $membershipId = (int) $membership["MembershipID"];
            // Push the renewal date out by the plan's duration.
            // COALESCE guards against a NULL NextRenewalDate.
            $extend = $conn->prepare(
                "UPDATE Memberships
                 SET NextRenewalDate = DATE_ADD(COALESCE(NextRenewalDate, CURDATE()), INTERVAL ? MONTH)
                 WHERE MembershipID = ?"
            );
            $extend->bind_param("ii", $durationMonths, $membershipId);
            $extend->execute();
            $extend->close();
        } else {
            $create = $conn->prepare(
                "INSERT INTO Memberships
                    (MemberID, PlanID, StartDate, NextRenewalDate, Status, SessionCredits, SessionsUsed)
                 VALUES
                    (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH), 'Active', ?, 0)"
            );
            $create->bind_param("iiii", $memberId, $planId, $durationMonths, $sessionCredits);
            $create->execute();
            $membershipId = $create->insert_id;
            $create->close();
        }

        // Link the payment to the membership so billing_history can join them.
        $link = $conn->prepare("UPDATE Payments SET MembershipID = ? WHERE PaymentID = ?");
        $link->bind_param("ii", $membershipId, $paymentId);
        $link->execute();
        $link->close();

        $conn->commit();
        return true;
    } catch (\Throwable $e) {
        $conn->rollback();
        error_log("billing_api: activate_membership_for_payment($paymentId) failed: " . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------
// Manual-receipt OCR helpers (Tesseract via thiagoalessio/tesseract_ocr).
// TESSERACT_BIN points at the standard Windows install; if that file is
// missing we let the wrapper look for `tesseract` on PATH instead.
// ------------------------------------------------------------
define("TESSERACT_BIN", 'C:\Program Files\Tesseract-OCR\tesseract.exe');

function ocr_receipt_text($imagePath)
{
    try {
        $ocr = new \thiagoalessio\TesseractOCR\TesseractOCR($imagePath);
        if (is_file(TESSERACT_BIN)) {
            $ocr->executable(TESSERACT_BIN);
        }
        return (string) $ocr->run();
    } catch (\Throwable $e) {
        error_log("billing_api: OCR failed for $imagePath: " . $e->getMessage());
        return "";
    }
}

function ocr_money_to_float($s)
{
    return (float) str_replace([',', ' '], '', $s);
}

// Best-effort peso amount from receipt OCR text. Returns float|null.
// Receipt layouts vary, so this is a hint for the admin, never the
// source of truth -- the recorded Amount is always the plan price.
//
// Verified against a real GCash receipt whose text reads:
//   "Amount 125.00"
//   "Total Amount Sent P125.00"      (the peso sign OCRs as a bare "P")
//   "Ref No. 2044305568664 Aug 24, 2026 7:13 PM"
//   "+63 995 762 2282"
// -> must return 125.00, never the phone digits or the year.
function ocr_extract_amount($text)
{
    if ($text === "") {
        return null;
    }

    // A peso figure: digits, optional thousands separators, optional .NN.
    $num = '([0-9][0-9,]*(?:\.[0-9]{2})?)';

    // (1) the "Total Amount Sent" line -- the authoritative total on
    //     GCash / Maya receipts. \D{0,12} skips a stray "P"/"PHP"/newline.
    if (preg_match('/total\s+amount\s+sent\D{0,12}' . $num . '/iu', $text, $m)) {
        return ocr_money_to_float($m[1]);
    }

    // (2) a number directly preceded by a peso marker: "\x{20B1}", "PHP",
    //     or a lone "P" (how Tesseract usually renders the peso glyph).
    if (preg_match('/(?:\x{20B1}|PHP|(?<![A-Za-z])P)\s*' . $num . '/u', $text, $m)) {
        return ocr_money_to_float($m[1]);
    }

    // (3) an "Amount" / "Total" label followed by a number.
    if (preg_match('/(?:amount|total)\D{0,12}(?:\x{20B1}|PHP|P)?\s*' . $num . '/iu', $text, $m)) {
        return ocr_money_to_float($m[1]);
    }

    // (4) last resort: the largest strictly-currency-shaped number (must
    //     carry a ".NN"), which rules out bare years and phone digits.
    if (preg_match_all('/(?<![0-9.])([0-9][0-9,]*\.[0-9]{2})(?![0-9])/', $text, $m)) {
        return max(array_map('ocr_money_to_float', $m[1]));
    }

    return null;
}

// Best-effort reference / transaction number from receipt OCR text.
// Returns string|null. See the layout notes on ocr_extract_amount().
function ocr_extract_reference($text)
{
    if ($text === "") {
        return null;
    }

    // The reference token right after the label: a space-grouped digit run
    // ("1029 3847 5610"), a plain digit run ("2044305568664"), or an
    // alphanumeric code ("A1B2C3D4E5F6", "ABC-123"). Ordered so a trailing
    // date ("... 2044305568664 Aug 24, 2026") can't bleed into the match.
    $refToken = '([0-9]{3,}(?:\s+[0-9]{3,})+|[0-9]{6,}|[A-Z][A-Z0-9]{4,}(?:-[A-Z0-9]+)*)';

    // (1) labelled: "Ref No.", "Reference No/Number/ID/Code",
    //     "Transaction No/ID", "Txn ...".
    if (preg_match(
        '/(?:ref(?:erence)?|transaction|txn)\.?\s*(?:no\.?|number|num|id|code)?\s*[:#.\-]?\s*' . $refToken . '/i',
        $text, $m
    )) {
        return trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // (2) fallback: a 12-16 digit run that isn't glued to a "+" (skips
    //     phone numbers like "+63 995 762 2282" when they get concatenated).
    if (preg_match('/(?<![+0-9])([0-9]{12,16})(?![0-9])/', $text, $m)) {
        return $m[1];
    }

    return null;
}

// ============================================================
// action=create_payment  (POST)
// ============================================================
if ($action === "create_payment") {
    $response = ["success" => false, "message" => ""];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        $response["message"] = "create_payment requires a POST request.";
        echo json_encode($response);
        exit();
    }

    $memberId = member_session_resolve_member_id($conn, (int) ($body["member_id"] ?? 0));
    $planId   = (int) ($body["plan_id"] ?? 0);
    $method   = strtolower(trim($body["method"] ?? ""));

    if ($memberId <= 0 || $planId <= 0) {
        $response["message"] = "member_id and plan_id are required.";
        echo json_encode($response);
        exit();
    }

    if (has_unapproved_initial_receipt($conn, $memberId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your registration is awaiting admin review.']);
        exit();
    }

    if ($method !== "gcash" && $method !== "paymaya") {
        $response["message"] = "method must be 'gcash' or 'paymaya'.";
        echo json_encode($response);
        exit();
    }

    // 1. Look up the plan's real price server-side -- never trust an
    //    amount sent by the client.
    $planStmt = $conn->prepare("SELECT DurationLabel, Price FROM Plans WHERE PlanID = ?");
    $planStmt->bind_param("i", $planId);
    $planStmt->execute();
    $plan = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();

    if (!$plan) {
        $response["message"] = "Plan not found.";
        echo json_encode($response);
        exit();
    }

    $price = (float) $plan["Price"];
    $amountCentavos = (int) round($price * 100); // PayMongo works in centavos

    // 2. Create the PaymentIntent.
    $intentRes = paymongo_call("POST", "/payment_intents", [
        "amount"                 => $amountCentavos,
        "payment_method_allowed" => ["gcash", "paymaya"],
        "currency"               => "PHP",
        "capture_type"           => "automatic",
        "description"            => "PrimeFit membership - " . $plan["DurationLabel"],
    ]);

    $intentId = $intentRes["data"]["data"]["id"] ?? "";
    if ($intentRes["http_code"] < 200 || $intentRes["http_code"] >= 300 || $intentId === "") {
        $response["message"] = "Could not create the PayMongo payment intent.";
        $response["paymongo_error"] = $intentRes["data"]["errors"] ?? $intentRes["raw"];
        echo json_encode($response);
        exit();
    }

    // 3. Create the PaymentMethod (gcash / paymaya).
    $pmRes = paymongo_call("POST", "/payment_methods", [
        "type" => $method,
    ]);

    $paymentMethodId = $pmRes["data"]["data"]["id"] ?? "";
    if ($pmRes["http_code"] < 200 || $pmRes["http_code"] >= 300 || $paymentMethodId === "") {
        $response["message"] = "Could not create the PayMongo payment method.";
        $response["paymongo_error"] = $pmRes["data"]["errors"] ?? $pmRes["raw"];
        echo json_encode($response);
        exit();
    }

    // 4. Record a Pending payment BEFORE attaching, so the row exists even
    //    if the client disappears mid-redirect. MembershipID stays NULL
    //    until payment.paid; PaymentIntentID lets the webhook find this row.
    try {
        $insert = $conn->prepare(
            "INSERT INTO Payments (MemberID, MembershipID, Date, Amount, Method, Status, PaymentIntentID)
             VALUES (?, NULL, CURDATE(), ?, ?, 'Pending', ?)"
        );
        $insert->bind_param("idss", $memberId, $price, $method, $intentId);
        $insert->execute();
        $paymentId = (int) $insert->insert_id;
        $insert->close();
    } catch (\Throwable $e) {
        error_log("billing_api create_payment insert failed: " . $e->getMessage());
        $response["message"] = "Could not record the payment. Please try again.";
        echo json_encode($response);
        exit();
    }

    // 5. Attach the PaymentMethod to the PaymentIntent. return_url is
    //    required for e-wallets.
    $attachRes = paymongo_call("POST", "/payment_intents/" . rawurlencode($intentId) . "/attach", [
        "payment_method" => $paymentMethodId,
        "return_url"     => PAYMONGO_RETURN_URL,
    ]);

    $attachAttr  = $attachRes["data"]["data"]["attributes"] ?? [];
    $intentStatus = $attachAttr["status"] ?? "";
    $redirectUrl  = $attachAttr["next_action"]["redirect"]["url"] ?? "";

    if ($attachRes["http_code"] < 200 || $attachRes["http_code"] >= 300) {
        $conn->query("UPDATE Payments SET Status = 'Failed' WHERE PaymentID = $paymentId");
        $response["message"] = "Could not attach the payment method.";
        $response["paymongo_error"] = $attachRes["data"]["errors"] ?? $attachRes["raw"];
        echo json_encode($response);
        exit();
    }

    // 6a. Rare test path: already settled, no redirect needed. Handle it
    //     exactly like the webhook would.
    if ($intentStatus === "succeeded" && $redirectUrl === "") {
        activate_membership_for_payment($conn, $paymentId);
        $conn->close();

        $response["success"]           = true;
        $response["message"]           = "Payment already completed.";
        $response["payment_id"]        = $paymentId;
        $response["payment_intent_id"] = $intentId;
        $response["status"]            = "succeeded";
        echo json_encode($response);
        exit();
    }

    // 6b. Normal e-wallet path: hand the redirect URL back to the app.
    if ($redirectUrl === "") {
        $response["message"] = "PayMongo did not return a checkout URL (intent status: $intentStatus).";
        echo json_encode($response);
        exit();
    }

    $conn->close();

    $response["success"]           = true;
    $response["message"]           = "Checkout ready.";
    $response["payment_id"]        = $paymentId; // the app polls payment_status with this
    $response["payment_intent_id"] = $intentId;
    $response["checkout_url"]      = $redirectUrl;
    echo json_encode($response);
    exit();
}

// ============================================================
// action=webhook  (POST from PayMongo)
// PayMongo retries on any non-2xx, so this branch always ends with
// HTTP 200 -- even on failure -- and just logs what went wrong.
// ============================================================
if ($action === "webhook") {
    if (PAYMONGO_WEBHOOK_SECRET === '') {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Webhook is not configured.']);
        exit();
    }
    http_response_code(200);

    // 1. Verify the Paymongo-Signature header.
    //    Header format:  t=<timestamp>,te=<test-sig>,li=<live-sig>
    //    Signed string:  "<timestamp>.<raw-body>"  (HMAC-SHA256, hex)
    $sigHeader = $_SERVER["HTTP_PAYMONGO_SIGNATURE"] ?? "";
    $sigFields = [];
    foreach (explode(",", $sigHeader) as $part) {
        $kv = explode("=", trim($part), 2);
        if (count($kv) === 2) {
            $sigFields[$kv[0]] = $kv[1];
        }
    }

    $timestamp   = $sigFields["t"] ?? "";
    $expectedSig = !empty($sigFields["li"]) ? $sigFields["li"] : ($sigFields["te"] ?? "");
    $computedSig = hash_hmac("sha256", $timestamp . "." . $rawBody, PAYMONGO_WEBHOOK_SECRET);

    if ($timestamp === "" || $expectedSig === "" || !hash_equals($computedSig, $expectedSig)) {
        error_log("billing_api webhook: signature verification failed");
        echo json_encode(["success" => false, "message" => "Invalid signature."]);
        exit();
    }

    // 2. Unwrap the PayMongo event envelope. The resource for payment.*
    //    events is a payment object; its parent intent id is in
    //    attributes.payment_intent_id.
    $eventType = $body["data"]["attributes"]["type"] ?? "";
    $payAttr   = $body["data"]["attributes"]["data"]["attributes"] ?? [];
    $intentId  = $payAttr["payment_intent_id"] ?? "";

    try {
        if (($eventType === "payment.paid" || $eventType === "payment.failed") && $intentId !== "") {
            $find = $conn->prepare(
                "SELECT PaymentID, Status FROM Payments WHERE PaymentIntentID = ? LIMIT 1"
            );
            $find->bind_param("s", $intentId);
            $find->execute();
            $row = $find->get_result()->fetch_assoc();
            $find->close();

            if (!$row) {
                error_log("billing_api webhook: $eventType for unknown intent $intentId");
                echo json_encode(["success" => true, "message" => "Unknown payment intent."]);
                exit();
            }

            $paymentId = (int) $row["PaymentID"];

            if ($eventType === "payment.paid") {
                activate_membership_for_payment($conn, $paymentId); // idempotent
            } else {
                // payment.failed -- don't clobber a payment that somehow
                // already went through.
                if ($row["Status"] !== "Paid") {
                    $upd = $conn->prepare("UPDATE Payments SET Status = 'Failed' WHERE PaymentID = ?");
                    $upd->bind_param("i", $paymentId);
                    $upd->execute();
                    $upd->close();
                }
            }

            $conn->close();
            echo json_encode(["success" => true]);
            exit();
        }
    } catch (\Throwable $e) {
        error_log("billing_api webhook error ($eventType): " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Handler error."]);
        exit();
    }

    // Any other event type -- acknowledge and ignore.
    echo json_encode(["success" => true, "message" => "Ignored event: $eventType"]);
    exit();
}

// ============================================================
// action=confirm_payment  (POST)
// Fallback for when the PayMongo webhook doesn't reach this server: the
// Flutter app calls this after the user returns from checkout_url (on
// app resume, or a "Check payment status" button). Reads the
// PaymentIntent straight from PayMongo and, if it has succeeded, runs
// the exact same activation the payment.paid webhook does.
// ============================================================
if ($action === "confirm_payment") {
    $response = ["success" => false, "message" => ""];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        $response["message"] = "confirm_payment requires a POST request.";
        echo json_encode($response);
        exit();
    }

    $paymentId = (int) ($body["payment_id"] ?? 0);
    if ($paymentId <= 0) {
        $response["message"] = "payment_id is required.";
        echo json_encode($response);
        exit();
    }

    // 1. Find our payment row and its stored PaymentIntent id.
    $stmt = $conn->prepare("SELECT PaymentIntentID, MemberID FROM Payments WHERE PaymentID = ? AND MemberID = ? LIMIT 1");
    $stmt->bind_param("ii", $paymentId, $authenticatedMemberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $response["message"] = "Payment not found.";
        echo json_encode($response);
        exit();
    }

    $intentId = $row["PaymentIntentID"] ?? "";
    if ($intentId === "" || $intentId === null) {
        $response["message"] = "This payment has no PayMongo payment intent on file.";
        echo json_encode($response);
        exit();
    }

    // 2. Ask PayMongo for the current PaymentIntent status.
    $intentRes = paymongo_call("GET", "/payment_intents/" . rawurlencode($intentId));

    $intentStatus = $intentRes["data"]["data"]["attributes"]["status"] ?? "";
    if ($intentRes["http_code"] < 200 || $intentRes["http_code"] >= 300 || $intentStatus === "") {
        $response["message"] = "Could not read the payment intent from PayMongo.";
        $response["paymongo_error"] = $intentRes["data"]["errors"] ?? $intentRes["raw"];
        echo json_encode($response);
        exit();
    }

    // 3. Only "succeeded" triggers activation. activate_membership_for_payment()
    //    is the same (idempotent) routine the payment.paid webhook calls, so a
    //    payment that was already confirmed by the webhook is a safe no-op here.
    $updated = false;
    if ($intentStatus === "succeeded") {
        $updated = activate_membership_for_payment($conn, $paymentId);
    }

    $conn->close();

    $response["success"] = true;
    $response["message"] = "";
    $response["status"]  = $intentStatus;   // raw PayMongo status
    $response["updated"] = $updated;        // true only if this call changed our data
    echo json_encode($response);
    exit();
}

// ============================================================
// action=submit_receipt  (POST, multipart/form-data)
// Member paid manually (GCash/Maya) outside the app and uploads a
// screenshot. Save it, OCR it for the admin's review, and record a
// Pending payment. No membership change yet -- that's approve_receipt.
// ============================================================
if ($action === "submit_receipt") {
    $response = ["success" => false, "message" => ""];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        $response["message"] = "submit_receipt requires a POST request.";
        echo json_encode($response);
        exit();
    }

    $memberId = member_session_resolve_member_id($conn, (int) ($_POST["member_id"] ?? 0));
    $planId   = (int) ($_POST["plan_id"] ?? 0);
    $method   = strtolower(trim($_POST["method"] ?? ""));
    $purpose  = trim($_POST["purpose"] ?? "New Registration");

    if ($memberId <= 0 || $planId <= 0) {
        $response["message"] = "member_id and plan_id are required.";
        echo json_encode($response);
        exit();
    }
    if ($method !== "gcash" && $method !== "paymaya") {
        $response["message"] = "method must be 'gcash' or 'paymaya'.";
        echo json_encode($response);
        exit();
    }
    if (!in_array($purpose, ["New Registration", "Renewal", "Upgrade"], true)) {
        $purpose = "New Registration";
    }
    if (!isset($_FILES["receipt"])) {
        $response["message"] = "Choose a receipt image before submitting.";
        echo json_encode($response);
        exit();
    }
    $uploadError = (int) ($_FILES["receipt"]["error"] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $response["message"] = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? "Receipt image is too large. Please upload an image under 10 MB."
            : "The receipt image could not be uploaded. Please choose it again and retry.";
        echo json_encode($response);
        exit();
    }
    if ((int) ($_FILES["receipt"]["size"] ?? 0) > 10 * 1024 * 1024) {
        $response["message"] = "Receipt image is too large. Please upload an image under 10 MB.";
        echo json_encode($response);
        exit();
    }

    // 1. Member email (for the filename, same pattern as
    //    complete_registration_api.php) + plan price (server-side only).
    $mStmt = $conn->prepare("SELECT Email FROM Members WHERE MemberID = ?");
    $mStmt->bind_param("i", $memberId);
    $mStmt->execute();
    $member = $mStmt->get_result()->fetch_assoc();
    $mStmt->close();

    if (!$member) {
        $response["message"] = "Member not found.";
        echo json_encode($response);
        exit();
    }

    $pStmt = $conn->prepare("SELECT Price FROM Plans WHERE PlanID = ?");
    $pStmt->bind_param("i", $planId);
    $pStmt->execute();
    $plan = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();

    if (!$plan) {
        $response["message"] = "Plan not found.";
        echo json_encode($response);
        exit();
    }
    $price = (float) $plan["Price"];

    // 2. Save the upload as receipt_<email-non-alnum-as-underscore>_<unixtime>.<ext>
    $uploadDir = __DIR__ . "/uploads/receipts/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($_FILES["receipt"]["name"], PATHINFO_EXTENSION));
    $safeExt = preg_match('/^(jpg|jpeg|png|webp)$/', $ext) ? $ext : 'jpg';
    $receiptFilename = "receipt_" . preg_replace('/[^a-zA-Z0-9]/', '_', $member["Email"]) . "_" . time() . "." . $safeExt;
    $receiptDestPath = $uploadDir . $receiptFilename;

    if (!move_uploaded_file($_FILES["receipt"]["tmp_name"], $receiptDestPath)) {
        $response["message"] = "Failed to save the receipt image. Please try again.";
        echo json_encode($response);
        exit();
    }
    $receiptRelativePath = "uploads/receipts/" . $receiptFilename;

    // 3. OCR the saved image for an amount + reference. Non-fatal: if OCR
    //    fails the columns stay NULL and the admin still reviews the image.
    $ocrText   = ocr_receipt_text($receiptDestPath);
    $ocrAmount = ocr_extract_amount($ocrText);      // float|null
    $ocrRef    = ocr_extract_reference($ocrText);   // string|null

    // 4. Insert the Pending payment. MembershipID / SourceID / PaymentIntentID
    //    stay NULL for the manual flow. Amount is the plan price, not the
    //    OCR guess.
    try {
        $insert = $conn->prepare(
            "INSERT INTO Payments
                (MemberID, MembershipID, Date, Amount, Method, Status, Purpose, ReceiptURL, OcrExtractedAmount, OcrExtractedReference)
             VALUES (?, NULL, CURDATE(), ?, ?, 'Pending', ?, ?, ?, ?)"
        );
        // Bind the OCR amount as a string so a null becomes SQL NULL
        // (a "d" bind would coerce null to 0).
        $ocrAmountParam = $ocrAmount !== null ? number_format($ocrAmount, 2, '.', '') : null;
        $insert->bind_param("idsssss", $memberId, $price, $method, $purpose, $receiptRelativePath, $ocrAmountParam, $ocrRef);
        $insert->execute();
        $paymentId = (int) $insert->insert_id;
        $insert->close();
    } catch (\Throwable $e) {
        error_log("billing_api submit_receipt insert failed: " . $e->getMessage());
        $response["message"] = "Could not record the receipt. Please try again.";
        echo json_encode($response);
        exit();
    }

    $conn->close();

    $response["success"]                 = true;
    $response["payment_id"]              = $paymentId;
    $response["message"]                 = "Receipt submitted, awaiting review.";
    $response["ocr_extracted_amount"]    = $ocrAmount;    // extra context, not in the spec
    $response["ocr_extracted_reference"] = $ocrRef;
    echo json_encode($response);
    exit();
}

// ============================================================
// action=list_pending_receipts  (GET)  -- admin app
// Every Pending payment that has an uploaded receipt, with the member
// name, the plan it's for (matched by price), the OCR guesses and the
// image URL so the admin can compare them.
// ============================================================
if ($action === "list_pending_receipts") {
    $sql =
        "SELECT py.PaymentID, py.MemberID, py.Date, py.Amount, py.Method, py.Status, py.Purpose,
                py.ReceiptURL, py.OcrExtractedAmount, py.OcrExtractedReference,
                m.FirstName, m.LastName, m.Email,
                pl.PlanID, pl.DurationLabel, pl.Price AS PlanPrice
         FROM Payments py
         JOIN Members m ON m.MemberID = py.MemberID
         LEFT JOIN Plans pl ON pl.Price = py.Amount
         WHERE py.Status = 'Pending' AND py.ReceiptURL IS NOT NULL
         ORDER BY py.Date DESC, py.PaymentID DESC";

    $result = $conn->query($sql);

    $pending = [];
    while ($row = $result->fetch_assoc()) {
        $ocrAmount = $row["OcrExtractedAmount"] !== null ? (float) $row["OcrExtractedAmount"] : null;
        $expected  = $row["PlanPrice"] !== null ? (float) $row["PlanPrice"] : null;
        $pending[] = [
            "payment_id"              => (int) $row["PaymentID"],
            "member_id"               => (int) $row["MemberID"],
            "member_name"             => trim($row["FirstName"] . " " . $row["LastName"]),
            "member_email"            => $row["Email"],
            "date"                    => $row["Date"],
            "method"                  => $row["Method"],
            "status"                  => $row["Status"],
            "purpose"                 => $row["Purpose"] ?? "New Registration",
            "expected_amount"         => (float) $row["Amount"],   // plan price we recorded
            "plan"                    => $row["DurationLabel"],
            "plan_id"                 => $row["PlanID"] !== null ? (int) $row["PlanID"] : null,
            "plan_price"              => $expected,
            "ocr_extracted_amount"    => $ocrAmount,
            "ocr_extracted_reference" => $row["OcrExtractedReference"],
            "amount_matches"          => ($ocrAmount !== null && $expected !== null)
                                          ? (abs($ocrAmount - $expected) < 0.01)
                                          : null,
            "receipt_url"             => $row["ReceiptURL"],
        ];
    }
    $result->free();
    $conn->close();

    echo json_encode(["success" => true, "pending" => $pending]);
    exit();
}

// ============================================================
// action=approve_receipt  (POST)  -- admin app
// Admin verified the receipt image. Mark it Paid and activate / extend
// the membership using the same routine the PayMongo webhook uses.
// ============================================================
if ($action === "approve_receipt") {
    $response = ["success" => false, "message" => ""];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        $response["message"] = "approve_receipt requires a POST request.";
        echo json_encode($response);
        exit();
    }

    $paymentId = (int) ($body["payment_id"] ?? $_POST["payment_id"] ?? 0);
    if ($paymentId <= 0) {
        $response["message"] = "payment_id is required.";
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare("SELECT Status FROM Payments WHERE PaymentID = ? LIMIT 1");
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $response["message"] = "Payment not found.";
        echo json_encode($response);
        exit();
    }

    // Same idempotent activation the payment.paid webhook and confirm_payment use.
    $updated = activate_membership_for_payment($conn, $paymentId);
    $conn->close();

    $response["success"]    = true;
    $response["payment_id"] = $paymentId;
    $response["status"]     = "Paid";
    $response["updated"]    = $updated;
    $response["message"]    = $updated
        ? "Receipt approved and membership updated."
        : "Payment was already approved.";
    echo json_encode($response);
    exit();
}

// ============================================================
// action=reject_receipt  (POST)  -- admin app
// Admin doesn't accept the receipt. Mark the payment Failed. The
// optional reason is logged and echoed back (no column stores it).
// ============================================================
if ($action === "reject_receipt") {
    $response = ["success" => false, "message" => ""];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        $response["message"] = "reject_receipt requires a POST request.";
        echo json_encode($response);
        exit();
    }

    $paymentId = (int) ($body["payment_id"] ?? $_POST["payment_id"] ?? 0);
    $reason    = trim($body["reason"] ?? $_POST["reason"] ?? "");
    if ($paymentId <= 0) {
        $response["message"] = "payment_id is required.";
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare("SELECT Status FROM Payments WHERE PaymentID = ? LIMIT 1");
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $response["message"] = "Payment not found.";
        echo json_encode($response);
        exit();
    }
    if ($row["Status"] === "Paid") {
        $response["message"] = "This payment is already Paid.";
        echo json_encode($response);
        exit();
    }

    $upd = $conn->prepare("UPDATE Payments SET Status = 'Failed' WHERE PaymentID = ?");
    $upd->bind_param("i", $paymentId);
    $upd->execute();
    $upd->close();
    $conn->close();

    if ($reason !== "") {
        error_log("billing_api reject_receipt: payment $paymentId rejected -- reason: $reason");
    }

    $response["success"]    = true;
    $response["payment_id"] = $paymentId;
    $response["status"]     = "Failed";
    $response["reason"]     = $reason;
    $response["message"]    = "Receipt rejected.";
    echo json_encode($response);
    exit();
}

// ============================================================
// action=payment_status  (GET)  ?payment_id=X   (our internal PaymentID)
// ============================================================
if ($action === "payment_status") {
    $response = ["success" => false, "message" => ""];

    $paymentId = (int) ($_GET["payment_id"] ?? 0);
    if ($paymentId <= 0) {
        $response["message"] = "payment_id is required.";
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare("SELECT Status FROM Payments WHERE PaymentID = ? AND MemberID = ? LIMIT 1");
    $stmt->bind_param("ii", $paymentId, $authenticatedMemberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        $response["message"] = "Payment not found.";
        echo json_encode($response);
        exit();
    }

    $response["success"]    = true;
    $response["payment_id"] = $paymentId;
    $response["status"]     = $row["Status"];
    echo json_encode($response);
    exit();
}

// ============================================================
// action=billing_history  (GET)  ?member_id=X
// ============================================================
if ($action === "billing_history") {
    $response = ["success" => false, "message" => ""];

    $memberId = member_session_resolve_member_id($conn, (int) ($_GET["member_id"] ?? 0));
    if ($memberId <= 0) {
        $response["message"] = "member_id is required.";
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT py.PaymentID, py.Date, py.Amount, py.Method, py.Status, py.PaymentIntentID, py.MembershipID,
                ms.StartDate, ms.NextRenewalDate, ms.Status AS MembershipStatus,
                pl.PlanID, pl.DurationLabel, pl.DurationMonths, pl.Price
         FROM Payments py
         LEFT JOIN Memberships ms ON ms.MembershipID = py.MembershipID
         LEFT JOIN Plans pl ON pl.PlanID = ms.PlanID
         WHERE py.MemberID = ?
         ORDER BY py.Date DESC, py.PaymentID DESC"
    );
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();

    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = [
            "payment_id"        => (int) $row["PaymentID"],
            "date"              => $row["Date"],
            "amount"            => (float) $row["Amount"],
            "method"            => $row["Method"],
            "status"            => $row["Status"],
            "membership_id"     => $row["MembershipID"] !== null ? (int) $row["MembershipID"] : null,
            "plan"              => $row["DurationLabel"],       // null until the payment is linked
            "plan_months"       => $row["DurationMonths"] !== null ? (int) $row["DurationMonths"] : null,
            "plan_price"        => $row["Price"] !== null ? (float) $row["Price"] : null,
            "start_date"        => $row["StartDate"],
            "next_renewal_date" => $row["NextRenewalDate"],
            "membership_status" => $row["MembershipStatus"],
        ];
    }
    $stmt->close();
    $conn->close();

    $response["success"] = true;
    $response["history"] = $history;
    echo json_encode($response);
    exit();
}

// ============================================================
// Unknown / missing action
// ============================================================
$conn->close();
echo json_encode(["success" => false, "message" => "Unknown action: " . $action]);
?>
