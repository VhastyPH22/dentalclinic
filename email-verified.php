<?php
session_start();

// Get the token from URL
$token = isset($_GET['token']) ? htmlspecialchars($_GET['token'], ENT_QUOTES, 'UTF-8') : '';
$status = isset($_GET['status']) ? htmlspecialchars($_GET['status'], ENT_QUOTES, 'UTF-8') : '';
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message'], ENT_QUOTES, 'UTF-8') : '';

// Determine if verification was successful based on status parameter
$isSuccess = ($status === 'success');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $isSuccess ? 'Email Verified' : 'Verification Failed'; ?> - San Nicolas Dental Clinic</title>
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
            background: linear-gradient(135deg, #f8fafc 0%, #f0f4f8 50%, #f0f9ff 100%);
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
            background-image: radial-gradient(circle at 20% 30%, rgba(30, 58, 95, 0.08) 0%, transparent 50%), 
                              radial-gradient(circle at 80% 70%, rgba(212, 168, 75, 0.08) 0%, transparent 50%);
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
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes scaleInIcon {
            from {
                opacity: 0;
                transform: scale(0);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes checkmark {
            0% {
                stroke-dashoffset: 66;
            }
            100% {
                stroke-dashoffset: 0;
            }
        }
        
        .logo-header {
            animation: fadeInDown 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        .card {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.1s forwards;
            opacity: 0;
            box-shadow: 0 20px 50px -10px rgba(30, 58, 95, 0.15), 0 0 1px rgba(30, 58, 95, 0.1);
            border: 1px solid rgba(30, 58, 95, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card:hover {
            box-shadow: 0 25px 60px -10px rgba(30, 58, 95, 0.2), 0 0 2px rgba(30, 58, 95, 0.15);
        }
        
        button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -5px rgba(30, 58, 95, 0.3);
        }
        
        button:active:not(:disabled) {
            transform: scale(0.98);
        }
        
        .success-icon {
            animation: scaleInIcon 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s forwards;
            opacity: 0;
        }
        
        .error-icon {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s forwards;
            opacity: 0;
        }
        
        .content-text {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s forwards;
            opacity: 0;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen font-display">
    
    <div class="min-h-screen flex flex-col items-center justify-center py-12 px-6">
        <!-- Centered Logo -->
        <div class="flex flex-col items-center mb-10 cursor-pointer logo-header" onclick="window.location.href='index.php'">
            <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-24 w-auto mb-2 drop-shadow-lg">
            <p class="text-primary text-[10px] font-bold uppercase tracking-widest">Clinic Management Portal</p>
        </div>

        <!-- Main Card -->
        <div class="w-full max-w-[520px] bg-white dark:bg-surface-dark px-6 md:px-8 lg:px-12 py-10 md:py-12 lg:py-14 rounded-3xl shadow-xl shadow-slate-200/50 dark:shadow-none border border-slate-100 dark:border-border-dark card">
            
            <?php if ($isSuccess): ?>
                <!-- SUCCESS STATE -->
                
                <!-- Success Icon -->
                <div class="flex justify-center mb-8">
                    <div class="relative w-24 h-24">
                        <svg class="w-24 h-24 text-green-500 success-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="11" stroke="currentColor" stroke-width="2" fill="none"/>
                            <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="6" style="animation: checkmark 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.4s forwards; stroke-dashoffset: 6;"/>
                        </svg>
                    </div>
                </div>
                
                <!-- Success Content -->
                <div class="text-center space-y-5 content-text">
                    <div>
                        <h1 class="text-3xl font-black tracking-tight mb-2">Email Verified!</h1>
                        <p class="text-slate-600 dark:text-slate-400 font-medium">Your email has been successfully verified</p>
                    </div>

                    <!-- Success Message Box -->
                    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-5">
                        <p class="text-sm text-green-900 dark:text-green-200 font-semibold">
                            ✓ Your account is now active and ready to use
                        </p>
                    </div>

                    <!-- Next Steps -->
                    <div class="text-left space-y-3 text-sm text-slate-700 dark:text-slate-300">
                        <p><strong>You can now:</strong></p>
                        <ul class="space-y-2 text-slate-600 dark:text-slate-400 list-none">
                            <li class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-green-500 text-xl">check_circle</span>
                                Log in to your account
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-green-500 text-xl">check_circle</span>
                                Access your patient dashboard
                            </li>
                            <li class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-green-500 text-xl">check_circle</span>
                                Book appointments and manage your profile
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="space-y-3 mt-8 flex flex-col">
                    <button onclick="window.location.href='login.php'" class="w-full h-12 bg-primary hover:bg-primary-hover text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 transition-all flex items-center justify-center gap-2 active:scale-95">
                        <span class="material-symbols-outlined">login</span>
                        Log In to Your Account
                    </button>
                    
                    <a href="index.php" class="w-full h-12 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-slate-100 rounded-xl font-bold text-sm shadow-lg transition-all flex items-center justify-center gap-2 active:scale-95 text-decoration-none">
                        <span class="material-symbols-outlined">home</span>
                        Back to Home
                    </a>
                </div>

                <!-- Additional Info -->
                <div class="mt-8 pt-6 border-t border-slate-200 dark:border-slate-700">
                    <p class="text-xs text-slate-500 dark:text-slate-400 text-center font-medium">
                        Welcome to San Nicolas Dental Clinic. We're excited to have you!
                    </p>
                </div>

            <?php else: ?>
                <!-- ERROR STATE -->
                
                <!-- Error Icon -->
                <div class="flex justify-center mb-8">
                    <span class="material-symbols-outlined text-6xl text-red-500 error-icon">error_circle</span>
                </div>
                
                <!-- Error Content -->
                <div class="text-center space-y-5 content-text">
                    <div>
                        <h1 class="text-3xl font-black tracking-tight mb-2">Verification Failed</h1>
                        <p class="text-slate-600 dark:text-slate-400 font-medium">We couldn't verify your email</p>
                    </div>

                    <!-- Error Message Box -->
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-5">
                        <p class="text-sm text-red-900 dark:text-red-200 font-semibold">
                            <?php echo !empty($message) ? $message : '⚠ The verification link is invalid or has expired.'; ?>
                        </p>
                    </div>

                    <!-- Troubleshooting -->
                    <div class="text-left space-y-3 text-sm text-slate-700 dark:text-slate-300 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4">
                        <p><strong>What you can do:</strong></p>
                        <ul class="space-y-2 text-slate-600 dark:text-slate-400 list-none">
                            <li class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-blue-500 text-lg flex-shrink-0 mt-0.5">info</span>
                                <span>The link may have expired (valid for 24 hours)</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-blue-500 text-lg flex-shrink-0 mt-0.5">info</span>
                                <span>Request a new verification link by checking your email</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-blue-500 text-lg flex-shrink-0 mt-0.5">info</span>
                                <span>Contact support if you continue to have issues</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="space-y-3 mt-8 flex flex-col">
                    <a href="login.php" class="w-full h-12 bg-primary hover:bg-primary-hover text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 transition-all flex items-center justify-center gap-2 active:scale-95 text-decoration-none">
                        <span class="material-symbols-outlined">login</span>
                        Back to Login
                    </a>
                    
                    <a href="register.php" class="w-full h-12 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-slate-100 rounded-xl font-bold text-sm shadow-lg transition-all flex items-center justify-center gap-2 active:scale-95 text-decoration-none">
                        <span class="material-symbols-outlined">app_registration</span>
                        Request New Verification Link
                    </a>
                </div>

            <?php endif; ?>
        </div>

        <!-- Footer -->
        <p class="mt-8 text-sm text-slate-500 font-medium tracking-tight">
            Need help? <a href="index.php" class="text-primary hover:underline font-bold">Contact Support</a>
        </p>
    </div>

</body>
</html>
