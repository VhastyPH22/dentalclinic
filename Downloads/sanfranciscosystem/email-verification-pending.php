<?php
session_start();

// Get email and username from URL parameters
$email = isset($_GET['email']) ? htmlspecialchars($_GET['email'], ENT_QUOTES, 'UTF-8') : '';
$username = isset($_GET['username']) ? htmlspecialchars($_GET['username'], ENT_QUOTES, 'UTF-8') : '';

if (empty($email)) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Email Verification Pending - San Nicolas Dental Clinic</title>
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
        
        .logo-header {
            animation: fadeInDown 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px -5px rgba(30, 58, 95, 0.3);
        }
        
        button:active:not(:disabled) {
            transform: scale(0.98);
        }
        
        .notification {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        .status-icon {
            font-size: 4rem;
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
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
            
            <!-- Status Icon -->
            <div class="flex justify-center mb-6">
                <span class="material-symbols-outlined status-icon text-blue-500">mail_lock</span>
            </div>
            
            <!-- Content -->
            <div class="text-center space-y-6">
                <div>
                    <h1 class="text-3xl font-black tracking-tight mb-2">Email Verification Required</h1>
                    <p class="text-slate-600 dark:text-slate-400 font-medium">Your account has been created successfully!</p>
                </div>

                <!-- Email Display -->
                <div class="bg-slate-50 dark:bg-slate-800/40 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <p class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold tracking-wider mb-1">Verification Email Sent To</p>
                    <p class="text-lg font-bold text-primary break-all"><?php echo $email; ?></p>
                </div>

                <!-- Instructions -->
                <div class="text-left space-y-3 text-sm text-slate-700 dark:text-slate-300">
                    <p><strong>Next steps:</strong></p>
                    <ol class="list-decimal list-inside space-y-2 text-slate-600 dark:text-slate-400">
                        <li>Check your inbox for an email from San Nicolas Dental Clinic</li>
                        <li>Click the verification link in the email</li>
                        <li>Return here and log in once verified</li>
                    </ol>
                </div>

                <!-- Tip -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4">
                    <p class="text-xs text-blue-900 dark:text-blue-200 font-medium">
                        <strong>Tip:</strong> Don't see the email? Check your spam or junk folder.
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="space-y-3 mt-8 flex flex-col">
                <button onclick="loginAttempt()" class="w-full h-12 bg-primary hover:bg-primary-hover text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 transition-all flex items-center justify-center gap-2 active:scale-95">
                    <span class="material-symbols-outlined">login</span>
                    Try Logging In Again
                </button>
                
                <button onclick="resendEmail()" id="resendBtn" class="w-full h-12 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-slate-100 rounded-xl font-bold text-sm shadow-lg shadow-slate-200/50 dark:shadow-slate-800/50 transition-all flex items-center justify-center gap-2 active:scale-95">
                    <span class="material-symbols-outlined">mail</span>
                    Resend Verification Email
                </button>

                <a href="login.php" class="w-full h-12 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-slate-100 rounded-xl font-bold text-sm shadow-lg transition-all flex items-center justify-center gap-2 active:scale-95 text-decoration-none">
                    <span class="material-symbols-outlined">arrow_back</span>
                    Back to Login
                </a>
            </div>

            <!-- Additional Info -->
            <div class="mt-8 pt-6 border-t border-slate-200 dark:border-slate-700">
                <p class="text-xs text-slate-500 dark:text-slate-400 text-center font-medium">
                    This verification link expires in <strong>24 hours</strong>. After that, you'll need to request a new one.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <p class="mt-8 text-sm text-slate-500 font-medium tracking-tight">
            Need help? <a href="index.php" class="text-primary hover:underline font-bold">Contact Support</a>
        </p>
    </div>

    <!-- Notification System -->
    <div id="notification" class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[200] hidden items-center gap-3 px-6 py-4 rounded-2xl bg-slate-900 text-white border border-slate-700 shadow-2xl">
        <span id="notifIcon" class="material-symbols-outlined text-orange-400">warning</span>
        <span id="notifMessage" class="font-bold text-sm"></span>
    </div>

    <script>
        function showNotification(message, type = 'info') {
            const notif = document.getElementById('notification');
            const icon = document.getElementById('notifIcon');
            const msg = document.getElementById('notifMessage');
            
            msg.textContent = message;
            
            if (type === 'success') {
                icon.textContent = 'check_circle';
                icon.className = 'material-symbols-outlined text-green-400';
            } else if (type === 'error') {
                icon.textContent = 'error';
                icon.className = 'material-symbols-outlined text-red-400';
            } else {
                icon.textContent = 'info';
                icon.className = 'material-symbols-outlined text-blue-400';
            }
            
            notif.classList.remove('hidden');
            notif.classList.add('flex');
            
            setTimeout(() => notif.classList.add('hidden'), 4000);
        }

        function resendEmail() {
            const btn = document.getElementById('resendBtn');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Sending...';
            
            fetch('backend/resend_verification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'email=' + encodeURIComponent('<?php echo $email; ?>')
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                
                if (data.success) {
                    showNotification('Verification email sent! Check your inbox.', 'success');
                } else {
                    showNotification('Failed to resend email. Please try again.', 'error');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                btn.disabled = false;
                btn.innerHTML = originalText;
                showNotification('An error occurred. Please try again.', 'error');
            });
        }

        function loginAttempt() {
            window.location.href = 'login.php';
        }
    </script>
</body>
</html>
