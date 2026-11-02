<?php

/** Authenticate a member bearer token and return its database MemberID. */
function member_session_auth_member_id(mysqli $conn): int
{
    if (isset($GLOBALS['member_session_authenticated_id'])) {
        return (int) $GLOBALS['member_session_authenticated_id'];
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $authorization = $value;
                break;
            }
        }
    }

    if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', trim($authorization), $matches)) {
        member_session_auth_fail(401, 'Please sign in again.');
    }

    $hash = hash('sha256', $matches[1]);
    $stmt = $conn->prepare('SELECT MemberID FROM MemberSessions WHERE TokenHash = ? AND ExpiresAt > UTC_TIMESTAMP() LIMIT 1');
    if (!$stmt) {
        member_session_auth_fail(503, 'Unable to verify your session. Please try again.');
    }
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (int) $row['MemberID'] <= 0) {
        member_session_auth_fail(401, 'Your session has expired. Please sign in again.');
    }

    $GLOBALS['member_session_authenticated_id'] = (int) $row['MemberID'];
    return (int) $row['MemberID'];
}

/** Use the session owner as authority; reject a client-supplied other ID. */
function member_session_resolve_member_id(mysqli $conn, int $requestedId = 0): int
{
    $authenticatedId = member_session_auth_member_id($conn);
    if ($requestedId > 0 && $requestedId !== $authenticatedId) {
        member_session_auth_fail(403, 'You cannot access another member account.');
    }
    return $authenticatedId;
}

function member_session_auth_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function member_session_find_by_id(mysqli $conn, int $memberId): ?array
{
    $stmt = $conn->prepare("SELECT m.MemberID, m.FirstName, m.LastName, m.Email, m.Phone,
        m.ProfilePictureURL, m.MemberSince, m.QRCodeData,
        p.DurationLabel AS PlanLabel, p.Price AS PlanPrice,
        ms.MembershipID, ms.StartDate, ms.NextRenewalDate, ms.Status AS MembershipStatus,
        ms.SessionCredits, ms.SessionsUsed
        FROM Members m
        LEFT JOIN Memberships ms ON ms.MemberID = m.MemberID
        LEFT JOIN Plans p ON p.PlanID = ms.PlanID
        WHERE m.MemberID = ?
        ORDER BY (ms.Status = 'Active') DESC, ms.MembershipID DESC LIMIT 1");
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $member ?: null;
}

function member_session_payment_approved(mysqli $conn, array $member): bool
{
    if ($member['MembershipID'] === null) return true;
    $membershipId = (int) $member['MembershipID'];
    $stmt = $conn->prepare("SELECT SUM(Status IN ('Pending', 'Failed')) AS unapproved_count,
        SUM(Status = 'Paid') AS paid_count FROM Payments WHERE MembershipID = ?");
    $stmt->bind_param('i', $membershipId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !((int) ($payment['unapproved_count'] ?? 0) > 0 && (int) ($payment['paid_count'] ?? 0) === 0);
}

function member_session_payload(array $member): array
{
    return [
        'member_id' => (int) $member['MemberID'], 'MemberID' => (int) $member['MemberID'],
        'first_name' => $member['FirstName'], 'last_name' => $member['LastName'],
        'email' => $member['Email'], 'phone' => $member['Phone'],
        'profile_picture' => $member['ProfilePictureURL'], 'member_since' => $member['MemberSince'],
        'membership_plan' => $member['PlanLabel'], 'membership_status' => $member['MembershipStatus'],
        'next_renewal_date' => $member['NextRenewalDate'], 'plan_price' => $member['PlanPrice'],
        'session_credits' => $member['SessionCredits'], 'sessions_used' => $member['SessionsUsed'],
        'qr_code_data' => $member['QRCodeData'],
    ];
}
