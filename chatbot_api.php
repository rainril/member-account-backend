<?php
// ============================================================
// chatbot_api.php
// ============================================================

// Never let PHP warnings/errors leak into the JSON response.
ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 3600');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'ai_config.php';
require_once 'db_connect.php';

$rawInput = file_get_contents('php://input');
error_log('RAW INPUT LENGTH: ' . strlen($rawInput));
error_log('RAW INPUT: ' . $rawInput);

$input = json_decode($rawInput, true);
error_log('JSON DECODE ERROR: ' . json_last_error_msg());
error_log('DECODED INPUT: ' . print_r($input, true));

if (!isset($input['message']) || trim($input['message']) === '') {
    echo json_encode(['success' => false, 'error' => 'Message is required.']);
    exit();
}

$userMessage = $input['message'];
$memberId = intval($input['member_id'] ?? 0);
$history = isset($input['history']) && is_array($input['history']) ? $input['history'] : [];

if ($memberId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Member ID is required for personalized assistance.']);
    exit();
}

$memberCheckStmt = $conn->prepare("SELECT MemberID FROM Members WHERE MemberID = ?");
if ($memberCheckStmt) {
    $memberCheckStmt->bind_param("i", $memberId);
    $memberCheckStmt->execute();
    $memberExists = $memberCheckStmt->get_result()->fetch_assoc();
    $memberCheckStmt->close();
} else {
    $memberExists = null;
}

if (!$memberExists) {
    echo json_encode(['success' => false, 'error' => 'Member not found. Please log in again.']);
    exit();
}

$memberContext = '';

// Profile
$profileStmt = $conn->prepare("SELECT FirstName, LastName, Email, Phone FROM Members WHERE MemberID = ?");
if ($profileStmt) {
    $profileStmt->bind_param("i", $memberId);
    $profileStmt->execute();
    $profile = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();

    if ($profile) {
        $memberContext .= "Member Name: {$profile['FirstName']} {$profile['LastName']}\n";
        $memberContext .= "Email: {$profile['Email']}\n";
        $memberContext .= "Phone: {$profile['Phone']}\n";
    }
}

// Membership status
$membershipStmt = $conn->prepare(
    "SELECT m.Status, p.DurationLabel, p.Price, m.StartDate, m.NextRenewalDate 
     FROM Memberships m 
     JOIN Plans p ON m.PlanID = p.PlanID 
     WHERE m.MemberID = ? 
     ORDER BY m.StartDate DESC LIMIT 1"
);
if ($membershipStmt) {
    $membershipStmt->bind_param("i", $memberId);
    $membershipStmt->execute();
    $membership = $membershipStmt->get_result()->fetch_assoc();
    $membershipStmt->close();
    
    if ($membership) {
        $memberContext .= "\nMembership Status: {$membership['Status']}\n";
        $memberContext .= "Plan: {$membership['DurationLabel']} ({$membership['Price']})\n"; // fixed
        $memberContext .= "Start Date: {$membership['StartDate']}\n";
        if ($membership['NextRenewalDate']) {
            $memberContext .= "Next Renewal: {$membership['NextRenewalDate']}\n";
        }
    }
}

// Attendance stats
$attendanceStmt = $conn->prepare(
    "SELECT COUNT(*) as total_visits, MAX(Date) as last_visit FROM AttendanceLogs WHERE MemberID = ?"
);
if ($attendanceStmt) {
    $attendanceStmt->bind_param("i", $memberId);
    $attendanceStmt->execute();
    $attendance = $attendanceStmt->get_result()->fetch_assoc();
    $attendanceStmt->close();

    if ($attendance) {
        $memberContext .= "\nAttendance: {$attendance['total_visits']} total visits\n";
        if ($attendance['last_visit']) {
            $memberContext .= "Last Visit: {$attendance['last_visit']}\n";
        }
    }
}

// Body metrics
$metricsStmt = $conn->prepare(
    "SELECT Weight, BodyFatPercentage, MusclePercentage, BMI, MeasurementDate 
     FROM BodyMetrics WHERE MemberID = ? ORDER BY MeasurementDate DESC LIMIT 1"
);
if ($metricsStmt) {
    $metricsStmt->bind_param("i", $memberId);
    $metricsStmt->execute();
    $metrics = $metricsStmt->get_result()->fetch_assoc();
    $metricsStmt->close();

    if ($metrics) {
        $memberContext .= "\nLatest Body Metrics ({$metrics['MeasurementDate']}):\n";
        $memberContext .= "Weight: {$metrics['Weight']} lbs, BMI: {$metrics['BMI']}\n";
        if ($metrics['BodyFatPercentage']) {
            $memberContext .= "Body Fat: {$metrics['BodyFatPercentage']}%, Muscle: {$metrics['MusclePercentage']}%\n";
        }
    }
}

