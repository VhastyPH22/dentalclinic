<?php
session_start();
require_once 'config.php';
require_once 'middleware.php';

// Verify user is authorized
checkAccess(['dentist', 'assistant', 'patient'], true);

// Get user ID from request
$userId = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? $_SESSION['user_id']);

if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID not provided']);
    exit;
}

// SECURITY: Users can only upload for themselves, staff can upload for patients
$currentUser = $_SESSION['user_id'];
$currentRole = $_SESSION['role'];
$tenantId = getTenantId();

// Verify user belongs to same tenant
if (!userBelongsToTenant($userId, $tenantId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized: User does not belong to your clinic']);
    exit;
}

// Check if user can upload (own picture or staff managing patient)
if ($userId !== $currentUser && $currentRole !== 'dentist' && $currentRole !== 'assistant') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized: Cannot upload picture for other users']);
    exit;
}

// Validate file upload
if (!isset($_FILES['profile_picture'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file provided']);
    exit;
}

$file = $_FILES['profile_picture'];
$uploadDir = __DIR__ . '/../assets/images/';
$maxFileSize = 5 * 1024 * 1024; // 5MB
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

// Validate file
if ($file['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large (max 5MB)']);
    exit;
}

if (!in_array($file['type'], $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only JPEG, PNG, GIF allowed']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

// Create unique filename with tenant isolation
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'profile_' . $userId . '_' . $tenantId . '_' . time() . '.' . $ext;
$filePath = $uploadDir . $filename;

// Get old picture to delete
$sql = "SELECT profile_picture FROM users WHERE id = $userId AND tenant_id = " . (int)$tenantId;
$result = mysqli_query($conn, $sql);
$oldPicture = null;

if ($result && $row = mysqli_fetch_assoc($result)) {
    $oldPicture = $row['profile_picture'];
}

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $filePath)) {
    // Delete old picture if exists
    if (!empty($oldPicture) && file_exists($oldPicture)) {
        @unlink($oldPicture);
    }

    // Update database
    $relPath = 'assets/images/' . $filename;
    $updateSql = "UPDATE users SET profile_picture = '$relPath' WHERE id = $userId AND tenant_id = " . (int)$tenantId;
    
    if (mysqli_query($conn, $updateSql)) {
        logTenantAudit('UPLOAD_PROFILE_PICTURE', 'users', $userId, ['profile_picture' => $oldPicture], ['profile_picture' => $relPath]);
        echo json_encode(['success' => true, 'message' => 'Profile picture uploaded', 'path' => $relPath]);
    } else {
        @unlink($filePath);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save picture to database']);
    }
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload file']);
}
?>
