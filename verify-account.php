<?php
session_start();
// Check if token is in URL
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Email Verification - San Nicolas Dental Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
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
        
        @keyframes bounceIn {
            0% { transform: scale(0); }
            60% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
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
        
        .verification-card {
            animation: slideInUp 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) 0.15s forwards;
            opacity: 0;
            box-shadow: 0 25px 60px -15px rgba(30, 58, 95, 0.2), 
                        0 0 1px rgba(30, 58, 95, 0.1),
                        inset 0 1px 0 rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(30, 58, 95, 0.1);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.95) 100%);
        }
        
        .verification-card:hover {
            box-shadow: 0 30px 80px -20px rgba(30, 58, 95, 0.25), 
                        0 0 2px rgba(30, 58, 95, 0.15),
                        inset 0 1px 0 rgba(255, 255, 255, 0.6);
            transform: translateY(-4px);
        }
        
        .icon-container {
            animation: bounceIn 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s forwards;
            animation-fill-mode: both;
        }
        
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        
        .success-icon {
            color: #10b981;
            filter: drop-shadow(0 8px 16px rgba(16, 185, 129, 0.3));
        }
        
        .error-icon {
            color: #ef4444;
            filter: drop-shadow(0 8px 16px rgba(239, 68, 68, 0.3));
        }
        
        .content-fade {
            animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.5s forwards;
            opacity: 0;
        }
        
        .user-info-box {
            background: linear-gradient(135deg, rgba(30, 58, 95, 0.05) 0%, rgba(212, 168, 75, 0.05) 100%);
            border: 1px solid rgba(30, 58, 95, 0.1);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .user-info-box:hover {
            background: linear-gradient(135deg, rgba(30, 58, 95, 0.08) 0%, rgba(212, 168, 75, 0.08) 100%);
            border-color: rgba(30, 58, 95, 0.2);
            transform: translateY(-2px);
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

        *:focus, *:focus-visible, *:active {
            outline: none !important;
            outline-offset: 0 !important;
        }

        .error-card {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05) 0%, rgba(239, 68, 68, 0.08) 100%);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .warning-text {
            color: #dc2626;
            font-size: 0.875rem;
            line-height: 1.5;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen font-display">

<div class="min-h-screen flex flex-col items-center justify-center py-12 px-6">
    <!-- Centered Logo -->
    <div class="flex flex-col items-center mb-12 cursor-pointer logo-header transition-all hover:opacity-80" onclick="window.location.href='index.php'">
        <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-24 w-auto mb-3 drop-shadow-lg">
        <p class="text-primary text-[10px] font-bold uppercase tracking-widest">Clinic Management Portal</p>
    </div>

    <!-- Main Verification Container -->
    <div class="w-full max-w-[640px] bg-white dark:bg-surface-dark px-6 md:px-8 lg:px-12 py-10 md:py-12 lg:py-14 rounded-3xl shadow-xl shadow-slate-200/50 dark:shadow-none border border-slate-100 dark:border-border-dark verification-card">
        
        <!-- Loading State -->
        <div id="loadingState" class="flex flex-col items-center justify-center min-h-[500px]">
            <div class="icon-container">
                <span class="material-symbols-outlined text-6xl loading-spinner text-primary">hourglass_empty</span>
            </div>
            <h2 class="text-2xl font-black tracking-tight mt-6 text-slate-900 dark:text-white">Verifying Email</h2>
            <p class="text-slate-500 dark:text-slate-400 font-medium mt-2">Please wait while we verify your email address...</p>
        </div>

        <!-- Success State -->
        <div id="successState" class="hidden flex flex-col items-center justify-center text-center">
            <div class="icon-container mb-6">
                <span class="material-symbols-outlined text-7xl success-icon">check_circle</span>
            </div>
            
            <h2 class="text-3xl font-black tracking-tight mb-2 text-slate-900 dark:text-white">Email Verified!</h2>
            <p class="text-slate-600 dark:text-slate-300 font-medium mb-8">Your account is now fully activated and ready to use.</p>

            <!-- User Info Display -->
            <div class="w-full space-y-3 mb-8">
                <div class="user-info-box px-4 py-3 rounded-xl">
                    <p class="text-xs font-black uppercase text-slate-500 dark:text-slate-400 tracking-wider mb-1">Your Name</p>
                    <p id="userNameDisplay" class="text-lg font-bold text-slate-900 dark:text-white">Loading...</p>
                </div>
                <div class="user-info-box px-4 py-3 rounded-xl">
                    <p class="text-xs font-black uppercase text-slate-500 dark:text-slate-400 tracking-wider mb-1">Email Address</p>
                    <p id="userEmailDisplay" class="text-lg font-bold text-slate-900 dark:text-white break-all">Loading...</p>
                </div>
                <div class="user-info-box px-4 py-3 rounded-xl">
                    <p class="text-xs font-black uppercase text-slate-500 dark:text-slate-400 tracking-wider mb-1">Username</p>
                    <p id="userUsernameDisplay" class="text-lg font-bold text-slate-900 dark:text-white">Loading...</p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="w-full flex flex-col sm:flex-row gap-3">
                <button onclick="window.location.href='login.php'" class="flex-1 h-12 bg-gradient-to-r from-primary to-primary-hover hover:from-primary-hover hover:to-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/25 transition-all flex items-center justify-center gap-2 active:scale-95 group relative">
                    <span class="relative z-10">Login Now</span>
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform relative z-10 text-lg">arrow_forward</span>
                </button>
                <button onclick="window.location.href='index.php'" class="flex-1 h-12 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-white rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2 group relative">
                    <span class="relative z-10">Back to Home</span>
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform relative z-10 text-lg">home</span>
                </button>
            </div>
        </div>

        <!-- Error State -->
        <div id="errorState" class="hidden flex flex-col items-center justify-center text-center">
            <div class="icon-container mb-6">
                <span class="material-symbols-outlined text-7xl error-icon">error</span>
            </div>
            
            <h2 class="text-3xl font-black tracking-tight mb-2 text-slate-900 dark:text-white">Verification Failed</h2>
            <p id="errorMessage" class="warning-text mb-8">An error occurred during email verification.</p>

            <!-- Error Details Box -->
            <div class="error-card px-4 py-4 rounded-xl w-full mb-8 text-left">
                <p class="text-xs font-black uppercase text-red-600 dark:text-red-400 tracking-wider mb-2">Error Details</p>
                <p id="errorDetail" class="text-sm text-slate-700 dark:text-slate-300 font-medium">Checking verification details...</p>
            </div>

            <!-- Action Buttons -->
            <div class="w-full flex flex-col sm:flex-row gap-3">
                <button onclick="location.reload()" class="flex-1 h-12 bg-gradient-to-r from-primary to-primary-hover hover:from-primary-hover hover:to-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/25 transition-all flex items-center justify-center gap-2 active:scale-95 group relative">
                    <span class="relative z-10">Try Again</span>
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform relative z-10 text-lg">refresh</span>
                </button>
                <button onclick="window.location.href='register.php'" class="flex-1 h-12 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-white rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2 group relative">
                    <span class="relative z-10">Register Again</span>
                    <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform relative z-10 text-lg">app_registration</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <p class="mt-8 text-sm text-slate-500 font-medium tracking-tight">
        Protected by medical grade security © <?php echo date('Y'); ?> San Nicolas Dental Clinic
    </p>
</div>

<script>
    /**
     * HANDLE EMAIL VERIFICATION
     */
    async function verifyEmail() {
        const token = new URLSearchParams(window.location.search).get('token');
        
        if (!token) {
            showError('Missing verification token', 'No token was provided in the verification link.');
            return;
        }

        try {
            // Use absolute path for API endpoint to work on both localhost and production
            const apiPath = window.location.pathname.includes('sanfranciscosystem') 
                ? '/sanfranciscosystem/backend/verify-email.php' 
                : '/backend/verify-email.php';
            const response = await fetch(apiPath + '?token=' + encodeURIComponent(token));
            const data = await response.json();

            if (data.success && data.user) {
                showSuccess(data.user);
            } else {
                showError(data.message || 'Verification failed', data.message || 'An unknown error occurred during verification.');
            }
        } catch (error) {
            console.error('Verification error:', error);
            showError('Connection Error', 'Failed to connect to the verification service. Please try again.');
        }
    }

    /**
     * SHOW SUCCESS STATE
     */
    function showSuccess(user) {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('errorState').classList.add('hidden');
        document.getElementById('successState').classList.remove('hidden');
        
        // Populate user information
        document.getElementById('userNameDisplay').textContent = (user.first_name || 'User');
        document.getElementById('userEmailDisplay').textContent = (user.email || 'N/A');
        document.getElementById('userUsernameDisplay').textContent = (user.username || 'N/A');
    }

    /**
     * SHOW ERROR STATE
     */
    function showError(title, message) {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('successState').classList.add('hidden');
        document.getElementById('errorState').classList.remove('hidden');
        
        document.getElementById('errorMessage').textContent = title;
        document.getElementById('errorDetail').textContent = message;
    }

    /**
     * INIT: Start verification on page load
     */
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(verifyEmail, 500); // Small delay for smooth animation
    });
</script>
</body>
</html>
