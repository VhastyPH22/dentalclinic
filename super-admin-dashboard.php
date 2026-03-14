<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/analytics.php';

// Check if user is logged in and has super_admin role
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userRole = strtolower(trim($_SESSION['role'] ?? ''));
$userRole = str_replace('-', '_', $userRole);

if ($userRole !== 'super_admin') {
    header('Location: login.php');
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle clinic CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $clinic_id = (int)$_POST['clinic_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $updateSql = "UPDATE tenants SET status = 'approved', is_active = 1, updated_at = NOW() WHERE id = $clinic_id";
        if (mysqli_query($conn, $updateSql)) {
            $success = "Clinic approved successfully!";
        } else {
            $error = "Error approving clinic: " . mysqli_error($conn);
        }
    } elseif ($action === 'reject') {
        $rejection_reason = mysqli_real_escape_string($conn, $_POST['rejection_reason'] ?? 'No reason provided');
        $updateSql = "UPDATE tenants SET status = 'rejected', is_active = 0, updated_at = NOW() WHERE id = $clinic_id";
        if (mysqli_query($conn, $updateSql)) {
            $success = "Clinic rejected successfully!";
        } else {
            $error = "Error rejecting clinic: " . mysqli_error($conn);
        }
    } elseif ($action === 'archive') {
        $updateSql = "UPDATE tenants SET is_archived = 1, updated_at = NOW() WHERE id = $clinic_id";
        if (mysqli_query($conn, $updateSql)) {
            $success = "Clinic archived successfully!";
        } else {
            $error = "Error archiving clinic: " . mysqli_error($conn);
        }
    } elseif ($action === 'restore') {
        $updateSql = "UPDATE tenants SET is_archived = 0, updated_at = NOW() WHERE id = $clinic_id";
        if (mysqli_query($conn, $updateSql)) {
            $success = "Clinic restored successfully!";
        } else {
            $error = "Error restoring clinic: " . mysqli_error($conn);
        }
    } elseif ($action === 'delete') {
        $deleteSql = "DELETE FROM tenants WHERE id = $clinic_id";
        if (mysqli_query($conn, $deleteSql)) {
            $success = "Clinic deleted permanently!";
        } else {
            $error = "Error deleting clinic: " . mysqli_error($conn);
        }
    } elseif ($action === 'edit') {
        $clinic_name = mysqli_real_escape_string($conn, $_POST['clinic_name'] ?? '');
        $clinic_email = mysqli_real_escape_string($conn, $_POST['clinic_email'] ?? '');
        $clinic_phone = mysqli_real_escape_string($conn, $_POST['clinic_phone'] ?? '');
        $clinic_code = mysqli_real_escape_string($conn, $_POST['clinic_code'] ?? '');
        $clinic_address = mysqli_real_escape_string($conn, $_POST['clinic_address'] ?? '');
        
        if (!$clinic_name || !$clinic_email || !$clinic_phone) {
            $error = "Please fill in all required fields!";
        } else {
            $updateSql = "UPDATE tenants SET clinic_name='$clinic_name', clinic_email='$clinic_email', clinic_phone='$clinic_phone', clinic_code='$clinic_code', clinic_address='$clinic_address', updated_at=NOW() WHERE id=$clinic_id";
            if (mysqli_query($conn, $updateSql)) {
                $success = "Clinic updated successfully!";
            } else {
                $error = "Error updating clinic: " . mysqli_error($conn);
            }
        }
    }
}

// Get all clinics (archived and non-archived) for display in all sections
$clinicsSql = "SELECT t.*, u.first_name as owner_first_name, u.last_name as owner_last_name, u.email as owner_email FROM tenants t LEFT JOIN users u ON t.owner_id = u.id ORDER BY t.created_at DESC";

$clinicsResult = mysqli_query($conn, $clinicsSql);
$clinics = [];
if ($clinicsResult) {
    while ($row = mysqli_fetch_assoc($clinicsResult)) {
        $clinics[] = $row;
    }
}

// Get statistics
$pendingSql = "SELECT COUNT(*) as count FROM tenants WHERE status = 'pending' AND is_archived = 0";
$pendingResult = mysqli_fetch_assoc(mysqli_query($conn, $pendingSql));
$pendingCount = $pendingResult['count'];

