<?php
// ============================================================
// programs_api.php
// GET  -> returns all workout programs, each with its list of
//         days (Day A/B tabs) and each day's list of exercises,
//         nested as JSON so the Flutter app can render it directly.
// POST -> (multipart/form-data) creates a new custom program,
//         optionally with an uploaded diagram/cover image, plus
//         its days and exercises (sent as a JSON string field).
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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    handleCreateProgram($conn);
    exit();
}

$response = ["success" => false, "programs" => []];

try {
    // 1. Fetch all programs
    $programsResult = $conn->query("SELECT * FROM Programs ORDER BY ProgramID");
    $programs = [];
    while ($row = $programsResult->fetch_assoc()) {
        $row["days"] = [];
        $programs[$row["ProgramID"]] = $row;
    }

    // 2. Fetch all days, grouped under their program
    $daysResult = $conn->query("SELECT * FROM ProgramDays ORDER BY ProgramID, DisplayOrder");
    $daysByProgram = [];
    while ($day = $daysResult->fetch_assoc()) {
        $day["exercises"] = [];
        $daysByProgram[$day["ProgramDayID"]] = $day;
        $programs[$day["ProgramID"]]["days"][] = $day["ProgramDayID"];
    }

    // 3. Fetch all exercises, grouped under their day
    $exResult = $conn->query("SELECT * FROM ProgramExercises ORDER BY ProgramDayID, DisplayOrder");
    while ($ex = $exResult->fetch_assoc()) {
        $daysByProgram[$ex["ProgramDayID"]]["exercises"][] = $ex;
    }

    // 4. Assemble the final nested structure
    $finalPrograms = [];
    foreach ($programs as $program) {
        $program["days"] = array_map(function ($dayId) use ($daysByProgram) {
            return $daysByProgram[$dayId];
        }, $program["days"]);
        $finalPrograms[] = $program;
    }

    $response["success"] = true;
    $response["programs"] = $finalPrograms;
} catch (Exception $e) {
    $response["message"] = "Error: " . $e->getMessage();
}

$conn->close();
echo json_encode($response);

// ============================================================
// POST handler: create a new custom program (+ days + exercises,
// + optional uploaded image)
// ============================================================
function handleCreateProgram($conn) {
    $response = ["success" => false, "message" => ""];

    $memberId    = intval($_POST["member_id"] ?? 0);
    $programName = trim($_POST["program_name"] ?? "");
    $muscleGroup = trim($_POST["muscle_group"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $duration    = trim($_POST["duration_range"] ?? "");
    $frequency   = intval($_POST["frequency_per_week"] ?? 0);
    $level       = trim($_POST["level"] ?? "");
    $daysJson    = $_POST["days_json"] ?? "[]";

    if ($memberId <= 0 || empty($programName) || empty($muscleGroup)) {
        $response["message"] = "Missing member, program name, or muscle group.";
        echo json_encode($response);
        return;
    }

    $days = json_decode($daysJson, true);
    if (!is_array($days)) {
        $response["message"] = "Invalid days/exercises data.";
        echo json_encode($response);
        return;
    }

    // Auto-assign a color palette (rotates through 6 preset themes,
    // same family of colors used by the original built-in programs).
    $palettes = [
        ['#00B4D8', '#F4FBFD', '#CFEEF7', '#FFF3CD', '#FFB703'],
        ['#D4A373', '#FFFDF6', '#FEF0CD', '#FFF3CD', '#FFB703'],
        ['#9C27B0', '#FDFBFE', '#F3E5F5', '#F8D7DA', '#DC3545'],
        ['#E65100', '#FFFBF9', '#FFE0CC', '#D1E7DD', '#0F5132'],
        ['#E91E63', '#FFF1F6', '#FBCFE8', '#D1E7DD', '#0F5132'],
        ['#2E7D32', '#F0FDF4', '#DCFCE7', '#D1E7DD', '#0F5132'],
    ];
    $countResult = $conn->query("SELECT COUNT(*) AS c FROM Programs");
    $count = intval($countResult->fetch_assoc()["c"]);
    $palette = $palettes[$count % count($palettes)];

    // Handle optional image upload
    $imageUrl = null;
    if (isset($_FILES["image"]) && $_FILES["image"]["error"] === UPLOAD_ERR_OK) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedType = finfo_file($finfo, $_FILES["image"]["tmp_name"]);
        finfo_close($finfo);

        $allowedTypes = ["image/jpeg", "image/png", "image/webp"];
        if (in_array($detectedType, $allowedTypes)) {
            $uploadDir = __DIR__ . "/uploads/program_images/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $ext = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
            $filename = "program_" . time() . "_" . rand(1000, 9999) . "." . $ext;
            $destPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES["image"]["tmp_name"], $destPath)) {
                $protocol = isset($_SERVER["HTTPS"]) ? "https" : "http";
                $host = $_SERVER["HTTP_HOST"];
                $imageUrl = "$protocol://$host/memberaccount/uploads/program_images/$filename";
            }
        }
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO Programs (CreatedByMemberID, ProgramName, MuscleGroup, Description, DurationRange,
                                    FrequencyPerWeek, Level, ThemeColorHex, CardBgHex, BorderColorHex,
                                    BadgeBgHex, BadgeTextColorHex, ImageURL)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "issssisssssss",
            $memberId, $programName, $muscleGroup, $description, $duration, $frequency, $level,
            $palette[0], $palette[1], $palette[2], $palette[3], $palette[4], $imageUrl
        );
        $stmt->execute();
        $programId = $stmt->insert_id;
        $stmt->close();

        $dayStmt = $conn->prepare("INSERT INTO ProgramDays (ProgramID, DayLabel, DisplayOrder) VALUES (?, ?, ?)");
        $exStmt = $conn->prepare(
            "INSERT INTO ProgramExercises (ProgramDayID, ExerciseName, Tip, Sets, Reps, Rest, DisplayOrder)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $dayOrder = 0;
        foreach ($days as $day) {
            $dayLabel = trim($day["label"] ?? "Day " . ($dayOrder + 1));
            $dayStmt->bind_param("isi", $programId, $dayLabel, $dayOrder);
            $dayStmt->execute();
            $dayId = $dayStmt->insert_id;
            $dayOrder++;

            $exOrder = 0;
            foreach (($day["exercises"] ?? []) as $ex) {
                $name = trim($ex["name"] ?? "");
                if (empty($name)) continue;
                $tip = trim($ex["tip"] ?? "");
                $sets = trim($ex["sets"] ?? "");
                $reps = trim($ex["reps"] ?? "");
                $rest = trim($ex["rest"] ?? "");
                $exStmt->bind_param("isssssi", $dayId, $name, $tip, $sets, $reps, $rest, $exOrder);
                $exStmt->execute();
                $exOrder++;
            }
        }

        $dayStmt->close();
        $exStmt->close();
        $conn->commit();

        $response["success"] = true;
        $response["message"] = "Program created.";
        $response["program_id"] = $programId;
    } catch (Exception $e) {
        $conn->rollback();
        $response["message"] = "Error: " . $e->getMessage();
    }

    $conn->close();
    echo json_encode($response);
}
?>
