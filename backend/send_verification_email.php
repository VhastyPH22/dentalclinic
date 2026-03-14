<?php
/**
 * Email Verification Helper
 * Uses PHPMailer with Gmail SMTP
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Import PHPMailer classes
 */
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once 'env-loader.php';
require_once 'config.php';

function generateVerificationToken() {
    return bin2hex(random_bytes(32));
}

function sendVerificationEmail($email, $username, $first_name, $token) {        
    $mail = new PHPMailer(true);

    try {
        // --- SERVER SETTINGS ---
        // Get SMTP credentials from environment variables for hosting compatibility
        $smtpHost = getEnvVar('SMTP_HOST');
        if (empty($smtpHost)) $smtpHost = 'smtp.gmail.com';
        
        $smtpUsername = getEnvVar('SMTP_USERNAME');
        if (empty($smtpUsername)) $smtpUsername = 'timoteovhasty@gmail.com';
        
        $smtpPassword = getEnvVar('SMTP_PASSWORD');
        if (empty($smtpPassword)) $smtpPassword = 'rana qmsj dbwc ajvt';
        
        $smtpPort = (int)(getEnvVar('SMTP_PORT') ?: 587);
        $fromEmail = getEnvVar('SMTP_FROM_EMAIL');
        if (empty($fromEmail)) $fromEmail = 'timoteovhasty@gmail.com';
        
        $fromName = getEnvVar('SMTP_FROM_NAME');
        if (empty($fromName)) $fromName = 'San Nicolas Dental Clinic';
        
        // Validate that credentials are configured
        if (empty($smtpUsername) || empty($smtpPassword)) {
            error_log("CRITICAL: SMTP credentials not available even with fallback!");
            return false;
        }
        
        $mail->isSMTP();
        $mail->SMTPDebug = 0; // Disable debug output
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUsername;
        $mail->Password   = $smtpPassword;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;
        $mail->Timeout    = 10;
        $mail->SMTPKeepAlive = true;

        // --- RECIPIENTS ---
        $mail->setFrom($fromEmail, $fromName); 
        $mail->addAddress($email);

        // Detect if on localhost or production and build verification link
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';
        $isLocalhost = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
        
        if ($isLocalhost) {
            $path = '/sanfranciscosystem/verify-account.php';
        } else {
            // On production, use the root directory path
            $path = '/verify-account.php';
        }

        $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . $path . "?token=" . urlencode($token);
        $mail->Subject = 'Verify Your Email - San Nicolas Dental Clinic';
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; color: #334155; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; } 
                .header { background: #1e3a5f; color: white; padding: 30px; text-align: center; border-radius: 12px 12px 0 0; }
                .content { background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-radius: 0 0 12px 12px; }
                .button { display: inline-block; background: #1e3a5f; color: white; padding: 14px 36px; text-decoration: none; border-radius: 8px; margin: 20px 0; font-weight: bold; }
                .button:hover { background: #152a45; }
                .footer { text-align: center; font-size: 12px; color: #94a3b8; margin-top: 20px; }
                .link-box { background: #f1f5f9; padding: 12px; border-radius: 6px; font-size: 12px; word-break: break-all; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Welcome to San Nicolas Dental Clinic</h2>
                </div>
                <div class='content'>
                    <p>Hello <strong>$first_name</strong>,</p>

                    <p>Thank you for registering! To complete your account setup, please verify your email address by clicking the button below:</p>

                    <div style='text-align: center;'>
                        <a href='$verification_link' class='button'>Verify Email Address</a>
                    </div>

                    <p>Or copy and paste this link in your browser:</p>
                    <div class='link-box'>$verification_link</div>

                    <p style='color: #64748b; font-size: 13px; margin-top: 20px;'>
                        This link will expire in 24 hours. If you didn't create this account, please ignore this email.
                    </p>

                    <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>

                    <div class='footer'>
                        <p>&copy; " . date('Y') . " San Nicolas Dental Clinic. All rights reserved.</p>
                        <p>This is an automated message, please do not reply directly to this email.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->AltBody = "Welcome $first_name!\n\nPlease verify your email by visiting: $verification_link\n\nThis link will expire in 24 hours.\n\nSan Nicolas Dental Clinic";

        $result = $mail->send();
        return $result;
    } catch (Exception $e) {
        // Log detailed error information
        $errorMsg = "PHPMailer Error: {$mail->ErrorInfo} | Exception: {$e->getMessage()}";
        error_log($errorMsg);
        error_log("PHPMailer Debug: Host=" . $mail->Host . ", Port=" . $mail->Port . ", Auth=" . ($mail->SMTPAuth ? "true" : "false"));

        // Fallback to PHP mail() function
        return sendVerificationEmailFallback($email, $username, $first_name, $token);
    }
}

function sendVerificationEmailFallback($email, $username, $first_name, $token) {
    // Don't use mail() - XAMPP doesn't have SMTP configured
    // Log the attempt for debugging
    $isLocalhost = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
      $path = $isLocalhost ? '/sanfranciscosystem/verify-account.php' : '/verify-account.php';
    // For development: Just return true and let user know to check console/manually verify
    // In production, you'd want a proper SMTP solution
    return false;
}

function createVerificationRecord($userId, $email, $token, $conn) {
    $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $sql = "UPDATE users SET email_verification_token = ?, verification_token_expiry = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("ssi", $token, $expiry, $userId);
        return $stmt->execute();
    }

    return false;
}

function resendVerificationEmail($email, $conn) {
    $sql = "SELECT id, username, first_name, email_verification_token FROM users WHERE email = ? AND email_verified = 0 LIMIT 1";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $token = $user['email_verification_token'] ?: generateVerificationToken();

            if (!$user['email_verification_token']) {
                createVerificationRecord($user['id'], $email, $token, $conn);   
            }

            return sendVerificationEmail($email, $user['username'], $user['first_name'], $token);
        }
    }

    return false;
}
?>

