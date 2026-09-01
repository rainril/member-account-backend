<?php
// ============================================================
// body_metrics_api.php
// GET    ?member_id=X&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
//                              -> list a member's BMI/weight history,
//                                 newest first (date range optional)
// POST   { member_id, weight, height, recorded_at }
//                              -> add a new entry; BMI is calculated
//                                 server-side from weight/height
//                                 (recorded_at optional, defaults to today)
// DELETE ?metric_id=X&member_id=X
//                              -> delete an entry (must belong to member_id)
// ============================================================

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/sync_debug.log');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";

$method = $_SERVER["REQUEST_METHOD"];

// Sanity caps -- reject values that are almost certainly typos rather
// than silently accepting garbage into the health data.
$maxWeightKg = 500;
$maxHeightCm = 300;

// ------------------------------------------------------------
// GET: list a member's body metrics, newest first, optionally
// restricted to a date range
// ------------------------------------------------------------
if ($method === "GET") {
    $memberId = intval($_GET["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    $startDate = trim($_GET["start_date"] ?? "");
    $endDate   = trim($_GET["end_date"] ?? "");

    $sql = "SELECT MetricID, MemberID, Weight, Height, BMI, RecordedAt, CreatedAt
            FROM BodyMetrics
            WHERE MemberID = ?";
    $types  = "i";
    $params = [$memberId];

    if ($startDate !== "" && $endDate !== "") {
        $sql .= " AND RecordedAt BETWEEN ? AND ?";
        $types .= "ss";
        $params[] = $startDate;
        $params[] = $endDate;
    }

    $sql .= " ORDER BY RecordedAt DESC, MetricID DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $metrics = [];
        while ($row = $result->fetch_assoc()) {
            $metrics[] = $row;
        }
        $stmt->close();
        $conn->close();
    } catch (\Throwable $e) {
        error_log("body_metrics_api GET failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not load body metrics. Please try again."]);
        exit();
    }

    echo json_encode(["success" => true, "metrics" => $metrics]);
    exit();
}

// ------------------------------------------------------------
// POST: add a new body metrics entry, auto-calculating BMI
// ------------------------------------------------------------
if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $memberId   = intval($data["member_id"] ?? 0);
    $weight     = floatval($data["weight"] ?? 0);   // kg
    $height     = floatval($data["height"] ?? 0);   // cm
    $recordedAt = trim($data["recorded_at"] ?? "");

    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing or invalid member_id."]);
        exit();
    }

    if ($weight <= 0 || $height <= 0) {
        echo json_encode(["success" => false, "message" => "Weight and height must be greater than zero."]);
        exit();
    }

    if ($weight > $maxWeightKg) {
        echo json_encode(["success" => false, "message" => "Weight must be $maxWeightKg kg or less."]);
        exit();
    }

    if ($height > $maxHeightCm) {
        echo json_encode(["success" => false, "message" => "Height must be $maxHeightCm cm or less."]);
        exit();
    }

    if ($recordedAt === "") {
        $recordedAt = date("Y-m-d");
    }

    // BMI = weight(kg) / height(m)^2 -- guard against a zero/invalid
    // height so we never divide by zero here, even though the check
    // above should already have rejected it.
    $heightMeters = $height / 100;
    if ($heightMeters <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid height."]);
        exit();
    }
    $bmi = round($weight / ($heightMeters * $heightMeters), 1);

    try {
        $stmt = $conn->prepare(
            "INSERT INTO BodyMetrics (MemberID, Weight, Height, BMI, RecordedAt)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iddds", $memberId, $weight, $height, $bmi, $recordedAt);
        $stmt->execute();
        $metricId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
    } catch (\Throwable $e) {
        error_log("body_metrics_api POST failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not save body metrics. Please try again."]);
        exit();
    }

    echo json_encode([
        "success"     => true,
        "message"     => "Body metrics saved.",
        "metric_id"   => $metricId,
        "bmi"         => $bmi,
        "recorded_at" => $recordedAt,
    ]);
    exit();
}

// ------------------------------------------------------------
// DELETE: remove a body metrics entry -- only if it belongs to
// the requesting member, so one member can't delete another
// member's entry by guessing a metric_id
// ------------------------------------------------------------
if ($method === "DELETE") {
    $metricId = intval($_GET["metric_id"] ?? 0);
    $memberId = intval($_GET["member_id"] ?? 0);

    if ($metricId <= 0 || $memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing metric_id or member_id."]);
        exit();
    }

    try {
        $stmt = $conn->prepare("DELETE FROM BodyMetrics WHERE MetricID = ? AND MemberID = ?");
        $stmt->bind_param("ii", $metricId, $memberId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        $conn->close();
    } catch (\Throwable $e) {
        error_log("body_metrics_api DELETE failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not delete body metrics entry. Please try again."]);
        exit();
    }

    if (!$deleted) {
        echo json_encode(["success" => false, "message" => "Body metrics entry not found."]);
        exit();
    }

    echo json_encode(["success" => true, "message" => "Body metrics entry deleted."]);
    exit();
}

echo json_encode(["success" => false, "message" => "Unsupported request method."]);
?>
