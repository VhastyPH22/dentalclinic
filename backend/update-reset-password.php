<?php
header('Content-Type: application/json');
session_start();

require_once 'config.php';

// Check if user went through verification
if (!isset($_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session. Please start over.']);
    exit();
}

if (!isset($_SESSION['reset_email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session. Please start over.']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password is required']);
    exit();
}

$password = $input['password'];
$email = $_SESSION['reset_email'];

// Validate password
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
    exit();
}

if (!preg_match('/[A-Z]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one uppercase letter']);
    exit();
}

if (!preg_match('/[a-z]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one lowercase letter']);
    exit();
}

if (!preg_match('/[0-9]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one number']);
    exit();
}

if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must contain at least one special character']);
    exit();
}

try {
    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    
    // Find user by email (single email query - no tenant filter needed for password reset by email)
    $findQuery = "SELECT id, tenant_id FROM users WHERE email = ?";
    $findStmt = $conn->prepare($findQuery);
    if (!$findStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $findStmt->bind_param('s', $email);
    if (!$findStmt->execute()) {
        throw new Exception("Execute failed: " . $findStmt->error);
    }
    
    $result = $findStmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    $user = $result->fetch_assoc();
    $userId = $user['id'];
    $tenantId = $user['tenant_id'];
    
    // Update password in users table with multi-tenant filter
    $updateQuery = "UPDATE users SET password = ? WHERE id = ? AND tenant_id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    if (!$updateStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $updateStmt->bind_param('sii', $hashedPassword, $userId, $tenantId);
    if (!$updateStmt->execute()) {
        throw new Exception("Execute failed: " . $updateStmt->error);
    }
    

    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    error_log("Update reset password error: " . $errorMsg);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $errorMsg]);
}
?>
