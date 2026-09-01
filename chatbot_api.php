<?php
// ============================================================
// chatbot_api.php
// PrimeFit Member Chatbot - Groq
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
    error_log(
        'CHATBOT DB ERROR: ' .
        $conn->connect_error
    );

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

error_log(
    'CHATBOT RAW INPUT LENGTH: ' .
    strlen($rawInput)
);

error_log(
    'CHATBOT RAW INPUT: ' .
    $rawInput
);

// ------------------------------------------------------------
// VALIDATE JSON INPUT
// ------------------------------------------------------------

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

error_log(
    'CHATBOT JSON DECODE ERROR: ' .
    json_last_error_msg()
);

error_log(
    'CHATBOT DECODED INPUT: ' .
    print_r($input, true)
);

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
// VALIDATE MESSAGE
// ------------------------------------------------------------

if (
    !isset($input['message']) ||
    !is_string($input['message']) ||
    trim($input['message']) === ''
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => 'Message is required.',
        'code' => 'MESSAGE_REQUIRED'
    ]);

    exit();
}

$userMessage = trim($input['message']);

$memberId = intval(
    $input['member_id'] ?? 0
);

$history = (
    isset($input['history']) &&
    is_array($input['history'])
)
    ? $input['history']
    : [];

// ------------------------------------------------------------
// VALIDATE MEMBER ID
// ------------------------------------------------------------

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

$memberCheckStmt = $conn->prepare(
    "SELECT MemberID
     FROM Members
     WHERE MemberID = ?"
);

if (!$memberCheckStmt) {

    error_log(
        'CHATBOT MEMBER CHECK PREPARE ERROR: ' .
        $conn->error
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Unable to verify member account.',
        'code' => 'DB_QUERY_ERROR'
    ]);

    exit();
}

$memberCheckStmt->bind_param(
    "i",
    $memberId
);

$memberCheckStmt->execute();

$memberResult = $memberCheckStmt->get_result();

$memberExists = $memberResult
    ? $memberResult->fetch_assoc()
    : null;

$memberCheckStmt->close();

if (!$memberExists) {

    http_response_code(404);

    echo json_encode([
        'success' => false,
        'error' => 'Member not found. Please log in again.',
        'code' => 'MEMBER_NOT_FOUND'
    ]);

    exit();
}

// ------------------------------------------------------------
// MEMBER CONTEXT
// ------------------------------------------------------------

$memberContext = '';

// ------------------------------------------------------------
// PROFILE
// ------------------------------------------------------------

$profileStmt = $conn->prepare(
    "SELECT FirstName, LastName, Email, Phone
     FROM Members
     WHERE MemberID = ?"
);

if ($profileStmt) {

    $profileStmt->bind_param(
        "i",
        $memberId
    );

    $profileStmt->execute();

    $profileResult = $profileStmt->get_result();

    $profile = $profileResult
        ? $profileResult->fetch_assoc()
        : null;

    $profileStmt->close();

    if ($profile) {

        $memberContext .=
            "Member Name: " .
            ($profile['FirstName'] ?? '') .
            " " .
            ($profile['LastName'] ?? '') .
            "\n";

        $memberContext .=
            "Email: " .
            ($profile['Email'] ?? '') .
            "\n";

        $memberContext .=
            "Phone: " .
            ($profile['Phone'] ?? '') .
            "\n";
    }
}

// ------------------------------------------------------------
// MEMBERSHIP STATUS
// ------------------------------------------------------------

$membershipStmt = $conn->prepare(
    "SELECT
        m.Status,
        p.DurationLabel,
        p.Price,
        m.StartDate,
        m.NextRenewalDate
     FROM Memberships m
     JOIN Plans p
        ON m.PlanID = p.PlanID
     WHERE m.MemberID = ?
     ORDER BY m.StartDate DESC
     LIMIT 1"
);

if ($membershipStmt) {

    $membershipStmt->bind_param(
        "i",
        $memberId
    );

    $membershipStmt->execute();

    $membershipResult =
        $membershipStmt->get_result();

    $membership =
        $membershipResult
            ? $membershipResult->fetch_assoc()
            : null;

    $membershipStmt->close();

    if ($membership) {

        $memberContext .=
            "\nMembership Status: " .
            ($membership['Status'] ?? '') .
            "\n";

        $memberContext .=
            "Plan: " .
            ($membership['DurationLabel'] ?? '') .
            " (" .
            ($membership['Price'] ?? '') .
            ")\n";

        $memberContext .=
            "Start Date: " .
            ($membership['StartDate'] ?? '') .
            "\n";

        if (!empty($membership['NextRenewalDate'])) {

            $memberContext .=
                "Next Renewal: " .
                $membership['NextRenewalDate'] .
                "\n";
        }
    }
}

