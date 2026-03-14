<?php
/**
 * Function to send the verification code via email
 * Uses PHPMailer with Gmail SMTP
 * CREDENTIALS: Hardcoded fallback to ensure hosting compatibility
 */

// Load environment variables first
require_once __DIR__ . '/env-loader.php';

// Only load PHPMailer if not already loaded
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    $phpmailerPath = __DIR__ . '/../PHPMailer/src/';
    if (file_exists($phpmailerPath . 'PHPMailer.php')) {
        require_once $phpmailerPath . 'Exception.php';
        require_once $phpmailerPath . 'PHPMailer.php';
        require_once $phpmailerPath . 'SMTP.php';
    } else {
        error_log("PHPMailer library not found at: " . $phpmailerPath);
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendVerificationEmail($recipientEmail, $verificationCode) {
    global $conn;

    try {
        // Validate email
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("Invalid email format: " . $recipientEmail);
            return "Invalid email address";
        }

        // Check database connection
        if (!$conn) {
            error_log("Database connection not available");
            return "System error: unable to process request";
        }

        // Log the verification code to database (if table exists)
        $stmt = $conn->prepare("INSERT INTO verification_logs (email, code, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
        if ($stmt) {
            $stmt->bind_param("si", $recipientEmail, $verificationCode);        
            if (!$stmt->execute()) {
                error_log("Database execute error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            // Table might not exist, log the error but continue
            error_log("Could not log to verification_logs table: " . $conn->error);
        }

        error_log("Verification code logged for: " . $recipientEmail . " | Code: " . $verificationCode);

        // Now send the email via PHPMailer
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log("PHPMailer class not available, email will not be sent");
            return true; // Still return true to not break the flow
        }

        $mail = new PHPMailer(true);

        try {
            // --- SMTP CREDENTIALS WITH HARDCODED FALLBACK ---
            // Primary: Try to get from environment (.env file)
            $smtpHost = getEnvVar('SMTP_HOST');
            $smtpUsername = getEnvVar('SMTP_USERNAME');
            $smtpPassword = getEnvVar('SMTP_PASSWORD');
            $smtpPort = getEnvVar('SMTP_PORT');
            $fromEmail = getEnvVar('SMTP_FROM_EMAIL');
            $fromName = getEnvVar('SMTP_FROM_NAME');
            
            // Fallback: Use hardcoded credentials if environment variables are empty
            if (empty($smtpHost)) $smtpHost = 'smtp.gmail.com';
            if (empty($smtpUsername)) $smtpUsername = 'timoteovhasty@gmail.com';
            if (empty($smtpPassword)) $smtpPassword = 'rana qmsj dbwc ajvt';
            if (empty($smtpPort)) $smtpPort = 587;
            if (empty($fromEmail)) $fromEmail = 'timoteovhasty@gmail.com';
            if (empty($fromName)) $fromName = 'San Nicolas Dental Clinic';
            
            // Convert port to integer
            $smtpPort = (int)$smtpPort;

            error_log("DEBUG: Email config - Host: $smtpHost, Port: $smtpPort, From: $fromEmail");

            // Validate that credentials are now set
            if (empty($smtpUsername) || empty($smtpPassword)) {
                error_log("CRITICAL: SMTP credentials not available even with fallback!");
                return "Email service not configured. Please contact administrator.";
            }

            // Configure PHPMailer
            $mail->isSMTP();
            $mail->SMTPDebug = 0; // Set to 2 for debugging
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtpPort;
            $mail->Timeout = 15;
            $mail->SMTPKeepAlive = true;
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($recipientEmail);

            // Email content
            $mail->Subject = 'Password Reset Code - San Nicolas Dental Clinic';
            $mail->isHTML(true);
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif; color: #334155; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #1e3a5f; color: white; padding: 30px; text-align: center; border-radius: 12px 12px 0 0; }
                    .content { background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-radius: 0 0 12px 12px; }
                    .code-box { background: #f1f5f9; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0; border: 2px solid #1e3a5f; }
                    .code-box .code { font-size: 32px; font-weight: bold; color: #1e3a5f; letter-spacing: 4px; }
                    .footer { text-align: center; font-size: 12px; color: #94a3b8; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Password Reset Request</h2>
                    </div>
                    <div class='content'>
                        <p>Hello,</p>
                        <p>We received a request to reset your password. Use the verification code below to proceed:</p>

                        <div class='code-box'>
                            <p style='margin: 0 0 10px 0; color: #64748b; font-size: 14px;'>Your Verification Code:</p>
                            <div class='code'>$verificationCode</div>
                        </div>

                        <p style='color: #64748b; font-size: 13px; margin-top: 20px;'>
                            <strong>This code will expire in 15 minutes.</strong><br>
                            If you did not request a password reset, please ignore this email.
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

            $mail->AltBody = "Password Reset Code\n\nYour verification code is: $verificationCode\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.\n\nSan Nicolas Dental Clinic";

            // Send the email
            if ($mail->send()) {
                error_log("Verification email sent successfully to: " . $recipientEmail);
                return true;
            } else {
                error_log("PHPMailer failed to send: " . $mail->ErrorInfo);
                return "Unable to send verification code: " . $mail->ErrorInfo;
            }

        } catch (Exception $e) {
            error_log("PHPMailer Exception: " . $e->getMessage());
            if (isset($mail)) {
                error_log("PHPMailer ErrorInfo: " . $mail->ErrorInfo);
            }
            return "Unable to send verification code. Please try again.";       
        }

    } catch (Exception $e) {
        error_log("Verification email exception: " . $e->getMessage());
        return "An error occurred. Please try again later.";
    }
}
?>
