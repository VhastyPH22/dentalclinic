<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/analytics.php';

$error = "";
$remembered_identifier = "";

// Check if user has "Remember Me" cookie
if (isset($_COOKIE['remembered_identifier'])) {
    $remembered_identifier = htmlspecialchars($_COOKIE['remembered_identifier']);
}

// 1. Initialize Attempt Counters
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

// 2. Initialize Attempt Counters (completed above)

// 3. Handle Login
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($error)) {
    
    $identity = mysqli_real_escape_string($conn, trim($_POST['identifier']));
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']) ? true : false;

    if(empty($identity) || empty($password)) {
        $error = "Please enter both username/email and password.";
    } else {
        // Check for Username OR Email
        $sql = "SELECT id, username, email, password, role, tenant_id, is_archived, first_name, last_name FROM users WHERE username = '$identity' OR email = '$identity' LIMIT 1";
        $result = mysqli_query($conn, $sql);

        if ($result && mysqli_num_rows($result) === 1) {
            $row = mysqli_fetch_assoc($result);

            if (password_verify($password, $row['password'])) {
                // CHECK: Archived Account - Prevent archived accounts from logging in
                if (isset($row['is_archived']) && $row['is_archived'] == 1) {
                    $_SESSION['login_attempts']++;
                    $error = "This account has been archived and cannot be used.";
                    logLoginAttempt($row['username'], $row['email'], $row['id'], $row['role'], $row['tenant_id'], 'failed_account_archived', $error);
                } else {
                    // Normalize role first to check if super_admin
                    $normalizedRole = strtolower(trim($row['role']));
                    $normalizedRole = str_replace('-', '_', $normalizedRole);

                    // CHECK: Clinic Approval Status - Only for non-super-admin users
                    $skipClinicCheck = ($normalizedRole === 'super_admin');
                    
                    if (!$skipClinicCheck) {
                        $tenantId = (int)$row['tenant_id'];
                        $tenantCheckSql = "SELECT status, is_active FROM tenants WHERE id = $tenantId LIMIT 1";
                        $tenantResult = mysqli_query($conn, $tenantCheckSql);
                        
                        if (!$tenantResult || mysqli_num_rows($tenantResult) === 0) {
                            $_SESSION['login_attempts']++;
                            $error = "Clinic information not found. Please contact support.";
                        } else {
                            $tenantRow = mysqli_fetch_assoc($tenantResult);
                            
                            if ($tenantRow['status'] !== 'approved' || $tenantRow['is_active'] != 1) {
                                $_SESSION['login_attempts']++;
                                if ($tenantRow['status'] === 'pending') {
                                    $error = "Your clinic registration is pending super admin approval. Please try again later.";
                                    logLoginAttempt($row['username'], $row['email'], $row['id'], $row['role'], $row['tenant_id'], 'failed_clinic_not_approved', $error);
                                } elseif ($tenantRow['status'] === 'rejected') {
                                    $error = "Your clinic registration has been rejected. Please contact support for more information.";
                                    logLoginAttempt($row['username'], $row['email'], $row['id'], $row['role'], $row['tenant_id'], 'failed_clinic_rejected', $error);
                                } else {
                                    $error = "Your clinic account is not active. Please contact support.";
                                    logLoginAttempt($row['username'], $row['email'], $row['id'], $row['role'], $row['tenant_id'], 'failed_clinic_inactive', $error);
                                }
                            }
                        }
                    }

                    // If no error yet, continue with login
                    if (empty($error)) {
                        // CHECK: Patient role not allowed in this application
                        if ($normalizedRole === 'patient') {
                            $_SESSION['login_attempts']++;
                            $error = "Patient accounts cannot access this system. Please use the mobile app.";
                            logLoginAttempt($row['username'], $row['email'], $row['id'], $row['role'], $row['tenant_id'], 'failed_patient_role', $error);
                        } else {
                            // SUCCESS: Reset counters and log in
                            $_SESSION['login_attempts'] = 0;
                            unset($_SESSION['lockout_time']);

                            $_SESSION['user_id'] = $row['id'];
                            $_SESSION['username'] = $row['username'];
                            $_SESSION['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
                            $_SESSION['role'] = $normalizedRole;
                            $_SESSION['tenant_id'] = $row['tenant_id'];

                            // Log successful login
                            logLoginAttempt($row['username'], $row['email'], $row['id'], $row['role'], $row['tenant_id'], 'success', 'Successful login');

                            // Handle "Remember Me" cookie
                            if ($remember_me) {
                                setcookie('remembered_identifier', $identity, time() + (30 * 24 * 60 * 60), '/');
                            } else {
                                setcookie('remembered_identifier', '', time() - 3600, '/'); 
                            }

                            // Ensure session is written before redirect
                            session_write_close();

                            // Redirect Logic (role is already normalized in session)
                            if ($_SESSION['role'] === 'super_admin') {
                                header("Location: super-admin-dashboard.php");
                            } elseif ($_SESSION['role'] === 'dentist') {
                                header("Location: dentist-dashboard.php");
                            } elseif ($_SESSION['role'] === 'staff') {
                                header("Location: assistant-dashboard.php");
                            } else {
                                header("Location: login.php");
                            }
                            exit();
                        }
                    }
                }
            } else {
                // FAILED PASSWORD
                $_SESSION['login_attempts']++;
                $error = "Incorrect password. Please try again.";
                // Extract identifier from POST
                $loginIdentifier = $_POST['login_identifier'] ?? '';
                logLoginAttempt($loginIdentifier, $loginIdentifier, null, null, null, 'failed_password', $error);
            }
        } else {
            // USER NOT FOUND
            $_SESSION['login_attempts']++;
            $error = "User not found.";
            $loginIdentifier = $_POST['login_identifier'] ?? '';
            logLoginAttempt($loginIdentifier, $loginIdentifier, null, null, null, 'failed_account_not_found', $error);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Login | San Nicolas Dental Clinic</title>
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
                        primary: '#2C3E50',
                    },
                    fontFamily: {
                        sans: ['Montserrat', 'sans-serif'],
                    },
                    borderRadius: {
                        'custom': '8px',
                    }
                }
            }
        }
    </script>
    <style data-purpose="custom-styling">
        body {
            font-family: 'Montserrat', sans-serif;
            background: linear-gradient(135deg, #f8fafb 0%, #f0f4f8 100%);
        }
        .brand-gradient {
            background: linear-gradient(135deg, rgba(44, 62, 80, 0.9) 0%, rgba(44, 62, 80, 0.7) 100%);
        }
        
        /* Page load animations */
        main {
            animation: fadeInScale 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 20px 60px rgba(44, 62, 80, 0.15), 0 0 40px rgba(212, 175, 55, 0.05);
        }
        
        /* Enhanced form input styling */
        input[type="text"],
        input[type="password"] {
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
            position: relative;
            border-color: #e5e7eb;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }
        
        input[type="text"]:hover,
        input[type="password"]:hover {
            border-color: #2C3E50;
            box-shadow: 0 4px 12px rgba(44, 62, 80, 0.08);
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            background: linear-gradient(135deg, #ffffff 0%, #ffffff 100%);
            box-shadow: 0 0 0 4px rgba(44, 62, 80, 0.12), 0 10px 30px rgba(44, 62, 80, 0.12);
            transform: translateY(-3px);
            border-color: #2C3E50;
        }
        
        /* Form field staggered animations */
        form div:nth-child(1) {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.1s both;
        }
        
        form div:nth-child(2) {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
        }
        
        form div:nth-child(3) {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s both;
        }
        
        form div:nth-child(4) {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.4s both;
        }
        
        form button[type="submit"] {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.5s both;
        }
        
        /* Input labels animation */
        label {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        /* Button improvements with enhanced animation */
        button[type="submit"] {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            font-weight: 700;
            background: linear-gradient(135deg, #2C3E50 0%, #34495e 100%);
        }
        
        button[type="submit"]::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            transform: translate(-50%, -50%);
            transition: width 0.6s cubic-bezier(0.34, 1.56, 0.64, 1), height 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        button[type="submit"]::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        button[type="submit"]:hover:not(:disabled)::before {
            width: 400px;
            height: 400px;
        }
        
        button[type="submit"]:hover:not(:disabled)::after {
            left: 100%;
        }
        
        button[type="submit"]:hover:not(:disabled) {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px rgba(44, 62, 80, 0.35), 0 0 20px rgba(212, 175, 55, 0.2);
            background: linear-gradient(135deg, #1a252f 0%, #2C3E50 100%);
        }
        
        button[type="submit"]:active:not(:disabled) {
            transform: translateY(-1px) scale(0.98);
        }
        
        button[type="submit"]:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transition: all 0.3s ease;
        }
        
        /* Checkbox improvements */
        input[type="checkbox"] {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            cursor: pointer;
        }
        
        input[type="checkbox"]:hover {
            border-color: #2C3E50;
        }
        
        input[type="checkbox"]:checked {
            background-color: #2C3E50;
            border-color: #2C3E50;
            animation: checkPulse 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
        }
        
        /* Link hover effects */
        a {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }
        
        a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #2C3E50, #D4AF37);
            transition: width 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        a:hover::after {
            width: 100%;
        }
        
        /* Error message enhancement */
        .error-message {
            animation: shake 0.6s ease-out, slideDown 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        
        .error-message::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: linear-gradient(180deg, #dc2626, #f87171);
        }
        
        /* Logo animation */
        img[alt*="Logo"] {
            animation: fadeInDown 0.7s cubic-bezier(0.34, 1.56, 0.64, 1), floatGently 6s ease-in-out infinite;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.1));
        }
        
        /* Header animations */
        header h1 {
            animation: fadeInUp 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
            background: linear-gradient(135deg, #2C3E50 0%, #34495e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        header p {
            animation: fadeInUp 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s both;
        }
        
        /* Footer animation */
        footer {
            animation: fadeInUp 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) 0.6s both;
        }
        
        /* Brand side section animation */
        section[data-purpose="branding-side"] {
            animation: slideInLeft 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #2C3E50 0%, #34495e 100%);
            position: relative;
            overflow: hidden;
        }
        
        section[data-purpose="branding-side"]::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.1) 0%, transparent 70%);
            animation: rotateBg 20s linear infinite;
        }
        
        /* Login form side animation */
        section[data-purpose="login-form-side"] {
            animation: slideInRight 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        /* Password toggle button */
        button[aria-label="Toggle Password Visibility"] {
            transition: color 0.2s ease;
        }
        
        button[aria-label="Toggle Password Visibility"]:hover {
            color: #2C3E50 !important;
        }
        
        button[aria-label="Toggle Password Visibility"]:active {
            opacity: 0.7;
        }
        
        /* Remember me styling */
        label input[type="checkbox"] + span {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        label:hover input[type="checkbox"] + span {
            color: #2C3E50;
            font-weight: 600;
        }
        
        /* Contact icons animation */
        svg {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        div:has(> svg):hover svg {
            transform: scale(1.2) rotate(5deg);
            filter: drop-shadow(0 4px 8px rgba(212, 175, 55, 0.4));
        }
        
        /* Keyframe animations */
        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.92);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(25px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-25px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes shake {
            0%, 100% {
                transform: translateX(0);
            }
            25% {
                transform: translateX(-8px);
            }
            50% {
                transform: translateX(8px);
            }
            75% {
                transform: translateX(-8px);
            }
        }
        
        @keyframes floatGently {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-15px);
            }
        }
        
        @keyframes checkPulse {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(44, 62, 80, 0.4);
            }
            50% {
                transform: scale(1.15);
                box-shadow: 0 0 0 6px rgba(44, 62, 80, 0);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(44, 62, 80, 0);
            }
        }
        
        @keyframes rotateBg {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-slate-900 min-h-screen flex items-center justify-center p-2 sm:p-3 md:p-4">
    <main class="w-full max-w-5xl bg-white shadow-2xl rounded-lg sm:rounded-custom overflow-hidden flex flex-col md:flex-row min-h-screen sm:min-h-[600px] md:min-h-[500px]">
        <!-- Left Side - Brand & Visuals -->
        <section class="flex md:w-1/2 relative overflow-hidden flex-col justify-center items-center p-6 sm:p-8 md:p-12 text-center bg-gradient-to-br from-brandBlue via-slate-800 to-slate-900" data-purpose="branding-side">
            <div class="relative z-10 mb-4 sm:mb-6 md:mb-8" data-purpose="logo-container">
                <div class="relative inline-block">
                    <div class="absolute inset-0 bg-gradient-to-r from-brandGold via-yellow-300 to-brandGold opacity-0 blur-2xl rounded-full group-hover:opacity-50 transition-opacity duration-300"></div>
                    <div class="max-w-[180px] sm:max-w-[240px] md:max-w-[280px] lg:max-w-[320px] h-auto mx-auto drop-shadow-2xl relative z-10 flex items-center justify-center">
                        <div class="w-32 h-32 sm:w-40 sm:h-40 md:w-48 md:h-48 bg-gradient-to-br from-brandGold to-yellow-400 rounded-3xl flex items-center justify-center shadow-2xl">
                            <span class="material-symbols-outlined text-7xl sm:text-8xl md:text-9xl text-slate-900 font-black">dentistry</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="relative z-10">
                <h2 class="text-base sm:text-lg md:text-2xl font-extrabold mb-2 sm:mb-3 md:mb-4 text-white leading-tight">Excellence in Oral Health</h2>
                <p class="max-w-xs mx-auto leading-relaxed text-xs sm:text-sm md:text-base text-slate-200">
                    Providing modern, compassionate dental care for you and your family in Pulilan.
                </p>
            </div>
        </section>
        <!-- Right Side - Login Form -->
        <section class="w-full md:w-1/2 flex flex-col justify-center p-4 sm:p-6 md:p-8 lg:p-12" data-purpose="login-form-side">
            <header class="mb-6 sm:mb-8">
                <h1 class="text-2xl sm:text-3xl md:text-4xl font-black text-slate-900 mb-1.5 sm:mb-2 tracking-tight">Welcome Back</h1>
                <p class="text-slate-500 text-sm sm:text-base font-medium">Sign in to access your dental clinic account</p>
            </header>
            
            <?php if (!empty($error)): ?>
            <div class="mb-4 sm:mb-6 p-4 sm:p-5 rounded-lg bg-gradient-to-r from-red-50 to-pink-50 border border-red-300 text-red-700 text-xs sm:text-sm font-semibold error-message shadow-md">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 5.5H2m16 5H2m16 5H2" stroke-width="2" stroke="currentColor" stroke-linecap="round"/></svg>
                    <span id="errorMessage"><?php echo htmlspecialchars($error); ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <form action="" method="POST" class="space-y-5" data-purpose="auth-form">
                <!-- Email/Username Field -->
                <div class="group">
                    <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2 sm:mb-3 transition-colors group-focus-within:text-brandBlue" for="identifier">
                        <span class="inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"></path><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"></path></svg>
                            Email Address or Username
                        </span>
                    </label>
                    <input class="w-full px-4 py-2.5 sm:py-3 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 transition-all outline-none font-medium hover:border-gray-300 text-sm group-focus-within:shadow-lg" id="identifier" name="identifier" placeholder="Enter your email or username" required type="text" value="<?php echo $remembered_identifier; ?>"/>
                </div>
                <!-- Password Field -->
                <div class="relative group">
                    <div class="flex justify-between items-center mb-2 sm:mb-3">
                        <label class="block text-xs sm:text-sm font-bold text-slate-700 transition-colors group-focus-within:text-brandBlue" for="password">
                            <span class="inline-flex items-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path></svg>
                                Password
                            </span>
                        </label>
                    </div>
                    <div class="relative" data-purpose="password-input-container">
                        <input class="w-full px-4 py-2.5 sm:py-3 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 transition-all outline-none pr-12 font-medium hover:border-gray-300 text-sm group-focus-within:shadow-lg" id="password" name="password" placeholder="••••••••" required type="password"/>
                        <!-- Show/Hide Password Icon -->
                        <button aria-label="Toggle Password Visibility" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary transition-colors cursor-pointer" id="togglePassword" type="button" onclick="return togglePasswordVisibility(event)">
                            <span class="material-symbols-outlined text-xl" id="passwordIcon">visibility_off</span>
                        </button>
                    </div>
                </div>
                <!-- Remember Me & Forgot Password -->
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between text-xs sm:text-sm gap-3">
                    <label class="flex items-center cursor-pointer group gap-2.5 hover:scale-105 transition-transform">
                        <input class="w-5 h-5 text-primary border-2 border-gray-300 rounded focus:ring-2 focus:ring-primary cursor-pointer transition-colors hover:border-primary" type="checkbox" name="remember_me" <?php echo ($remembered_identifier !== '') ? 'checked' : ''; ?>/>
                        <span class="text-slate-600 group-hover:text-slate-800 transition-colors font-medium">Remember me</span>
                    </label>
                    <a href="forgotpassword.php" class="text-primary hover:text-brandGold font-semibold transition-colors hover:underline">Forgot password?</a>
                </div>
                <!-- Login Button -->
                <button class="w-full bg-brandBlue hover:bg-blue-700 active:bg-blue-800 text-white font-bold py-2.5 sm:py-3 px-4 rounded-lg shadow-lg hover:shadow-xl transition-all mt-6 disabled:opacity-70 text-sm sm:text-base relative overflow-hidden" type="submit">
                    <span class="inline-block">Log In</span>
                </button>

            </form>
            <!-- Registration Call to Action -->
            <footer class="mt-8 sm:mt-10 text-center border-t border-gray-200 pt-6 sm:pt-8" data-purpose="registration-footer">
                <div class="mb-8 pb-6 border-b border-gray-200">
                    <p class="text-slate-700 mb-4 font-bold text-sm tracking-wide">Want to register your clinic?</p>
                    <a href="register-clinic.php" class="inline-block bg-gradient-to-r from-brandGold to-yellow-400 hover:from-brandGold hover:to-yellow-500 text-slate-900 font-bold py-2.5 px-6 rounded-lg shadow-lg hover:shadow-xl transition-all text-sm">
                        <span class="inline-flex items-center gap-2">
                            <span class="material-symbols-outlined">business</span>
                            Register Your Clinic
                        </span>
                    </a>
                    <p class="text-xs text-slate-500 mt-3">Complete registration and await super admin approval</p>
                </div>

                <p class="text-slate-700 mb-4 sm:mb-6 font-bold text-sm tracking-wide">New patients or inquiries:<span class="text-brandGold">?</span></p>
                <div class="space-y-3 sm:space-y-4 text-xs sm:text-sm">
                    <div class="flex items-center justify-center gap-3 text-slate-700 font-medium hover:text-brandBlue transition-all cursor-pointer group">
                        <div class="p-2 bg-gradient-to-br from-brandGold/10 to-brandGold/5 rounded-lg group-hover:from-brandGold/20 group-hover:to-brandGold/10 transition-all">
                            <svg class="w-5 h-5 text-brandGold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                        </div>
                        <span>Call: <a class="text-brandBlue hover:text-brandGold transition-colors font-bold" href="tel:09253102892">0925 310 2892</a></span>
                    </div>
                    <div class="flex items-center justify-center gap-3 text-slate-700 font-medium hover:text-brandBlue transition-all cursor-pointer group">
                        <div class="p-2 bg-gradient-to-br from-brandGold/10 to-brandGold/5 rounded-lg group-hover:from-brandGold/20 group-hover:to-brandGold/10 transition-all">
                            <svg class="w-5 h-5 text-brandGold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                        </div>
                        <a class="hover:text-brandGold transition-colors break-all" href="mailto:francissantiagonationalu@gmail.com">francissantiagonationalu@gmail.com</a>
                    </div>
                </div>
                <div class="flex items-center justify-center gap-2 text-slate-500 text-[11px] sm:text-xs italic mt-5 pt-4 border-t border-gray-100">
                    <svg class="w-4 h-4 text-brandGold flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    <span>9147 Cagayan Valley Road, Pulilan, Philippines, 3005</span>
                </div>
            </footer>
        </section>
    </main>

    <script data-purpose="event-handlers">
        // Password Visibility Toggle Logic
        function togglePasswordVisibility(e) {
            e.preventDefault();
            e.stopPropagation();
            const passwordInput = document.getElementById('password');
            const icon = document.getElementById('passwordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.textContent = 'visibility';
            } else {
                passwordInput.type = 'password';
                icon.textContent = 'visibility_off';
            }
            return false;
        }

        // Countdown timer removed - no more lockout
        
        // Add smooth focus effects to form inputs
        const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.classList.add('input-focused');
            });
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.classList.remove('input-focused');
            });
        });

        // Add ripple effect on button click
        const submitBtn = document.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.addEventListener('mousedown', function(e) {
                if (e.button !== 0) return; // Only left click
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => ripple.remove(), 600);
            });
        }

        // Add intersection observer for lazy animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'none';
                    setTimeout(() => {
                        entry.target.style.animation = '';
                    }, 10);
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('form div').forEach(el => {
            observer.observe(el);
        });
    </script>

    <style data-purpose="dynamic-animations">
        .input-focused {
            animation: inputGlow 0.4s ease !important;
        }

        @keyframes inputGlow {
            from {
                filter: drop-shadow(0 0 0 rgba(44, 62, 80, 0));
            }
            to {
                filter: drop-shadow(0 0 5px rgba(44, 62, 80, 0.2));
            }
        }

        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            pointer-events: none;
            animation: rippleAnimation 0.6s ease-out;
        }

        @keyframes rippleAnimation {
            from {
                opacity: 1;
                transform: scale(0);
            }
            to {
                opacity: 0;
                transform: scale(1);
            }
        }

        @keyframes pulse-light {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }
    </style>
</body>
</html>
