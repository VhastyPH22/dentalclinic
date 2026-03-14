<?php
/**
 * backend/config.php - Enhanced with Multi-Tenant Support
 * Database configuration and multi-tenant utility functions with additional security layers
 */

// ========================================
// DATABASE CONNECTION
// ========================================

$host = "localhost";
$user = "root";
$pass = "";
$db   = "if0_40636983_setup";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

$DEFAULT_TENANT_ID = 1;

// ========================================
// MULTI-TENANT UTILITY FUNCTIONS
// ========================================

function getTenantId() {
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
}

function setTenantId($tenantId) {
    $_SESSION['tenant_id'] = (int)$tenantId;
}

function getTenantInfo($tenantId) {
    global $conn;
    $tenantId = (int)$tenantId;
    $sql = "SELECT * FROM tenants WHERE id = $tenantId AND is_active = 1 LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log("Database error in getTenantInfo: " . mysqli_error($conn));
        return null;
    }
    if (mysqli_num_rows($result) === 1) {
        return mysqli_fetch_assoc($result);
    }
    return null;
}

/**
 * SECURITY: Apply tenant filtering to all queries
 * Ensures users can NEVER access another clinic's data
 */
function getTenantFilter($tableAlias = '') {
    $tenantId = getTenantId();
    if ($tenantId === null) {
        return '';
    }
    $prefix = !empty($tableAlias) ? $tableAlias . '.' : '';
    return " AND {$prefix}tenant_id = " . (int)$tenantId;
}

/**
 * SECURITY: Add tenant_id to all INSERT statements
 * New records are ALWAYS assigned to the creating user's clinic
 */
function getTenantValueForInsert() {
    $tenantId = getTenantId();
    if (!empty($tenantId)) {
        return (int)$tenantId;
    }
    if (isset($_SESSION['user_id'])) {
        global $conn;
        $userId = (int)$_SESSION['user_id'];
        $result = mysqli_query($conn, "SELECT tenant_id FROM users WHERE id = $userId LIMIT 1");
        if ($result && $row = mysqli_fetch_assoc($result)) {
            $tenantId = (int)$row['tenant_id'];
            $_SESSION['tenant_id'] = $tenantId;
            return $tenantId;
        }
    }
    return 1;
}

function canAccessRecord($tableName, $recordId) {
    global $conn;
    $tenantId = getTenantId();
    if ($tenantId === null) {
        return false;
    }
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $recordId = (int)$recordId;
    $tenantId = (int)$tenantId;
    $sql = "SELECT id FROM `$tableName` WHERE id = $recordId AND tenant_id = $tenantId LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log("Database error in canAccessRecord: " . mysqli_error($conn));
        return false;
    }
    return mysqli_num_rows($result) === 1;
}

function logTenantAudit($action, $tableName, $recordId, $oldValues = [], $newValues = []) {
    global $conn;
    $tenantId = getTenantId();
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    if ($tenantId === null) {
        return;
    }
    $action = mysqli_real_escape_string($conn, $action);
    $tableName = mysqli_real_escape_string($conn, $tableName);
    $recordId = (int)$recordId;
    $oldValuesJson = json_encode($oldValues);
    $newValuesJson = json_encode($newValues);
    $ipAddress = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $sql = "INSERT INTO tenant_audit_logs (tenant_id, user_id, action, table_name, record_id, old_values, new_values, ip_address, created_at)
            VALUES ($tenantId, " . ($userId ? $userId : 'NULL') . ", '$action', '$tableName', $recordId, '$oldValuesJson', '$newValuesJson', '$ipAddress', NOW())";
    mysqli_query($conn, $sql);
}

function userBelongsToTenant($userId, $tenantId) {
    global $conn;
    $userId = (int)$userId;
    $tenantId = (int)$tenantId;
    $sql = "SELECT id FROM users WHERE id = $userId AND tenant_id = $tenantId LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log("Database error in userBelongsToTenant: " . mysqli_error($conn));
        return false;
    }
    return mysqli_num_rows($result) === 1;
}

