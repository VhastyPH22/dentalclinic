<?php
session_start();
require_once "backend/config.php";
require_once "backend/middleware.php";

// Set Timezone
date_default_timezone_set('Asia/Manila');

// Security Check - allow dentists and assistants with tenant isolation
checkAccess(['dentist', 'assistant'], true);

$fullName = $_SESSION['username'] ?? 'Dentist';
$displayFirstName = explode(' ', $_SESSION['full_name'] ?? $fullName)[0];
$profilePicture = '';
$error = '';
$success = '';

// Fetch dentist profile picture
$dentistID = $_SESSION['user_id'] ?? 0;
$checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
$columnExists = mysqli_query($conn, $checkColumnSQL);
if ($columnExists && mysqli_num_rows($columnExists) > 0) {
    $profileQuery = mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '$dentistID'" . getTenantFilter());
    if ($profileQuery) {
        $profileData = mysqli_fetch_assoc($profileQuery);
        $profilePictureRaw = $profileData['profile_picture'] ?? '';
        if (!empty($profilePictureRaw)) {
            $cleanedPath = preg_replace('|^.*?/?(assets/images/profiles/)|', '$1', trim($profilePictureRaw));
            $cleanedPath = ltrim($cleanedPath, '/');
            $appRoot = __DIR__;
            $fullPath = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanedPath);
            $fileTime = @filemtime($fullPath);
            $profilePicture = $cleanedPath . '?t=' . ($fileTime ? intval($fileTime) : intval(microtime(true) * 1000));
        }
    }
}

// Handle account creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_account') {
        $first_name = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
        $last_name = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $username = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $role = $_POST['role'] ?? '';
        
        // Validation
        if (empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($password) || empty($role)) {
            $error = 'All fields are required.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must be at least 8 characters with uppercase, lowercase, and numbers.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!in_array($role, ['dentist', 'assistant'])) {
            $error = 'Invalid role selected.';
        } else {
            // Check if email already exists
            $checkEmail = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email' OR username = '$username'");
            if (mysqli_num_rows($checkEmail) > 0) {
                $error = 'Email or username already exists in the system.';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                // Insert user
                $tenantIdForInsert = getTenantValueForInsert();
                
                // Debug: Check if tenant_id is available
                if (empty($tenantIdForInsert)) {
                    error_log("WARNING: tenant_id is empty for account creation. Session tenant_id: " . print_r($_SESSION['tenant_id'] ?? 'NOT SET', true));
                    $tenantIdForInsert = 1; // Fallback to default
                }
                
                $insertUser = mysqli_query($conn, "INSERT INTO users (tenant_id, first_name, last_name, email, username, password, role, created_at)
                                                    VALUES ($tenantIdForInsert, '$first_name', '$last_name', '$email', '$username', '$hashed_password', '$role', NOW())");
                if ($insertUser) {
                    $success = ucfirst($role) . ' account for ' . $first_name . ' ' . $last_name . ' has been created successfully!';
                } else {
                    $error = 'Failed to create account. Please try again.';
                }
            }
        }
    } elseif ($action === 'edit_account') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $first_name = mysqli_real_escape_string($conn, $_POST['first_name'] ?? '');
        $last_name = mysqli_real_escape_string($conn, $_POST['last_name'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $username = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // Validation
        if (empty($user_id) || empty($first_name) || empty($last_name) || empty($email) || empty($username) || empty($role)) {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!in_array($role, ['dentist', 'assistant'])) {
            $error = 'Invalid role selected.';
        } else {
            // Check if email already exists (excluding current user)
            $checkEmail = mysqli_query($conn, "SELECT id FROM users WHERE (email = '$email' OR username = '$username') AND id != $user_id");
            if (mysqli_num_rows($checkEmail) > 0) {
                $error = 'Email or username already exists in the system.';
            } else {
                // Build update query
                $updateQuery = "UPDATE users SET first_name = '$first_name', last_name = '$last_name', email = '$email', username = '$username', role = '$role'";
                
                // If password is provided, validate and update it
                if (!empty($password)) {
                    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                        $error = 'Password must be at least 8 characters with uppercase, lowercase, and numbers.';
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        $updateQuery .= ", password = '$hashed_password'";
                    }
                }
                
                if (empty($error)) {
                    $updateQuery .= " WHERE id = $user_id" . getTenantFilter();
                    $updateUser = mysqli_query($conn, $updateQuery);
                    
                    if ($updateUser) {
                        $success = 'Account for ' . $first_name . ' ' . $last_name . ' has been updated successfully!';
                    } else {
                        $error = 'Failed to update account. Please try again.';
                    }
                }
            }
        }
    } elseif ($action === 'archive_account') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if (mysqli_query($conn, "UPDATE users SET is_archived = 1 WHERE id = '$user_id' AND role IN ('dentist', 'assistant')" . getTenantFilter())) {
            $success = 'Account has been archived successfully!';
        } else {
            $error = 'Failed to archive account. Please try again.';
        }
    } elseif ($action === 'restore_account') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if (mysqli_query($conn, "UPDATE users SET is_archived = 0 WHERE id = '$user_id' AND role IN ('dentist', 'assistant')" . getTenantFilter())) {
            $success = 'Account has been restored successfully!';
        } else {
            $error = 'Failed to restore account. Please try again.';
        }
    }
}

