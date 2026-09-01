<?php
// ============================================================
// body_photos_api.php
// GET    ?member_id=X          -> list a member's body photos
// POST   (multipart: member_id, photo)  -> upload a new photo
// DELETE ?photo_id=X           -> delete a photo
//
// Uploaded photos are analyzed by Gemini vision, combined with the
// member's real tracked data (BMI, weight/height, food logs) from
// progress_context_helper.php so the insight is grounded in their
// actual numbers rather than a guess from the photo alone.
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
require "ai_config.php";
require "progress_context_helper.php";

$method = $_SERVER["REQUEST_METHOD"];

// ------------------------------------------------------------
// GET: list a member's body photos, newest first, with the AI
// insight text saved for each
// ------------------------------------------------------------
if ($method === "GET") {
    $memberId = intval($_GET["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT bp.PhotoID, bp.PhotoURL, bp.UploadDate, ai.InsightText
         FROM BodyPhotos bp
         LEFT JOIN AI_Insights ai ON ai.PhotoID = bp.PhotoID
         WHERE bp.MemberID = ?
         ORDER BY bp.UploadDate DESC, bp.PhotoID DESC"
    );
    $stmt->bind_param("i", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();

    $photos = [];
    while ($row = $result->fetch_assoc()) {
        $photos[] = $row;
    }
    $stmt->close();
    $conn->close();

    echo json_encode(["success" => true, "photos" => $photos]);
    exit();
}

// ------------------------------------------------------------
// POST: upload a new body photo
// ------------------------------------------------------------
if ($method === "POST") {
    $memberId = intval($_POST["member_id"] ?? 0);
    if ($memberId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing member_id."]);
        exit();
    }

    if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
        echo json_encode(["success" => false, "message" => "No photo received, or the upload failed."]);
        exit();
    }

    // Detect the real file type from content, not the client-supplied header
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedType = finfo_file($finfo, $_FILES["photo"]["tmp_name"]);
    finfo_close($finfo);

    $allowedTypes = ["image/jpeg", "image/png", "image/webp"];
    if (!in_array($detectedType, $allowedTypes)) {
        echo json_encode(["success" => false, "message" => "Only JPG, PNG, or WEBP images are allowed."]);
        exit();
    }

    $maxSize = 8 * 1024 * 1024; // 8MB
    if ($_FILES["photo"]["size"] > $maxSize) {
        echo json_encode(["success" => false, "message" => "Image must be smaller than 8MB."]);
        exit();
    }

    $uploadDir = __DIR__ . "/uploads/body_photos/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
    $filename = "photo_" . $memberId . "_" . time() . "." . $ext;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $destPath)) {
        echo json_encode(["success" => false, "message" => "Failed to save the uploaded file."]);
        exit();
    }

    $protocol = isset($_SERVER["HTTPS"]) ? "https" : "http";
    $host = $_SERVER["HTTP_HOST"];
    $publicUrl = "$protocol://$host/memberaccount/uploads/body_photos/$filename";

    // Save the BodyPhotos row
    $stmt = $conn->prepare(
        "INSERT INTO BodyPhotos (MemberID, PhotoURL, UploadDate) VALUES (?, ?, CURDATE())"
    );
    $stmt->bind_param("is", $memberId, $publicUrl);
    $stmt->execute();
    $photoId = $stmt->insert_id;
    $stmt->close();

    // ------------------------------------------------------------
    // Build a data-grounded prompt: the member's real BMI, weight,
    // target weight, and recent food log, so Gemini's read of the
    // photo is checked against actual numbers instead of guessing.
    // ------------------------------------------------------------
    $progress = getMemberProgressContext($conn, $memberId);

    $targetWeight = null;
    $tStmt = $conn->prepare("SELECT TargetWeight FROM UserTargets WHERE MemberID = ?");
    $tStmt->bind_param("i", $memberId);
    $tStmt->execute();
    $targetRow = $tStmt->get_result()->fetch_assoc();
    $tStmt->close();
    if ($targetRow && $targetRow["TargetWeight"] !== null) {
        $targetWeight = floatval($targetRow["TargetWeight"]);
    }

    $dataLines = [];

    if ($progress["latest_bmi"] !== null) {
        $bmiVal = $progress["latest_bmi"];
        if ($bmiVal < 18.5) {
            $bmiCategory = "underweight";
        } elseif ($bmiVal < 25) {
            $bmiCategory = "normal weight";
        } elseif ($bmiVal < 30) {
            $bmiCategory = "overweight";
        } else {
            $bmiCategory = "obese";
        }
        $dataLines[] = "BMI: " . number_format($bmiVal, 1) . " ({$bmiCategory}), recorded {$progress['latest_bmi_date']}";
    } else {
        $dataLines[] = "BMI: not recorded yet";
    }

    if ($progress["latest_weight_kg"] !== null && $progress["latest_height_cm"] !== null) {
        $dataLines[] = "Weight: " . number_format($progress["latest_weight_kg"], 1) . " kg, Height: " . number_format($progress["latest_height_cm"], 1) . " cm";
    }

    if ($targetWeight !== null) {
        $dataLines[] = "Target weight: " . number_format($targetWeight, 1) . " kg";
    }

    $food = $progress["food_totals_7_days"];
    if ($food["days_logged"] > 0) {
        $avgCalories = round($food["calories"] / $food["days_logged"]);
        $avgProtein = round($food["protein"] / $food["days_logged"]);
        $dataLines[] = "Last 7 days food log: logged {$food['days_logged']} of 7 days, averaging {$avgCalories} kcal/day and {$avgProtein}g protein/day";
    } else {
        $dataLines[] = "No food logs in the last 7 days.";
    }

    $memberData = implode("\n", array_map(fn($l) => "- $l", $dataLines));

    $promptText = "You are PrimeFit Gym's AI fitness coach. A member just uploaded a progress photo. "
        . "Look at their visible physique in the photo (muscle tone, body composition, posture), then combine "
        . "that with their real tracked data below to give one accurate, personalized insight. Treat the tracked "
        . "data as the source of truth for their health status, not just what the photo looks like.\n\n"
        . "Member data:\n{$memberData}\n\n"
        . "Write a short, encouraging insight (strictly under 100 words, plain text, no markdown, no headers, no bullet points) that:\n"
        . "1. Comments briefly on their visible body build/composition in the photo.\n"
        . "2. Points out what's lacking or could improve (muscle definition, symmetry, nutrition consistency, etc.), grounded in the BMI/weight data above rather than guessed from the photo alone.\n"
        . "3. Gives 1-2 concrete, specific food or nutrition suggestions tailored to help them reach their target, based on their current intake vs. their goal.\n\n"
        . "Speak directly to the member as 'you'. Be specific and practical, not generic.";

    $insightText = null;

    $imageData = file_get_contents($destPath);
    if ($imageData !== false) {
        $base64Image = base64_encode($imageData);

        $requestBody = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $promptText],
                        [
                            "inline_data" => [
                                "mime_type" => $detectedType,
                                "data" => $base64Image,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // gemini-3.6-flash first: it's the current stable model and
        // responds in a few seconds. "gemini-flash-latest" is kept as a
        // fallback for when it eventually points somewhere current again,
        // but it currently hangs for the full 30s timeout on every call,
        // so it no longer goes first -- that wasted 30s widened the
        // window for the photo to be deleted mid-request (see the
        // try/catch around the AI_Insights insert below).
        $modelsToTry = ['gemini-3.6-flash', 'gemini-flash-latest', 'gemini-2.5-flash'];

        foreach ($modelsToTry as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($reply) {
                    $insightText = trim($reply);
                }
                break;
            }

            if ($httpCode !== 404 && !$curlError) {
                break;
            }
        }
    }

    if ($insightText === null) {
        $insightText = "Photo saved to your progress log. AI insight is temporarily unavailable -- please check back later.";
    }

    // Wrapped defensively: if the photo was deleted while the (slow) AI
    // call above was still in flight, this insert would otherwise throw
    // an uncaught foreign-key exception and crash the whole request.
    try {
        $aiStmt = $conn->prepare(
            "INSERT INTO AI_Insights (PhotoID, InsightText, Confidence, Date)
             VALUES (?, ?, NULL, CURDATE())"
        );
        $aiStmt->bind_param("is", $photoId, $insightText);
        $aiStmt->execute();
        $aiStmt->close();
    } catch (\Throwable $e) {
        error_log("body_photos_api: could not save AI insight for photo $photoId: " . $e->getMessage());
    }

    $conn->close();

    echo json_encode([
        "success"  => true,
        "message"  => "Photo uploaded.",
        "photo_id" => $photoId,
        "url"      => $publicUrl,
        "date"     => date("Y-m-d"),
        "insight"  => $insightText,
    ]);
    exit();
}

// ------------------------------------------------------------
// DELETE: remove a body photo (and its AI insight row, if any)
// ------------------------------------------------------------
if ($method === "DELETE") {
    $photoId = intval($_GET["photo_id"] ?? 0);
    if ($photoId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing photo_id."]);
        exit();
    }

    $delInsight = $conn->prepare("DELETE FROM AI_Insights WHERE PhotoID = ?");
    $delInsight->bind_param("i", $photoId);
    $delInsight->execute();
    $delInsight->close();

    $delPhoto = $conn->prepare("DELETE FROM BodyPhotos WHERE PhotoID = ?");
    $delPhoto->bind_param("i", $photoId);
    $delPhoto->execute();
    $delPhoto->close();
    $conn->close();

    echo json_encode(["success" => true, "message" => "Photo deleted."]);
    exit();
}

echo json_encode(["success" => false, "message" => "Unsupported request method."]);
?>
