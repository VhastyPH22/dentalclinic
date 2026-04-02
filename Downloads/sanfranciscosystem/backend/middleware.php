<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('checkAccess')) {
    function checkAccess($allowed_roles) {
        global $conn;
        
        // If the user is not logged in, redirect to the login page
        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit();
        }

        // Check if the user's account is archived - if so, force logout
        // Use global connection or require config if not available
        if (!isset($GLOBALS['conn']) || $GLOBALS['conn'] === null) {
            require_once __DIR__ . '/config.php';
        }
        
        $userId = $_SESSION['user_id'];
        $archiveCheck = mysqli_query($GLOBALS['conn'], "SELECT is_archived FROM users WHERE id = '$userId' LIMIT 1");
        
        if ($archiveCheck && mysqli_num_rows($archiveCheck) > 0) {
            $archiveData = mysqli_fetch_assoc($archiveCheck);
            if (isset($archiveData['is_archived']) && $archiveData['is_archived'] == 1) {
                // Account has been archived - destroy session and redirect
                session_destroy();
                header("Location: login.php?error=Your account has been archived and cannot be used");
                exit();
            }
        }

        // Convert roles to lowercase to prevent case-sensitivity issues        
        $userRole = strtolower($_SESSION['role']);
        $allowed_roles = array_map('strtolower', $allowed_roles);

        // If the user's role isn't allowed, send them to login
        if (!in_array($userRole, $allowed_roles)) {
            header("Location: login.php");
            exit();
        }
    }
}
?>
