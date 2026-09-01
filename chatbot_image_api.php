<?php
// ============================================================
// chatbot_image_api.php
// Member chatbot - image messages, powered by Gemini.
// Expects multipart/form-data POST with one or more file fields named
// "images[]" (up to 10), and an optional "message" text field
// (caption/question about the photo(s)).
// Returns JSON: { "success": true, "reply": "..." }
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'ai_config.php';

const MAX_CHAT_IMAGES = 10;
$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

// Accept the new "images[]" array field, falling back to the legacy
// single "image" field for backwards compatibility.
$uploadNames = [];
$uploadTypes = [];
$uploadTmpNames = [];
$uploadErrors = [];

if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
    $uploadNames = $_FILES['images']['name'];
    $uploadTypes = $_FILES['images']['type'];
    $uploadTmpNames = $_FILES['images']['tmp_name'];
    $uploadErrors = $_FILES['images']['error'];
} elseif (isset($_FILES['image'])) {
    $uploadNames = [$_FILES['image']['name']];
    $uploadTypes = [$_FILES['image']['type']];
    $uploadTmpNames = [$_FILES['image']['tmp_name']];
    $uploadErrors = [$_FILES['image']['error']];
}

if (empty($uploadNames)) {
    echo json_encode(['success' => false, 'error' => 'No image uploaded.']);
    exit();
}

$userCaption = isset($_POST['message']) ? trim($_POST['message']) : '';

$imageParts = [];
for ($i = 0; $i < count($uploadNames) && count($imageParts) < MAX_CHAT_IMAGES; $i++) {
    if ($uploadErrors[$i] !== UPLOAD_ERR_OK) continue;
    if (!in_array($uploadTypes[$i], $allowedTypes)) continue;

    $imageData = file_get_contents($uploadTmpNames[$i]);
    $imageParts[] = [
        'inline_data' => [
            'mime_type' => $uploadTypes[$i],
            'data' => base64_encode($imageData),
        ],
    ];
}

if (empty($imageParts)) {
    echo json_encode(['success' => false, 'error' => 'No valid images uploaded. Use JPEG, PNG, or WebP.']);
    exit();
}

$promptText = $userCaption !== ''
    ? $userCaption
    : (count($imageParts) > 1
        ? "The member sent these photos to the gym's chatbot. Briefly describe what's in them and respond helpfully as PrimeFit Gym's member support assistant."
        : "The member sent this photo to the gym's chatbot. Briefly describe what's in it and respond helpfully as PrimeFit Gym's member support assistant.");

$requestBody = [
    'contents' => [
        [
            'parts' => array_merge([['text' => $promptText]], $imageParts),
        ],
    ],
];

// Try the auto-updating alias first; if that specific alias name ever
// gets retired, fall back to known-stable model names instead of
// breaking silently.
$modelsToTry = ['gemini-flash-latest', 'gemini-2.5-flash', 'gemini-3.6-flash'];

$response = null;
$httpCode = null;
$curlError = null;

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

    // Success, or a real failure that isn't just "model not found" -
    // stop trying more models either way.
    if ($httpCode === 200 || ($httpCode !== 404 && !$curlError)) {
        break;
    }
}

if ($curlError) {
    echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError]);
    exit();
}

if ($httpCode !== 200) {
    echo json_encode(['success' => false, 'error' => 'Gemini API error: ' . $response]);
    exit();
}

$data = json_decode($response, true);
$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, I could not analyze that image.';

echo json_encode(['success' => true, 'reply' => $reply]);
?>