// Determine view mode - archived or active accounts
$showArchived = isset($_GET['archived']) && $_GET['archived'] == 1;
$archiveFilter = $showArchived ? 1 : 0;

// Fetch all dentist and assistant accounts with profile pictures (TENANT ISOLATED)
$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.username, u.role, u.created_at, p.profile_picture FROM users u LEFT JOIN patient_profiles p ON u.id = p.user_id WHERE u.role IN ('dentist', 'assistant') AND u.is_archived = $archiveFilter" . getTenantFilter('u') . " ORDER BY u.created_at DESC";
$result = mysqli_query($conn, $sql);
$accounts = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Process profile picture path if exists
        if (!empty($row['profile_picture'])) {
            $cleanedPath = preg_replace('|^.*?/?(assets/images/profiles/)|', '$1', trim($row['profile_picture']));
            $cleanedPath = ltrim($cleanedPath, '/');
            $appRoot = __DIR__;
            $fullPath = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanedPath);
            $fileTime = @filemtime($fullPath);
            $row['profile_picture'] = $cleanedPath . '?t=' . ($fileTime ? intval($fileTime) : intval(microtime(true) * 1000));
        }
        $accounts[] = $row;
    }
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta charset="utf-8"/>
    <title>Accounts - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
        }
    </script>
    <style>
        * { scroll-behavior: smooth; }
        html { scroll-behavior: smooth; }
        
        .animate-fade-in { 
            animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; 
        } 
        
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(15px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        
        @keyframes slideInUp {
            from { opacity: 0; transform: translateY(25px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        body {
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        header {
            animation: slideInDown 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        #sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 1024px) {
            #sidebar.hidden-mobile { transform: translateX(-100%); }
            #sidebar.visible-mobile { transform: translateX(0); }
        }
    </style>
</head>

<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 font-display overflow-hidden text-sm transition-colors duration-200">
<div class="flex h-screen w-full overflow-hidden relative">

    <div id="sidebarOverlay" onclick="" class="hidden"></div>

    <aside id="sidebar" class="hidden">
        <div class="p-4 flex items-center gap-3">
            <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-12 w-auto">
            <div>
                <h1 class="text-sm font-bold leading-tight text-slate-900 dark:text-white">San Nicolas</h1>
                <p class="text-[10px] text-slate-500 font-black">Dentist panel</p>
            </div>
        </div>

        <nav class="flex-1 px-4 py-4 gap-2 flex flex-col overflow-y-auto font-black">
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="dentist-dashboard.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="text-sm font-bold">Dashboard</span>
            </a>
            

            
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="reports.php">
                <span class="material-symbols-outlined">analytics</span>
                <span class="text-sm font-bold">Clinic Reports</span>
            </a>

            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="schedule.php">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="text-sm font-bold">Schedule</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="patients.php">
                <span class="material-symbols-outlined">groups</span>
                <span class="text-sm font-bold">Patient Profile</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="treatment-records.php">
                <span class="material-symbols-outlined">edit_document</span>
                <span class="text-sm font-bold">Treatment history</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="record-payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="text-sm font-bold">Billing records</span>
            </a>

            
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg bg-primary/10 text-primary shadow-sm transition-all" href="accounts.php">
                <span class="material-symbols-outlined fill">admin_panel_settings</span>
                <span class="text-sm font-bold">Accounts</span>
            </a>
        </nav>

        <div class="border-t border-slate-200 dark:border-slate-700 p-4">
            <a href="javascript:void(0);" onclick="openLogoutModal()" class="flex items-center gap-3 px-4 py-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer transition-colors font-bold">
                <span class="material-symbols-outlined">logout</span>
                <span class="text-sm font-bold">Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 w-full overflow-y-auto h-full relative bg-[#f8fafc] dark:bg-background-dark text-slate-900 dark:text-white">
        <header class="flex items-center justify-between p-4 bg-white dark:bg-[#1e293b] border-b border-slate-200 dark:border-slate-800 sticky top-0 z-30 shadow-sm font-black">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center text-white shadow-sm">
                    <span class="material-symbols-outlined text-xl font-black">admin_panel_settings</span>
                </div>
                <span class="text-sm font-bold text-slate-900 dark:text-white">Accounts</span>
            </div>
            <button onclick="openBackModal()" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors text-sm font-bold shadow-sm font-black text-slate-900 dark:text-white">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Dashboard
            </button>
        </header>

        <div class="p-6 md:p-10 max-w-[1600px] mx-auto flex flex-col gap-6 md:gap-10 animate-fade-in text-slate-900 dark:text-white">
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 animate-fade-in">
                <div class="space-y-1">
                    <h1 class="text-4xl md:text-5xl font-black tracking-tight">Accounts 👥</h1>
                    <p class="text-slate-500 text-lg font-bold">Manage dentist and assistant accounts for your clinic.</p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right hidden sm:block">
                        <p class="text-sm font-bold"><?php echo date('l, M jS'); ?> • <span class="text-primary font-bold"><?php echo date('h:i A'); ?></span></p>
                        <p class="text-xs text-slate-500 font-bold">Practice ID: <span class="text-primary font-black">#<?php echo $_SESSION['user_id']; ?></span></p>
                    </div>
                    <div class="size-14 rounded-2xl bg-primary/10 text-primary flex items-center justify-center font-black text-xl border-2 border-white dark:border-slate-700 shadow-sm transition-transform hover:scale-110 uppercase overflow-hidden flex-shrink-0">
                        <?php if (!empty($profilePicture)): ?>
                            <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?php echo substr($fullName, 0, 1); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <div class="flex items-center gap-0 mb-6 border-b-2 border-slate-200 dark:border-slate-800 font-black bg-gradient-to-r from-transparent to-slate-50 dark:to-slate-900/30 p-1 rounded-t-xl">
                <a href="accounts.php" class="px-8 py-4 text-sm font-black uppercase tracking-widest transition-all flex items-center gap-2 <?php echo !$showArchived ? 'text-primary border-b-2 border-primary' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'; ?>">
                    <span class="material-symbols-outlined text-lg">person</span> Active Accounts
                </a>
                <a href="accounts.php?archived=1" class="px-8 py-4 text-sm font-black uppercase tracking-widest transition-all flex items-center gap-2 <?php echo $showArchived ? 'text-orange-500 border-b-2 border-orange-500' : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'; ?>">
                    <span class="material-symbols-outlined text-lg">archive</span> Archived Accounts
                </a>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 md:gap-10">
                <!-- Form Section - Only show when viewing active accounts -->
                <?php if (!$showArchived): ?>
                <div class="xl:col-span-1 flex flex-col gap-6">
                    <div class="bg-white dark:bg-slate-800 rounded-[24px] border border-slate-200 dark:border-slate-700 p-6 md:p-8 shadow-md">
                        <h2 class="text-2xl font-black mb-6 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary font-black">person_add</span>
                            Create New Account
                        </h2>

                        <?php if ($error): ?>
                            <div class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                                <div class="flex gap-3">
                                    <span class="material-symbols-outlined text-red-600 dark:text-red-400 flex-shrink-0 text-xl">error</span>
                                    <p class="text-sm text-red-700 dark:text-red-200 font-semibold"><?php echo htmlspecialchars($error); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="mb-6 p-4 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
                                <div class="flex gap-3">
                                    <span class="material-symbols-outlined text-green-600 dark:text-green-400 flex-shrink-0 text-xl">check_circle</span>
                                    <p class="text-sm text-green-700 dark:text-green-200 font-semibold"><?php echo htmlspecialchars($success); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="flex flex-col gap-4">
                            <input type="hidden" name="action" value="create_account">
                            
                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-bold text-slate-600 dark:text-slate-300">First Name</label>
                                <input type="text" name="first_name" required class="h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all" placeholder="Enter first name">
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-bold text-slate-600 dark:text-slate-300">Last Name</label>
                                <input type="text" name="last_name" required class="h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all" placeholder="Enter last name">
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-bold text-slate-600 dark:text-slate-300">Email Address</label>
                                <input type="email" name="email" required class="h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all" placeholder="Enter email address">
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-bold text-slate-600 dark:text-slate-300">Username</label>
                                <input type="text" name="username" required class="h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all" placeholder="Enter username">
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-bold text-slate-600 dark:text-slate-300">Account Role</label>
                                <select name="role" required class="h-11 px-4 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all">
                                    <option value="">-- Select Role --</option>
                                    <option value="dentist">Dentist</option>
                                    <option value="assistant">Assistant</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-bold text-slate-600 dark:text-slate-300">Password</label>
                                <div class="relative">
                                    <input type="password" id="passwordInput" name="password" required class="h-11 px-4 pr-12 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all w-full" placeholder="Enter password" oninput="checkPasswordStrength()">
                                    <button type="button" onclick="togglePasswordVisibility('passwordInput', 'toggleIcon1')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                        <span class="material-symbols-outlined text-xl" id="toggleIcon1">visibility_off</span>
                                    </button>
                                </div>
                                
                                <div class="mt-3 p-4 bg-slate-50 dark:bg-slate-900/50 rounded-xl border border-slate-200 dark:border-slate-700">
                                    <p class="text-xs font-bold text-slate-600 dark:text-slate-400 mb-3 uppercase tracking-wide">Security Strength</p>
                                    <div class="space-y-2">
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-lg" id="check1">radio_button_unchecked</span>
                                            <span class="text-xs text-slate-600 dark:text-slate-400 font-semibold">8+ Characters</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-lg" id="check2">radio_button_unchecked</span>
                                            <span class="text-xs text-slate-600 dark:text-slate-400 font-semibold">Uppercase letter</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-lg" id="check3">radio_button_unchecked</span>
                                            <span class="text-xs text-slate-600 dark:text-slate-400 font-semibold">Lowercase letter</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-lg" id="check4">radio_button_unchecked</span>
                                            <span class="text-xs text-slate-600 dark:text-slate-400 font-semibold">Numbers included</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2">
                                <label class="text-sm font-bold text-slate-600 dark:text-slate-300">Confirm Password</label>
                                <div class="relative">
                                    <input type="password" id="confirmPasswordInput" name="confirm_password" required class="h-11 px-4 pr-12 rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all w-full" placeholder="Confirm password">
                                    <button type="button" onclick="togglePasswordVisibility('confirmPasswordInput', 'toggleIcon2')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                        <span class="material-symbols-outlined text-xl" id="toggleIcon2">visibility_off</span>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="h-12 mt-4 px-6 bg-primary hover:bg-primary-hover text-white font-black rounded-xl shadow-lg shadow-blue-500/30 transition-all active:scale-95 flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined">add</span>
                                Create Account
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Accounts List Section -->
                <div class="<?php echo !$showArchived ? 'xl:col-span-2' : 'xl:col-span-3'; ?> flex flex-col gap-6">
                    <div class="bg-white dark:bg-slate-800 rounded-[24px] border border-slate-200 dark:border-slate-700 p-6 md:p-8 shadow-md">
                        <h2 class="text-2xl font-black mb-6 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary font-black">group</span>
                            <?php echo $showArchived ? 'Archived Accounts' : 'Active Accounts'; ?> (<?php echo count($accounts); ?>)
                        </h2>

                        <?php if (!empty($accounts)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full table-fixed">
                                    <thead>
                                        <tr class="border-b border-slate-200 dark:border-slate-700">
                                            <th class="text-left py-4 px-4 font-black text-slate-700 dark:text-slate-300 text-xs uppercase tracking-wider w-[25%]">Name</th>
                                            <th class="text-left py-4 px-4 font-black text-slate-700 dark:text-slate-300 text-xs uppercase tracking-wider w-[25%]">Email</th>
                                            <th class="text-left py-4 px-4 font-black text-slate-700 dark:text-slate-300 text-xs uppercase tracking-wider w-[15%]">Role</th>
                                            <th class="text-left py-4 px-4 font-black text-slate-700 dark:text-slate-300 text-xs uppercase tracking-wider w-[15%]">Created</th>
                                            <th class="text-center py-4 px-4 font-black text-slate-700 dark:text-slate-300 text-xs uppercase tracking-wider w-[20%] flex items-center justify-center gap-2"><span class="material-symbols-outlined text-lg text-primary">manage_accounts</span><span>Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($accounts as $account): ?>
                                            <tr class="border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors animate-fade-in">
                                                <td class="py-4 px-4 w-[25%]">
                                                    <div class="flex items-center gap-3">
                                                        <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-sm border-2 border-primary/20 overflow-hidden">
                                                            <?php if (!empty($account['profile_picture'])): ?>
                                                                <img src="<?php echo htmlspecialchars($account['profile_picture']); ?>" alt="<?php echo htmlspecialchars($account['first_name']); ?>" class="w-full h-full object-cover">
                                                            <?php else: ?>
                                                                <?php echo strtoupper(substr($account['first_name'], 0, 1)); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="truncate">
                                                            <p class="text-sm font-semibold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="py-4 px-4 w-[25%]">
                                                    <p class="text-sm text-slate-600 dark:text-slate-400 truncate"><?php echo htmlspecialchars($account['email']); ?></p>
                                                </td>
                                                <td class="py-4 px-4 w-[15%]">
                                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-lg text-xs font-bold <?php echo $account['role'] === 'dentist' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300'; ?>">
                                                        <span class="material-symbols-outlined text-sm"><?php echo $account['role'] === 'dentist' ? 'dentistry' : 'person'; ?></span>
                                                        <?php echo ucfirst($account['role']); ?>
                                                    </span>
                                                </td>
                                                <td class="py-4 px-4 w-[15%]">
                                                    <p class="text-sm text-slate-600 dark:text-slate-400 font-medium"><?php echo date('M d, Y', strtotime($account['created_at'])); ?></p>
                                                </td>
                                                <td class="py-4 px-4 w-[20%]">
                                                    <div class="flex items-center justify-center gap-1.5">
                                                        <button type="button" onclick="openViewModal(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['first_name']); ?>', '<?php echo htmlspecialchars($account['last_name']); ?>', '<?php echo htmlspecialchars($account['email']); ?>', '<?php echo htmlspecialchars($account['username']); ?>', '<?php echo htmlspecialchars($account['role']); ?>', '<?php echo htmlspecialchars($account['created_at']); ?>')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors text-slate-600 dark:text-slate-400" title="View Details">
                                                            <span class="material-symbols-outlined text-xl">visibility</span>
                                                        </button>
                                                        <?php if (!$showArchived): ?>
                                                            <button type="button" onclick="openEditModal(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['first_name']); ?>', '<?php echo htmlspecialchars($account['last_name']); ?>', '<?php echo htmlspecialchars($account['email']); ?>', '<?php echo htmlspecialchars($account['username']); ?>', '<?php echo htmlspecialchars($account['role']); ?>')" class="p-2 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors text-primary" title="Edit">
                                                                <span class="material-symbols-outlined text-xl">edit</span>
                                                            </button>
                                                            <button type="button" onclick="openArchiveModal(<?php echo $account['id']; ?>)" class="p-2 hover:bg-orange-50 dark:hover:bg-orange-900/20 rounded-lg transition-colors text-orange-600 dark:text-orange-400" title="Archive">
                                                                <span class="material-symbols-outlined text-xl">archive</span>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" onclick="openRestoreModal(<?php echo $account['id']; ?>)" class="p-2 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors text-green-600 dark:text-green-400" title="Restore">
                                                                <span class="material-symbols-outlined text-xl">unarchive</span>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-12">
                                <span class="material-symbols-outlined text-6xl text-slate-300 dark:text-slate-600 mx-auto block mb-4">person_off</span>
                                <p class="text-slate-500 dark:text-slate-400 font-semibold">No accounts created yet.</p>
                                <p class="text-slate-400 dark:text-slate-500 text-sm">Create your first account using the form on the left.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="editModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl font-black border border-slate-100 dark:border-slate-700 max-h-[90vh] overflow-y-auto" id="editModalContent">
        <div class="space-y-4">
            <div class="text-center mb-6">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-900/20 mb-4 ring-2 ring-blue-200 dark:ring-blue-900/50">
                    <span class="material-symbols-outlined text-3xl text-blue-600 font-black">edit</span>
                </div>
                <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-2 uppercase tracking-tight">Edit Account</h3>
                <p class="text-[11px] text-slate-600 dark:text-slate-400 font-bold tracking-wider">Update account information</p>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="user_id" id="editUserId" value="">
                
                <div>
                    <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">First Name</label>
                    <input type="text" id="editFirstName" name="first_name" required class="w-full h-10 px-3 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white text-sm font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all">
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Last Name</label>
                    <input type="text" id="editLastName" name="last_name" required class="w-full h-10 px-3 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white text-sm font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all">
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Email Address</label>
                    <input type="email" id="editEmail" name="email" required class="w-full h-10 px-3 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white text-sm font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all">
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Username</label>
                    <input type="text" id="editUsername" name="username" required class="w-full h-10 px-3 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white text-sm font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all">
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Role</label>
                    <select id="editRole" name="role" required class="w-full h-10 px-3 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white text-sm font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all">
                        <option value="dentist">Dentist</option>
                        <option value="assistant">Assistant</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Password (Leave blank to keep current password)</label>
                    <div class="relative">
                        <input type="password" id="editPasswordInput" name="password" class="w-full h-10 px-3 pr-10 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900 text-slate-900 dark:text-white text-sm font-semibold focus:ring-2 focus:ring-primary focus:border-transparent shadow-sm transition-all">
                        <button type="button" onclick="togglePasswordVisibility('editPasswordInput', 'editPasswordIcon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 transition-colors">
                            <span class="material-symbols-outlined text-lg" id="editPasswordIcon">visibility_off</span>
                        </button>
                    </div>
                </div>

                <div class="flex gap-3 justify-center pt-4">
                    <button type="button" onclick="closeM('editModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Cancel</button>
                    <button type="submit" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="backModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl font-black border border-slate-100 dark:border-slate-700" id="backModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-900/20 mb-6 ring-2 ring-blue-200 dark:ring-blue-900/50">
                <span class="material-symbols-outlined text-3xl text-blue-600 font-black">arrow_back</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Are you sure?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-8 px-4 font-bold tracking-wider">Any unsaved progress will be lost.</p>
            <div class="flex gap-3 justify-center">
              <button onclick="closeM('backModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Stay</button>
                <a href="dentist-dashboard.php" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 flex items-center justify-center text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Go Back</a>
            </div>
        </div>
    </div>
</div>

<div id="archiveModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl font-black border border-slate-100 dark:border-slate-700" id="archiveModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-orange-100 to-orange-50 dark:from-orange-900/30 dark:to-orange-900/20 mb-6 ring-2 ring-orange-200 dark:ring-orange-900/50">
                <span class="material-symbols-outlined text-3xl text-orange-600 font-black">archive</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Archive account?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-8 px-4 font-bold tracking-wider">This account will be moved to archives.</p>
            <form method="POST" class="flex gap-3 justify-center">
                <input type="hidden" name="action" value="archive_account">
                <input type="hidden" name="user_id" id="archiveUserId">
                <button type="button" onclick="closeM('archiveModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 text-white font-black shadow-lg shadow-orange-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Yes, archive</button>
            </form>
        </div>
    </div>
</div>

<div id="restoreModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl font-black border border-slate-100 dark:border-slate-700" id="restoreModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-green-100 to-green-50 dark:from-green-900/30 dark:to-green-900/20 mb-6 ring-2 ring-green-200 dark:ring-green-900/50">
                <span class="material-symbols-outlined text-3xl text-green-600 font-black">unarchive</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Restore account?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-8 px-4 font-bold tracking-wider">This account will be restored from archives.</p>
            <form method="POST" class="flex gap-3 justify-center">
                <input type="hidden" name="action" value="restore_account">
                <input type="hidden" name="user_id" id="restoreUserId">
                <button type="button" onclick="closeM('restoreModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-green-500 to-green-600 text-white font-black shadow-lg shadow-green-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Yes, restore</button>
            </form>
        </div>
    </div>
</div>

<div id="viewModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl font-black border border-slate-100 dark:border-slate-700 max-h-[90vh] overflow-y-auto" id="viewModalContent">
        <div class="space-y-4">
            <div class="text-center mb-6">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-slate-100 to-slate-50 dark:from-slate-700/50 dark:to-slate-700/30 mb-4 ring-2 ring-slate-200 dark:ring-slate-600">
                    <span class="material-symbols-outlined text-3xl text-slate-700 dark:text-slate-300 font-black">person</span>
                </div>
                <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-2 uppercase tracking-tight">Account Details</h3>
                <p class="text-[11px] text-slate-600 dark:text-slate-400 font-bold tracking-wider">View account information</p>
            </div>

            <div>
                <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">First Name</label>
                <div class="w-full h-10 px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900/50 text-slate-900 dark:text-white text-sm font-semibold bg-slate-50 dark:bg-slate-900/30 flex items-center">
                    <span id="viewFirstName">-</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Last Name</label>
                <div class="w-full h-10 px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900/50 text-slate-900 dark:text-white text-sm font-semibold bg-slate-50 dark:bg-slate-900/30 flex items-center">
                    <span id="viewLastName">-</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Email Address</label>
                <div class="w-full h-10 px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900/50 text-slate-900 dark:text-white text-sm font-semibold bg-slate-50 dark:bg-slate-900/30 flex items-center">
                    <span id="viewEmail">-</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Username</label>
                <div class="w-full h-10 px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900/50 text-slate-900 dark:text-white text-sm font-semibold bg-slate-50 dark:bg-slate-900/30 flex items-center">
                    <span id="viewUsername">-</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Role</label>
                <div class="w-full h-10 px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900/50 text-slate-900 dark:text-white text-sm font-semibold bg-slate-50 dark:bg-slate-900/30 flex items-center">
                    <span id="viewRole" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-black">-</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-black text-slate-700 dark:text-slate-300 mb-2 uppercase tracking-tight">Created Date</label>
                <div class="w-full h-10 px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900/50 text-slate-900 dark:text-white text-sm font-semibold bg-slate-50 dark:bg-slate-900/30 flex items-center">
                    <span id="viewCreatedAt">-</span>
                </div>
            </div>

            <div class="flex gap-3 justify-center pt-4">
                <button type="button" onclick="closeM('viewModal')" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-slate-500 to-slate-600 text-white font-black shadow-lg shadow-slate-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Close</button>
            </div>
        </div>
    </div>
</div>

<div id="logoutModalUI" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 backdrop-blur-md">
    <div class="fixed inset-0 bg-slate-950/50" onclick="closeLogoutModal()"></div>
    <div class="relative w-full max-w-[480px] bg-white dark:bg-slate-900 rounded-[32px] shadow-2xl overflow-hidden animate-fade-in duration-300">
        <div class="bg-gradient-to-br from-red-600 to-red-700 dark:from-red-700 dark:to-red-800 px-10 pt-10 pb-6 flex flex-col items-center text-center relative overflow-hidden">
            <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 20px 20px;"></div>
            <div class="relative w-full flex flex-col items-center justify-center">
                <div class="size-16 rounded-full bg-white/20 flex items-center justify-center mb-6 backdrop-blur-sm border-2 border-white/30">
                    <span class="material-symbols-outlined text-4xl text-white">logout</span>
                </div>
                <h2 class="text-3xl font-black text-white mb-2">Sign out?</h2>
                <p class="text-red-100 text-sm font-semibold">End your session</p>
            </div>
        </div>
        
        <div class="px-10 py-8 flex flex-col items-center text-center gap-8">
            <div class="space-y-3">
                <p class="text-slate-600 dark:text-slate-300 text-sm font-medium">Are you sure you want to sign out? You'll need to log in again to access your account.</p>
                <p class="text-slate-500 dark:text-slate-400 text-xs font-medium opacity-75">Your data will be saved.</p>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-3 w-full font-black text-sm">
                <button onclick="closeLogoutModal()" class="flex-1 h-14 rounded-2xl border-2 border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition-all text-slate-700 dark:text-slate-200 font-bold flex items-center justify-center gap-2 group">
                    <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">close</span>
                    <span>Cancel</span>
                </button>
                <a href="backend/logout.php" class="flex-1 h-14 rounded-2xl bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white font-black shadow-lg hover:shadow-red-500/30 transition-all flex items-center justify-center gap-2 group active:scale-95">
                    <span class="material-symbols-outlined text-lg group-hover:translate-x-1 transition-transform">exit_to_app</span>
                    <span>Sign out</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    function togglePasswordVisibility(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'visibility';
        } else {
            input.type = 'password';
            icon.textContent = 'visibility_off';
        }
    }

    function openEditModal(userId, firstName, lastName, email, username, role) {
        document.getElementById('editUserId').value = userId;
        document.getElementById('editFirstName').value = firstName;
        document.getElementById('editLastName').value = lastName;
        document.getElementById('editEmail').value = email;
        document.getElementById('editUsername').value = username;
        document.getElementById('editRole').value = role;
        document.getElementById('editPasswordInput').value = '';
        document.getElementById('editPasswordIcon').textContent = 'visibility_off';
        const modal = document.getElementById('editModal');
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            document.getElementById('editModalContent').classList.remove('scale-95');
        }, 10);
    }

    function closeEditModal() {
        closeM('editModal');
    }

    function checkPasswordStrength() {
        const password = document.getElementById('passwordInput').value;
        
        // Check 8+ characters
        const check1 = document.getElementById('check1');
        if (password.length >= 8) {
            check1.textContent = 'check_circle';
            check1.className = 'material-symbols-outlined text-lg text-green-600 dark:text-green-400 fill';
        } else {
            check1.textContent = 'radio_button_unchecked';
            check1.className = 'material-symbols-outlined text-lg text-slate-400 dark:text-slate-600';
        }
        
        // Check uppercase
        const check2 = document.getElementById('check2');
        if (/[A-Z]/.test(password)) {
            check2.textContent = 'check_circle';
            check2.className = 'material-symbols-outlined text-lg text-green-600 dark:text-green-400 fill';
        } else {
            check2.textContent = 'radio_button_unchecked';
            check2.className = 'material-symbols-outlined text-lg text-slate-400 dark:text-slate-600';
        }
        
        // Check lowercase
        const check3 = document.getElementById('check3');
        if (/[a-z]/.test(password)) {
            check3.textContent = 'check_circle';
            check3.className = 'material-symbols-outlined text-lg text-green-600 dark:text-green-400 fill';
        } else {
            check3.textContent = 'radio_button_unchecked';
            check3.className = 'material-symbols-outlined text-lg text-slate-400 dark:text-slate-600';
        }
        
        // Check numbers
        const check4 = document.getElementById('check4');
        if (/[0-9]/.test(password)) {
            check4.textContent = 'check_circle';
            check4.className = 'material-symbols-outlined text-lg text-green-600 dark:text-green-400 fill';
        } else {
            check4.textContent = 'radio_button_unchecked';
            check4.className = 'material-symbols-outlined text-lg text-slate-400 dark:text-slate-600';
        }
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar.classList.contains('hidden-mobile')) {
            sidebar.classList.remove('hidden-mobile');
            sidebar.classList.add('visible-mobile');
            overlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        } else {
            sidebar.classList.add('hidden-mobile');
            sidebar.classList.remove('visible-mobile');
            overlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    }
    function openBackModal() {
        document.getElementById('backModal').classList.remove('hidden');
        setTimeout(() => {
            document.getElementById('backModal').classList.remove('opacity-0');
            document.getElementById('backModalContent').classList.remove('scale-95');
        }, 10);
    }
    
    function closeM(modalId) {
        const modal = document.getElementById(modalId);
        const modalContent = document.getElementById(modalId + 'Content');
        if (modal) {
            modal.classList.add('opacity-0');
            if (modalContent) modalContent.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
    }
    
    function closeBackModal() {
        closeM('backModal');
    }

    function openArchiveModal(userId) {
        document.getElementById('archiveUserId').value = userId;
        document.getElementById('archiveModal').classList.remove('hidden');
        setTimeout(() => {
            document.getElementById('archiveModal').classList.remove('opacity-0');
            document.getElementById('archiveModalContent').classList.remove('scale-95');
        }, 10);
    }

    function openRestoreModal(userId) {
        document.getElementById('restoreUserId').value = userId;
        document.getElementById('restoreModal').classList.remove('hidden');
        setTimeout(() => {
            document.getElementById('restoreModal').classList.remove('opacity-0');
            document.getElementById('restoreModalContent').classList.remove('scale-95');
        }, 10);
    }

    function openViewModal(userId, firstName, lastName, email, username, role, createdAt) {
        document.getElementById('viewFirstName').textContent = firstName;
        document.getElementById('viewLastName').textContent = lastName;
        document.getElementById('viewEmail').textContent = email;
        document.getElementById('viewUsername').textContent = username;
        
        // Format role with badge styling
        let roleBadge = '';
        if (role === 'dentist') {
            roleBadge = '<span class="material-symbols-outlined text-sm">dentistry</span> Dentist';
        } else {
            roleBadge = '<span class="material-symbols-outlined text-sm">person</span> Assistant';
        }
        document.getElementById('viewRole').innerHTML = roleBadge;
        
        // Format and display creation date
        const date = new Date(createdAt);
        const formattedDate = date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        document.getElementById('viewCreatedAt').textContent = formattedDate;
        
        const modal = document.getElementById('viewModal');
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            document.getElementById('viewModalContent').classList.remove('scale-95');
        }, 10);
    }

    function openLogoutModal() {
        document.getElementById('logoutModalUI').classList.remove('hidden');
        document.getElementById('logoutModalUI').classList.add('flex');
    }
    function closeLogoutModal() {
        document.getElementById('logoutModalUI').classList.add('hidden');
        document.getElementById('logoutModalUI').classList.remove('flex');
    }
</script>
</body>
</html>
