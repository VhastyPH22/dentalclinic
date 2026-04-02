<?php
session_start();
require_once 'backend/config.php';

// Context: Get email from URL or Session
$email = isset($_GET['email']) ? mysqli_real_escape_string($conn, $_GET['email']) : (isset($_SESSION['reset_email']) ? $_SESSION['reset_email'] : "");

$success_redirect = false;
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_reset'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error_msg = "Passwords do not match.";
    } else {
        // Hash and Update
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_sql = "UPDATE users SET password = '$hashed_password' WHERE email = '$email'";
        
        if (mysqli_query($conn, $update_sql)) {
            $success_redirect = true;
            unset($_SESSION['reset_code']);
            unset($_SESSION['reset_email']);
        } else {
            $error_msg = "Database error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>San Nicolas Dental Clinic - Change Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&amp;family=Noto+Sans:wght@400;500;700&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" },
                    fontFamily: { "display": ["Manrope", "sans-serif"], "body": ["Noto Sans", "sans-serif"] },
                    borderRadius: { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" },
                },
            },
        }
    </script>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-slate-900 dark:text-slate-50 antialiased min-h-screen flex flex-col transition-colors duration-300">
    <div class="flex-1 flex items-center justify-center p-4 sm:p-6 lg:p-8">
        <div class="w-full max-w-[480px] bg-white dark:bg-[#1A2633] rounded-2xl shadow-lg sm:shadow-2xl overflow-hidden border border-slate-100 dark:border-slate-800 hover:shadow-2xl transition-shadow duration-300 animate-fade-in">
            <div class="px-6 sm:px-8 pt-8 sm:pt-10 pb-2 text-center">
                <div class="flex flex-col items-center mb-4">
                    <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-16 sm:h-20 w-auto mb-3 drop-shadow-lg hover:scale-105 transition-transform duration-300">
                </div>
                <h3 class="text-primary text-xs sm:text-sm font-bold tracking-wider uppercase mb-3 opacity-75">San Nicolas Dental Clinic</h3>
                <h1 class="text-slate-900 dark:text-white text-2xl sm:text-3xl font-bold leading-tight mb-2">Change Password</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Please create a secure password for your account.</p>
            </div>

            <div class="p-6 sm:p-8 flex flex-col gap-6">
                <form id="resetForm" method="POST" action="" class="flex flex-col gap-6">
                    <input type="hidden" name="confirm_reset" value="1">
                    
                    <div>
                        <label class="block text-slate-900 dark:text-slate-200 text-sm font-semibold mb-2">New Password</label>
                        <div class="relative flex items-center group">
                            <input id="newPassword" name="new_password" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-primary focus:ring-2 focus:ring-primary/20 px-4 pr-12 text-base transition-all duration-200" placeholder="Enter new password" type="password" required/>
                            <button class="toggle-password absolute right-0 h-full px-3 text-slate-400 hover:text-primary dark:hover:text-primary transition-colors flex items-center justify-center" type="button" data-target="newPassword">
                                <span class="material-symbols-outlined text-lg">visibility</span>
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <div class="flex justify-between items-center">
                            <p class="text-slate-700 dark:text-slate-300 text-xs font-semibold">Password Strength</p>
                            <p id="strengthLabel" class="text-slate-400 text-xs font-bold px-2 py-1 rounded-full bg-slate-100 dark:bg-slate-800">Weak</p>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden shadow-sm">
                            <div id="strengthBar" class="h-full bg-slate-300 rounded-full transition-all duration-300 ease-out" style="width: 5%;"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="checklist-item flex items-start gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50 transition-all duration-200" data-rule="length">
                            <span class="material-symbols-outlined text-slate-300 dark:text-slate-600 text-[20px] flex-shrink-0 mt-0.5">radio_button_unchecked</span>
                            <p class="text-slate-600 dark:text-slate-400 text-sm leading-snug">At least 8 characters</p>
                        </div>
                        <div class="checklist-item flex items-start gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50 transition-all duration-200" data-rule="number">
                            <span class="material-symbols-outlined text-slate-300 dark:text-slate-600 text-[20px] flex-shrink-0 mt-0.5">radio_button_unchecked</span>
                            <p class="text-slate-600 dark:text-slate-400 text-sm leading-snug">Contains a number</p>
                        </div>
                        <div class="checklist-item flex items-start gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50 transition-all duration-200" data-rule="symbol">
                            <span class="material-symbols-outlined text-slate-300 dark:text-slate-600 text-[20px] flex-shrink-0 mt-0.5">radio_button_unchecked</span>
                            <p class="text-slate-600 dark:text-slate-400 text-sm leading-snug">Contains a symbol</p>
                        </div>
                        <div class="checklist-item flex items-start gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800/50 transition-all duration-200" data-rule="uppercase">
                            <span class="material-symbols-outlined text-slate-300 dark:text-slate-600 text-[20px] flex-shrink-0 mt-0.5">radio_button_unchecked</span>
                            <p class="text-slate-600 dark:text-slate-400 text-sm leading-snug">Contains uppercase</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-slate-900 dark:text-slate-200 text-sm font-semibold mb-2">Confirm Password</label>
                        <div class="relative flex items-center group">
                            <input id="confirmPassword" name="confirm_password" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-primary focus:ring-2 focus:ring-primary/20 px-4 pr-12 text-base transition-all duration-200" placeholder="Re-enter password" type="password" required/>
                            <button class="toggle-password absolute right-0 h-full px-3 text-slate-400 hover:text-primary dark:hover:text-primary transition-colors flex items-center justify-center" type="button" data-target="confirmPassword">
                                <span class="material-symbols-outlined text-lg">visibility_off</span>
                            </button>
                        </div>
                    </div>

                    <div class="pt-4 flex flex-col gap-3">
                        <button type="button" id="submitBtn" class="w-full bg-primary hover:bg-primary-hover text-white font-semibold h-12 rounded-lg shadow-md shadow-primary/30 hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 flex items-center justify-center gap-2 active:translate-y-0 active:shadow-sm">
                            <span>Set New Password</span>
                            <span class="material-symbols-outlined text-lg">arrow_forward</span>
                        </button>
                        <button type="button" id="backToLoginBtn" class="text-slate-600 dark:text-slate-400 hover:text-primary dark:hover:text-primary font-medium text-sm text-center transition-colors flex items-center justify-center gap-2 p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 group w-full bg-transparent border-none cursor-pointer">
                            <span class="material-symbols-outlined text-lg group-hover:-translate-x-1 transition-transform">arrow_back</span>
                            Back to Login
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="absolute bottom-4 left-0 right-0 text-center px-4">
            <p class="text-slate-400 dark:text-slate-600 text-xs opacity-75">© <?php echo date('Y'); ?> San Nicolas Dental Clinic. Secure System.</p>
        </div>
    </div>

    <div id="uiModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm hidden">
        <div class="bg-white dark:bg-[#1e293b] w-full max-w-sm rounded-2xl shadow-2xl p-6 sm:p-8 flex flex-col gap-6 animate-in zoom-in-95 fade-in duration-200">
            <div class="flex flex-col gap-3 text-center">
                <div id="modalIconBg" class="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-1">
                    <span id="modalIcon" class="material-symbols-outlined text-3xl">help_outline</span>
                </div>
                <h3 id="modalTitle" class="text-slate-900 dark:text-white text-xl font-bold">Confirmation</h3>
                <p id="modalDescription" class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed"></p>
            </div>
            <div id="modalActions" class="flex flex-col gap-3 w-full">
                <button id="modalConfirm" class="w-full h-11 px-4 rounded-lg bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/30 transition-all duration-200 hover:-translate-y-0.5">Confirm</button>
                <button id="modalCancel" class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-all duration-200">Cancel</button>
            </div>
            <button id="modalClose" class="w-full h-11 rounded-lg bg-primary text-white font-semibold text-sm hidden hover:bg-primary-hover shadow-md shadow-primary/30 transition-all duration-200">Back to Login</button>
        </div>
    </div>

    <script>
        const newPass = document.getElementById('newPassword');
        const confirmPass = document.getElementById('confirmPassword');
        const strengthBar = document.getElementById('strengthBar');
        const strengthLabel = document.getElementById('strengthLabel');
        const modal = document.getElementById('uiModal');
        let currentAction = null;

        // Toggle Password Visibility
        document.querySelectorAll('.toggle-password').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = document.getElementById(btn.dataset.target);
                const isPass = target.type === 'password';
                target.type = isPass ? 'text' : 'password';
                btn.querySelector('span').innerText = isPass ? 'visibility' : 'visibility_off';
            });
        });

        // Real-time Strength & Validation
        newPass.addEventListener('input', () => {
            const val = newPass.value;
            const rules = {
                length: val.length >= 8,
                number: /[0-9]/.test(val),
                symbol: /[^A-Za-z0-9]/.test(val),
                uppercase: /[A-Z]/.test(val)
            };

            let passed = 0;
            Object.keys(rules).forEach(rule => {
                const item = document.querySelector(`[data-rule="${rule}"]`);
                const icon = item.querySelector('span');
                if (rules[rule]) {
                    icon.innerText = 'check_circle';
                    icon.className = 'material-symbols-outlined text-primary text-[20px] flex-shrink-0 mt-0.5';
                    item.classList.add('bg-primary/5', 'border-l-2', 'border-primary');
                    item.classList.remove('bg-slate-50', 'dark:bg-slate-800/50');
                    passed++;
                } else {
                    icon.innerText = 'radio_button_unchecked';
                    icon.className = 'material-symbols-outlined text-slate-300 dark:text-slate-600 text-[20px] flex-shrink-0 mt-0.5';
                    item.classList.remove('bg-primary/5', 'border-l-2', 'border-primary');
                    item.classList.add('bg-slate-50', 'dark:bg-slate-800/50');
                }
            });

            const percent = (passed / 4) * 100;
            strengthBar.style.width = passed === 0 ? '5%' : percent + '%';
            strengthBar.className = passed < 2 ? 'h-full bg-red-500 rounded-full transition-all' : (passed < 4 ? 'h-full bg-yellow-500 rounded-full transition-all' : 'h-full bg-primary rounded-full transition-all');
            strengthLabel.innerText = passed < 2 ? 'Weak' : (passed < 4 ? 'Good' : 'Strong');
            strengthLabel.className = passed < 2 ? 'text-red-500 text-xs font-bold px-2 py-1 rounded-full bg-red-100 dark:bg-red-900/30' : (passed < 4 ? 'text-yellow-600 dark:text-yellow-500 text-xs font-bold px-2 py-1 rounded-full bg-yellow-100 dark:bg-yellow-900/30' : 'text-primary text-xs font-bold px-2 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30');
        });

        function showModal(title, desc, icon, iconClass, type) {
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalDescription').innerText = desc;
            document.getElementById('modalIcon').innerText = icon;
            document.getElementById('modalIconBg').className = `w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-2 ${iconClass}`;
            
            if (type === 'confirm') {
                document.getElementById('modalActions').classList.remove('hidden');
                document.getElementById('modalClose').classList.add('hidden');
            } else {
                document.getElementById('modalActions').classList.add('hidden');
                document.getElementById('modalClose').classList.remove('hidden');
            }
            modal.classList.remove('hidden');
        }

        document.getElementById('submitBtn').addEventListener('click', () => {
            if (newPass.value === "" || confirmPass.value === "") return;
            if (newPass.value !== confirmPass.value) {
                showModal("Error", "Passwords do not match.", "error", "bg-red-50 text-red-500", "alert");
                return;
            }
            currentAction = 'confirm';
            showUIModal("Change Password?", "Are you sure you want to update your password?", "lock", "bg-blue-50 text-primary", "confirm");
        });

        document.getElementById('backToLoginBtn').addEventListener('click', () => {
            currentAction = 'back';
            showUIModal("Go Back?", "Are you sure you want to go back to login?", "arrow_back", "bg-slate-100 dark:bg-slate-800 text-slate-500", "confirm");
        });

        function showUIModal(title, desc, icon, iconClass, type) {
            showModal(title, desc, icon, iconClass, type);
        }

        document.getElementById('modalCancel').onclick = () => modal.classList.add('hidden');
        document.getElementById('modalConfirm').onclick = () => {
            if (currentAction === 'back') {
                window.location.href = 'login.php';
            } else if (currentAction === 'confirm') {
                document.getElementById('resetForm').submit();
            }
        };
        document.getElementById('modalClose').onclick = () => window.location.href = 'login.php';

        <?php if ($success_redirect): ?>
            showModal("Success!", "Your password has been changed successfully.", "check_circle", "bg-green-50 text-green-500", "success");
        <?php endif; ?>

        <?php if ($error_msg): ?>
            showModal("Error", "<?php echo $error_msg; ?>", "error", "bg-red-50 text-red-500", "alert");
        <?php endif; ?>
    </script>
</body>
</html> 