$approvedSql = "SELECT COUNT(*) as count FROM tenants WHERE status = 'approved' AND is_archived = 0";
$approvedResult = mysqli_fetch_assoc(mysqli_query($conn, $approvedSql));
$approvedCount = $approvedResult['count'];

$rejectedSql = "SELECT COUNT(*) as count FROM tenants WHERE status = 'rejected' AND is_archived = 0";
$rejectedResult = mysqli_fetch_assoc(mysqli_query($conn, $rejectedSql));
$rejectedCount = $rejectedResult['count'];

$archivedSql = "SELECT COUNT(*) as count FROM tenants WHERE is_archived = 1";
$archivedResult = mysqli_fetch_assoc(mysqli_query($conn, $archivedSql));
$archivedCount = $archivedResult['count'];

// System-wide statistics
$totalClinicsSql = "SELECT COUNT(*) as count FROM tenants WHERE is_archived = 0";
$totalClinicsResult = mysqli_fetch_assoc(mysqli_query($conn, $totalClinicsSql));
$totalClinics = $totalClinicsResult['count'];

$totalUsersSql = "SELECT COUNT(*) as count FROM users";
$totalUsersResult = mysqli_fetch_assoc(mysqli_query($conn, $totalUsersSql));
$totalUsers = $totalUsersResult['count'];

$totalAppointmentsSql = "SELECT COUNT(*) as count FROM appointments";
$totalAppointmentsResult = mysqli_fetch_assoc(mysqli_query($conn, $totalAppointmentsSql));
$totalAppointments = $totalAppointmentsResult['count'];

// Get recent appointments
$recentAppointmentsSql = "SELECT a.*, t.clinic_name FROM appointments a LEFT JOIN tenants t ON a.tenant_id = t.id ORDER BY a.appointment_date DESC LIMIT 10";
$recentAppointmentsResult = mysqli_query($conn, $recentAppointmentsSql);
$recentAppointments = [];
if ($recentAppointmentsResult) {
    while ($row = mysqli_fetch_assoc($recentAppointmentsResult)) {
        $recentAppointments[] = $row;
    }
}

// Get all users
$allUsersSql = "SELECT id, first_name, last_name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 20";
$allUsersResult = mysqli_query($conn, $allUsersSql);
$allUsers = [];
if ($allUsersResult) {
    while ($row = mysqli_fetch_assoc($allUsersResult)) {
        $allUsers[] = $row;
    }
}

// Get login history if table exists
$loginHistory = [];
$failedLogins24h = 0;
if (tableExists($conn, 'login_history')) {
    $loginHistorySql = "SELECT * FROM login_history ORDER BY login_timestamp DESC LIMIT 30";
    $loginHistoryResult = mysqli_query($conn, $loginHistorySql);
    if ($loginHistoryResult) {
        while ($row = mysqli_fetch_assoc($loginHistoryResult)) {
            $loginHistory[] = $row;
        }
    }
    
    // Get failed login attempts in last 24 hours
    $failedLoginsSql = "SELECT COUNT(*) as count FROM login_history WHERE login_status = 'failed' AND login_timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    $failedLoginsResult = mysqli_fetch_assoc(mysqli_query($conn, $failedLoginsSql));
    $failedLogins24h = $failedLoginsResult['count'] ?? 0;
}

// Get system logs if table exists
$systemLogs = [];
if (tableExists($conn, 'system_logs')) {
    $systemLogsSql = "SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 30";
    $systemLogsResult = mysqli_query($conn, $systemLogsSql);
    if ($systemLogsResult) {
        while ($row = mysqli_fetch_assoc($systemLogsResult)) {
            $systemLogs[] = $row;
        }
    }
}

// Get active sessions count (from login_history if it exists)
$activeSessions = 0;
if (tableExists($conn, 'login_history')) {
    $activeSessionsSql = "SELECT COUNT(DISTINCT user_id) as count FROM login_history WHERE login_status = 'success' AND login_timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    $activeSessionsResult = mysqli_fetch_assoc(mysqli_query($conn, $activeSessionsSql));
    $activeSessions = $activeSessionsResult['count'] ?? 0;
}

