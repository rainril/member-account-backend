<?php
// ============================================================
// chatbot_api.php
// PrimeFit Member Chatbot - Google Gemini API
// ============================================================

// ------------------------------------------------------------
// ERROR HANDLING
// ------------------------------------------------------------

ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 3600');

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null) {
        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR
        ];

        if (in_array($error['type'], $fatalTypes, true)) {
            error_log(
                'CHATBOT PHP FATAL ERROR: ' .
                $error['message'] .
                ' in ' .
                $error['file'] .
                ':' .
                $error['line']
            );

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }

            echo json_encode([
                'success' => false,
                'error' => 'Internal server error.',
                'code' => 'PHP_FATAL_ERROR'
            ]);
        }
    }
});

// ------------------------------------------------------------
// CORS PREFLIGHT
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode([
        'success' => true
    ]);
    exit();
}

// ------------------------------------------------------------
// LOAD CONFIGURATION
// ------------------------------------------------------------

require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/db_connect.php';

// Ensure Gemini Key is defined
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
}

if (empty(GEMINI_API_KEY)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Gemini API Key is not configured in ai_config.php or environment.',
        'code' => 'MISSING_API_KEY'
    ]);
    exit();
}

// ------------------------------------------------------------
// CHECK DATABASE CONNECTION
// ------------------------------------------------------------

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('CHATBOT ERROR: Database connection is not available.');

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection is not available.',
        'code' => 'DB_CONNECTION_ERROR'
    ]);
    exit();
}

if ($conn->connect_errno) {
    error_log('CHATBOT DB ERROR: ' . $conn->connect_error);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed.',
        'code' => 'DB_CONNECTION_ERROR'
    ]);
    exit();
}

// ------------------------------------------------------------
// READ REQUEST BODY
// ------------------------------------------------------------

$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Request body is empty.',
        'code' => 'EMPTY_REQUEST'
    ]);
    exit();
}

$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON request.',
        'code' => 'INVALID_JSON',
        'json_error' => json_last_error_msg()
    ]);
    exit();
}

// ------------------------------------------------------------
// VALIDATE MESSAGE & MEMBER ID
// ------------------------------------------------------------

if (!isset($input['message']) || !is_string($input['message']) || trim($input['message']) === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Message is required.',
        'code' => 'MESSAGE_REQUIRED'
    ]);
    exit();
}

$userMessage = trim($input['message']);
$memberId = intval($input['member_id'] ?? 0);
$rawHistory = (isset($input['history']) && is_array($input['history'])) ? $input['history'] : [];

if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Member ID is required for personalized assistance.',
        'code' => 'MEMBER_ID_REQUIRED'
    ]);
    exit();
}

// ------------------------------------------------------------
// CHECK MEMBER EXISTS
// ------------------------------------------------------------

$memberCheckStmt = $conn->prepare("SELECT MemberID FROM Members WHERE MemberID = ?");
if (!$memberCheckStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to verify member account.', 'code' => 'DB_QUERY_ERROR']);
    exit();
}

$memberCheckStmt->bind_param("i", $memberId);
$memberCheckStmt->execute();
$memberExists = $memberCheckStmt->get_result()->fetch_assoc();
$memberCheckStmt->close();

if (!$memberExists) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Member not found. Please log in again.', 'code' => 'MEMBER_NOT_FOUND']);
    exit();
}

// ------------------------------------------------------------
// BUILD MEMBER CONTEXT FROM DB
// ------------------------------------------------------------

$memberContext = '';

// Profile
$profileStmt = $conn->prepare("SELECT FirstName, LastName, Email, Phone FROM Members WHERE MemberID = ?");
if ($profileStmt) {
    $profileStmt->bind_param("i", $memberId);
    $profileStmt->execute();
    $profile = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();
    if ($profile) {
        $memberContext .= "Member Name: " . ($profile['FirstName'] ?? '') . " " . ($profile['LastName'] ?? '') . "\n";
        $memberContext .= "Email: " . ($profile['Email'] ?? '') . "\n";
        $memberContext .= "Phone: " . ($profile['Phone'] ?? '') . "\n";
    }
}

