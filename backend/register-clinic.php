<?php
session_start();
require_once 'config.php';
date_default_timezone_set('Asia/Manila');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// Check if user is logged in and has super_admin role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

$userRole = strtolower(trim($_SESSION['role'] ?? ''));
$userRole = str_replace('-', '_', $userRole);

if ($userRole !== 'super_admin') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']));
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$requiredFields = ['clinic_name', 'clinic_address', 'clinic_email', 'clinic_phone', 'owner_name', 'owner_email', 'owner_password', 'owner_password_confirm'];
foreach ($requiredFields as $field) {
    if (empty($input[$field] ?? '')) {
        exit(json_encode(['success' => false, 'message' => "Field '{$field}' is required"]));
    }
}

$clinic_name = trim($input['clinic_name']);
$clinic_address = trim($input['clinic_address']);
$clinic_email = trim($input['clinic_email']);
$clinic_phone = trim($input['clinic_phone']);
$owner_name = trim($input['owner_name']);
$owner_email = trim($input['owner_email']);
$owner_password = $input['owner_password'];
$owner_password_confirm = $input['owner_password_confirm'];

// Validate email formats
if (!filter_var($clinic_email, FILTER_VALIDATE_EMAIL)) {
    exit(json_encode(['success' => false, 'message' => 'Clinic email is not valid']));
}

if (!filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
    exit(json_encode(['success' => false, 'message' => 'Owner email is not valid']));
}

// Validate passwords match
if ($owner_password !== $owner_password_confirm) {
    exit(json_encode(['success' => false, 'message' => 'Passwords do not match']));
}

// Validate password strength (min 8 chars, must have uppercase, lowercase, number)
if (!validatePasswordStrength($owner_password)) {
    exit(json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and contain uppercase, lowercase, and number']));
}

// Check if clinic name already exists
$clinicCheckSql = "SELECT id FROM tenants WHERE clinic_name = ?";
$stmt = $conn->prepare($clinicCheckSql);
if (!$stmt) {
    exit(json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]));
}
$stmt->bind_param('s', $clinic_name);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $stmt->close();
    exit(json_encode(['success' => false, 'message' => 'A clinic with this name already exists']));
}
$stmt->close();

// Check if owner email already exists
$emailCheckSql = "SELECT id FROM users WHERE email = ?";
$stmt = $conn->prepare($emailCheckSql);
if (!$stmt) {
    exit(json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]));
}
$stmt->bind_param('s', $owner_email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $stmt->close();
    exit(json_encode(['success' => false, 'message' => 'This email is already registered in the system']));
}
$stmt->close();

// Generate unique clinic code (6-character alphanumeric)
$clinic_code = generateUniqueClinicCode($conn);

// Hash password
$hashed_password = password_hash($owner_password, PASSWORD_BCRYPT, ['cost' => 10]);

