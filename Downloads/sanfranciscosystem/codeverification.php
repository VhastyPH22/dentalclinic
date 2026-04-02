<?php
/**
 * 1. INITIALIZATION & CONTEXT
 */
session_start(); // Enable session access
date_default_timezone_set('Asia/Manila'); 

// Get the email from the URL parameter (passed from forgotpassword.php)
$email_display = isset($_GET['email']) ? htmlspecialchars($_GET['email']) : "your email";

/**
 * 2. WRONG ATTEMPT COOLDOWN LOGIC
 */
if (!isset($_SESSION['verify_attempts'])) {
    $_SESSION['verify_attempts'] = 0;
}

$lockout_duration = 30; // seconds to wait
$is_locked_out = false;
$error_msg = "";

// Check if currently locked out
if (isset($_SESSION['verify_lockout_time'])) {
    $seconds_since_lockout = time() - $_SESSION['verify_lockout_time'];
    if ($seconds_since_lockout < $lockout_duration) {
        $is_locked_out = true;
        $remaining = $lockout_duration - $seconds_since_lockout;
        $error_msg = "Too many wrong attempts. Please wait $remaining seconds.";
    } else {
        // Reset after timeout
        unset($_SESSION['verify_lockout_time']);
        $_SESSION['verify_attempts'] = 0;
    }
}

/**
 * 3. REAL VERIFICATION LOGIC
 */
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_code']) && !$is_locked_out) {
    // Collect the 6 digits from the array
    $user_code = isset($_POST['code']) ? implode('', $_POST['code']) : '';
    
    // VALIDATE against the session code created in forgotpassword.php
    if (isset($_SESSION['reset_code']) && $user_code == $_SESSION['reset_code']) {
        // SUCCESS: Reset counters and redirect
        $_SESSION['verify_attempts'] = 0;
        unset($_SESSION['verify_lockout_time']);
        unset($_SESSION['reset_code']); 
        header("Location: resetpassword.php?email=" . urlencode($email_display) . "&status=verified");
        exit();
    } else {
        $_SESSION['verify_attempts']++;
        if ($_SESSION['verify_attempts'] >= 3) {
            $_SESSION['verify_lockout_time'] = time();
            $error_msg = "Too many wrong attempts. Please wait $lockout_duration seconds.";
            $is_locked_out = true;
        } else {
            $attempts_left = 3 - $_SESSION['verify_attempts'];
            $error_msg = "Invalid verification code. ($attempts_left attempts left)";
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Code Verification - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                    borderRadius: {"DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px"},
                },
            },
        }
    </script>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
