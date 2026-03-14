<?php
session_start();
require_once 'config.php';

/**
 * EMAIL VERIFICATION HANDLER
 * Verifies user email via token sent to their email address
 * Updates email_verified flag and clears verification token
 */

date_default_timezone_set('Asia/Manila');

// Check if token is provided
if (!isset($_GET['token']) || empty(trim($_GET['token']))) {
    $response = [
        'success' => false,
        'message' => 'Invalid verification link. No token provided.'
    ];
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

$token = mysqli_real_escape_string($conn, trim($_GET['token']));

try {
    // 1. Find user with this verification token
    $stmt = $conn->prepare("SELECT id, email, username, first_name, email_verified, verification_token_expiry 
                            FROM users 
                            WHERE email_verification_token = ? 
                            LIMIT 1");
    
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $response = [
            'success' => false,
            'message' => 'Invalid verification token. This link may have expired or is incorrect.'
        ];
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // 2. Check if already verified
    if ($user['email_verified'] == 1) {
        $response = [
            'success' => false,
            'message' => 'This email has already been verified. You can log in now.'
        ];
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    // 3. Check if token has expired
    $currentTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $expiryTime = new DateTime($user['verification_token_expiry'], new DateTimeZone('Asia/Manila'));

    if ($currentTime > $expiryTime) {
        $response = [
            'success' => false,
            'message' => 'Verification link has expired. Please check your email or request a new verification link.'
        ];
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    // 4. Update user email verification status
    $updateStmt = $conn->prepare("UPDATE users 
                                  SET email_verified = 1, 
                                      email_verification_token = NULL,
                                      verification_token_expiry = NULL
                                  WHERE id = ?");
    
    if (!$updateStmt) {
        throw new Exception("Database error: " . $conn->error);
    }

    $updateStmt->bind_param("i", $user['id']);
    
    if (!$updateStmt->execute()) {
        throw new Exception("Failed to update verification status: " . $updateStmt->error);
    }

    $updateStmt->close();

    // 5. Return success response
    $response = [
        'success' => true,
        'message' => 'Email verified successfully! Your account is now active.',
        'user' => [
            'username' => $user['username'],
            'email' => $user['email'],
            'first_name' => $user['first_name']
        ]
    ];
    
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($response);
    
    exit();

} catch (Exception $e) {
    error_log("Email Verification Error: " . $e->getMessage());
    $response = [
        'success' => false,
        'message' => 'An error occurred during email verification. Please try again later.'
    ];
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
?>