// Get clinic status breakdown
$statusBreakdownSql = "SELECT status, COUNT(*) as count FROM tenants WHERE is_archived = 0 GROUP BY status";
$statusBreakdownResult = mysqli_query($conn, $statusBreakdownSql);
$statusBreakdown = [];
if ($statusBreakdownResult) {
    while ($row = mysqli_fetch_assoc($statusBreakdownResult)) {
        $statusBreakdown[] = $row;
    }
}

// Get user role breakdown
$roleBreakdownSql = "SELECT role, COUNT(*) as count FROM users GROUP BY role ORDER BY count DESC";
$roleBreakdownResult = mysqli_query($conn, $roleBreakdownSql);
$roleBreakdown = [];
if ($roleBreakdownResult) {
    while ($row = mysqli_fetch_assoc($roleBreakdownResult)) {
        $roleBreakdown[] = $row;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Super Admin - Clinic Approvals | San Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brandBlue: '#2C3E50',
                        brandGold: '#D4AF37',
                    },
                    fontFamily: {
                        sans: ['Montserrat', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Montserrat', sans-serif; }
    </style>
</head>
<body class="bg-slate-50">
<div class="h-screen flex overflow-hidden">
    <!-- SIDEBAR -->
    <aside class="w-64 bg-slate-900 text-white flex flex-col">
        <div class="p-6 border-b border-slate-700">
            <p class="text-brandGold text-sm font-bold">System Admin</p>
        </div>

        <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
            <div class="text-xs font-bold text-slate-400 uppercase px-4 py-2">Clinic Management</div>
            <button onclick="switchSection('clinics')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                All Clinics
            </button>
            <button onclick="switchSection('pending')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                Pending (<?php echo $pendingCount; ?>)
            </button>
            <button onclick="switchSection('approved')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                Approved (<?php echo $approvedCount; ?>)
            </button>
            <button onclick="switchSection('rejected')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                Rejected (<?php echo $rejectedCount; ?>)
            </button>
            <button onclick="switchSection('archived')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                Archived (<?php echo $archivedCount; ?>)
            </button>

            <div class="text-xs font-bold text-slate-400 uppercase px-4 py-2 mt-6">System Monitoring</div>
            <button onclick="switchSection('monitoring')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                📊 Dashboard
            </button>
            <button onclick="switchSection('users')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                👥 Users
            </button>
            <button onclick="switchSection('appointments')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                📅 Appointments
            </button>

            <div class="text-xs font-bold text-slate-400 uppercase px-4 py-2 mt-6">Security & Logs</div>
            <button onclick="switchSection('login-history')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                🔑 Login History
            </button>
            <button onclick="switchSection('system-logs')" class="w-full text-left px-4 py-2 rounded hover:bg-slate-800 text-slate-300 hover:text-brandGold transition">
                📋 System Logs
            </button>
        </nav>

        <div class="p-4 border-t border-slate-700">
            <a href="?logout=1" class="block px-4 py-2 rounded hover:bg-red-900 text-slate-300 hover:text-red-400 transition">Logout</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="flex-1 overflow-y-auto bg-slate-50">
        <header class="bg-white border-b border-gray-200 px-8 py-4 shadow-sm">
            <h2 class="text-2xl font-bold text-slate-900" id="pageTitle">Clinic Management</h2>
            <p class="text-sm text-slate-500">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Super Admin'); ?></p>
        </header>

        <div class="p-8">
            <?php if (!empty($success)): ?>
            <div class="mb-6 p-4 rounded-lg bg-green-50 border border-green-300 text-green-700 font-semibold">
                ✓ <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
            <div class="mb-6 p-4 rounded-lg bg-red-50 border border-red-300 text-red-700 font-semibold">
                ✗ <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- CLINICS SECTION -->
            <div id="clinics-section" class="section">
                <!-- Stats Cards -->
                <div class="grid grid-cols-4 gap-4 mb-8">
                    <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                        <p class="text-slate-500 text-sm font-medium">Total Clinics</p>
                        <p class="text-3xl font-bold text-slate-900"><?php echo $totalClinics; ?></p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                        <p class="text-slate-500 text-sm font-medium">Pending</p>
                        <p class="text-3xl font-bold text-yellow-600"><?php echo $pendingCount; ?></p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                        <p class="text-slate-500 text-sm font-medium">Approved</p>
                        <p class="text-3xl font-bold text-green-600"><?php echo $approvedCount; ?></p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                        <p class="text-slate-500 text-sm font-medium">Other</p>
                        <p class="text-3xl font-bold text-red-600"><?php echo $rejectedCount + $archivedCount; ?></p>
                    </div>
                </div>

                <!-- Clinics List -->
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <h3 class="text-xl font-bold text-slate-900 mb-6">All Clinics</h3>
                    
                    <?php $activeClinics = array_filter($clinics, fn($c) => $c['is_archived'] == 0); ?>
                    <?php if (count($activeClinics) > 0): ?>
                        <?php foreach ($activeClinics as $clinic): ?>
                        <div class="p-4 border border-gray-200 rounded-lg mb-4 hover:shadow-md transition">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <h4 class="text-lg font-bold text-slate-900"><?php echo htmlspecialchars($clinic['clinic_name']); ?></h4>
                                    <p class="text-sm text-slate-500"><?php echo htmlspecialchars($clinic['clinic_code']); ?></p>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-bold <?php 
                                    if ($clinic['status'] === 'pending') echo 'bg-yellow-100 text-yellow-800';
                                    elseif ($clinic['status'] === 'approved') echo 'bg-green-100 text-green-800';
                                    else echo 'bg-red-100 text-red-800';
                                ?>">
                                    <?php echo strtoupper($clinic['status']); ?>
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3 mb-4 text-sm">
                                <div>
                                    <p class="text-slate-500">Email</p>
                                    <p class="font-semibold"><?php echo htmlspecialchars($clinic['clinic_email']); ?></p>
                                </div>
                                <div>
                                    <p class="text-slate-500">Phone</p>
                                    <p class="font-semibold"><?php echo htmlspecialchars($clinic['clinic_phone']); ?></p>
                                </div>
                                <div>
                                    <p class="text-slate-500">Owner</p>
                                    <p class="font-semibold"><?php echo htmlspecialchars($clinic['owner_first_name'] . ' ' . $clinic['owner_last_name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-slate-500">Owner Email</p>
                                    <p class="font-semibold"><?php echo htmlspecialchars($clinic['owner_email']); ?></p>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-2 mb-3">
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($clinic)); ?>)" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded transition">✏️ Edit</button>
                                <?php if ($clinic['status'] === 'pending'): ?>
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="clinic_id" value="<?php echo $clinic['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded transition">✓ Approve</button>
                                </form>
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="clinic_id" value="<?php echo $clinic['id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition">✗ Reject</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="clinic_id" value="<?php echo $clinic['id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo $clinic['is_archived'] ? 'restore' : 'archive'; ?>">
                                    <button type="submit" class="w-full <?php echo $clinic['is_archived'] ? 'bg-amber-600 hover:bg-amber-700' : 'bg-orange-600 hover:bg-orange-700'; ?> text-white font-bold py-2 px-4 rounded transition">
                                        <?php echo $clinic['is_archived'] ? '↩️ Restore' : '📦 Archive'; ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-slate-500 text-center py-8">No active clinics found</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SYSTEM MONITORING SECTION -->
            <div id="monitoring-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">System Dashboard</h3>
                
                <!-- Key Metrics -->
                <div class="grid grid-cols-4 gap-4 mb-8">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-6 rounded-lg border border-blue-200">
                        <p class="text-blue-700 text-sm font-bold uppercase">Total Clinics</p>
                        <p class="text-4xl font-bold text-blue-900 mt-2"><?php echo $totalClinics; ?></p>
                        <p class="text-xs text-blue-600 mt-1">Active & Archived</p>
                    </div>
                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-6 rounded-lg border border-purple-200">
                        <p class="text-purple-700 text-sm font-bold uppercase">Total Users</p>
                        <p class="text-4xl font-bold text-purple-900 mt-2"><?php echo $totalUsers; ?></p>
                        <p class="text-xs text-purple-600 mt-1">All Roles</p>
                    </div>
                    <div class="bg-gradient-to-br from-green-50 to-green-100 p-6 rounded-lg border border-green-200">
                        <p class="text-green-700 text-sm font-bold uppercase">Total Appointments</p>
                        <p class="text-4xl font-bold text-green-900 mt-2"><?php echo $totalAppointments; ?></p>
                        <p class="text-xs text-green-600 mt-1">All Clinics</p>
                    </div>
                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 p-6 rounded-lg border border-orange-200">
                        <p class="text-orange-700 text-sm font-bold uppercase">Active Sessions</p>
                        <p class="text-4xl font-bold text-orange-900 mt-2"><?php echo $activeSessions; ?></p>
                        <p class="text-xs text-orange-600 mt-1">Last 1 Hour</p>
                    </div>
                </div>

                <!-- Clinic Status Breakdown -->
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm mb-6">
                    <h4 class="text-lg font-bold text-slate-900 mb-4">Clinic Status Breakdown</h4>
                    <div class="grid grid-cols-4 gap-4">
                        <div class="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                            <p class="text-yellow-700 text-sm font-semibold">Pending</p>
                            <p class="text-2xl font-bold text-yellow-900 mt-1"><?php echo $pendingCount; ?></p>
                        </div>
                        <div class="p-4 bg-green-50 rounded-lg border border-green-200">
                            <p class="text-green-700 text-sm font-semibold">Approved</p>
                            <p class="text-2xl font-bold text-green-900 mt-1"><?php echo $approvedCount; ?></p>
                        </div>
                        <div class="p-4 bg-red-50 rounded-lg border border-red-200">
                            <p class="text-red-700 text-sm font-semibold">Rejected</p>
                            <p class="text-2xl font-bold text-red-900 mt-1"><?php echo $rejectedCount; ?></p>
                        </div>
                        <div class="p-4 bg-orange-50 rounded-lg border border-orange-200">
                            <p class="text-orange-700 text-sm font-semibold">Archived</p>
                            <p class="text-2xl font-bold text-orange-900 mt-1"><?php echo $archivedCount; ?></p>
                        </div>
                    </div>
                </div>

                <!-- User Role Breakdown -->
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <h4 class="text-lg font-bold text-slate-900 mb-4">Users by Role</h4>
                    <div class="grid grid-cols-5 gap-3">
                        <?php foreach ($roleBreakdown as $role): ?>
                        <div class="p-4 bg-slate-50 rounded-lg border border-slate-200">
                            <p class="text-slate-700 text-sm font-semibold capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $role['role'])); ?></p>
                            <p class="text-2xl font-bold text-slate-900 mt-1"><?php echo $role['count']; ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- USERS SECTION -->
            <div id="users-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">User Management</h3>
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-300 bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Name</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Email</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Role</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Registered</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($allUsers as $user): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                <td class="px-4 py-3 text-slate-600"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded text-xs font-semibold bg-slate-100 text-slate-700">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-600"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- APPOINTMENTS SECTION -->
            <div id="appointments-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">Recent Appointments</h3>
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-300 bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Clinic</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Date</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($recentAppointments as $appt): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars($appt['clinic_name'] ?? 'N/A'); ?></td>
                                <td class="px-4 py-3 text-slate-600"><?php echo date('M d, Y g:i A', strtotime($appt['appointment_date'])); ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded text-xs font-semibold bg-blue-100 text-blue-700">
                                        <?php echo htmlspecialchars(ucfirst($appt['status'] ?? 'pending')); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentAppointments)): ?>
                            <tr>
                                <td colspan="3" class="px-4 py-8 text-center text-slate-500">No appointments yet</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- LOGIN HISTORY SECTION -->
            <div id="login-history-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">🔐 Login History & Security</h3>
                
                <!-- Security Alert -->
                <div class="bg-red-50 border border-red-300 p-4 rounded-lg mb-6">
                    <p class="text-red-700 font-semibold">Failed Login Attempts (Last 24 Hours): <span class="text-2xl font-bold"><?php echo $failedLogins24h; ?></span></p>
                </div>

                <!-- Login History Table -->
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <h4 class="text-lg font-bold text-slate-900 mb-4">Recent Login Activity</h4>
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-300 bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Username</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Status</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">IP Address</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach (array_slice($loginHistory, 0, 20) as $login): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars($login['username']); ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded text-xs font-semibold <?php echo $login['login_status'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                        <?php echo ucfirst($login['login_status']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-slate-600 text-xs"><?php echo htmlspecialchars($login['ip_address']); ?></td>
                                <td class="px-4 py-3 text-slate-600"><?php echo date('M d, Y g:i A', strtotime($login['login_timestamp'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($loginHistory)): ?>
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500">No login history available</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SYSTEM LOGS SECTION -->
            <div id="system-logs-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">📋 System Logs</h3>
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-300 bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Type</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Description</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">User</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-700">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach (array_slice($systemLogs, 0, 30) as $log): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <?php 
                                    $logType = $log['log_type'] ?? 'info';
                                    $badgeClass = 'bg-slate-100 text-slate-700';
                                    if (strpos($logType, 'error') !== false) $badgeClass = 'bg-red-100 text-red-700';
                                    elseif (strpos($logType, 'warning') !== false) $badgeClass = 'bg-yellow-100 text-yellow-700';
                                    elseif (strpos($logType, 'success') !== false) $badgeClass = 'bg-green-100 text-green-700';
                                    ?>
                                    <span class="px-2 py-1 rounded text-xs font-semibold <?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars(ucfirst($logType)); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-900"><?php echo htmlspecialchars($log['description'] ?? ''); ?></td>
                                <td class="px-4 py-3 text-slate-600"><?php echo htmlspecialchars($log['user_id'] ?? 'System'); ?></td>
                                <td class="px-4 py-3 text-slate-600"><?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($systemLogs)): ?>
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500">No system logs available</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PENDING SECTION (for filter=pending) -->
            <div id="pending-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">Pending Clinic Approvals</h3>
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <?php $pendingClinics = array_filter($clinics, fn($c) => $c['status'] === 'pending'); ?>
                    <?php if (count($pendingClinics) > 0): ?>
                        <?php foreach ($pendingClinics as $clinic): ?>
                        <div class="p-4 border border-gray-200 rounded-lg mb-4 hover:shadow-md transition">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <h4 class="text-lg font-bold text-slate-900"><?php echo htmlspecialchars($clinic['clinic_name']); ?></h4>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-800">PENDING</span>
                            </div>
                            <div class="grid grid-cols-2 gap-3 mb-4 text-sm">
                                <div><p class="text-slate-500">Email:</p><p class="font-semibold"><?php echo htmlspecialchars($clinic['clinic_email']); ?></p></div>
                                <div><p class="text-slate-500">Phone:</p><p class="font-semibold"><?php echo htmlspecialchars($clinic['clinic_phone']); ?></p></div>
                            </div>
                            <form method="POST" class="flex gap-2">
                                <input type="hidden" name="clinic_id" value="<?php echo $clinic['id']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded transition">✓ Approve</button>
                                <button form="reject-<?php echo $clinic['id']; ?>" type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition">✗ Reject</button>
                            </form>
                            <form id="reject-<?php echo $clinic['id']; ?>" method="POST" style="display:none;"><input type="hidden" name="clinic_id" value="<?php echo $clinic['id']; ?>"><input type="hidden" name="action" value="reject"></form>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center text-slate-500 py-8">No pending clinics</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- APPROVED SECTION -->
            <div id="approved-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">Approved Clinics</h3>
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <?php $approvedClinics = array_filter($clinics, fn($c) => $c['status'] === 'approved'); ?>
                    <?php if (count($approvedClinics) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($approvedClinics as $clinic): ?>
                            <div class="p-4 border border-green-200 rounded-lg bg-green-50">
                                <h4 class="font-bold text-slate-900"><?php echo htmlspecialchars($clinic['clinic_name']); ?></h4>
                                <p class="text-sm text-slate-600"><?php echo htmlspecialchars($clinic['clinic_email']); ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-slate-500 py-8">No approved clinics</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- REJECTED SECTION -->
            <div id="rejected-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">Rejected Clinics</h3>
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <?php $rejectedClinics = array_filter($clinics, fn($c) => $c['status'] === 'rejected'); ?>
                    <?php if (count($rejectedClinics) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($rejectedClinics as $clinic): ?>
                            <div class="p-4 border border-red-200 rounded-lg bg-red-50">
                                <h4 class="font-bold text-slate-900"><?php echo htmlspecialchars($clinic['clinic_name']); ?></h4>
                                <p class="text-sm text-slate-600"><?php echo htmlspecialchars($clinic['clinic_email']); ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-slate-500 py-8">No rejected clinics</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ARCHIVED SECTION -->
            <div id="archived-section" class="section hidden">
                <h3 class="text-2xl font-bold text-slate-900 mb-6">Archived Clinics</h3>
                <div class="bg-white p-6 rounded-lg border border-gray-200 shadow-sm">
                    <?php $archivedClinics = array_filter($clinics, fn($c) => $c['is_archived'] == 1); ?>
                    <?php if (count($archivedClinics) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($archivedClinics as $clinic): ?>
                            <div class="p-4 border border-orange-200 rounded-lg bg-orange-50">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <h4 class="font-bold text-slate-900"><?php echo htmlspecialchars($clinic['clinic_name']); ?></h4>
                                        <p class="text-sm text-slate-600"><?php echo htmlspecialchars($clinic['clinic_email']); ?></p>
                                    </div>
                                    <span class="px-2 py-1 rounded text-xs font-bold bg-orange-200 text-orange-800">ARCHIVED</span>
                                </div>
                                <div class="flex gap-2 mt-3">
                                    <form method="POST" class="flex-1">
                                        <input type="hidden" name="clinic_id" value="<?php echo $clinic['id']; ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="w-full bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-4 rounded transition">✓ Restore</button>
                                    </form>
                                    <form method="POST" class="flex-1" onsubmit="return confirm('Are you sure? This will permanently delete the clinic.');">
                                        <input type="hidden" name="clinic_id" value="<?php echo $clinic['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition">🗑️ Delete</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-slate-500 py-8">No archived clinics</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- EDIT CLINIC MODAL -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-2xl font-bold text-slate-900 mb-4">Edit Clinic Information</h3>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="clinic_id" id="editClinicId">
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic Name *</label>
                <input type="text" name="clinic_name" id="editClinicName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic Code</label>
                <input type="text" name="clinic_code" id="editClinicCode" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Email *</label>
                <input type="email" name="clinic_email" id="editClinicEmail" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Phone *</label>
                <input type="tel" name="clinic_phone" id="editClinicPhone" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Address</label>
                <textarea name="clinic_address" id="editClinicAddress" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
            </div>
            
            <div class="flex gap-2 pt-4">
                <button type="button" onclick="closeEditModal()" class="flex-1 bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-4 rounded transition">Cancel</button>
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(clinic) {
    document.getElementById('editClinicId').value = clinic.id;
    document.getElementById('editClinicName').value = clinic.clinic_name || '';
    document.getElementById('editClinicCode').value = clinic.clinic_code || '';
    document.getElementById('editClinicEmail').value = clinic.clinic_email || '';
    document.getElementById('editClinicPhone').value = clinic.clinic_phone || '';
    document.getElementById('editClinicAddress').value = clinic.clinic_address || '';
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeEditModal();
    }
});

function switchSection(section) {
    console.log('switchSection() called with: ' + section);
    
    // Hide all sections
    const sections = document.querySelectorAll('.section');
    sections.forEach(function(el) {
        el.classList.add('hidden');
    });
    
    // Map section IDs to title text
    const titles = {
        'clinics': 'Clinic Management',
        'pending': 'Pending Clinic Approvals',
        'approved': 'Approved Clinics',
        'rejected': 'Rejected Clinics',
        'archived': 'Archived Clinics',
        'monitoring': 'System Dashboard',
        'users': 'User Management',
        'appointments': 'Recent Appointments',
        'login-history': 'Login History & Security',
        'system-logs': 'System Logs'
    };
    
    // Update page title
    const pageTitle = document.getElementById('pageTitle');
    if (pageTitle) {
        pageTitle.textContent = titles[section] || 'Dashboard';
    }
    
    // Show the selected section
    const targetSection = document.getElementById(section + '-section');
    if (targetSection) {
        targetSection.classList.remove('hidden');
    } else {
        console.warn('Section not found: ' + section + '-section');
    }
}

console.log('✓ JavaScript loaded - All functions ready!');
document.addEventListener('DOMContentLoaded', function() {
    console.log('✓ DOM is ready');
});
</script>

</body>
</html>
