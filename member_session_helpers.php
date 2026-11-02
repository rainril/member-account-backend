<?php

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
