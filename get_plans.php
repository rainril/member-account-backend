<?php
// ============================================================
// get_plans.php
// Returns all rows from the Plans table as JSON
// Called by Flutter to get the real PlanID for each plan
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require "db_connect.php";

$response = ["success" => false, "plans" => []];

try {
    $result = $conn->query("SELECT PlanID, DurationLabel, DurationMonths, Price, Features FROM Plans");
    $plans = [];
    while ($row = $result->fetch_assoc()) {
        $plans[] = $row;
    }
    $response["success"] = true;
    $response["plans"] = $plans;
} catch (Exception $e) {
    $response["message"] = "Error: " . $e->getMessage();
}

$conn->close();
echo json_encode($response);
?>
