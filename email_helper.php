<?php
// ============================================================
// email_helper.php
// Sends the member's QR code to their email using PHPMailer.
//
// SETUP REQUIRED (one-time):
// 1. Place the PHPMailer/ folder (src/PHPMailer.php, src/SMTP.php,
//    src/Exception.php) in the same directory as this file.
// 2. Fill in GMAIL_ADDRESS and GMAIL_APP_PASSWORD below.
//    - Use a Gmail "App Password", NOT your normal Gmail password.
//    - Generate one at: https://myaccount.google.com/apppasswords
//      (requires 2-Step Verification to be turned on for your Google account)
// ============================================================

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 🔒 List all your sender accounts here. Add as many as you need --
// e.g. one per branch, or several to spread out sending volume so you
// don't hit Gmail's ~500 emails/day free-account limit as fast.
//
// Each entry needs: address, app password (no spaces), and display name.
$GMAIL_SENDERS = [
    'default' => [
        'address'  => 'irishangela526@gmail.com',
        'password' => 'rusltnbdodnflsfs',
        'name'     => 'PrimeFit Fitness Gym',
    ],
    // Add more accounts like this, then reference them by key
    // (e.g. 'branch2') when calling sendQrCodeEmail(..., $senderKey):
    //
    // 'branch2' => [
    //     'address'  => 'primefit.branch2@gmail.com',
    //     'password' => 'anotherapppassword',
    //     'name'     => 'PrimeFit Branch 2',
    // ],
];

/**
 * Picks which sender account to use. If $senderKey is given and exists,
 * uses that one. Otherwise picks randomly among all configured senders
 * (useful for spreading volume across multiple accounts).
 */
function pickGmailSender($senderKey = null) {
    global $GMAIL_SENDERS;
    if ($senderKey !== null && isset($GMAIL_SENDERS[$senderKey])) {
        return $GMAIL_SENDERS[$senderKey];
    }
    $keys = array_keys($GMAIL_SENDERS);
    $randomKey = $keys[array_rand($keys)];
    return $GMAIL_SENDERS[$randomKey];
}

/**
 * Sends the member's QR check-in code to their email.
 * Returns true on success, or a string error message on failure.
 *
 * @param string      $toEmail        Recipient's email (whatever the member typed in the form)
 * @param string      $memberName     Member's full name
 * @param string      $memberIdLabel  e.g. "PF-00006"
 * @param string      $planName       e.g. "7 Months"
 * @param string      $qrToken        The signed QR token to encode
 * @param string|null $senderKey      Optional: which entry in $GMAIL_SENDERS to send from.
 *                                    Leave null to pick automatically (random, if multiple).
 */
function sendQrCodeEmail($toEmail, $memberName, $memberIdLabel, $planName, $qrToken, $senderKey = null) {
    $sender = pickGmailSender($senderKey);

    // Generate a QR code PNG image using a free public QR API.
    // (Runs on your PHP server, which has normal internet access --
    // this is separate from any sandboxed environment restrictions.)
    $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qrToken);
    $qrImageData = @file_get_contents($qrImageUrl);

    if ($qrImageData === false) {
        return "Could not generate QR code image.";
    }

    $tempQrPath = sys_get_temp_dir() . '/qr_' . $memberIdLabel . '.png';
    file_put_contents($tempQrPath, $qrImageData);

    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $sender['address'];
        $mail->Password   = $sender['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom($sender['address'], $sender['name']);
        $mail->addAddress($toEmail, $memberName);

        // Attach the QR code image
        $mail->addAttachment($tempQrPath, 'PrimeFit_QR_Code.png');

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your PrimeFit Member QR Code';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif;'>
                <h2 style='color:#22B8D8;'>Welcome to PrimeFit, {$memberName}!</h2>
                <p>Your account has been created and your <b>{$planName}</b> membership is now active.</p>
                <p>Attached is your personal QR code (<b>{$memberIdLabel}</b>) — present this at the front desk
                   for every gym session. This code is unique to you; please don't share it with others.</p>
                <p style='color:#888; font-size:12px;'>&copy; 2026 PrimeFit Fitness Gym</p>
            </div>
        ";

        $mail->send();
        @unlink($tempQrPath);
        return true;
    } catch (Exception $e) {
        @unlink($tempQrPath);
        return "Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
}
?>
