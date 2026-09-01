<?php
// ============================================================
// qr_helper.php
// Generates and verifies tamper-proof QR tokens for member IDs.
//
// How it works:
// - The QR code encodes a token: "<memberId>.<timestamp>.<signature>"
// - <signature> = HMAC-SHA256("<memberId>.<timestamp>", QR_SECRET_KEY)
// - Only this server knows QR_SECRET_KEY, so nobody can forge a valid
//   token without it -- editing the memberId in a copied QR code
//   breaks the signature and verification will fail.
// ============================================================

// 🔒 IMPORTANT: Change this to your own long random string, and never
// share it or commit it somewhere public (like a public GitHub repo).
// Anyone who has this key could forge valid member QR codes.
define('QR_SECRET_KEY', 'PrimeFit-2026-CHANGE-THIS-9f3ak2ndKq7ZpLxT1mWvR8sYcE');

/**
 * Generates a signed QR token for a given MemberID.
 */
function generateQrToken($memberId) {
    $payload = $memberId . '.' . time();
    $signature = hash_hmac('sha256', $payload, QR_SECRET_KEY);
    return $payload . '.' . $signature;
}

/**
 * Verifies a QR token. Returns the MemberID (int) if valid,
 * or false if the token is missing, malformed, or tampered with.
 */
function verifyQrToken($token) {
    if (!is_string($token)) return false;

    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    [$memberId, $timestamp, $signature] = $parts;

    if (!ctype_digit($memberId) || !ctype_digit($timestamp)) return false;

    $expectedPayload = $memberId . '.' . $timestamp;
    $expectedSignature = hash_hmac('sha256', $expectedPayload, QR_SECRET_KEY);

    // hash_equals() does a constant-time comparison -- safer than ==
    // for comparing secrets/signatures (avoids timing attacks).
    if (!hash_equals($expectedSignature, $signature)) return false;

    return intval($memberId);
}
?>
