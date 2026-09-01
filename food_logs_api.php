<?php
// ============================================================
// food_logs_api.php
// GET    ?member_id=X&date=YYYY-MM-DD
//        ?member_id=X&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
//                              -> list a member's food log entries,
//                                 newest first, plus a totals summary
//                                 (calories/protein/carbs/fats) for
//                                 whatever range was requested. With
//                                 no date filter, defaults to today.
// POST   { member_id, food_name, meal_type, calories, protein,
//          carbs, fats, logged_at }
//                              -> add a new food log entry
//                                 (logged_at optional, defaults to today)
// DELETE ?log_id=X&member_id=X -> delete an entry (must belong to member_id)
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

// Allowed meal types and sanity caps -- reject anything else / anything
// implausibly large as a likely typo rather than accepting it silently.
$allowedMealTypes = ["breakfast", "lunch", "dinner", "snack"];
$maxCalories    = 20000;
$maxMacroGrams  = 2000;

// ------------------------------------------------------------
// GET: list a member's food log entries for a date/date range
// (defaults to today if nothing is specified), plus totals
// ------------------------------------------------------------
if ($method === "GET") {
    $memberId = intval($_GET["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    $date      = trim($_GET["date"] ?? "");
    $startDate = trim($_GET["start_date"] ?? "");
    $endDate   = trim($_GET["end_date"] ?? "");

    $sql    = "SELECT LogID, MemberID, FoodName, MealType, Calories, Protein, Carbs, Fats, LoggedAt, CreatedAt
                FROM FoodLogs
                WHERE MemberID = ?";
    $types  = "i";
    $params = [$memberId];

    if ($date !== "") {
        $sql .= " AND LoggedAt = ?";
        $types .= "s";
        $params[] = $date;
    } elseif ($startDate !== "" && $endDate !== "") {
        $sql .= " AND LoggedAt BETWEEN ? AND ?";
        $types .= "ss";
        $params[] = $startDate;
        $params[] = $endDate;
    } else {
        // No filter given -- default to today
        $sql .= " AND LoggedAt = ?";
        $types .= "s";
        $params[] = date("Y-m-d");
    }

    $sql .= " ORDER BY LoggedAt DESC, LogID DESC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        $totals = ["calories" => 0, "protein" => 0.0, "carbs" => 0.0, "fats" => 0.0];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
            $totals["calories"] += intval($row["Calories"]);
            $totals["protein"]  += floatval($row["Protein"]);
            $totals["carbs"]    += floatval($row["Carbs"]);
            $totals["fats"]     += floatval($row["Fats"]);
        }
        $stmt->close();
        $conn->close();
    } catch (\Throwable $e) {
        error_log("food_logs_api GET failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not load food logs. Please try again."]);
        exit();
    }

    echo json_encode(["success" => true, "logs" => $logs, "totals" => $totals]);
    exit();
}

// ------------------------------------------------------------
// POST: add a new food log entry
// ------------------------------------------------------------
if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $memberId = intval($data["member_id"] ?? 0);
    $foodName = trim($data["food_name"] ?? "");
    $mealType = strtolower(trim($data["meal_type"] ?? ""));
    $calories = intval($data["calories"] ?? 0);
    $protein  = isset($data["protein"]) ? floatval($data["protein"]) : null;
    $carbs    = isset($data["carbs"]) ? floatval($data["carbs"]) : null;
    $fats     = isset($data["fats"]) ? floatval($data["fats"]) : null;
    $loggedAt = trim($data["logged_at"] ?? "");

    if ($memberId <= 0 || empty($foodName) || empty($mealType)) {
        echo json_encode(["success" => false, "message" => "Missing food_name, meal_type, or member ID."]);
        exit();
    }

    if (!in_array($mealType, $allowedMealTypes, true)) {
        echo json_encode([
            "success" => false,
            "message" => "meal_type must be one of: " . implode(", ", $allowedMealTypes) . ".",
        ]);
        exit();
    }

    if ($calories <= 0) {
        echo json_encode(["success" => false, "message" => "Calories must be greater than zero."]);
        exit();
    }

    if ($calories > $maxCalories) {
        echo json_encode(["success" => false, "message" => "Calories must be $maxCalories or less."]);
        exit();
    }

    foreach (["protein" => $protein, "carbs" => $carbs, "fats" => $fats] as $label => $value) {
        if ($value !== null && $value < 0) {
            echo json_encode(["success" => false, "message" => ucfirst($label) . " cannot be negative."]);
            exit();
        }
        if ($value !== null && $value > $maxMacroGrams) {
            echo json_encode(["success" => false, "message" => ucfirst($label) . " must be $maxMacroGrams" . "g or less."]);
            exit();
        }
    }

    if ($loggedAt === "") {
        $loggedAt = date("Y-m-d");
    }

    try {
        $stmt = $conn->prepare(
            "INSERT INTO FoodLogs (MemberID, FoodName, MealType, Calories, Protein, Carbs, Fats, LoggedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("issiddds", $memberId, $foodName, $mealType, $calories, $protein, $carbs, $fats, $loggedAt);
        $stmt->execute();
        $logId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
    } catch (\Throwable $e) {
        error_log("food_logs_api POST failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not save food log entry. Please try again."]);
        exit();
    }

    echo json_encode([
        "success"   => true,
        "message"   => "Food log entry saved.",
        "log_id"    => $logId,
        "logged_at" => $loggedAt,
    ]);
    exit();
}

// ------------------------------------------------------------
// DELETE: remove a food log entry -- only if it belongs to the
// requesting member, so one member can't delete another member's
// entry by guessing a log_id
// ------------------------------------------------------------
if ($method === "DELETE") {
    $logId    = intval($_GET["log_id"] ?? 0);
    $memberId = intval($_GET["member_id"] ?? 0);

    if ($logId <= 0 || $memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing log_id or member_id."]);
        exit();
    }

    try {
        $stmt = $conn->prepare("DELETE FROM FoodLogs WHERE LogID = ? AND MemberID = ?");
        $stmt->bind_param("ii", $logId, $memberId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        $conn->close();
    } catch (\Throwable $e) {
        error_log("food_logs_api DELETE failed: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Could not delete food log entry. Please try again."]);
        exit();
    }

    if (!$deleted) {
        echo json_encode(["success" => false, "message" => "Food log entry not found."]);
        exit();
    }

    echo json_encode(["success" => true, "message" => "Food log entry deleted."]);
    exit();
}

echo json_encode(["success" => false, "message" => "Unsupported request method."]);
?>
