<?php
/**
 * Analytics and Logging Utility Functions
 * Handles login history, activity logs, and system metrics tracking
 */

/**
 * Check if a table exists in the database
 */
function tableExists($connection, $tableName) {
    $result = mysqli_query($connection, "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableName' LIMIT 1");
    return $result && mysqli_num_rows($result) > 0;
}

/**
 * Log login attempt to login_history table
 */
function logLoginAttempt($username, $email, $userId = null, $role = null, $tenantId = null, $status = 'success', $statusMessage = '') {
    global $conn;
    
    // Check if login_history table exists before trying to log
    if (!tableExists($conn, 'login_history')) {
        return;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Parse browser and OS from user agent
    $browser_info = parseBrowserInfo($user_agent);
    $os_info = parseOSInfo($user_agent);
    
    $username = mysqli_real_escape_string($conn, $username);
    $email = mysqli_real_escape_string($conn, $email);
    $role = mysqli_real_escape_string($conn, $role ?? '');
    $status = mysqli_real_escape_string($conn, $status);
    $statusMessage = mysqli_real_escape_string($conn, $statusMessage);
    $browser_info = mysqli_real_escape_string($conn, $browser_info);
    $os_info = mysqli_real_escape_string($conn, $os_info);
    
    $userId = intval($userId);
    $tenantId = intval($tenantId);
    
    $sql = "INSERT INTO login_history (user_id, username, email, role, tenant_id, ip_address, user_agent, login_status, status_message, browser_info, os_info)
            VALUES ($userId, '$username', '$email', '$role', $tenantId, '$ip_address', '$user_agent', '$status', '$statusMessage', '$browser_info', '$os_info')";
    
    if (!mysqli_query($conn, $sql)) {
        error_log("Failed to log login attempt: " . mysqli_error($conn));
    }
}

/**
 * Log user activity to activity_logs table
 */
function logActivity($userId, $username, $role, $tenantId, $actionType, $resourceType, $resourceId = null, $resourceName = '', $actionDetails = '', $oldValues = null, $newValues = null) {
    global $conn;
    
    // Check if activity_logs table exists before trying to log
    if (!tableExists($conn, 'activity_logs')) {
        return;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $username = mysqli_real_escape_string($conn, $username);
    $role = mysqli_real_escape_string($conn, $role);
    $actionType = mysqli_real_escape_string($conn, $actionType);
    $resourceType = mysqli_real_escape_string($conn, $resourceType);
    $resourceName = mysqli_real_escape_string($conn, $resourceName);
    $actionDetails = mysqli_real_escape_string($conn, $actionDetails);
    
    $oldValuesJson = $oldValues ? mysqli_real_escape_string($conn, json_encode($oldValues)) : 'NULL';
    $newValuesJson = $newValues ? mysqli_real_escape_string($conn, json_encode($newValues)) : 'NULL';
    
    $userId = intval($userId);
    $tenantId = intval($tenantId);
    $resourceId = intval($resourceId);
    
    $oldValuesJson = $oldValues ? "'$oldValuesJson'" : 'NULL';
    $newValuesJson = $newValues ? "'$newValuesJson'" : 'NULL';
    
    $sql = "INSERT INTO activity_logs (user_id, username, role, tenant_id, action_type, resource_type, resource_id, resource_name, action_details, old_values, new_values, ip_address, status)
            VALUES ($userId, '$username', '$role', $tenantId, '$actionType', '$resourceType', $resourceId, '$resourceName', '$actionDetails', $oldValuesJson, $newValuesJson, '$ip_address', 'success')";
    
    if (!mysqli_query($conn, $sql)) {
        error_log("Failed to log activity: " . mysqli_error($conn));
    }
}

/**
 * Record session logout (update logout_timestamp)
 */
function recordLogout($userId) {
    global $conn;
    
    // Check if login_history table exists before trying to update
    if (!tableExists($conn, 'login_history')) {
        return;
    }
    
    $userId = intval($userId);
    
    // Find the most recent login session for this user
    $sql = "UPDATE login_history 
            SET logout_timestamp = NOW(), 
                session_duration = TIMESTAMPDIFF(SECOND, login_timestamp, NOW())
            WHERE user_id = $userId AND logout_timestamp IS NULL
            ORDER BY login_timestamp DESC
            LIMIT 1";
    
    mysqli_query($conn, $sql);
}

/**
 * Get login history for a specific user
 */
function getLoginHistory($userId, $limit = 20) {
    global $conn;
    
    if (!tableExists($conn, 'login_history')) {
        return [];
    }

    $userId = intval($userId);

    $sql = "SELECT * FROM login_history
            WHERE user_id = $userId 
            ORDER BY login_timestamp DESC 
            LIMIT $limit";
    
    $result = mysqli_query($conn, $sql);
    $history = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $history[] = $row;
    }
    
    return $history;
}

/**
 * Get failed login attempts in the last 24 hours
 */
function getFailedLoginAttempts($hours = 24) {
    global $conn;
    
    if (!tableExists($conn, 'login_history')) {
        return [];
    }

    $sql = "SELECT * FROM login_history
            WHERE login_status LIKE 'failed_%' 
            AND login_timestamp >= DATE_SUB(NOW(), INTERVAL $hours HOUR)
            ORDER BY login_timestamp DESC";
    
    $result = mysqli_query($conn, $sql);
    $attempts = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $attempts[] = $row;
    }
    
    return $attempts;
}

