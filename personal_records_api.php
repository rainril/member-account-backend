<?php
// ============================================================
// personal_records_api.php
// GET    ?member_id=X       -> list a member's personal records
// POST   { member_id, exercise, muscle, weight, sets, reps }
//                           -> add a new personal record
// DELETE ?pr_id=X           -> delete a personal record
// ============================================================

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

// ------------------------------------------------------------
// GET: list all personal records for a member, newest first
// ------------------------------------------------------------
if ($method === "GET") {
    $memberId = intval($_GET["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT PRID, Exercise, Muscle, Weight, Sets, Reps, Date
         FROM PersonalRecords
         WHERE MemberID = ?
         ORDER BY Date DESC, PRID DESC"
    );
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();

    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
    $conn->close();

    echo json_encode(["success" => true, "records" => $records]);
    exit();
}

// ------------------------------------------------------------
// POST: add a new personal record
// ------------------------------------------------------------
if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $memberId = intval($data["member_id"] ?? 0);
    $exercise = trim($data["exercise"] ?? "");
    $muscle   = trim($data["muscle"] ?? "");
    $weight   = intval($data["weight"] ?? 0);
    $sets     = intval($data["sets"] ?? 0);
    $reps     = intval($data["reps"] ?? 0);

    if ($memberId <= 0 || empty($exercise) || empty($muscle)) {
        echo json_encode(["success" => false, "message" => "Missing exercise, muscle, or member ID."]);
        exit();
    }

    $stmt = $conn->prepare(
        "INSERT INTO PersonalRecords (MemberID, Exercise, Muscle, Weight, Sets, Reps, Date)
         VALUES (?, ?, ?, ?, ?, ?, CURDATE())"
    );
    $stmt->bind_param("issiii", $memberId, $exercise, $muscle, $weight, $sets, $reps);
    $stmt->execute();
    $prId = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    echo json_encode([
        "success" => true,
        "message" => "Personal record saved.",
        "pr_id"   => $prId,
        "date"    => date("Y-m-d"),
    ]);
    exit();
}

// ------------------------------------------------------------
// DELETE: remove a personal record
// ------------------------------------------------------------
if ($method === "DELETE") {
    $prId = intval($_GET["pr_id"] ?? 0);
    if ($prId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing pr_id."]);
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM PersonalRecords WHERE PRID = ?");
    $stmt->bind_param("i", $prId);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(["success" => true, "message" => "Personal record deleted."]);
    exit();
}

echo json_encode(["success" => false, "message" => "Unsupported request method."]);
?>
