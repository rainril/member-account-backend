<?php
// ============================================================
// personal_records_api.php
// GET    ?member_id=X       -> list a member's personal records
// POST   { member_id, exercise, muscle, weight, sets, reps, duration_seconds }
//                           -> add a new personal record. duration_seconds
//                              is optional -- only relevant for time-based
//                              exercises (planks, cardio, etc.).
// DELETE ?pr_id=X           -> delete a personal record
// ============================================================

// ------------------------------------------------------------
// CORS (must run before ANY output)
// ------------------------------------------------------------
// Add your deployed frontend URL(s) here, e.g. "https://primefit.vercel.app"
$allowedOrigins = [
    // "https://your-app.vercel.app",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
$isLocalOrigin = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);

if ($origin !== "" && ($isLocalOrigin || in_array($origin, $allowedOrigins, true))) {
    // Known origin: echo it back (works with or without credentials)
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
} else {
    // Unknown / no origin: keep the old open behaviour (no credentials)
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With, X-Member-Id");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=utf-8");

// Preflight request: answer immediately, no DB needed
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit();
}

// ------------------------------------------------------------
// Always return JSON, even on errors (so the browser never sees
// a dead request / "Failed to fetch" because of a PHP crash)
// ------------------------------------------------------------
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage(),
    ]);
    exit();
});

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
        "SELECT PRID, Exercise, Muscle, Weight, Sets, Reps, DurationSeconds, Date
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
    if (!is_array($data)) {
        $data = $_POST; // fallback for form-encoded requests
    }

    $memberId = intval($data["member_id"] ?? 0);
    $exercise = trim($data["exercise"] ?? "");
    $muscle   = trim($data["muscle"] ?? "");
    $weight   = floatval($data["weight"] ?? 0);
    $sets     = intval($data["sets"] ?? 0);
    $reps     = intval($data["reps"] ?? 0);

    // Optional -- only time-based exercises (planks, cardio, etc.) set this.
    $durationRaw = $data["duration_seconds"] ?? null;
    $duration = ($durationRaw === null || $durationRaw === "") ? null : intval($durationRaw);

    if ($memberId <= 0 || $exercise === "" || $muscle === "") {
        echo json_encode(["success" => false, "message" => "Missing exercise, muscle, or member ID."]);
        exit();
    }

    $stmt = $conn->prepare(
        "INSERT INTO PersonalRecords (MemberID, Exercise, Muscle, Weight, Sets, Reps, DurationSeconds, Date)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())"
    );
    $stmt->bind_param("issdiii", $memberId, $exercise, $muscle, $weight, $sets, $reps, $duration);
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

http_response_code(405);
echo json_encode(["success" => false, "message" => "Unsupported request method."]);
