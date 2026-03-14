<?php
header('Content-Type: application/json');
session_start();

require_once 'config.php';

// Check if email is in session
if (!isset($_SESSION['reset_email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session. Please start over.']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['code'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Code is required']);
    exit();
}

$code = trim($input['code']);
$email = $_SESSION['reset_email'];

try {
    // Check if code exists and is valid in verification_logs
    $query = "SELECT id, email, code, used, expires_at FROM verification_logs 
              WHERE email = ? AND code = ? AND used = 0";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param('ss', $email, $code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired code']);
        exit();
    }
    
    $log = $result->fetch_assoc();
    
    // Check if code is expired
    if (strtotime($log['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Code has expired. Request a new one.']);
        exit();
    }
    
    // Mark code as used
    $updateQuery = "UPDATE verification_logs SET used = 1 WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    if (!$updateStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $updateStmt->bind_param('i', $log['id']);
    if (!$updateStmt->execute()) {
        throw new Exception("Execute failed: " . $updateStmt->error);
    }
    
    // Mark session as verified
    $_SESSION['reset_verified'] = true;
    
    echo json_encode(['success' => true, 'message' => 'Code verified successfully']);
    
} catch (Exception $e) {
    error_log("Verify reset code error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
?>
