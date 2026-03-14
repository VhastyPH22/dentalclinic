<?php
session_start();
require_once 'config.php';
require_once 'analytics.php';

// Record logout before destroying session
if (isset($_SESSION['user_id'])) {
    recordLogout($_SESSION['user_id']);
}

// 1. Unset all session variables
$_SESSION = array();

// 2. Destroy the session cookie (Best practice)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session
session_destroy();

// 4. Redirect to the Login Page
// We use "../" to go out of the 'backend' folder and back to the main folder
header("Location: ../login.php");
exit();
?>