// Personal records
$recordsStmt = $conn->prepare(
    "SELECT Exercise, Weight, Reps, DateAchieved FROM PersonalRecords 
     WHERE MemberID = ? ORDER BY DateAchieved DESC LIMIT 5"
);
if ($recordsStmt) {
    $recordsStmt->bind_param("i", $memberId);
    $recordsStmt->execute();
    $records = $recordsStmt->get_result();

    $recordsList = [];
    while ($record = $records->fetch_assoc()) {
        $recordsList[] = "{$record['Exercise']}: {$record['Weight']}lbs x{$record['Reps']}";
    }
    $recordsStmt->close();

    if (!empty($recordsList)) {
        $memberContext .= "\nRecent PRs: " . implode(", ", $recordsList) . "\n";
    }
}

// Enrolled programs
$programsStmt = $conn->prepare(
    "SELECT DISTINCT p.ProgramName FROM ProgramEnrollments pe
     JOIN Programs p ON pe.ProgramID = p.ProgramID
     WHERE pe.MemberID = ? AND pe.Status = 'active'"
);
if ($programsStmt) {
    $programsStmt->bind_param("i", $memberId);
    $programsStmt->execute();
    $programsList = [];
    $pgResult = $programsStmt->get_result();
    while ($pg = $pgResult->fetch_assoc()) {
        $programsList[] = $pg['ProgramName'];
    }
    $programsStmt->close();

    if (!empty($programsList)) {
        $memberContext .= "\nEnrolled Programs: " . implode(", ", $programsList) . "\n";
    }
}

$systemPrompt = "You are PrimeFit Gym's personalized fitness assistant. You help members achieve their fitness goals "
    . "by providing guidance on workouts, nutrition, membership benefits, and gym features. "
    . "You can access the member's personal data to give customized advice.\n\n"
    . "MEMBER INFORMATION:\n"
    . $memberContext
    . "\nGYM FEATURES & PROGRAMS:\n"
    . "- Classes: yoga, pilates, cardio, strength training, HIIT, CrossFit\n"
    . "- Personal training available\n"
    . "- Nutrition tracking and food logging\n"
    . "- Progress tracking with body metrics\n"
    . "- Monthly goal setting\n"
    . "- QR check-ins for attendance\n\n"
    . "INSTRUCTIONS:\n"
    . "1. Be warm, encouraging, and supportive\n"
    . "2. Use member's personal data to give customized fitness advice\n"
    . "3. Suggest programs and classes based on their goals and progress\n"
    . "4. Help them understand their membership benefits\n"
    . "5. Track their progress and celebrate improvements\n"
    . "6. Only if asked, direct them to speak with staff about billing or account issues";

$messages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    $history,
    [['role' => 'user', 'content' => $userMessage]]
);

$modelsToTry = [
    'openai/gpt-oss-120b',
    'openai/gpt-oss-20b',
];

$response = null;
$httpCode = null;
$curlError = '';

foreach ($modelsToTry as $model) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . GROQ_API_KEY,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => 400,
    ]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 || ($httpCode !== 400 && $httpCode !== 404)) {
        break;
    }
}

if ($curlError) {
    $errorMsg = 'Connection error: ' . $curlError;
    error_log('Chatbot CURL Error: ' . $errorMsg);
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => $errorMsg, 'code' => 'CURL_ERROR']);
    exit();
}

if ($httpCode !== 200) {
    $isHtml = strpos($response, '<html') !== false || strpos($response, '<body') !== false || strpos($response, '<') === 0;

    if ($isHtml) {
        $cleanError = strip_tags($response);
        $cleanError = trim(preg_replace('/\s+/', ' ', $cleanError));
        $errorMsg = 'API Error (HTTP ' . $httpCode . '): ' . substr($cleanError, 0, 300);
    } else {
        $errorMsg = 'Groq API error (HTTP ' . $httpCode . '): ' . substr($response, 0, 300);
    }

    error_log('Chatbot API Error: ' . $errorMsg);
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $errorMsg, 'code' => 'API_ERROR', 'http_code' => $httpCode]);
    exit();
}

$data = json_decode($response, true);
if (!$data || !isset($data['choices'][0]['message']['content'])) {
    $errorMsg = 'Invalid response from Groq API';
    error_log('Chatbot Invalid Response: ' . substr($response, 0, 300));
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => $errorMsg, 'code' => 'INVALID_RESPONSE']);
    exit();
}

$reply = $data['choices'][0]['message']['content'];

$responseData = [
    'success' => true,
    'reply' => $reply,
    'member_id' => $memberId,
    'timestamp' => date('Y-m-d H:i:s'),
];

http_response_code(200);
echo json_encode($responseData);
?>