try {
    // Start transaction
    $conn->begin_transaction();

    // Insert clinic into tenants table
    $insertTenantSql = "INSERT INTO tenants (clinic_name, clinic_email, clinic_phone, clinic_address, clinic_code, is_active, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())";
    $stmt = $conn->prepare($insertTenantSql);
    if (!$stmt) {
        throw new Exception('Failed to prepare tenant insert: ' . $conn->error);
    }
    $stmt->bind_param('sssss', $clinic_name, $clinic_email, $clinic_phone, $clinic_address, $clinic_code);
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert clinic: ' . $stmt->error);
    }
    $tenant_id = $conn->insert_id;
    $stmt->close();

    // Insert owner user into users table
    $insertUserSql = "INSERT INTO users (tenant_id, first_name, last_name, email, password, role, email_verified, is_archived, created_at) 
                      VALUES (?, ?, ?, ?, ?, 'dentist', 1, 0, NOW())";
    $nameParts = explode(' ', $owner_name, 2);
    $first_name = $nameParts[0];
    $last_name = isset($nameParts[1]) ? $nameParts[1] : '';
    
    $stmt = $conn->prepare($insertUserSql);
    if (!$stmt) {
        throw new Exception('Failed to prepare user insert: ' . $conn->error);
    }
    $stmt->bind_param('issss', $tenant_id, $first_name, $last_name, $owner_email, $hashed_password);
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert owner account: ' . $stmt->error);
    }
    $owner_id = $conn->insert_id;
    $stmt->close();

    // Update tenant with owner_id
    $updateTenantSql = "UPDATE tenants SET owner_id = ? WHERE id = ?";
    $stmt = $conn->prepare($updateTenantSql);
    if (!$stmt) {
        throw new Exception('Failed to prepare tenant update: ' . $conn->error);
    }
    $stmt->bind_param('ii', $owner_id, $tenant_id);
    if (!$stmt->execute()) {
        throw new Exception('Failed to update tenant owner: ' . $stmt->error);
    }
    $stmt->close();

    // Commit transaction
    $conn->commit();

    // Log the action in audit logs if table exists
    logAuditTrail($conn, $tenant_id, $_SESSION['user_id'], 'CREATE_CLINIC', 'tenants', $tenant_id, null, ['clinic_name' => $clinic_name, 'clinic_code' => $clinic_code, 'owner_email' => $owner_email]);

    // Return success with clinic code
    $message = "Clinic \"$clinic_name\" has been successfully added.\n";
    $message .= "Clinic Code: $clinic_code\n";
    $message .= "Owner account created: $owner_email";
    
    exit(json_encode([
        'success' => true,
        'message' => $message,
        'clinic_code' => $clinic_code,
        'tenant_id' => $tenant_id,
        'owner_id' => $owner_id
    ]));

} catch (Exception $e) {
    // Rollback transaction
    if ($conn->connect_error) {
        http_response_code(500);
        exit(json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]));
    }
    $conn->rollback();
    
    http_response_code(500);
    exit(json_encode([
        'success' => false,
        'message' => 'Error creating clinic: ' . $e->getMessage()
    ]));
}

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return false;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }
    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }
    return true;
}

/**
 * Generate unique clinic code (6-character alphanumeric)
 */
function generateUniqueClinicCode($conn) {
    do {
        $code = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        $checkSql = "SELECT id FROM tenants WHERE clinic_code = ?";
        $stmt = $conn->prepare($checkSql);
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
    } while ($exists);
    
    return $code;
}

/**
 * Log audit trail (only if audit_logs table exists)
 */
function logAuditTrail($conn, $tenant_id, $user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null) {
    // Check if audit_logs table exists
    $checkTableSql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_logs'";
    $result = $conn->query($checkTableSql);
    if (!$result || $result->num_rows === 0) {
        return; // Table doesn't exist, skip logging
    }
    
    $old_values_json = $old_values ? json_encode($old_values) : null;
    $new_values_json = $new_values ? json_encode($new_values) : null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $auditSql = "INSERT INTO audit_logs (tenant_id, user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($auditSql);
    if ($stmt) {
        $stmt->bind_param('sisssisss', $tenant_id, $user_id, $action, $table_name, $record_id, $old_values_json, $new_values_json, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}
?>

/**
 * Validate password strength
 */
function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return false;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }
    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }
    return true;
}

/**
 * Generate unique clinic code
 */
function generateUniqueClinicCode($conn) {
    do {
        $code = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
        $checkSql = "SELECT id FROM clinics WHERE clinic_code = ?";
        $stmt = $conn->prepare($checkSql);
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
    } while ($exists);
    
    return $code;
}

/**
 * Generate UUID v4
 */
function generateUUID() {
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Log audit trail
 */
function logAuditTrail($conn, $tenant_id, $user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null) {
    $old_values_json = $old_values ? json_encode($old_values) : null;
    $new_values_json = $new_values ? json_encode($new_values) : null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $auditSql = "INSERT INTO audit_logs (tenant_id, user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($auditSql);
    if ($stmt) {
        $stmt->bind_param('siisiss ss', $tenant_id, $user_id, $action, $table_name, $record_id, $old_values_json, $new_values_json, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}
?>
