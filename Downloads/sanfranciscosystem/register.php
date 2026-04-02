<?php
session_start();
$msg = "";
$msgType = "";

// Handle backend errors or success redirected via URL
if (isset($_GET['error'])) {
    $msgType = "error";
    if ($_GET['error'] === 'exists') {
        $msg = "Account already exists with this email or username.";
    } elseif ($_GET['error'] === 'email_exists') {
        $msg = $_GET['msg'] ?? "This email address is already registered. Please use a different email or contact support.";
    } elseif ($_GET['error'] === 'username_exists') {
        $msg = $_GET['msg'] ?? "This username is already taken. Please choose a different username.";
    } elseif ($_GET['error'] === 'invalid_email') {
        $msg = $_GET['msg'] ?? "Please enter a valid email address.";
    } elseif ($_GET['error'] === 'db') {
        $msg = "Database error: " . ($_GET['msg'] ?? 'Registration failed.');
    }
}

// Handle success notification
if (isset($_GET['notif']) && $_GET['notif'] === 'added') {
    $msg = "Account Created Successfully";
    $msgType = "success";
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Patient Registration - San Nicolas Dental Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#1e3a5f",
                        "primary-hover": "#152a45",
                        "accent": "#d4a84b",
                        "accent-hover": "#b8923f",
                        "background-light": "#f8fafc",
                        "background-dark": "#0f172a",
                        "surface-light": "#ffffff",
                        "surface-dark": "#1e293b",
                        "border-light": "#e2e8f0",
                        "border-dark": "#334155",
                    },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
        }
    </script>
    <style>
        * { scroll-behavior: smooth; }
        body { font-family: 'Manrope', sans-serif; }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f0f4f8 50%, #fef9f3 100%);
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: radial-gradient(circle at 20% 30%, rgba(30, 58, 95, 0.12) 0%, transparent 50%), 
                              radial-gradient(circle at 80% 70%, rgba(212, 168, 75, 0.12) 0%, transparent 50%),
                              radial-gradient(circle at 40% 60%, rgba(30, 58, 95, 0.06) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .min-h-screen {
            position: relative;
            z-index: 1;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
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
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.93);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }
            100% {
                background-position: 1000px 0;
            }
        }
        
        .logo-header {
            animation: fadeInDown 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        .logo-header img {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .logo-header:hover img {
            transform: scale(1.08);
            filter: drop-shadow(0 12px 24px rgba(30, 58, 95, 0.3));
        }
        
        .form-card {
            animation: slideInUp 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) 0.15s forwards;
            opacity: 0;
            box-shadow: 0 25px 60px -15px rgba(30, 58, 95, 0.2), 
                        0 0 1px rgba(30, 58, 95, 0.1),
                        inset 0 1px 0 rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(30, 58, 95, 0.1);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.95) 100%);
        }
        
        .form-card:hover {
            box-shadow: 0 30px 80px -20px rgba(30, 58, 95, 0.25), 
                        0 0 2px rgba(30, 58, 95, 0.15),
                        inset 0 1px 0 rgba(255, 255, 255, 0.6);
            transform: translateY(-4px);
        }
        
        section {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation-fill-mode: both;
        }
        
        section:nth-child(1) { animation-delay: 0.25s; }
        section:nth-child(2) { animation-delay: 0.40s; }
        section:nth-child(3) { animation-delay: 0.55s; }
        
        section > div:first-child {
            background: linear-gradient(90deg, rgba(212, 168, 75, 0.05), transparent);
            border-radius: 0.75rem;
            padding: 0.75rem 0;
        }
        
        input, textarea, select {
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
        }
        
        input:focus, textarea:focus, select:focus {
            transform: translateY(-3px);
            background: #ffffff;
        }
        
        input:disabled {
            background: #f3f4f6 !important;
            opacity: 0.65;
            cursor: not-allowed;
        }
        
        .dark input:disabled {
            background: #374151 !important;
        }
        
        button {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
        }
        
        button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        button:active::before {
            width: 300px;
            height: 300px;
        }
        
        button:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(30, 58, 95, 0.3);
        }
        
        button:active:not(:disabled) {
            transform: scale(0.97) translateY(-1px);
        }
        
        button:disabled {
            opacity: 0.65;
            transform: none;
            cursor: not-allowed;
        }
        
        .requirement-item {
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            color: #94a3b8;
            padding: 0.4rem 0;
        }
        
        .requirement-item.valid { 
            color: #10b981;
            transform: translateX(4px);
        }
        
        .requirement-item.valid span { 
            font-variation-settings: 'FILL' 1;
            animation: fadeIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        @keyframes slideIn { 
            from { 
                opacity: 0; 
                transform: translate(-50%, 25px); 
            } 
            to { 
                opacity: 1; 
                transform: translate(-50%, 0); 
            } 
        }
        
        .animate-slide-in { 
            animation: slideIn 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; 
        }
        
        header {
            animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.15s forwards;
            opacity: 0;
        }
        
        /* Input field enhancements */
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        input[type="password"],
        select {
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
        }
        
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="tel"]:focus,
        input[type="date"]:focus,
        input[type="password"]:focus,
        select:focus {
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(212, 168, 75, 0.15);
        }
        
        .dark input[type="text"]:focus,
        .dark input[type="email"]:focus,
        .dark input[type="tel"]:focus,
        .dark input[type="date"]:focus,
        .dark input[type="password"]:focus,
        .dark select:focus {
            box-shadow: 0 0 0 3px rgba(212, 168, 75, 0.2);
        }

        /* Centered Success Card Styling */
        #validationUI.is-success {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) !important;
            bottom: auto;
            width: 90%;
            max-width: 540px;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.98) 0%, rgba(15, 23, 42, 0.96) 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 4rem 2.5rem;
            border-radius: 2.5rem;
            border: 1px solid rgba(16, 185, 129, 0.3);
            z-index: 1000;
            box-shadow: 0 0 0 100vmax rgba(0,0,0,0.65), 
                        0 30px 80px rgba(16, 185, 129, 0.4),
                        inset 0 1px 0 rgba(255, 255, 255, 0.1);
            animation: scaleIn 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        #validationUI.is-success .success-subtitle {
            color: #cbd5e1;
            font-size: 0.875rem;
            margin-top: 0.75rem;
            line-height: 1.5;
        }
        
        #validationUI.is-success .resend-link {
            color: #d4a84b;
            cursor: pointer;
            text-decoration: underline;
            font-weight: 600;
            transition: opacity 0.3s;
        }
        
        #validationUI.is-success .resend-link:hover {
            opacity: 0.8;
        }
        
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: translate(-50%, -50%) scale(0.85);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }
        
        @keyframes bounceIn {
            0% { transform: scale(0); }
            60% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        #validationUI.is-success #toastIcon {
            font-size: 5.5rem;
            margin-bottom: 1.75rem;
            color: #10b981;
            animation: bounceIn 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            filter: drop-shadow(0 8px 16px rgba(16, 185, 129, 0.3));
        }
        
        #validationUI.is-success #validationMsg {
            font-size: 1.6rem;
            line-height: 2.2rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: white;
            animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s forwards;
            opacity: 0;
        }
            
        /* Remove all focus rings and outlines */
        button:focus, button:focus-visible, button:active,
        input:focus, input:focus-visible, input:active,
        select:focus, select:focus-visible, select:active,
        textarea:focus, textarea:focus-visible, textarea:active,
        a:focus, a:focus-visible, a:active,
        *:focus, *:focus-visible, *:active {
            outline: none !important;
            outline-offset: 0 !important;
            box-shadow: none !important;
        }
        
        .focus\:ring-0:focus, [class*="focus:ring"]:focus {
            box-shadow: none !important;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen font-display">

<!-- Hidden element to store server messages safely -->
<div id="php-data" 
     data-msg="<?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>" 
     data-msg-type="<?php echo htmlspecialchars($msgType, ENT_QUOTES, 'UTF-8'); ?>" 
     style="display: none;"></div>

<!-- Notification System -->
<div id="validationUI" class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[200] hidden items-center gap-3 px-6 py-4 rounded-2xl bg-slate-900 text-white border border-slate-700 shadow-2xl animate-slide-in">
    <span id="toastIcon" class="material-symbols-outlined text-orange-400">warning</span>
    <span id="validationMsg" class="font-bold text-sm"></span>
</div>

<div class="min-h-screen flex flex-col items-center py-12 px-6">
    <!-- Centered Logo -->
    <div class="flex flex-col items-center mb-10 cursor-pointer logo-header transition-all hover:opacity-80" onclick="window.location.href='index.php'">
        <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-24 w-auto mb-3 drop-shadow-lg">
        <p class="text-primary text-[10px] font-bold uppercase tracking-widest">Clinic Management Portal</p>
    </div>

    <!-- Main Form Container -->
    <div class="w-full max-w-[640px] bg-white dark:bg-surface-dark px-6 md:px-8 lg:px-12 py-8 md:py-10 lg:py-12 rounded-3xl shadow-xl shadow-slate-200/50 dark:shadow-none border border-slate-100 dark:border-border-dark form-card">
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-10">
            <div>
                <h2 class="text-3xl font-black tracking-tight mb-2 text-slate-900">Create Account</h2>
                <p class="text-slate-500 font-medium">Please enter your clinical details</p>
            </div>
            <a href="login.php" class="text-sm font-bold text-primary hover:text-primary-hover transition-colors whitespace-nowrap">Already registered?</a>
        </header>

        <form id="registrationForm" action="backend/process_registration.php" method="POST" class="space-y-8">
            
            <!-- Section: Personal -->
            <section>
                <div class="flex items-center gap-2 mb-6">
                    <span class="text-xs font-black uppercase tracking-widest text-primary bg-gradient-to-r from-primary/10 to-transparent px-3 py-1.5 rounded-lg">01 / Personal Info</span>
                    <div class="h-px flex-1 bg-gradient-to-r from-slate-300 to-transparent dark:from-slate-700"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700 dark:text-slate-300">First Name</label>
                        <input id="first_name" name="first_name" required type="text" placeholder="Jane"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 focus:ring-0 focus:border-primary focus:bg-white transition-all outline-none font-semibold shadow-sm hover:shadow-md"/>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700 dark:text-slate-300">Last Name</label>
                        <input id="last_name" name="last_name" required type="text" placeholder="Doe"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 focus:ring-0 focus:border-primary focus:bg-white transition-all outline-none font-semibold shadow-sm hover:shadow-md"/>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700 dark:text-slate-300">Date of Birth</label>
                        <input name="dob" id="dob-field" required type="date"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 focus:ring-0 focus:border-primary focus:bg-white transition-all outline-none font-semibold shadow-sm hover:shadow-md"/>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700 dark:text-slate-300">Phone Number</label>
                        <input name="phone" id="phone-field" pattern="09[0-9]{9}" maxlength="11" required type="tel" placeholder="09123456789"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 focus:ring-0 focus:border-primary focus:bg-white transition-all outline-none font-semibold shadow-sm hover:shadow-md"/>
                    </div>
                </div>
            </section>

            <!-- Section: Account -->
            <section>
                <div class="flex items-center gap-2 mb-6">
                    <span class="text-xs font-black uppercase tracking-widest text-primary bg-gradient-to-r from-primary/10 to-transparent px-3 py-1.5 rounded-lg">02 / Credentials</span>
                    <div class="h-px flex-1 bg-gradient-to-r from-slate-300 to-transparent dark:from-slate-700"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700 dark:text-slate-300">Email Address</label>
                        <input id="email" name="email" required type="email" placeholder="jane@example.com"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 focus:ring-0 focus:border-primary focus:bg-white transition-all outline-none font-semibold shadow-sm hover:shadow-md"/>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700 dark:text-slate-300">Username</label>
                        <input id="username" name="username" required type="text" placeholder="janedoe23"
                            class="w-full h-12 px-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 focus:ring-0 focus:border-primary focus:bg-white transition-all outline-none font-semibold shadow-sm hover:shadow-md"/>
                    </div>
                </div>
            </section>

            <!-- Section: Security -->
            <section>
                <div class="flex items-center gap-2 mb-6">
                    <span class="text-xs font-black uppercase tracking-widest text-primary bg-gradient-to-r from-primary/10 to-transparent px-3 py-1.5 rounded-lg">03 / Security</span>
                    <div class="h-px flex-1 bg-gradient-to-r from-slate-300 to-transparent dark:from-slate-700"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700 dark:text-slate-300">Password</label>
                        <div class="relative">
                            <input id="password-field" name="password" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" required type="password" placeholder="••••••••"
                                onkeyup="checkPasswordStrength(this.value)"
                                class="w-full h-12 px-4 pr-12 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 focus:ring-0 focus:border-primary focus:bg-white transition-all outline-none font-semibold shadow-sm hover:shadow-md"/>
                            <button type="button" onclick="togglePassword()" id="togglePasswordBtn" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary transition-colors">
                                <span class="material-symbols-outlined text-xl">visibility_off</span>
                            </button>
                        </div>
                    </div>
                    <div class="p-4 bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-800/50 dark:to-slate-800/30 rounded-xl border border-slate-100 dark:border-slate-800 space-y-2 shadow-sm">
                        <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Security Strength</p>
                        <div id="req-length" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all"><span class="material-symbols-outlined text-[14px]">check_circle</span> 8+ Characters</div>
                        <div id="req-upper" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all"><span class="material-symbols-outlined text-[14px]">check_circle</span> Uppercase letter</div>
                        <div id="req-lower" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all"><span class="material-symbols-outlined text-[14px]">check_circle</span> Lowercase letter</div>
                        <div id="req-number" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all"><span class="material-symbols-outlined text-[14px]">check_circle</span> Numbers included</div>
                    </div>
                </div>
            </section>

            <div class="pt-6">
                <button id="submitBtn" type="submit" class="w-full h-14 bg-gradient-to-r from-primary to-primary-hover hover:from-primary-hover hover:to-primary text-white rounded-xl font-bold text-lg shadow-lg shadow-primary/25 transition-all flex items-center justify-center gap-2.5 active:scale-95 disabled:opacity-65 group relative">
                    <span class="relative z-10">Register Account</span>
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform relative z-10">arrow_forward</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Footer link -->
    <p class="mt-8 text-sm text-slate-500 font-medium tracking-tight">
        Protected by medical grade security © <?php echo date('Y'); ?> San Nicolas Dental Clinic
    </p>
</div>

<script>
    /**
     * UI ERROR SYSTEM
     */
    function triggerUIError(message, type = 'warning', showResend = false, email = '') {
        const toast = document.getElementById('validationUI');
        const text = document.getElementById('validationMsg');
        const icon = document.getElementById('toastIcon');
        
        if (!toast || !text || !icon) return;
        
        if (type === 'success') {
            text.innerHTML = `
                <div style="margin-bottom: 1.5rem;">Account Created Successfully</div>
                <div class="success-subtitle">
                    A verification email has been sent to:
                </div>
                <div style="background-color: rgba(255,255,255,0.1); padding: 1rem; border-radius: 0.75rem; margin: 1rem 0; border: 1px solid rgba(255,255,255,0.2);">
                    <div class="success-subtitle" style="font-size: 0.75rem; color: #94a3b8; margin-top: 0; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em;">📧 Email Recipient</div>
                    <div style="color: white; font-weight: 700; font-size: 1rem; word-break: break-all;">${email}</div>
                </div>
                <div style="background-color: rgba(212, 168, 75, 0.1); padding: 1rem; border-radius: 0.75rem; margin: 1rem 0; border: 1px solid rgba(212, 168, 75, 0.3);">
                    <div class="success-subtitle" style="font-size: 0.75rem; color: #d4a84b; margin-top: 0; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em;">📬 Email From</div>
                    <div style="color: white; font-weight: 700; font-size: 1rem;">San Nicolas Dental Clinic</div>
                </div>
                <div class="success-subtitle" style="margin: 1rem 0 0 0; font-size: 0.875rem;">
                    Please check your <strong>inbox</strong> (or spam folder) for the verification email and click the link inside to complete your registration.
                </div>
                ${showResend ? `<div class="success-subtitle" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1);">
                    Didn't receive the email? <span class="resend-link" onclick="resendVerificationEmail('${email}')">Resend verification link</span>
                </div>` : ''}
            `;
            icon.innerText = 'check_circle';
            icon.className = 'material-symbols-outlined text-green-400';
            toast.classList.add('is-success');
        } else {
            text.innerText = message;
            icon.innerText = 'warning';
            icon.className = 'material-symbols-outlined text-orange-400';
            toast.classList.remove('is-success');
        }
        
        toast.classList.remove('hidden'); 
        toast.classList.add('flex');
        
        if (type !== 'success') {
            setTimeout(() => { toast.classList.add('hidden'); }, 3000);
        }
    }

    /**
     * RESEND VERIFICATION EMAIL
     */
    function resendVerificationEmail(email) {
        const resendBtn = document.querySelector('.resend-link');
        resendBtn.textContent = 'Sending...';
        resendBtn.style.pointerEvents = 'none';
        
        fetch('backend/resend_verification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'email=' + encodeURIComponent(email)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                resendBtn.textContent = 'Verification email sent! Check your inbox.';
                resendBtn.style.color = '#10b981';
                setTimeout(() => {
                    resendBtn.textContent = 'Resend verification link';
                    resendBtn.style.pointerEvents = 'auto';
                }, 5000);
            } else {
                resendBtn.textContent = 'Failed to send. Try again.';
                setTimeout(() => {
                    resendBtn.textContent = 'Resend verification link';
                    resendBtn.style.pointerEvents = 'auto';
                }, 3000);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            resendBtn.textContent = 'Error sending email';
            resendBtn.style.pointerEvents = 'auto';
        });
    }

    /**
     * CAPTURE SERVER MESSAGES SAFELY
     */
    document.addEventListener('DOMContentLoaded', function() {
        const phpData = document.getElementById('php-data');
        if (phpData) {
            const serverMsg = phpData.getAttribute('data-msg');
            const serverMsgType = phpData.getAttribute('data-msg-type');
            
            if (serverMsg && serverMsg.trim() !== "") {
                triggerUIError(serverMsg, serverMsgType);
            }
        }
    });

    /**
     * FORM SUBMISSION HANDLING
     */
    document.getElementById('registrationForm').addEventListener('submit', function(e) {
        const firstNameField = document.getElementById('first_name');
        const lastNameField = document.getElementById('last_name');
        const phoneField = document.getElementById('phone-field');
        const dobField = document.getElementById('dob-field');
        const passField = document.getElementById('password-field');
        const emailField = document.getElementById('email');
        const usernameField = document.getElementById('username');
        const submitBtn = document.getElementById('submitBtn');

        let hasError = false;
        const errorMessages = [];

        // First Name validation
        const fnameVal = firstNameField.value.trim();
        if (!fnameVal) {
            firstNameField.classList.add('border-red-500');
            errorMessages.push("First name is required.");
            hasError = true;
        } else {
            firstNameField.classList.remove('border-red-500');
        }

        // Last Name validation
        const lnameVal = lastNameField.value.trim();
        if (!lnameVal) {
            lastNameField.classList.add('border-red-500');
            errorMessages.push("Last name is required.");
            hasError = true;
        } else {
            lastNameField.classList.remove('border-red-500');
        }

        // Email validation
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(emailField.value)) {
            emailField.classList.add('border-red-500');
            errorMessages.push("Please enter a valid email address.");
            hasError = true;
        } else {
            emailField.classList.remove('border-red-500');
        }

        // Username validation (alphanumeric and underscore only, 3+ chars)
        if (!/^[a-zA-Z0-9_]{3,}$/.test(usernameField.value)) {
            usernameField.classList.add('border-red-500');
            errorMessages.push("Username must be at least 3 characters (letters, numbers, underscore only).");
            hasError = true;
        } else {
            usernameField.classList.remove('border-red-500');
        }

        // Phone validation (09 prefix, 11 total digits)
        if (!/^09[0-9]{9}$/.test(phoneField.value)) {
            phoneField.classList.add('border-red-500');
            errorMessages.push("Phone must be in format 09XXXXXXXXX (11 digits starting with 09).");
            hasError = true;
        } else {
            phoneField.classList.remove('border-red-500');
        }

        // DOB validation
        if (!dobField.value) {
            dobField.classList.add('border-red-500');
            errorMessages.push("Date of birth is required.");
            hasError = true;
        } else if (new Date(dobField.value) > new Date()) {
            dobField.classList.add('border-red-500');
            errorMessages.push("Date of birth cannot be in the future.");
            hasError = true;
        } else {
            dobField.classList.remove('border-red-500');
        }

        // Password validation
        const passReq = /(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/;
        if (!passField.value) {
            passField.classList.add('border-red-500');
            errorMessages.push("Password is required.");
            hasError = true;
        } else if (!passReq.test(passField.value)) {
            passField.classList.add('border-red-500');
            errorMessages.push("Password must be 8+ characters with uppercase, lowercase, and numbers.");
            hasError = true;
        } else {
            passField.classList.remove('border-red-500');
        }

        if (hasError) {
            e.preventDefault();
            const msg = errorMessages.length === 1 ? errorMessages[0] : "Please fix the errors above.";
            triggerUIError(msg, "error");
            return;
        }

        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.innerHTML = `<span class="material-symbols-outlined animate-spin">progress_activity</span> Processing...`;
        
        const userEmail = emailField.value.trim();
        const formData = new FormData(this);
        
        fetch(this.action, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                triggerUIError("Account Created Successfully", "success", true, userEmail);
                // Clear form
                document.getElementById('registrationForm').reset();
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<span class="material-symbols-outlined">verified</span> Register Account`;
            } else {
                throw new Error(data.message || "Registration failed");
            }
        })
        .catch(error => {
            console.error('Error:', error);
            submitBtn.disabled = false;
            submitBtn.innerHTML = `<span class="material-symbols-outlined">arrow_forward</span> Register Account`;
            triggerUIError(error.message || "Registration failed. Please try again.", "error");
        });
    });

    /**
     * REAL-TIME EMAIL & USERNAME VALIDATION
     */
    let emailCheckTimeout;
    let usernameCheckTimeout;

    document.getElementById('email').addEventListener('input', function() {
        clearTimeout(emailCheckTimeout);
        const email = this.value.trim();
        
        if (!email) return;

        // Email format validation
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(email)) {
            this.classList.add('border-red-500');
            return;
        }

        this.classList.remove('border-red-500');

        // Check if email is already registered
        emailCheckTimeout = setTimeout(() => {
            fetch('backend/check-email.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'email=' + encodeURIComponent(email)
            })
            .then(res => res.json())
            .then(data => {
                if (data.exists) {
                    this.classList.add('border-red-500');
                    triggerUIError("This email is already registered. Please log in or use a different email.", "error");
                } else {
                    this.classList.remove('border-red-500');
                }
            })
            .catch(err => console.error('Email check error:', err));
        }, 500); // Debounce 500ms
    });

    document.getElementById('username').addEventListener('input', function() {
        clearTimeout(usernameCheckTimeout);
        const username = this.value.trim();
        
        if (!username) return;

        // Username format validation
        if (!/^[a-zA-Z0-9_]{3,}$/.test(username)) {
            this.classList.add('border-red-500');
            return;
        }

        this.classList.remove('border-red-500');

        // Check if username is already taken
        usernameCheckTimeout = setTimeout(() => {
            fetch('backend/check-username.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'username=' + encodeURIComponent(username)
            })
            .then(res => res.json())
            .then(data => {
                if (data.exists) {
                    this.classList.add('border-red-500');
                    triggerUIError("This username is already taken. Please choose a different one.", "error");
                } else {
                    this.classList.remove('border-red-500');
                }
            })
            .catch(err => console.error('Username check error:', err));
        }, 500); 
    });

    function togglePassword() {
        const f = document.getElementById('password-field');
        const b = document.getElementById('togglePasswordBtn').querySelector('span');
        const isP = f.type === 'password';
        f.type = isP ? 'text' : 'password';
        b.textContent = isP ? 'visibility' : 'visibility_off';
    }

    function checkPasswordStrength(val) {
        const reqs = {
            length: val.length >= 8,
            upper: /[A-Z]/.test(val),
            lower: /[a-z]/.test(val),
            number: /[0-9]/.test(val)
        };
        Object.keys(reqs).forEach(key => {
            const el = document.getElementById(`req-${key}`);
            if (el) {
                if (reqs[key]) el.classList.add('valid');
                else el.classList.remove('valid');
            }
        });
    }

    // Set date restriction
    const dobInput = document.getElementById('dob-field');
    if (dobInput) dobInput.setAttribute('max', new Date().toISOString().split('T')[0]);
</script>
</body>
</html>
