<?php
// Streams a member's body progress photo only to its authenticated owner.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }

require __DIR__ . '/db_connect.php';
require_once __DIR__ . '/member_session_helpers.php';
$memberId = member_session_auth_member_id($conn);
$photoId = filter_input(INPUT_GET, 'photo_id', FILTER_VALIDATE_INT);
if (!$photoId || $photoId < 1) { http_response_code(404); exit; }

$stmt = $conn->prepare('SELECT PhotoURL FROM BodyPhotos WHERE PhotoID = ? AND MemberID = ? LIMIT 1');
$stmt->bind_param('ii', $photoId, $memberId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$row) { http_response_code(404); exit; }

$filename = basename(parse_url($row['PhotoURL'], PHP_URL_PATH) ?: $row['PhotoURL']);
if (!preg_match('/^photo_[0-9]+_[0-9]+(?:_[a-f0-9]{12})?\.(jpg|jpeg|png|webp)$/i', $filename)) { http_response_code(404); exit; }
$base = realpath(__DIR__ . '/uploads/body_photos');
$file = $base ? realpath($base . DIRECTORY_SEPARATOR . $filename) : false;
if (!$base || !$file || dirname($file) !== $base || !is_file($file)) { http_response_code(404); exit; }

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($file);
