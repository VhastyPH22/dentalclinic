<?php
header('Content-Type: application/json');
session_start();require_once 'env-loader.php';require_once 'config.php';

// Only load PHPMailer if not already loaded
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    $phpmailerPath = __DIR__ . '/../PHPMailer/src/';
    if (file_exists($phpmailerPath . 'PHPMailer.php')) {
        require $phpmailerPath . 'Exception.php';
        require $phpmailerPath . 'PHPMailer.php';
        require $phpmailerPath . 'SMTP.php';
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';

if (empty($email)) {
    http_response_code(400);
    $response['message'] = 'Email is required';
    echo json_encode($response);
    exit;
}

// Check if user exists and hasn't verified email yet
try {
    $sql = "SELECT id, username, first_name, email_verification_token FROM users WHERE email = ? AND email_verified = 0 LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $conn->error);
    }

    $stmt->bind_param("s", $email);
    if (!$stmt->execute()) {
        throw new Exception('Database query error: ' . $stmt->error);
    }
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Always generate a fresh token for security
        $token = bin2hex(random_bytes(32));
        $verifyExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Update with new token
        $updateSql = "UPDATE users SET email_verification_token = ?, verification_token_expiry = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        $updateStmt->bind_param("ssi", $token, $verifyExpiry, $user['id']);
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update verification token');
        }
        $updateStmt->close();

        // Send verification email
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';
        $isLocalhost = (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
        
        if ($isLocalhost) {
            $path = '/sanfranciscosystem/backend/verify-email.php';
        } else {
            // Build path dynamically based on directory structure
            $path = dirname($_SERVER['REQUEST_URI']) . '/backend/verify-email.php';
            $path = str_replace('\\', '/', $path); // Normalize path on Windows
        }
        
        $verifyLink = $protocol . "://" . $_SERVER['HTTP_HOST'] . $path . "?token=" . $token;
        $emailSubject = "Verify Your Email - San Nicolas Dental Clinic";
        $emailBody = "<html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head><body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f8fafc;'><div style='max-width: 600px; margin: 0 auto; padding: 20px;'><div style='background-color: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'><h2 style='color: #1e3a5f; font-size: 24px; margin-top: 0;'>Verify Your Email Address</h2><p style='color: #333; font-size: 16px; line-height: 1.6;'>Please verify your email address by clicking the button below:</p><div style='text-align: center; margin: 30px 0;'><a href='" . $verifyLink . "' style='background-color: #1e3a5f; color: white; padding: 16px 40px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px; display: inline-block; cursor: pointer;'>Verify Email Address</a></div><p style='color: #666; font-size: 14px; line-height: 1.6;'>Or copy and paste this link in your browser:<br><a href='" . $verifyLink . "' style='color: #1e3a5f; word-break: break-all;'>" . htmlspecialchars($verifyLink) . "</a></p><hr style='border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;'><p style='color: #666; font-size: 13px; line-height: 1.6;'>This link will expire in 24 hours.<br>If you did not request this verification, please ignore this email.</p><p style='font-size: 12px; color: #94a3b8; text-align: center; margin-top: 30px;'>© San Nicolas Dental Clinic. All rights reserved.</p></div></div></body></html>";

        // Send verification email via PHPMailer SMTP
        try {
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                throw new Exception('PHPMailer library not available');
            }
            
            // Get SMTP credentials from environment, with hardcoded fallback
            $smtpHost = getEnvVar('SMTP_HOST');
            if (empty($smtpHost)) $smtpHost = 'smtp.gmail.com';
            
            $smtpUsername = getEnvVar('SMTP_USERNAME');
            if (empty($smtpUsername)) $smtpUsername = 'timoteovhasty@gmail.com';
            
            $smtpPassword = getEnvVar('SMTP_PASSWORD');
            if (empty($smtpPassword)) $smtpPassword = 'rana qmsj dbwc ajvt';
            
            $smtpPort = getEnvVar('SMTP_PORT');
            if (empty($smtpPort)) $smtpPort = 587;
            $smtpPort = (int)$smtpPort;
            
            $fromEmail = getEnvVar('SMTP_FROM_EMAIL');
            if (empty($fromEmail)) $fromEmail = 'timoteovhasty@gmail.com';
            
            $fromName = getEnvVar('SMTP_FROM_NAME');
            if (empty($fromName)) $fromName = 'San Nicolas Dental Clinic';
            
            // Validate that credentials are now set
            if (empty($smtpUsername) || empty($smtpPassword)) {
                error_log("CRITICAL: SMTP credentials not available even with fallback!");
                throw new Exception('Email service not configured.');
            }
            
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->SMTPDebug = 0;
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtpPort;
            $mail->Timeout = 10;
            $mail->SMTPKeepAlive = true;

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = $emailSubject;
            $mail->Body = $emailBody;

            if ($mail->send()) {
                $response['success'] = true;
                $response['message'] = 'Verification email sent successfully';  
                http_response_code(200);
            } else {
                throw new Exception('Mail send failed');
            }
        } catch (Exception $e) {
            $response['message'] = 'Email service temporarily unavailable. Please try again later.';
            http_response_code(500);
        }
    } else {
        $response['message'] = 'Email not found or already verified';
        http_response_code(404);
    }
    $stmt->close();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    if (strpos($e->getMessage(), 'Database') === 0) {
        http_response_code(500);
    }
}

echo json_encode($response);
?>
