<?php
/**
 * Analytics System Verification Script
 * Run this to verify all analytics components are properly installed
 */

session_start();
require_once 'backend/config.php';

$checks = [];
$allPassed = true;

// Check 1: Analytics PHP file exists
$checks['analytics_file'] = [
    'name' => 'Analytics PHP Module',
    'path' => 'backend/analytics.php',
    'passed' => file_exists('backend/analytics.php')
];

// Check 2: Try to include analytics
$checks['analytics_include'] = [
    'name' => 'Analytics Require Test',
    'path' => 'backend/analytics.php',
    'passed' => @include_once('backend/analytics.php')
];

// Check 3: Database connection
$checks['db_connection'] = [
    'name' => 'Database Connection',
    'path' => 'Database: ' . $GLOBALS['db_name'] . ' @ ' . $GLOBALS['db_host'],
    'passed' => isset($conn) && $conn !== false
];

// Check 4: Login history table exists
if (isset($conn) && $conn) {
    $result = mysqli_query($conn, "SHOW TABLES LIKE 'login_history'");
    $checks['login_history_table'] = [
        'name' => 'Login History Table',
        'path' => 'Table: login_history',
        'passed' => mysqli_num_rows($result) > 0
    ];
    
    // Check 5: Activity logs table exists
    $result = mysqli_query($conn, "SHOW TABLES LIKE 'activity_logs'");
    $checks['activity_logs_table'] = [
        'name' => 'Activity Logs Table',
        'path' => 'Table: activity_logs',
        'passed' => mysqli_num_rows($result) > 0
    ];

    // Check 6: System metrics table exists
    $result = mysqli_query($conn, "SHOW TABLES LIKE 'system_metrics'");
    $checks['system_metrics_table'] = [
        'name' => 'System Metrics Table',
        'path' => 'Table: system_metrics',
        'passed' => mysqli_num_rows($result) > 0
    ];

    // Check 7: Login history has data
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM login_history");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $count = $row['count'] ?? 0;
        $checks['login_data'] = [
            'name' => 'Login History Data',
            'path' => "Records: $count",
            'passed' => true,
            'extra' => "Found $count login records"
        ];
    }
}

// Check 8: Login.php has analytics
$loginPhp = file_get_contents('login.php');
$checks['login_analytics'] = [
    'name' => 'Login.php Analytics Integration',
    'path' => 'login.php',
    'passed' => strpos($loginPhp, 'logLoginAttempt') !== false,
    'extra' => strpos($loginPhp, 'require_once') ? 'Analytics module required' : 'NOT FOUND'
];

// Check 9: Logout.php has analytics
$logoutPhp = file_get_contents('backend/logout.php');
$checks['logout_analytics'] = [
    'name' => 'Logout.php Analytics Integration',
    'path' => 'backend/logout.php',
    'passed' => strpos($logoutPhp, 'recordLogout') !== false,
    'extra' => 'Logout tracking enabled'
];

// Check 10: Super admin dashboard has analytics sections
$adminDash = file_get_contents('super-admin-dashboard.php');
$checks['admin_analytics_section'] = [
    'name' => 'Admin Dashboard Analytics Section',
    'path' => 'super-admin-dashboard.php',
    'passed' => strpos($adminDash, 'analytics-section') !== false
];

$checks['admin_login_history_section'] = [
    'name' => 'Admin Dashboard Login History Section',
    'path' => 'super-admin-dashboard.php',
    'passed' => strpos($adminDash, 'login-history-section') !== false
];

$checks['admin_chart_js'] = [
    'name' => 'Chart.js Library Included',
    'path' => 'super-admin-dashboard.php',
    'passed' => strpos($adminDash, 'chart.js') !== false
];

// Calculate results
foreach ($checks as $check) {
    if (!$check['passed']) {
        $allPassed = false;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Analytics System Verification</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 30px;
        }
        .status-badge {
            display: inline-block;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 600;
            text-align: center;
            font-size: 16px;
        }
        .status-badge.pass {
            background: #d1f4e8;
            color: #0e7490;
            border-left: 4px solid #0e7490;
        }
        .status-badge.fail {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }
        .checks {
            display: grid;
            gap: 12px;
        }
        .check-item {
            display: flex;
            align-items: flex-start;
            padding: 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f9fafb;
            transition: all 0.2s;
        }
        .check-item:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        .check-icon {
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            margin-right: 15px;
            font-size: 14px;
        }
        .check-icon.pass {
            background: #10b981;
        }
        .check-icon.fail {
            background: #ef4444;
        }
        .check-content {
            flex: 1;
        }
        .check-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
        }
        .check-path {
            font-size: 12px;
            color: #6b7280;
            font-family: monospace;
            margin-bottom: 4px;
        }
        .check-extra {
            font-size: 12px;
            color: #0e7490;
            font-style: italic;
        }
        .footer {
            background: #f9fafb;
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 13px;
            color: #6b7280;
        }
        .actions {
            padding: 20px 30px;
            background: #eff6ff;
            border-top: 1px solid #bfdbfe;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .summary {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .summary.all-pass {
            background: #d1f4e8;
            color: #0e7490;
            border-left: 4px solid #0e7490;
        }
        .summary.some-fail {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Analytics System Verification</h1>
            <p>Checking all components are properly installed and configured</p>
        </div>

        <div class="content">
            <div class="summary <?php echo $allPassed ? 'all-pass' : 'some-fail'; ?>">
                <?php if ($allPassed): ?>
                    ✅ All systems operational! Analytics dashboard is ready to use.
                <?php else: ?>
                    ⚠️ Some components need attention. See details below.
                <?php endif; ?>
            </div>

            <div class="checks">
                <?php foreach ($checks as $key => $check): ?>
                <div class="check-item">
                    <div class="check-icon <?php echo $check['passed'] ? 'pass' : 'fail'; ?>">
                        <?php echo $check['passed'] ? '✓' : '✕'; ?>
                    </div>
                    <div class="check-content">
                        <div class="check-name"><?php echo htmlspecialchars($check['name']); ?></div>
                        <div class="check-path"><?php echo htmlspecialchars($check['path']); ?></div>
                        <?php if (isset($check['extra'])): ?>
                            <div class="check-extra"><?php echo htmlspecialchars($check['extra']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="actions">
            <?php if (!$allPassed): ?>
                <a href="backend/create-analytics-tables.php" class="btn btn-primary">
                    🛠️ Create Missing Tables
                </a>
            <?php endif; ?>
            <a href="super-admin-dashboard.php" class="btn btn-primary">
                📊 Go to Dashboard
            </a>
            <a href="javascript:location.reload()" class="btn btn-secondary">
                🔄 Refresh Check
            </a>
        </div>

        <div class="footer">
            Analytics System v1.0 | Last checked: <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</body>
</html>
<?php
mysqli_close($conn);
?>