</head>
<body class="font-display bg-background-light dark:bg-background-dark text-slate-900 dark:text-white antialiased transition-colors duration-200">
    <div class="relative flex min-h-screen w-full flex-col items-center justify-center p-4 md:p-6">
        <div class="w-full max-w-[480px] rounded-xl bg-white dark:bg-[#1A2633] shadow-xl ring-1 ring-slate-900/5 dark:ring-white/10 p-6 md:p-8 sm:p-10 flex flex-col gap-8 transition-all">
            <div class="flex flex-col items-center gap-4 text-center">
                <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-20 w-auto drop-shadow-lg">
                <div class="flex flex-col gap-2">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Code Verification</h1>
                    <p class="text-sm font-normal text-slate-500 dark:text-slate-400">
                        Please enter the 6-digit code sent to <span class="font-bold text-slate-900 dark:text-white"><?php echo $email_display; ?></span>
                    </p>
                </div>
            </div>

            <?php if($error_msg): ?>
                <div class="p-3 bg-red-50 text-red-600 rounded-lg text-xs font-bold border border-red-100 text-center animate-pulse"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <div class="flex w-full flex-col items-center gap-6">
                <form id="verifyForm" class="flex w-full justify-center gap-2 sm:gap-4" method="POST" action="">
                    <input type="hidden" name="verify_code" value="1">
                    <input name="code[]" <?php echo $is_locked_out ? 'disabled' : ''; ?> class="digit-input flex h-12 w-10 sm:h-14 sm:w-12 rounded-lg border border-slate-200 bg-slate-50 text-center text-lg font-bold text-slate-900 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-all shadow-sm disabled:opacity-50" inputmode="numeric" maxlength="1" type="text" required/>
                    <input name="code[]" <?php echo $is_locked_out ? 'disabled' : ''; ?> class="digit-input flex h-12 w-10 sm:h-14 sm:w-12 rounded-lg border border-slate-200 bg-slate-50 text-center text-lg font-bold text-slate-900 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-all shadow-sm disabled:opacity-50" inputmode="numeric" maxlength="1" type="text" required/>
                    <input name="code[]" <?php echo $is_locked_out ? 'disabled' : ''; ?> class="digit-input flex h-12 w-10 sm:h-14 sm:w-12 rounded-lg border border-slate-200 bg-slate-50 text-center text-lg font-bold text-slate-900 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-all shadow-sm disabled:opacity-50" inputmode="numeric" maxlength="1" type="text" required/>
                    <input name="code[]" <?php echo $is_locked_out ? 'disabled' : ''; ?> class="digit-input flex h-12 w-10 sm:h-14 sm:w-12 rounded-lg border border-slate-200 bg-slate-50 text-center text-lg font-bold text-slate-900 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-all shadow-sm disabled:opacity-50" inputmode="numeric" maxlength="1" type="text" required/>
                    <input name="code[]" <?php echo $is_locked_out ? 'disabled' : ''; ?> class="digit-input flex h-12 w-10 sm:h-14 sm:w-12 rounded-lg border border-slate-200 bg-slate-50 text-center text-lg font-bold text-slate-900 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-all shadow-sm disabled:opacity-50" inputmode="numeric" maxlength="1" type="text" required/>
                    <input name="code[]" <?php echo $is_locked_out ? 'disabled' : ''; ?> class="digit-input flex h-12 w-10 sm:h-14 sm:w-12 rounded-lg border border-slate-200 bg-slate-50 text-center text-lg font-bold text-slate-900 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white transition-all shadow-sm disabled:opacity-50" inputmode="numeric" maxlength="1" type="text" required/>
                </form>
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">
                    Time remaining: <span id="timerDisplay" class="text-primary font-bold">02:30</span>
                </p>
            </div>
            <div class="flex flex-col gap-4">
                <button type="button" id="verifyBtnTrigger" <?php echo $is_locked_out ? 'disabled' : ''; ?> class="flex h-12 w-full cursor-pointer items-center justify-center rounded-lg bg-primary px-5 text-base font-bold leading-normal text-white transition-colors hover:bg-blue-600 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed">
                    Verify Code
                </button>
                <div class="flex flex-col items-center gap-3">
                    <p class="text-center text-sm font-normal text-slate-500 dark:text-slate-400">
                        Didn't receive the code? 
                        <a id="resendBtnTrigger" class="font-semibold text-primary decoration-primary/30 underline-offset-4 hover:underline cursor-pointer">Resend Code</a>
                        <span id="cooldownDisplay" class="hidden text-xs text-slate-400 font-medium ml-1"></span>
                    </p>
                    <button id="backBtnTrigger" class="group flex h-10 w-auto min-w-[120px] cursor-pointer items-center justify-center rounded-lg bg-transparent px-4 text-sm font-bold text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white">
                        <span class="material-symbols-outlined mr-1 text-lg transition-transform group-hover:-translate-x-1">arrow_back</span>
                        Back to Login
                    </button>
                </div>
            </div>
        </div>
        <div class="mt-8 text-center">
            <p class="text-xs text-slate-400 dark:text-slate-500">Â© <?php echo date('Y'); ?> San Nicolas Dental Clinic. All rights reserved.</p>
        </div>
    </div>

    <div id="uiModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden">
        <div class="bg-white dark:bg-[#1e293b] w-full max-w-sm rounded-2xl shadow-2xl p-6 flex flex-col gap-6 scale-95 transition-transform duration-300">
            <div class="flex flex-col gap-2 text-center">
                <div id="modalIconBg" class="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-2">
                    <span id="modalIcon" class="material-symbols-outlined text-2xl">help_outline</span>
                </div>
                <h3 id="modalTitle" class="text-slate-900 dark:text-white text-xl font-bold">Confirmation</h3>
                <p id="modalDescription" class="text-slate-500 dark:text-slate-400 text-sm">Are you sure you want to proceed?</p>
            </div>
            <div class="flex gap-3">
                <button id="modalCancel" class="flex-1 h-11 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Cancel</button>
                <button id="modalConfirm" class="flex-1 h-11 rounded-lg bg-primary text-white font-bold text-sm hover:bg-primary-hover shadow-lg shadow-primary/30 transition-all">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        // Modal logic elements
        const modal = document.getElementById('uiModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalDescription = document.getElementById('modalDescription');
        const modalIcon = document.getElementById('modalIcon');
        const modalIconBg = document.getElementById('modalIconBg');
        const modalConfirm = document.getElementById('modalConfirm');
        const modalCancel = document.getElementById('modalCancel');
        const verifyForm = document.getElementById('verifyForm');

        let currentAction = null;
        let resendCooldownActive = false;

        function showUIModal(title, desc, icon, iconColorClass, action) {
            modalTitle.innerText = title;
            modalDescription.innerText = desc;
            modalIcon.innerText = icon;
            modalIconBg.className = `w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-2 ${iconColorClass}`;
            currentAction = action;
            modal.classList.remove('hidden');
        }

        modalCancel.addEventListener('click', () => modal.classList.add('hidden'));

        modalConfirm.addEventListener('click', () => {
            if (currentAction === 'verify') verifyForm.submit();
            else if (currentAction === 'resend') startResendCooldown();
            else if (currentAction === 'back') window.location.href = 'login.php';
            modal.classList.add('hidden');
        });

        // Trigger Listeners
        document.getElementById('verifyBtnTrigger').addEventListener('click', () => {
            showUIModal("Verify Code?", "Submit your 6-digit code for verification.", "check_circle", "bg-blue-50 dark:bg-blue-900/20 text-primary", "verify");
        });

        document.getElementById('resendBtnTrigger').addEventListener('click', (e) => {
            if (resendCooldownActive) return;
            showUIModal("Resend Code?", "Request a new verification code to be sent to your email.", "refresh", "bg-green-50 dark:bg-green-900/20 text-green-600", "resend");
        });

        function startResendCooldown() {
            const resendBtn = document.getElementById('resendBtnTrigger');
            const cooldownDisplay = document.getElementById('cooldownDisplay');
            let seconds = 60; // 60-second cooldown for resend

            resendCooldownActive = true;
            resendBtn.classList.add('opacity-50', 'cursor-not-allowed', 'no-underline');
            resendBtn.classList.remove('hover:underline');
            cooldownDisplay.classList.remove('hidden');

            const interval = setInterval(() => {
                seconds--;
                cooldownDisplay.innerText = `(Retry in ${seconds}s)`;
                if (seconds <= 0) {
                    clearInterval(interval);
                    resendCooldownActive = false;
                    resendBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'no-underline');
                    resendBtn.classList.add('hover:underline');
                    cooldownDisplay.classList.add('hidden');
                }
            }, 1000);
            
            // Simulation: In reality, you'd trigger a reload or an AJAX call to send code.
            // window.location.reload(); 
        }

        document.getElementById('backBtnTrigger').addEventListener('click', () => {
            showUIModal("Go Back?", "Unsaved verification data will be lost. Return to login?", "arrow_back", "bg-slate-100 dark:bg-slate-800 text-slate-500", "back");
        });

        // Auto-focus and Copy-Paste support logic
        const inputs = document.querySelectorAll('.digit-input');
        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < inputs.length - 1) inputs[index + 1].focus();
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && e.target.value.length === 0 && index > 0) inputs[index - 1].focus();
            });

            // Added Copy-Paste support
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasteData = (e.clipboardData || window.clipboardData).getData('text');
                const digits = pasteData.replace(/\D/g, '').split('').slice(0, 6); // Extract only digits, max 6
                
                digits.forEach((digit, i) => {
                    if (inputs[i]) {
                        inputs[i].value = digit;
                    }
                });

                // Focus the last filled input or the next empty one
                const lastIndex = Math.min(digits.length, inputs.length - 1);
                inputs[lastIndex].focus();
            });
        });

        // Timer logic
        let timeInSeconds = 150; 
        const timerDisplay = document.getElementById('timerDisplay');
        const countdown = setInterval(() => {
            if (timeInSeconds <= 0) { clearInterval(countdown); timerDisplay.textContent = "Expired"; }
            else { timeInSeconds--; let minutes = Math.floor(timeInSeconds / 60); let seconds = timeInSeconds % 60; timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`; }
        }, 1000);
    </script>
</body>
</html>