// Membership Status
$membershipStmt = $conn->prepare("SELECT m.Status, p.DurationLabel, p.Price, m.StartDate, m.NextRenewalDate FROM Memberships m JOIN Plans p ON m.PlanID = p.PlanID WHERE m.MemberID = ? ORDER BY m.StartDate DESC LIMIT 1");
if ($membershipStmt) {
    $membershipStmt->bind_param("i", $memberId);
    $membershipStmt->execute();
    $membership = $membershipStmt->get_result()->fetch_assoc();
    $membershipStmt->close();
    if ($membership) {
        $memberContext .= "\nMembership Status: " . ($membership['Status'] ?? '') . "\n";
        $memberContext .= "Plan: " . ($membership['DurationLabel'] ?? '') . " (" . ($membership['Price'] ?? '') . ")\n";
        $memberContext .= "Start Date: " . ($membership['StartDate'] ?? '') . "\n";
        if (!empty($membership['NextRenewalDate'])) {
            $memberContext .= "Next Renewal: " . $membership['NextRenewalDate'] . "\n";
        }
    }
}

// Attendance Stats
$attendanceStmt = $conn->prepare("SELECT COUNT(*) AS total_visits, MAX(Date) AS last_visit FROM AttendanceLogs WHERE MemberID = ?");
if ($attendanceStmt) {
    $attendanceStmt->bind_param("i", $memberId);
    $attendanceStmt->execute();
    $attendance = $attendanceStmt->get_result()->fetch_assoc();
    $attendanceStmt->close();
    if ($attendance) {
        $memberContext .= "\nAttendance: " . ($attendance['total_visits'] ?? 0) . " total visits\n";
        if (!empty($attendance['last_visit'])) {
            $memberContext .= "Last Visit: " . $attendance['last_visit'] . "\n";
        }
    }
}

// Body Metrics
$metricsStmt = $conn->prepare("SELECT Weight, Height, BMI, RecordedAt FROM BodyMetrics WHERE MemberID = ? ORDER BY RecordedAt DESC LIMIT 1");
if ($metricsStmt) {
    $metricsStmt->bind_param("i", $memberId);
    $metricsStmt->execute();
    $metrics = $metricsStmt->get_result()->fetch_assoc();
    $metricsStmt->close();
    if ($metrics) {
        $memberContext .= "\nLatest Body Metrics (" . ($metrics['RecordedAt'] ?? '') . "):\n";
        $memberContext .= "Weight: " . ($metrics['Weight'] ?? '') . " kg, Height: " . ($metrics['Height'] ?? '') . " cm, BMI: " . ($metrics['BMI'] ?? '') . "\n";
    }
}

// Personal Records
$recordsStmt = $conn->prepare("SELECT Exercise, Weight, Reps FROM PersonalRecords WHERE MemberID = ? LIMIT 5");
if ($recordsStmt) {
    $recordsStmt->bind_param("i", $memberId);
    $recordsStmt->execute();
    $recordsResult = $recordsStmt->get_result();
    $recordsList = [];
    if ($recordsResult) {
        while ($record = $recordsResult->fetch_assoc()) {
            $recordsList[] = ($record['Exercise'] ?? '') . ": " . ($record['Weight'] ?? '') . "lbs x" . ($record['Reps'] ?? '');
        }
    }
    $recordsStmt->close();
    if (!empty($recordsList)) {
        $memberContext .= "\nRecent PRs: " . implode(", ", $recordsList) . "\n";
    }
}

if (empty(trim($memberContext))) {
    $memberContext = "No specific member profile details retrieved.";
}

// ------------------------------------------------------------
// SYSTEM PROMPT & GEMINI PAYLOAD SETUP
// ------------------------------------------------------------

