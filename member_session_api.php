<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) { $authorization = $value; break; }
    }
}
if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', trim($authorization), $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit;
}
$hash = hash('sha256', $matches[1]);
$body = json_decode(file_get_contents('php://input'), true);
$action = is_array($body) ? ($body['action'] ?? '') : '';
if (!in_array($action, ['restore', 'logout'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session action.']);
    exit;
}

try {
    require __DIR__ . '/db_connect.php';
    require_once __DIR__ . '/member_session_helpers.php';
    if ($action === 'logout') {
        $stmt = $conn->prepare('DELETE FROM MemberSessions WHERE TokenHash = ?');
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        $conn->close();
        exit;
    }

    $stmt = $conn->prepare('SELECT MemberID FROM MemberSessions WHERE TokenHash = ? AND ExpiresAt > UTC_TIMESTAMP() LIMIT 1');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $member = $session ? member_session_find_by_id($conn, (int) $session['MemberID']) : null;
    if (!$member || !member_session_payment_approved($conn, $member)) {
        if ($session) {
            $delete = $conn->prepare('DELETE FROM MemberSessions WHERE TokenHash = ?');
            $delete->bind_param('s', $hash);
            $delete->execute();
            $delete->close();
        }
        $conn->close();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Your session has expired or your payment is awaiting review.']);
        exit;
    }
    $conn->close();
    echo json_encode(['success' => true, 'member' => member_session_payload($member)]);
} catch (Throwable $e) {
    error_log('Member session request failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Unable to verify your session. Please try again.']);
}