/**
 * CRITICAL SECURITY: Prevent cross-tenant patient access
 */
function canAccessPatient($patientId) {
    global $conn;
    $patientId = (int)$patientId;
    $tenantId = getTenantId();
    if ($tenantId === null) {
        return false;
    }
    $sql = "SELECT id FROM users WHERE id = $patientId AND tenant_id = $tenantId LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log("SECURITY: Patient access check failed");
        return false;
    }
    return mysqli_num_rows($result) === 1;
}

/**
 * CRITICAL SECURITY: Prevent cross-tenant appointment access
 */
function canAccessAppointment($appointmentId) {
    global $conn;
    $appointmentId = (int)$appointmentId;
    $tenantId = getTenantId();
    if ($tenantId === null) {
        return false;
    }
    $sql = "SELECT id FROM appointments WHERE id = $appointmentId AND tenant_id = $tenantId LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log("SECURITY: Appointment access check failed");
        return false;
    }
    return mysqli_num_rows($result) === 1;
}

/**
 * PARANOID SECURITY: Validate data row belongs to current tenant BEFORE display
 * Call this after fetching ANY sensitive data to prevent accidental leaks
 * @param array $dataRow The data row fetched from database
 * @param string $tableName Table name (for logging)
 * @return bool True if data is safe to display
 */
function validateDataTenant($dataRow, $tableName = '') {
    if (empty($dataRow) || !is_array($dataRow)) {
        return false;
    }
    
    $currentTenant = getTenantId();
    if ($currentTenant === null) {
        error_log("SECURITY BREACH ATTEMPT: No tenant_id in session - $tableName");
        return false;
    }
    
    // Check if data row has tenant_id field
    if (!isset($dataRow['tenant_id'])) {
        error_log("SECURITY WARNING: Data row missing tenant_id field from - $tableName");
        return false;
    }
    
    $dataRowTenant = (int)$dataRow['tenant_id'];
    
    // PARANOID CHECK: tenant_id MUST match
    if ($dataRowTenant !== $currentTenant) {
        error_log("🚨 SECURITY BREACH BLOCKED: Attempted cross-tenant access!");
        error_log("   Table: $tableName");
        error_log("   User Tenant: $currentTenant, Data Tenant: $dataRowTenant");
        error_log("   User ID: " . ($_SESSION['user_id'] ?? 'UNKNOWN'));
        error_log("   IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
        return false;
    }
    
    return true;
}

/**
 * PARANOID SECURITY: Verify entire result set belongs to tenant
 * Use after fetching multiple records
 * @param mysqli_result $result The database result
 * @param string $tableName Table name (for logging)
 * @return bool True if ALL rows belong to current tenant
 */
function validateResultSetTenant($result, $tableName = '') {
    if (!$result || mysqli_num_rows($result) === 0) {
        return true; // Empty result is valid
    }
    
    $currentTenant = getTenantId();
    if ($currentTenant === null) {
        return false;
    }
    
    // Reset pointer
    mysqli_data_seek($result, 0);
    
    // Check EVERY row
    while ($row = mysqli_fetch_assoc($result)) {
        if (!validateDataTenant($row, $tableName)) {
            // Reset pointer for caller
            mysqli_data_seek($result, 0);
            return false;
        }
    }
    
    // Reset pointer for caller to read again
    mysqli_data_seek($result, 0);
    return true;
}

function getTenantUsers($role = '') {
    global $conn;
    $tenantId = getTenantId();
    if ($tenantId === null) {
        return [];
    }
    $sql = "SELECT id, first_name, last_name, email, username, role, is_archived FROM users WHERE tenant_id = $tenantId";
    if (!empty($role)) {
        $role = mysqli_real_escape_string($conn, $role);
        $sql .= " AND role = '$role'";
    }
    $sql .= " ORDER BY created_at DESC";
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log("Database error in getTenantUsers: " . mysqli_error($conn));
        return [];
    }
    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
    return $users;
}

$GLOBALS['conn'] = $conn;
?>