$systemInstructionText =
    "You are PrimeFit Gym's personalized fitness assistant. " .
    "You help members achieve their fitness goals by providing guidance on workouts, nutrition, membership benefits, and gym features. " .
    "You can access the member's personal data to give customized advice.\n\n" .
    "MEMBER INFORMATION:\n" . (string)$memberContext . "\n\n" .
    "GYM FEATURES & PROGRAMS:\n" .
    "- Classes: yoga, pilates, cardio, strength training, HIIT, CrossFit\n" .
    "- Personal training available\n" .
    "- Nutrition tracking and food logging\n" .
    "- Progress tracking with body metrics\n" .
    "- Monthly goal setting\n" .
    "- QR check-ins for attendance\n\n" .
    "INSTRUCTIONS:\n" .
    "1. Be warm, encouraging, and supportive\n" .
    "2. Use member's personal data to give customized fitness advice\n" .
    "3. Suggest programs and classes based on their goals and progress\n" .
    "4. Help them understand their membership benefits\n" .
    "5. Track their progress and celebrate improvements\n" .
    "6. Only if asked, direct them to speak with staff about billing or account issues";

// Format chat history for Gemini (Map roles: 'assistant' -> 'model')
$contents = [];
foreach ($rawHistory as $h) {
    if (is_array($h) && isset($h['role'], $h['content']) && trim($h['content']) !== '') {
        $role = ($h['role'] === 'assistant' || $h['role'] === 'model') ? 'model' : 'user';
        $contents[] = [
            'role' => $role,
            'parts' => [['text' => trim($h['content'])]]
        ];
    }
}

// Append current user message
$contents[] = [
    'role' => 'user',
    'parts' => [['text' => $userMessage]]
];

$geminiPayload = [
    'systemInstruction' => [
        'parts' => [
            ['text' => $systemInstructionText]
        ]
    ],
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.4,
        'maxOutputTokens' => 1000
    ]
];

// ------------------------------------------------------------
// CALL GEMINI REST API (with automatic model fallback)
// ------------------------------------------------------------

$geminiModelFallback = array_map('trim', explode(',', getenv('GEMINI_MODELS') ?: 'gemini-2.5-flash,gemini-1.5-flash,gemini-3.5-flash'));

$httpCode = 0;
$response = null;
$curlError = '';
$usedModel = null;

foreach ($geminiModelFallback as $model) {
    if ($model === '') {
        continue;
    }

    $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . GEMINI_API_KEY;

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($geminiPayload, JSON_UNESCAPED_UNICODE));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        break; // network-level failure
    }

    if ($httpCode === 200) {
        $usedModel = $model;
        break; // success
    }

    if ($httpCode === 404 || $httpCode === 503 || $httpCode === 429) {
        error_log("Gemini model '$model' returned HTTP $httpCode -- trying next fallback model.");
        continue; 
    }

    break;
}

if ($curlError) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError, 'code' => 'CURL_ERROR']);
    exit();
}

if ($httpCode !== 200) {
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    echo json_encode([
        'success' => false,
        'error' => 'Gemini API error (HTTP ' . $httpCode . '): ' . substr((string)$response, 0, 500),
        'code' => 'API_ERROR',
        'http_code' => $httpCode
    ]);
    exit();
}

// ------------------------------------------------------------
// DECODE & EXTRACT REPLY
// ------------------------------------------------------------

$data = json_decode($response, true);
$candidate = $data['candidates'][0] ?? [];
$reply = $candidate['content']['parts'][0]['text'] ?? '';
$reply = trim((string)$reply);

$finishReason = $candidate['finishReason'] ?? '';
if ($finishReason === 'MAX_TOKENS') {
    $reply .= "\n\n*(Note: I reached my maximum response length limit.)*";
}

if ($reply === '') {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Gemini returned an empty reply.', 'code' => 'EMPTY_REPLY']);
    exit();
}

// ------------------------------------------------------------
// FINAL RESPONSE
// ------------------------------------------------------------

http_response_code(200);
echo json_encode([
    'success' => true,
    'reply' => $reply,
    'member_id' => $memberId,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);

exit();
?>