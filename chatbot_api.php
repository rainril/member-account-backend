<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'ai_config.php';
require_once 'db_connect.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['message']) || trim($input['message']) === '') {
    echo json_encode(['success' => false, 'error' => 'Message is required.']);
    exit();
}

$userMessage = $input['message'];
$history = isset($input['history']) && is_array($input['history']) ? $input['history'] : [];
$memberId = isset($input['member_id']) ? intval($input['member_id']) : 0;

// Only require member_id for requests that need personalized data
// (e.g. food plan, progress, billing lookups). Adjust this keyword
// check to match how you detect "personalized" intent.
$needsPersonalization = preg_match('/\b(food plan|my (progress|billing|attendance|plan))\b/i', $userMessage);

if ($needsPersonalization && $memberId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Member ID is required for personalized assistance.']);
    exit();
}

$systemPrompt = "You are PrimeFit Gym's friendly member support assistant. ...";

// If personalized, you can fetch member-specific context here and
// append it to the system prompt before calling Groq, e.g.:
// $memberContext = fetchMemberContext($conn, $memberId);
// $systemPrompt .= "\n\nMember context:\n" . $memberContext;

$messages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    $history,
    [['role' => 'user', 'content' => $userMessage]]
);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . GROQ_API_KEY,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'llama-3.3-70b-versatile',
    'messages' => $messages,
    'temperature' => 0.4,
    'max_tokens' => 400,
]));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError]);
    exit();
}

if ($httpCode !== 200) {
    echo json_encode(['success' => false, 'error' => 'Groq API error: ' . $response]);
    exit();
}

$data = json_decode($response, true);
$reply = $data['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';

echo json_encode(['success' => true, 'reply' => $reply]);
?>