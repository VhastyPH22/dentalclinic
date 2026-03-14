<?php
/**
 * backend/middleware.php - Authentication and Multi-Tenant Access Control
 * Checks user session, archived status, roles, and tenant isolation
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('checkAccess')) {
    /**
     * Main access control function
     * Validates user session, role, and tenant membership
     * 
     * @param array $allowed_roles Array of allowed roles
     * @param bool $require_tenant Whether tenant_id must be set in session
     */
    function checkAccess($allowed_roles, $require_tenant = true) {
        global $conn;

        // If the user is not logged in, redirect to the login page
        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php?error=Please login first");
            exit();
        }

        // Ensure database connection is available
        if (!isset($GLOBALS['conn']) || $GLOBALS['conn'] === null) {
            require_once __DIR__ . '/config.php';
        }

        // ========================================
        // MULTI-TENANT CHECK
        // ========================================
        if ($require_tenant && !isset($_SESSION['tenant_id'])) {
            session_destroy();
            header("Location: login.php?error=Tenant information not found. Please login again");
            exit();
        }

        $userId = (int)$_SESSION['user_id'];
        $tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;

        // ========================================
        // FETCH USER DATA WITH TENANT CHECK
        // ========================================
        $query = "SELECT id, is_archived, role, tenant_id FROM users WHERE id = $userId";
        
        if ($tenantId !== null) {
            $query .= " AND tenant_id = $tenantId";
        }
        
        $query .= " LIMIT 1";
        
        $archiveCheck = mysqli_query($GLOBALS['conn'], $query);

        if (!$archiveCheck || mysqli_num_rows($archiveCheck) === 0) {
            session_destroy();
            header("Location: login.php?error=User not found or unauthorized access");
            exit();
        }

        $userData = mysqli_fetch_assoc($archiveCheck);

        // ========================================
        // ARCHIVED ACCOUNT CHECK
        // ========================================
        if (isset($userData['is_archived']) && $userData['is_archived'] == 1) {
            session_destroy();
            header("Location: login.php?error=Your account has been archived and cannot be used");
            exit();
        }

        // ========================================
        // VERIFY TENANT MEMBERSHIP
        // ========================================
        if ($tenantId !== null && $userData['tenant_id'] != $tenantId) {
            session_destroy();
            header("Location: login.php?error=Tenant verification failed");
            exit();
        }

        // ========================================
        // ROLE-BASED ACCESS CHECK
        // ========================================
        $userRole = strtolower($userData['role']);
        $allowed_roles = array_map('strtolower', $allowed_roles);

        if (!in_array($userRole, $allowed_roles)) {
            header("Location: login.php?error=Your role does not have access to this page");
            exit();
        }

        // Update session with current role (in case it changed)
        $_SESSION['role'] = $userData['role'];
    }
}

if (!function_exists('checkTenantAccess')) {
    /**
     * Check if current user can access a specific tenant record
     * 
     * @param string $tableName The table name
     * @param int $recordId The record ID to check
     * @return bool True if user can access the record
     */
    function checkTenantAccess($tableName, $recordId) {
        global $conn;

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
            return false;
        }

        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $recordId = (int)$recordId;
        $tenantId = (int)$_SESSION['tenant_id'];

        $sql = "SELECT id FROM `$tableName` WHERE id = $recordId AND tenant_id = $tenantId LIMIT 1";
        $result = mysqli_query($conn, $sql);

        return $result && mysqli_num_rows($result) === 1;
    }
}

if (!function_exists('requireTenantAccess')) {
    /**
     * Check tenant access and exit if denied
     * Useful for protecting specific record operations
     * 
     * @param string $tableName The table name
     * @param int $recordId The record ID
     * @param string $redirectUrl Where to redirect on failure
     */
    function requireTenantAccess($tableName, $recordId, $redirectUrl = 'login.php') {
        if (!checkTenantAccess($tableName, $recordId)) {
            header("Location: $redirectUrl?error=Access denied to this record");
            exit();
        }
    }
}

?>