/**
 * Get failed login attempts grouped by IP address
 */
function getFailedLoginsByIP($hours = 24) {
    global $conn;
    
    if (!tableExists($conn, 'login_history')) {
        return [];
    }

    $sql = "SELECT
                ip_address, 
                COUNT(*) as attempt_count,
                GROUP_CONCAT(DISTINCT username) as usernames,
                MAX(login_timestamp) as last_attempt
            FROM login_history 
            WHERE login_status LIKE 'failed_%' 
            AND login_timestamp >= DATE_SUB(NOW(), INTERVAL $hours HOUR)
            GROUP BY ip_address
            ORDER BY attempt_count DESC
            LIMIT 20";
    
    $result = mysqli_query($conn, $sql);
    $data = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    return $data;
}

/**
 * Get failed login attempts grouped by username
 */
function getFailedLoginsByUsername($hours = 24) {
    global $conn;
    
    if (!tableExists($conn, 'login_history')) {
        return [];
    }

    $sql = "SELECT
                username, 
                COUNT(*) as attempt_count,
                GROUP_CONCAT(DISTINCT login_status) as failure_types,
                MAX(login_timestamp) as last_attempt
            FROM login_history 
            WHERE login_status LIKE 'failed_%' 
            AND login_timestamp >= DATE_SUB(NOW(), INTERVAL $hours HOUR)
            GROUP BY username
            ORDER BY attempt_count DESC
            LIMIT 20";
    
    $result = mysqli_query($conn, $sql);
    $data = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    return $data;
}

/**
 * Get activity logs for a specific tenant
 */
function getActivityLogs($tenantId, $limit = 50) {
    global $conn;

    if (!tableExists($conn, 'activity_logs')) {
        return [];
    }

    $tenantId = intval($tenantId);

    $sql = "SELECT * FROM activity_logs
            WHERE tenant_id = $tenantId 
            ORDER BY change_timestamp DESC 
            LIMIT $limit";
    
    $result = mysqli_query($conn, $sql);
    $logs = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }
    
    return $logs;
}

/**
 * Get monthly login statistics
 */
function getMonthlyLoginStats($months = 6) {
    global $conn;
    
    if (!tableExists($conn, 'login_history')) {
        return [];
    }

    $sql = "SELECT
                DATE_FORMAT(login_timestamp, '%Y-%m') as month,
                COUNT(*) as total_attempts,
                SUM(CASE WHEN login_status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN login_status LIKE 'failed_%' THEN 1 ELSE 0 END) as failed,
                COUNT(DISTINCT user_id) as unique_users
            FROM login_history
            WHERE login_timestamp >= DATE_SUB(NOW(), INTERVAL $months MONTH)
            GROUP BY DATE_FORMAT(login_timestamp, '%Y-%m')
            ORDER BY month ASC";
    
    $result = mysqli_query($conn, $sql);
    $stats = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $stats[] = $row;
    }
    
    return $stats;
}

/**
 * Get monthly appointment statistics
 */
function getMonthlyAppointmentStats($months = 6) {
    global $conn;

    $sql = "SELECT
                DATE_FORMAT(appointment_date, '%Y-%m') as month,
                COUNT(*) as total_appointments,
                SUM(CASE WHEN status_id = 3 THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status_id = 2 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status_id = 7 THEN 1 ELSE 0 END) as cancelled
            FROM appointments
            WHERE appointment_date >= DATE_SUB(NOW(), INTERVAL $months MONTH)
            GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
            ORDER BY month ASC";

    $result = mysqli_query($conn, $sql);
    $stats = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $stats[] = $row;
    }
    
    return $stats;
}

/**
 * Get appointments per clinic (for activity analysis)
 */
