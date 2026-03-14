<?php
/**
 * backend/login.php - Multi-Tenant Login Handler
 * 
 * Flow:
 * 1. User submits email/username and password
 * 2. System finds user and verifies password
 * 3. System identifies the user's tenant (clinic)
 * 4. Session is set with user_id, tenant_id, role, and username
 * 5. User is redirected to appropriate dashboard based on role
 */

session_start();
require_once "config.php";

// Allow only POST requests
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (isset($_POST['identity']) && isset($_POST['password'])) {

        $identity = trim($_POST['identity']);
        $password = trim($_POST['password']);

        // ========================================
        // 1. FIND USER (EMAIL OR USERNAME)
        // ========================================
        $identity_escaped = mysqli_real_escape_string($conn, $identity);
        $sql = "SELECT id, username, email, password, role, tenant_id, is_archived, email_verified, first_name, last_name
                FROM users 
                WHERE (username = '$identity_escaped' OR email = '$identity_escaped') 
                LIMIT 1";

        $result = mysqli_query($conn, $sql);

        if (!$result) {
            // Database error
            header("Location: ../login.php?error=" . urlencode("Database error during login"));
            exit();
        }

        if (mysqli_num_rows($result) === 1) {
            $row = mysqli_fetch_assoc($result);

            // ========================================
            // 2. VERIFY PASSWORD
            // ========================================
            if (!password_verify($password, $row['password'])) {
                // Wrong password
                header("Location: ../login.php?error=" . urlencode("Incorrect password"));
                exit();
            }

            // ========================================
            // 3. CHECK ARCHIVED STATUS
            // ========================================
            if (isset($row['is_archived']) && $row['is_archived'] == 1) {
                header("Location: ../login.php?error=" . urlencode("This account has been archived and cannot be used"));
                exit();
            }

            // ========================================

            // 5. VERIFY TENANT MEMBERSHIP
            // ========================================
            $tenantId = $row['tenant_id'];

            if (!$tenantId) {
                // User has no tenant assigned - this should not happen
                header("Location: ../login.php?error=" . urlencode("Your account is not assigned to any clinic. Please contact support."));
                exit();
            }

            // Verify tenant exists and is active
            $tenantInfo = getTenantInfo($tenantId);
            if (!$tenantInfo) {
                header("Location: ../login.php?error=" . urlencode("The clinic associated with your account is no longer active. Please contact support."));
                exit();
            }

            // ========================================
            // 6. SET SESSION DATA - MULTI-TENANT AWARE
            // ========================================
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['tenant_id'] = $tenantId;  // CRITICAL: Set tenant_id in session
            $_SESSION['clinic_name'] = $tenantInfo['clinic_name']; // For display purposes
            $_SESSION['first_name'] = $row['first_name'] ?? '';
            $_SESSION['last_name'] = $row['last_name'] ?? '';

            // Log the login activity (optional audit trail)
            logTenantAudit('LOGIN', 'users', $row['id'], [], ['ip_address' => $_SERVER['REMOTE_ADDR']]);

            // ========================================
            // REDIRECT BASED ON ROLE
            // ========================================
            $role = strtolower($row['role']);

            if ($role == 'clinic_owner' || $role == 'admin') {
                // Clinic owner/admin dashboard
                header("Location: ../clinic-admin-dashboard.php");
            } else if ($role == 'dentist') {
                // Dentist dashboard
                header("Location: ../dentist-dashboard.php");
            } else if ($role == 'staff') {
                // Staff/assistant dashboard
                header("Location: ../assistant-dashboard.php");
            } else {
                // Unknown role - fail safely
                header("Location: ../login.php?error=" . urlencode("Unknown role detected: " . htmlspecialchars($row['role'])));
            }
            exit();

        } else {
            // User not found
            header("Location: ../login.php?error=" . urlencode("User not found"));
            exit();
        }
    } else {
        // Missing credentials
        header("Location: ../login.php?error=" . urlencode("Please provide both email/username and password"));
        exit();
    }
} else {
    // Not a POST request
    header("Location: ../login.php");
    exit();
}
?>