// ------------------------------------------------------------
// ATTENDANCE STATS
// ------------------------------------------------------------

$attendanceStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_visits,
        MAX(Date) AS last_visit
     FROM AttendanceLogs
     WHERE MemberID = ?"
);

if ($attendanceStmt) {

    $attendanceStmt->bind_param(
        "i",
        $memberId
    );

    $attendanceStmt->execute();

    $attendanceResult =
        $attendanceStmt->get_result();

    $attendance =
        $attendanceResult
            ? $attendanceResult->fetch_assoc()
            : null;

    $attendanceStmt->close();

    if ($attendance) {

        $memberContext .=
            "\nAttendance: " .
            ($attendance['total_visits'] ?? 0) .
            " total visits\n";

        if (!empty($attendance['last_visit'])) {

            $memberContext .=
                "Last Visit: " .
                $attendance['last_visit'] .
                "\n";
        }
    }
}

// ------------------------------------------------------------
// BODY METRICS
// ------------------------------------------------------------

$metricsStmt = $conn->prepare(
    "SELECT
        Weight,
        Height,
        BMI,
        RecordedAt
     FROM BodyMetrics
     WHERE MemberID = ?
     ORDER BY RecordedAt DESC
     LIMIT 1"
);

if ($metricsStmt) {

    $metricsStmt->bind_param(
        "i",
        $memberId
    );

    $metricsStmt->execute();

    $metricsResult =
        $metricsStmt->get_result();

    $metrics =
        $metricsResult
            ? $metricsResult->fetch_assoc()
            : null;

    $metricsStmt->close();

    if ($metrics) {

        $memberContext .=
            "\nLatest Body Metrics (" .
            ($metrics['RecordedAt'] ?? '') .
            "):\n";

        $memberContext .=
            "Weight: " .
            ($metrics['Weight'] ?? '') .
            " kg, Height: " .
            ($metrics['Height'] ?? '') .
            " cm, BMI: " .
            ($metrics['BMI'] ?? '') .
            "\n";
    }
}

// ------------------------------------------------------------
// PERSONAL RECORDS
// ------------------------------------------------------------

$recordsStmt = $conn->prepare(
    "SELECT
        Exercise,
        Weight,
        Reps
     FROM PersonalRecords
     WHERE MemberID = ?
     LIMIT 5"
);

if ($recordsStmt) {

    $recordsStmt->bind_param(
        "i",
        $memberId
    );

    $recordsStmt->execute();

    $recordsResult =
        $recordsStmt->get_result();

    $recordsList = [];

    if ($recordsResult) {

        while (
            $record =
                $recordsResult->fetch_assoc()
        ) {

            $recordsList[] =
                ($record['Exercise'] ?? '') .
                ": " .
                ($record['Weight'] ?? '') .
                "lbs x" .
                ($record['Reps'] ?? '');
        }
    }

    $recordsStmt->close();

    if (!empty($recordsList)) {

        $memberContext .=
            "\nRecent PRs: " .
            implode(", ", $recordsList) .
            "\n";
    }
}

// ------------------------------------------------------------
// SYSTEM PROMPT
// ------------------------------------------------------------

if (empty($memberContext)) {
    $memberContext = "No specific member profile details retrieved.";
}

$systemPrompt =
    "You are PrimeFit Gym's personalized fitness assistant. " .
    "You help members achieve their fitness goals " .
    "by providing guidance on workouts, nutrition, " .
    "membership benefits, and gym features. " .
    "You can access the member's personal data " .
    "to give customized advice.\n\n" .

    "MEMBER INFORMATION:\n" .
    (string)$memberContext .

    "\n\nGYM FEATURES & PROGRAMS:\n" .
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

// ------------------------------------------------------------
// BUILD GROQ MESSAGES
// ------------------------------------------------------------

$messages = array_merge(
    [
        [
            'role' => 'system',
            'content' => $systemPrompt
        ]
    ],
    $history,
    [
        [
            'role' => 'user',
            'content' => $userMessage
        ]
    ]
);

// ------------------------------------------------------------
// GROQ MODELS
// ------------------------------------------------------------

$modelsToTry = [
    'llama-3.3-70b-versatile',
    'llama-3.1-8b-instant'
];

$response = null;
$httpCode = null;
$curlError = '';