function getAppointmentsPerClinic() {
    global $conn;
    
    $sql = "SELECT 
                t.id,
                t.clinic_name,
                COUNT(a.id) as total_appointments,
                SUM(CASE WHEN a.status_id = 3 THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN a.status_id = 2 THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN a.appointment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_30_days,
                t.status,
                t.created_at
            FROM tenants t
            LEFT JOIN appointments a ON t.id = a.tenant_id
            WHERE t.is_archived = 0
            GROUP BY t.id, t.clinic_name, t.status, t.created_at
            ORDER BY total_appointments DESC";

    $result = mysqli_query($conn, $sql);
    $data = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    
    return $data;
}

/**
 * Get active vs inactive clinics
 */
function getClinicActivityMetrics() {
    global $conn;
    
    // High activity: 50+ appointments
    // Medium activity: 10-49 appointments
    // Low activity: 1-9 appointments
    // No activity: 0 appointments
    
    $sql = "SELECT 
                COUNT(CASE WHEN a.appt_count >= 50 THEN 1 END) as high_activity,
                COUNT(CASE WHEN a.appt_count BETWEEN 10 AND 49 THEN 1 END) as medium_activity,
                COUNT(CASE WHEN a.appt_count BETWEEN 1 AND 9 THEN 1 END) as low_activity,
                COUNT(CASE WHEN a.appt_count = 0 THEN 1 END) as no_activity
            FROM (
                SELECT t.id, COUNT(appt.id) as appt_count
                FROM tenants t
                LEFT JOIN appointments appt ON t.id = appt.tenant_id
                WHERE t.is_archived = 0 AND t.status = 'approved'
                GROUP BY t.id
            ) a";
    
    $result = mysqli_query($conn, $sql);
    return mysqli_fetch_assoc($result);
}

/**
 * Get system growth trends (new clinics, patients per month)
 */
function getGrowthTrends($months = 12) {
    global $conn;

    $sql = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COALESCE(SUM(CASE WHEN type = 'clinic' THEN 1 ELSE 0 END), 0) as new_clinics,
                COALESCE(SUM(CASE WHEN type = 'patient' THEN 1 ELSE 0 END), 0) as new_patients
            FROM (
                SELECT t.created_at, 'clinic' as type FROM tenants t WHERE t.is_archived = 0
                UNION ALL
                SELECT u.created_at, 'patient' FROM users u
                LEFT JOIN patient_profiles p ON u.id = p.user_id
                WHERE p.user_id IS NOT NULL
            ) combined_data
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL $months MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC";

    $result = mysqli_query($conn, $sql);
    $trends = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $trends[] = $row;
    }

    return $trends;
}

/**
 * Get role-based user breakdown
 */
function getRoleBreakdown() {
    global $conn;
    
    $sql = "SELECT 
                role,
                COUNT(*) as count
            FROM users
            WHERE is_archived = 0
            GROUP BY role
            ORDER BY count DESC";
    
    $result = mysqli_query($conn, $sql);
    $breakdown = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $breakdown[] = $row;
    }
    
    return $breakdown;
}

/**
 * Get tenant isolation audit (check for potential breaches)
 */
function getTenantIsolationAudit() {
    global $conn;
    
    // Look for users accessing data from multiple tenants
    $sql = "SELECT 
                u.id,
                u.username,
                u.role,
                COUNT(DISTINCT CASE WHEN al.tenant_id IS NOT NULL THEN al.tenant_id END) as accessed_tenants,
                MAX(al.change_timestamp) as last_access
            FROM users u
            LEFT JOIN activity_logs al ON u.id = al.user_id
            WHERE u.role != 'super_admin'
            GROUP BY u.id, u.username, u.role
            HAVING accessed_tenants > 1
            ORDER BY accessed_tenants DESC";
    
    $result = mysqli_query($conn, $sql);
    $audit = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $audit[] = $row;
    }
    
    return $audit;
}

/**
 * Parse browser info from user agent string
 */
function parseBrowserInfo($userAgent) {
    if (preg_match('/Chrome\/([0-9]+)/', $userAgent, $match)) {
        return "Chrome {$match[1]}";
    } elseif (preg_match('/Firefox\/([0-9]+)/', $userAgent, $match)) {
        return "Firefox {$match[1]}";
    } elseif (preg_match('/Safari\/([0-9]+)/', $userAgent, $match)) {
        return "Safari {$match[1]}";
    } elseif (preg_match('/MSIE ([0-9]+)/', $userAgent, $match)) {
        return "IE {$match[1]}";
    } elseif (preg_match('/Edg\/([0-9]+)/', $userAgent, $match)) {
        return "Edge {$match[1]}";
    } else {
        return "Unknown Browser";
    }
}

/**
 * Parse OS info from user agent string
 */
function parseOSInfo($userAgent) {
    if (preg_match('/Windows NT 10/', $userAgent)) {
        return "Windows 10/11";
    } elseif (preg_match('/Windows NT 6\.1/', $userAgent)) {
        return "Windows 7";
    } elseif (preg_match('/Mac OS X/', $userAgent)) {
        return "macOS";
    } elseif (preg_match('/Linux/', $userAgent)) {
        return "Linux";
    } elseif (preg_match('/iPhone|iPad/', $userAgent)) {
        return "iOS";
    } elseif (preg_match('/Android/', $userAgent)) {
        return "Android";
    } else {
        return "Unknown OS";
    }
}

?>
