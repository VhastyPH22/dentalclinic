<?php
// Set JSON headers BEFORE any includes or output
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Clear any previous output
ob_end_clean();
ob_start();

// Start session
session_start();

// Load config and middleware
require_once 'config.php';
require_once 'middleware.php';

$response = ['success' => false, 'message' => ''];

try {
    // Custom access check that returns JSON error instead of redirect
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'User not authenticated. Please log in.';        
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    $userRole = strtolower($_SESSION['role'] ?? '');
    $allowedRoles = ['dentist', 'assistant', 'patient'];
    
    if (!in_array($userRole, $allowedRoles)) {
        $response['message'] = 'You do not have permission to delete profile pictures.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response['message'] = 'Invalid request method. Only POST allowed.';    
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    $currentUserID = $_SESSION['user_id'];
    $targetID = $_POST['user_id'] ?? $currentUserID;

    // Permission check - users can only delete their own picture, or admins can delete anyone's
    if ($targetID != $currentUserID && !in_array($userRole, ['dentist', 'assistant'])) {
        $response['message'] = 'You do not have permission to delete this profile picture.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Get current profile picture path
    $profileQuery = mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '" . intval($targetID) . "'");
    
    if (!$profileQuery) {
        throw new Exception('Database query failed');
    }

    $profileData = mysqli_fetch_assoc($profileQuery);
    $currentPicture = $profileData['profile_picture'] ?? '';

    // Delete physical file if it exists
    if (!empty($currentPicture)) {
        $filePath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $currentPicture);
        
        // Verify file is within allowed directory
        $realPath = realpath($filePath);
        $baseDir = realpath(__DIR__ . '/../assets/images/profiles');
        
        if ($realPath && strpos($realPath, $baseDir) === 0 && file_exists($realPath)) {
            if (!unlink($realPath)) {
                // Log error but continue - we'll still clear the DB entry
                error_log("Failed to delete file: " . $realPath);
            }
        }
    }

    // Clear profile_picture field in database
    $updateQuery = "UPDATE patient_profiles SET profile_picture = '' WHERE user_id = '" . intval($targetID) . "'";
    
    if (!mysqli_query($conn, $updateQuery)) {
        throw new Exception(mysqli_error($conn));
    }

    $response['success'] = true;
    $response['message'] = 'Profile picture removed successfully.';

} catch (Exception $e) {
    error_log("Error in delete-profile-picture.php: " . $e->getMessage());
    $response['message'] = 'Error: ' . $e->getMessage();
}

ob_end_clean();
echo json_encode($response);
?>