foreach ($modelsToTry as $model) {

    $ch = curl_init(
        'https://api.groq.com/openai/v1/chat/completions'
    );

    if ($ch === false) {

        $curlError =
            'Unable to initialize cURL.';

        break;
    }

    $requestData = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => 400
    ];

    $jsonRequest = json_encode(
        $requestData,
        JSON_UNESCAPED_UNICODE
    );

    if ($jsonRequest === false) {

        $curlError =
            'Failed to encode Groq request: ' .
            json_last_error_msg();

        curl_close($ch);

        break;
    }

    curl_setopt(
        $ch,
        CURLOPT_RETURNTRANSFER,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_POST,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_TIMEOUT,
        30
    );

    curl_setopt(
        $ch,
        CURLOPT_CONNECTTIMEOUT,
        10
    );

    curl_setopt(
        $ch,
        CURLOPT_SSL_VERIFYPEER,
        true
    );

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY
        ]
    );

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        $jsonRequest
    );

    $response = curl_exec($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    $curlError =
        curl_error($ch);

    curl_close($ch);

    error_log(
        "GROQ MODEL: {$model}"
    );

    error_log(
        "GROQ HTTP CODE: {$httpCode}"
    );

    error_log(
        "GROQ RESPONSE: " .
        substr((string)$response, 0, 1000)
    );

    if ($httpCode === 200) {
        break;
    }

    if (
        $httpCode !== 400 &&
        $httpCode !== 404
    ) {
        break;
    }
}

// ------------------------------------------------------------
// CURL ERROR
// ------------------------------------------------------------

if ($curlError) {

    $errorMsg =
        'Connection error: ' .
        $curlError;

    error_log(
        'CHATBOT CURL ERROR: ' .
        $errorMsg
    );

    http_response_code(503);

    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'code' => 'CURL_ERROR'
    ]);

    exit();
}

// ------------------------------------------------------------
// EMPTY GROQ RESPONSE
// ------------------------------------------------------------

if (
    $response === false ||
    $response === null ||
    trim((string)$response) === ''
) {

    error_log(
        'CHATBOT ERROR: Groq returned an empty response.'
    );

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'error' => 'Groq API returned an empty response.',
        'code' => 'EMPTY_API_RESPONSE'
    ]);

    exit();
}

// ------------------------------------------------------------
// GROQ HTTP ERROR
// ------------------------------------------------------------

if ($httpCode !== 200) {

    $responseText =
        (string)$response;

    $isHtml =
        strpos(
            strtolower($responseText),
            '<html'
        ) !== false ||
        strpos(
            strtolower($responseText),
            '<body'
        ) !== false;

    if ($isHtml) {

        $cleanError =
            strip_tags($responseText);

        $cleanError =
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    $cleanError
                )
            );

        $errorMsg =
            'API Error (HTTP ' .
            $httpCode .
            '): ' .
            substr(
                $cleanError,
                0,
                300
            );

    } else {

        $errorMsg =
            'Groq API error (HTTP ' .
            $httpCode .
            '): ' .
            substr(
                $responseText,
                0,
                500
            );
    }

    error_log(
        'CHATBOT API ERROR: ' .
        $errorMsg
    );

    http_response_code(
        $httpCode >= 400
            ? $httpCode
            : 502
    );

    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'code' => 'API_ERROR',
        'http_code' => $httpCode
    ]);

    exit();
}

// ------------------------------------------------------------
// DECODE GROQ RESPONSE
// ------------------------------------------------------------

$data = json_decode(
    $response,
    true
);

if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($data)
) {

    error_log(
        'CHATBOT INVALID GROQ JSON: ' .
        json_last_error_msg()
    );

    error_log(
        'CHATBOT RAW GROQ RESPONSE: ' .
        substr($response, 0, 1000)
    );

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'error' => 'Groq returned invalid JSON.',
        'code' => 'INVALID_API_JSON'
    ]);

    exit();
}

// ------------------------------------------------------------
// EXTRACT AI REPLY
// ------------------------------------------------------------

if (
    !isset(
        $data['choices'][0]['message']['content']
    )
) {

    error_log(
        'CHATBOT INVALID GROQ STRUCTURE: ' .
        substr($response, 0, 1000)
    );

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'error' => 'Invalid response from Groq API.',
        'code' => 'INVALID_RESPONSE'
    ]);

    exit();
}

$reply =
    trim(
        (string)$data['choices'][0]['message']['content']
    );

if ($reply === '') {

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'error' => 'Groq returned an empty reply.',
        'code' => 'EMPTY_REPLY'
    ]);

    exit();
}

// ------------------------------------------------------------
// FINAL JSON RESPONSE
// ------------------------------------------------------------

$responseData = [
    'success' => true,
    'reply' => $reply,
    'member_id' => $memberId,
    'timestamp' => date('Y-m-d H:i:s')
];

http_response_code(200);

echo json_encode(
    $responseData,
    JSON_UNESCAPED_UNICODE
);

exit();
?>