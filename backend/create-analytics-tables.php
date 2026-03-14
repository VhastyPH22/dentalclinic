<?php
/**
 * Create Analytics Tables
 * Run this file once to initialize login_history and activity_logs tables
 * Access: http://localhost/sanfranciscosystem/backend/create-analytics-tables.php
 */

require_once 'config.php';

$errors = [];
$success = [];

// Create login_history table
$loginHistorySql = "CREATE TABLE IF NOT EXISTS login_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(255),
    email VARCHAR(255),
    role VARCHAR(50),
    tenant_id INT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    login_status ENUM('success', 'failed_password', 'failed_account_archived', 'failed_clinic_not_approved', 'failed_clinic_rejected', 'failed_clinic_inactive', 'failed_account_not_found', 'failed_patient_role') DEFAULT 'success',
    status_message VARCHAR(255),
    login_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    logout_timestamp DATETIME NULL,
    session_duration INT COMMENT 'Duration in seconds',
    browser_info VARCHAR(255),
    os_info VARCHAR(255),
    INDEX idx_user_id (user_id),
    INDEX idx_username (username),
    INDEX idx_login_status (login_status),
    INDEX idx_login_timestamp (login_timestamp),
    INDEX idx_ip_address (ip_address),
    INDEX idx_tenant_id (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if (mysqli_query($conn, $loginHistorySql)) {
    $success[] = "✓ login_history table created/verified";
} else {
    $errors[] = "✗ Error creating login_history table: " . mysqli_error($conn);
}

// Create activity_logs table
$activityLogsSql = "CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(255),
    role VARCHAR(50),
    tenant_id INT NULL,
    action_type VARCHAR(100),
    resource_type VARCHAR(100),
    resource_id INT NULL,
    resource_name VARCHAR(255),
    action_details TEXT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    change_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(50),
    error_message TEXT,
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_resource_type (resource_type),
    INDEX idx_tenant_id (tenant_id),
    INDEX idx_change_timestamp (change_timestamp),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if (mysqli_query($conn, $activityLogsSql)) {
    $success[] = "✓ activity_logs table created/verified";
} else {
    $errors[] = "✗ Error creating activity_logs table: " . mysqli_error($conn);
}

// Create system_metrics table
$systemMetricsSql = "CREATE TABLE IF NOT EXISTS system_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric_date DATE NOT NULL,
    total_clinics INT DEFAULT 0,
    approved_clinics INT DEFAULT 0,
    pending_clinics INT DEFAULT 0,
    rejected_clinics INT DEFAULT 0,
    archived_clinics INT DEFAULT 0,
    total_users INT DEFAULT 0,
    total_dentists INT DEFAULT 0,
    total_assistants INT DEFAULT 0,
    total_patients INT DEFAULT 0,
    total_appointments INT DEFAULT 0,
    completed_appointments INT DEFAULT 0,
    pending_appointments INT DEFAULT 0,
    cancelled_appointments INT DEFAULT 0,
    total_login_attempts INT DEFAULT 0,
    successful_logins INT DEFAULT 0,
    failed_logins INT DEFAULT 0,
    unique_active_users INT DEFAULT 0,
    total_revenue DECIMAL(10, 2) DEFAULT 0.00,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_date (metric_date),
    INDEX idx_metric_date (metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if (mysqli_query($conn, $systemMetricsSql)) {
    $success[] = "✓ system_metrics table created/verified";
} else {
    $errors[] = "✗ Error creating system_metrics table: " . mysqli_error($conn);
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Analytics Tables Setup</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        .success { color: #27ae60; background: #d5f4e6; padding: 10px; border-left: 4px solid #27ae60; margin: 10px 0; }
        .error { color: #e74c3c; background: #fadbd8; padding: 10px; border-left: 4px solid #e74c3c; margin: 10px 0; }
        h1 { color: #2c3e50; }
        .status { margin-top: 20px; padding: 15px; background: #ecf0f1; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Analytics Tables Setup</h1>
        
        <div class="status">
            <?php if (!empty($success)): ?>
                <h3>✓ Success</h3>
                <?php foreach ($success as $msg): ?>
                    <div class="success"><?php echo $msg; ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <h3>✗ Errors</h3>
                <?php foreach ($errors as $msg): ?>
                    <div class="error"><?php echo $msg; ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (empty($success) && empty($errors)): ?>
                <p>No tables needed creation. All tables already exist.</p>
            <?php endif; ?>
        </div>

        <h3>Tables Created:</h3>
        <ul>
            <li><strong>login_history</strong> - Tracks all login attempts (success/failure)</li>
            <li><strong>activity_logs</strong> - Logs all user actions across the system</li>
            <li><strong>system_metrics</strong> - Daily aggregated statistics for analytics</li>
        </ul>

        <p style="margin-top: 20px; font-size: 12px; color: #7f8c8d;">
            ℹ️ You can now delete this file or access the Analytics Dashboard at super-admin-dashboard.php
        </p>
    </div>
</body>
</html>
