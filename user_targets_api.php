<?php
// ============================================================
// user_targets_api.php
// GET  ?member_id=X -> fetch the member's daily calorie/macro targets
//                      and target weight. If no row exists yet,
//                      returns success with null values instead of
//                      an error (no auto-create, unlike MonthlyGoals).
// POST { member_id, daily_calorie_target, daily_protein_target,
//        daily_carbs_target, daily_fats_target, target_weight }
//                   -> create or update the member's targets
//                      (upsert: one row per member)
// ============================================================

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/sync_debug.log');

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

// Sanity caps -- same bounds used by body_metrics_api.php / food_logs_api.php.
// Targets are also rejected at zero (not just negative), because the
// Flutter app divides current/target for progress bars and a stored
// zero would cause a division by zero there.
$maxCalories   = 20000;
$maxMacroGrams = 2000;
$maxWeightKg   = 500;

// ------------------------------------------------------------
// GET: fetch the member's targets. No row yet -> null values,
// not an error.
// ------------------------------------------------------------
if ($method === "GET") {
    $memberId = intval($_GET["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    try {
        $stmt = $conn->prepare(
            "SELECT TargetID, MemberID, DailyCalorieTarget, DailyProteinTarget,
                    DailyCarbsTarget, DailyFatsTarget, TargetWeight, UpdatedAt, CreatedAt
             FROM UserTargets
             WHERE MemberID = ?"
        );
        $stmt->bind_param("i", $memberId);
        $stmt->execute();
        $targets = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();
    } catch (\Throwable $e) {
        error_log("user_targets_api GET failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not load targets. Please try again."]);
        exit();
    }

    if (!$targets) {
        $targets = [
            "TargetID"           => null,
            "MemberID"           => $memberId,
            "DailyCalorieTarget" => null,
            "DailyProteinTarget" => null,
            "DailyCarbsTarget"   => null,
            "DailyFatsTarget"    => null,
            "TargetWeight"       => null,
            "UpdatedAt"          => null,
            "CreatedAt"          => null,
        ];
    }

    echo json_encode(["success" => true, "targets" => $targets]);
    exit();
}

// ------------------------------------------------------------
// POST: create or update the member's targets (upsert)
// ------------------------------------------------------------
if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $memberId           = intval($data["member_id"] ?? 0);
    $dailyCalorieTarget = intval($data["daily_calorie_target"] ?? 0);
    $dailyProteinTarget = isset($data["daily_protein_target"]) ? floatval($data["daily_protein_target"]) : null;
    $dailyCarbsTarget   = isset($data["daily_carbs_target"]) ? floatval($data["daily_carbs_target"]) : null;
    $dailyFatsTarget    = isset($data["daily_fats_target"]) ? floatval($data["daily_fats_target"]) : null;
    $targetWeight       = isset($data["target_weight"]) ? floatval($data["target_weight"]) : null;

    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    if ($dailyCalorieTarget <= 0) {
        echo json_encode(["success" => false, "message" => "daily_calorie_target must be greater than zero."]);
        exit();
    }

    if ($dailyCalorieTarget > $maxCalories) {
        echo json_encode(["success" => false, "message" => "daily_calorie_target must be $maxCalories or less."]);
        exit();
    }

    // Macro targets and target weight are optional, but if given they
    // must be positive (zero would divide-by-zero the Flutter progress
    // bars) and within a sane range.
    foreach (["daily_protein_target" => $dailyProteinTarget, "daily_carbs_target" => $dailyCarbsTarget, "daily_fats_target" => $dailyFatsTarget] as $label => $value) {
        if ($value !== null && $value <= 0) {
            echo json_encode(["success" => false, "message" => "$label must be greater than zero."]);
            exit();
        }
        if ($value !== null && $value > $maxMacroGrams) {
            echo json_encode(["success" => false, "message" => "$label must be $maxMacroGrams" . "g or less."]);
            exit();
        }
    }

    if ($targetWeight !== null && $targetWeight <= 0) {
        echo json_encode(["success" => false, "message" => "target_weight must be greater than zero."]);
        exit();
    }

    if ($targetWeight !== null && $targetWeight > $maxWeightKg) {
        echo json_encode(["success" => false, "message" => "target_weight must be $maxWeightKg kg or less."]);
        exit();
    }

    try {
        $checkStmt = $conn->prepare("SELECT TargetID FROM UserTargets WHERE MemberID = ?");
        $checkStmt->bind_param("i", $memberId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $updateStmt = $conn->prepare(
                "UPDATE UserTargets
                 SET DailyCalorieTarget = ?, DailyProteinTarget = ?, DailyCarbsTarget = ?,
                     DailyFatsTarget = ?, TargetWeight = ?
                 WHERE TargetID = ?"
            );
            $updateStmt->bind_param(
                "iddddi",
                $dailyCalorieTarget,
                $dailyProteinTarget,
                $dailyCarbsTarget,
                $dailyFatsTarget,
                $targetWeight,
                $existing["TargetID"]
            );
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            $insertStmt = $conn->prepare(
                "INSERT INTO UserTargets
                    (MemberID, DailyCalorieTarget, DailyProteinTarget, DailyCarbsTarget, DailyFatsTarget, TargetWeight)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->bind_param(
                "iidddd",
                $memberId,
                $dailyCalorieTarget,
                $dailyProteinTarget,
                $dailyCarbsTarget,
                $dailyFatsTarget,
                $targetWeight
            );
            $insertStmt->execute();
            $insertStmt->close();
        }

        $conn->close();
    } catch (\Throwable $e) {
        error_log("user_targets_api POST failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not save targets. Please try again."]);
        exit();
    }

    echo json_encode(["success" => true, "message" => "Targets saved."]);
    exit();
}

echo json_encode(["success" => false, "message" => "Unsupported request method."]);
?>
