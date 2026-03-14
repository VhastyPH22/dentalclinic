<?php
/**
 * backend/security_init.php
 * PARANOID SECURITY: Validates tenant_id on every page load
 * Prevents users from manipulating SESSION['tenant_id'] to see other clinics' data
 */

// Must be called AFTER session_start() and middleware.php

if (!function_exists('validateAndLockTenantId')) {
    function validateAndLockTenantId() {
        global $conn;
        
        // If not logged in, skip
        if (!isset($_SESSION['user_id'])) {
            return;
        }
        
        $userId = (int)$_SESSION['user_id'];
        $sessionTenant = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
        
        // Query the database for the user's ACTUAL tenant_id
        $query = "SELECT tenant_id FROM users WHERE id = $userId LIMIT 1";
        $result = mysqli_query($GLOBALS['conn'], $query);
        
        if (!$result || mysqli_num_rows($result) === 0) {
            // User not found - force logout
            session_destroy();
            header("Location: login.php?error=User not found. Please login again");
            exit();
        }
        
        $userData = mysqli_fetch_assoc($result);
        $actualTenant = (int)$userData['tenant_id'];
        
        // PARANOID CHECK: If session tenant doesn't match database, fix it immediately
        if ($sessionTenant !== $actualTenant) {
            error_log("🚨 SECURITY: Tenant ID mismatch detected!");
            error_log("   User: $userId");
            error_log("   Session Tenant: " . ($sessionTenant ?? 'NULL'));
            error_log("   Actual Tenant: $actualTenant");
            error_log("   IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
            
            // Force correct tenant_id
            $_SESSION['tenant_id'] = $actualTenant;
        }
        
        // Additional paranoia: Verify user's tenant exists and is active
        $tenantQuery = "SELECT id, is_active FROM tenants WHERE id = $actualTenant LIMIT 1";
        $tenantResult = mysqli_query($GLOBALS['conn'], $tenantQuery);
        
        if (!$tenantResult || mysqli_num_rows($tenantResult) === 0) {
            error_log("🚨 CRITICAL: User's tenant doesn't exist! User: $userId, Tenant: $actualTenant");
            session_destroy();
            header("Location: login.php?error=Critical error: Clinic not found");
            exit();
        }
        
        $tenantData = mysqli_fetch_assoc($tenantResult);
        if ((int)$tenantData['is_active'] === 0) {
            error_log("🚨 ALERT: User tried to access with inactive clinic. User: $userId, Tenant: $actualTenant");
            session_destroy();
            header("Location: login.php?error=Your clinic account is inactive");
            exit();
        }
    }
}

// Call on every protected page
if (isset($_SESSION['user_id'])) {
    validateAndLockTenantId();
}
?>
