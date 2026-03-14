<?php
// Ensure NO spaces or empty lines exist above the <?php tag
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

// Check if the user account is archived
require_once __DIR__ . '/config.php';
$userId = $_SESSION['user_id'] ?? 0;
if ($userId > 0 && isset($conn) && $conn !== null) {
    $archiveCheck = mysqli_query($conn, "SELECT is_archived FROM users WHERE id = '$userId' LIMIT 1");
    if ($archiveCheck && mysqli_num_rows($archiveCheck) > 0) {
        $archiveData = mysqli_fetch_assoc($archiveCheck);
        if (isset($archiveData['is_archived']) && $archiveData['is_archived'] == 1) {
            // Account has been archived - destroy session and redirect
            session_destroy();
            header("Location: ../login.php?error=Your account has been archived and cannot be used");
            exit();
        }
    }
}

// Clean the role string to avoid case-sensitivity issues
$role = strtolower(trim($_SESSION['role']));

switch ($role) {
    case 'admin':
        header("Location: ../admin-dashboard.php");
        break;
    case 'dentist':
        header("Location: ../dentist-dashboard.php");
        break;
    case 'assistant':
        header("Location: ../assistant-dashboard.php");
        break;
    case 'patient':
        header("Location: ../patient-dashboard.php");
        break;
    default:
        // Redirect with a specific error if the role is unrecognized
        header("Location: ../login.php?error=Invalid Role: " . urlencode($role));
        break;
}
exit();
?>
