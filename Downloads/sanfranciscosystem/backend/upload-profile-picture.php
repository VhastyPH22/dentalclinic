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
        $response['message'] = 'You do not have permission to upload profile pictures.';
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

    // Permission check
    if ($userRole === 'patient' && $targetID != $currentUserID) {
        $response['message'] = 'You can only upload your own profile picture.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Validate file upload
    if (!isset($_FILES['profile_picture'])) {
        $response['message'] = 'No file uploaded.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    $file = $_FILES['profile_picture'];
    $maxFileSize = 3 * 1024 * 1024; // 3MB
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit.',
            UPLOAD_ERR_PARTIAL => 'File upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server cannot write the file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the file.',
        ];
        $response['message'] = $uploadErrors[$file['error']] ?? 'Unknown upload error occurred.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Validate file size
    if ($file['size'] > $maxFileSize) {
        $response['message'] = 'File size exceeds 3MB limit.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Validate file type
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions)) {
        $response['message'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WebP.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Check MIME type
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mimeValid = true;
    
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($mimeType && !in_array($mimeType, $allowedMimes)) {
                $mimeValid = false;
            }
        }
    } elseif (function_exists('mime_content_type')) {
        $mimeType = @mime_content_type($file['tmp_name']);
        if ($mimeType && !in_array($mimeType, $allowedMimes)) {
            $mimeValid = false;
        }
    }
    
    if (!$mimeValid) {
        $response['message'] = 'Invalid image file. Please upload a valid image.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Setup upload directory - works for both localhost and hosting
    // Get the actual application root by using the location of this file
    $appRoot = dirname(__DIR__);  // backend folder is one level up
    $uploadDir = $appRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR;
    $webRoot = $appRoot;
    
    // Helper function to clean profile picture paths (remove /htdocs/ or similar incorrect prefixes)
    function cleanProfilePicturePath($path) {
        // Remove leading/trailing whitespace
        $path = trim($path);
        // Normalize path separators
        $path = str_replace('\\', '/', $path);
        // Remove any /htdocs/ prefix or similar
        $path = preg_replace('|^.*?/?(assets/images/profiles/)|', '$1', $path);
        // Remove leading slashes
        $path = ltrim($path, '/');
        return $path;
    }

    // Create directory if needed - use recursive creation
    if (!is_dir($uploadDir)) {
        // Try to create parent directories first
        $parentDir = dirname($uploadDir);
        if (!is_dir($parentDir)) {
            @mkdir($parentDir, 0755, true);
        }

        if (!@mkdir($uploadDir, 0755, true)) {
            $response['message'] = 'Cannot create upload directory. Please contact support.';
            ob_end_clean();
            echo json_encode($response);
            exit;
        }
    }

    // Make directory writable
    if (!is_writable($uploadDir)) {
        @chmod($uploadDir, 0755);
    }

    // Verify directory exists and is writable
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        $response['message'] = 'Upload directory is not writable. Please contact support.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Generate unique filename
    $fileName = 'profile_' . intval($targetID) . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    // Store relative path in database - use forward slashes for URLs - ensure it's clean
    $relativeFilePath = 'assets/images/profiles/' . $fileName;
    $relativeFilePath = cleanProfilePicturePath($relativeFilePath); // Ensure path is clean

    // Delete old profile picture if exists
    $checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
    $columnCheckResult = @mysqli_query($conn, $checkColumnSQL);
    
    if ($columnCheckResult && @mysqli_num_rows($columnCheckResult) > 0) {
        $targetIDEscaped = mysqli_real_escape_string($conn, $targetID);
        $oldPictureQuery = @mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '$targetIDEscaped'");
        
        if ($oldPictureQuery) {
            $oldData = @mysqli_fetch_assoc($oldPictureQuery);
            if ($oldData && !empty($oldData['profile_picture'])) {
                // Normalize path separators and construct proper file path
                $relativePath = str_replace('\\', '/', $oldData['profile_picture']);
                $oldFilePath = $webRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                if (file_exists($oldFilePath) && is_file($oldFilePath)) {
                    @unlink($oldFilePath);
                }
            }
        }
    }

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        // Debug info for failed upload
        $debugMsg = 'Failed to save file. ';
        $debugMsg .= 'Dir exists: ' . (is_dir($uploadDir) ? 'yes' : 'no') . '. ';
        $debugMsg .= 'Dir writable: ' . (is_writable($uploadDir) ? 'yes' : 'no') . '. ';
        $debugMsg .= 'Temp file: ' . (file_exists($file['tmp_name']) ? 'exists' : 'missing') . '. ';
        $debugMsg .= 'Target: ' . $filePath;
        
        $response['message'] = $debugMsg;
        ob_end_clean();
        echo json_encode($response);
        exit;
    }
    
    // Verify file was actually created
    if (!file_exists($filePath)) {
        $response['message'] = 'File appears to have been deleted or never saved: ' . $filePath;
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Set file permissions - ensure file is readable by web server
    @chmod($filePath, 0644);

    // Update database if column exists
    if ($columnCheckResult && @mysqli_num_rows($columnCheckResult) > 0) {
        // Ensure patient_profiles record exists
        $targetIDEscaped = mysqli_real_escape_string($conn, $targetID);
        $checkProfileQuery = @mysqli_query($conn, "SELECT user_id FROM patient_profiles WHERE user_id = '$targetIDEscaped'");
        
        if (!$checkProfileQuery || mysqli_num_rows($checkProfileQuery) === 0) {
            // Create patient_profiles record if it doesn't exist
            $createProfileQuery = "INSERT INTO patient_profiles (user_id, dob, phone, address, occupation, marital_status, gender, chief_complaint, profile_picture) 
                                 VALUES ('$targetIDEscaped', '2000-01-01', '', '', '', 'single', 'Not Specified', '', '')";
            @mysqli_query($conn, $createProfileQuery);
        }
        
        // Ensure path is clean before storing
        $cleanedPath = cleanProfilePicturePath($relativeFilePath);
        $escapedPath = mysqli_real_escape_string($conn, $cleanedPath);
        $updateQuery = "UPDATE patient_profiles SET profile_picture = '$escapedPath' WHERE user_id = '$targetIDEscaped'";
        
        if (!@mysqli_query($conn, $updateQuery)) {
            @unlink($filePath);
            $response['message'] = 'Database update failed. Please try again.';
            ob_end_clean();
            echo json_encode($response);
            exit;
        }
    }

    // Success response
    $response['success'] = true;
    $response['message'] = 'Profile picture updated successfully.';
    $response['image_path'] = $relativeFilePath; // Return relative path
    // Use file modification time for cache busting, fallback to current time
    $fileTime = @filemtime($filePath);
    $response['timestamp'] = intval(($fileTime ? $fileTime : time()) * 1000); // Add timestamp in milliseconds for cache busting
    
    // Add debug info (remove in production)
    $response['debug'] = [
        'file_path' => $filePath,
        'file_exists' => file_exists($filePath),
        'file_size' => @filesize($filePath),
        'upload_dir' => $uploadDir
    ];
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

// Send response
ob_end_clean();
echo json_encode($response);
exit;
?>
