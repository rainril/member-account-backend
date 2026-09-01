<?php
// ============================================================
// monthly_goals_api.php
// GET  ?member_id=X            -> fetch (or auto-create) the
//                                 member's goal for the CURRENT month
// POST { member_id, target_sessions } -> set/update this month's target
// ============================================================

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
$currentMonth = date("Y-m"); // e.g. "2026-07"

// Default target sessions for a brand-new month/member
$defaultTarget = 20;

// ------------------------------------------------------------
// GET: fetch this month's goal, creating a default row if none exists
// ------------------------------------------------------------
if ($method === "GET") {
    $memberId = intval($_GET["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT GoalID, TargetSessions, CompletedSessions FROM MonthlyGoals WHERE MemberID = ? AND Month = ?"
    );
    $stmt->bind_param("is", $memberId, $currentMonth);
    $stmt->execute();
    $goal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$goal) {
        // No goal row yet for this month -- create one with the default target
        $insertStmt = $conn->prepare(
            "INSERT INTO MonthlyGoals (MemberID, Month, TargetSessions, CompletedSessions) VALUES (?, ?, ?, 0)"
        );
        $insertStmt->bind_param("isi", $memberId, $currentMonth, $defaultTarget);
        $insertStmt->execute();
        $insertStmt->close();

        $goal = [
            "GoalID" => $conn->insert_id,
            "TargetSessions" => $defaultTarget,
            "CompletedSessions" => 0,
        ];
    }

    $conn->close();
    echo json_encode(["success" => true, "goal" => $goal, "month" => $currentMonth]);
    exit();
}

// ------------------------------------------------------------
// POST: update this month's target (member sets their own goal)
// ------------------------------------------------------------
if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);
    $memberId = intval($data["member_id"] ?? 0);
    $target = intval($data["target_sessions"] ?? 0);

    if ($memberId <= 0 || $target <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id or target_sessions."]);
        exit();
    }

    $checkStmt = $conn->prepare("SELECT GoalID FROM MonthlyGoals WHERE MemberID = ? AND Month = ?");
    $checkStmt->bind_param("is", $memberId, $currentMonth);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($existing) {
        $updateStmt = $conn->prepare("UPDATE MonthlyGoals SET TargetSessions = ? WHERE GoalID = ?");
        $updateStmt->bind_param("ii", $target, $existing["GoalID"]);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO MonthlyGoals (MemberID, Month, TargetSessions, CompletedSessions) VALUES (?, ?, ?, 0)"
        );
        $insertStmt->bind_param("isi", $memberId, $currentMonth, $target);
        $insertStmt->execute();
        $insertStmt->close();
    }

    $conn->close();
    echo json_encode(["success" => true, "message" => "Monthly goal updated."]);
    exit();
}

echo json_encode(["success" => false, "message" => "Unsupported request method."]);
?>
