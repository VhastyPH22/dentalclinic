<?php
session_start();

// Check if files exist before requiring
if (!file_exists('backend/config.php')) {
    die('Error: backend/config.php not found');
}
if (!file_exists('backend/send-email.php')) {
    die('Error: backend/send-email.php not found');
}

require_once 'backend/config.php'; 
require_once 'backend/send-email.php'; 

$message = "";
$messageType = "";

// --- FUNCTIONAL BACKEND LOGIC WITH VALIDATIONS ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate connection exists and is valid
        if (!isset($conn) || $conn === null) {
            throw new Exception("Database connection not initialized");
        }
        
        if (!is_object($conn) && !$conn) {
            throw new Exception("Database connection failed");
        }
        
        // Sanitize input
        $email = trim($_POST['email'] ?? '');
        
        // VALIDATION 1: Check if the user entered the email
        if (empty($email)) {
            $message = "Please enter your email address.";
            $messageType = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $messageType = "error";
        } else {
            // VALIDATION 2: Check if the account is found in the 'users' table using prepared statement
            if (!method_exists($conn, 'prepare')) {
                throw new Exception("Database connection doesn't support prepared statements");
            }
            
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            
            $stmt->bind_param("s", $email);
            if (!$stmt->execute()) {
                throw new Exception("Database execute error: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                // SUCCESS: Account Found. Proceed with code generation and sending.
                $verificationCode = rand(100000, 999999);
                
                // Store in Session for verification in codeverification.php
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_code'] = $verificationCode;
                
                // Verify function exists before calling
                if (!function_exists('sendVerificationEmail')) {
                    throw new Exception("sendVerificationEmail function not found");
                }
                
                // Call the function from backend/send-email.php
                $sent = sendVerificationEmail($email, $verificationCode);
                
                if ($sent === true) {
                    // Redirect to the verification panel
                    header("Location: codeverification.php?email=" . urlencode($email));
                    exit();
                } else {
                    // Mailer Error - $sent contains the error message
                    $message = (is_string($sent) && !empty($sent)) ? $sent : "Unable to send verification code. Please try again.";
                    $messageType = "error";
                    error_log("Password reset email error: " . $message);
                }
            } else {
                // ERROR: Account Not Found
                $message = "No account found with that email address.";
                $messageType = "error";
            }
            
            $stmt->close();
        }
    } catch (Exception $e) {
        $message = "An error occurred. Please try again later.";
        $messageType = "error";
        // Log the actual error for debugging (don't show to user)
        error_log("Forgot Password Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Forgot Password - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                    borderRadius: { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "2xl": "1rem", "full": "9999px" },
                },
            },
        }
    </script>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
</head>
<body class="font-display bg-background-light dark:bg-background-dark min-h-screen flex flex-col items-center justify-center p-4 sm:p-6 transition-colors duration-300">
    <div class="w-full max-w-[480px] animate-fade-in">
        <div class="flex justify-center mb-6 sm:mb-8">
            <div class="flex flex-col items-center gap-2">
                <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-16 sm:h-20 w-auto drop-shadow-lg hover:scale-105 transition-transform duration-300">
            </div>
        </div>
        
        <div class="bg-white dark:bg-[#1e293b] rounded-2xl shadow-lg sm:shadow-xl border border-slate-100 dark:border-slate-800 overflow-hidden hover:shadow-2xl transition-shadow duration-300">
            <div class="p-6 sm:p-8 lg:p-10 flex flex-col gap-6">
                <div class="flex flex-col gap-3 text-center">
                    <div class="w-14 h-14 bg-gradient-to-br from-blue-100 to-slate-50 dark:from-slate-800 dark:to-slate-900 rounded-full flex items-center justify-center mx-auto mb-2 ring-2 ring-primary ring-opacity-20 dark:ring-slate-700 shadow-md">
                        <span class="material-symbols-outlined text-primary text-2xl font-black animate-pulse">lock_reset</span>
                    </div>
                    <h1 class="text-slate-900 dark:text-white text-2xl sm:text-3xl font-bold leading-tight">Forgot password?</h1>
                    <p class="text-slate-500 dark:text-slate-400 text-sm sm:text-base font-normal leading-relaxed">
                        No worries, we'll send you reset instructions.
                    </p>
                </div>

                <?php if ($message !== ""): ?>
                <div class="p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 border-red-200 dark:border-red-600 text-red-700 dark:text-red-400 text-sm font-medium animate-in fade-in slide-in-from-top-2 duration-300">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-red-500 dark:text-red-400 flex-shrink-0 mt-0.5">error</span>
                        <p><?php echo $message; ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <form class="flex flex-col gap-6" method="POST" action="" id="resetForm">
                    <div class="flex flex-col gap-2">
                        <label class="text-slate-900 dark:text-slate-200 text-sm font-semibold leading-normal" for="email">
                            📧 Email Address
                        </label>
                        <div class="relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary transition-colors">mail</span>
                            <input name="email" class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:border-primary focus:ring-2 focus:ring-primary/20 h-12 pl-12 pr-4 font-medium transition-all duration-200" id="email" placeholder="Enter your registered email" required type="email"/>
                        </div>
                    </div>
                    <button type="button" class="w-full cursor-pointer flex items-center justify-center rounded-lg h-12 px-5 bg-primary text-white font-bold shadow-md shadow-primary/30 hover:bg-primary-hover hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 active:translate-y-0 active:shadow-sm" id="sendBtnTrigger">
                       ✉️ Send Code
                    </button>
                </form>

                <div class="flex justify-center pt-4">
                    <button type="button" class="group flex items-center gap-2 text-sm font-semibold text-slate-600 dark:text-slate-400 hover:text-primary dark:hover:text-primary transition-colors bg-transparent border-none p-2 cursor-pointer rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800" id="backBtnTrigger">
                        <span class="material-symbols-outlined text-lg transition-transform group-hover:-translate-x-1">arrow_back</span>
                        Back to log in
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="customModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm hidden animate-fade-in">
        <div class="bg-white dark:bg-[#1e293b] w-full max-w-sm rounded-2xl shadow-2xl p-6 sm:p-8 flex flex-col gap-6 animate-in zoom-in-95 fade-in duration-200">
            <div class="flex flex-col gap-3 text-center">
                <div id="modalIconBg" class="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-1">
                    <span id="modalIcon" class="material-symbols-outlined text-3xl">help_outline</span>
                </div>
                <h3 id="modalTitle" class="text-slate-900 dark:text-white text-xl font-bold">Confirmation</h3>
                <p id="modalDescription" class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Are you sure you want to proceed?</p>
            </div>
            <div class="flex flex-col gap-3 w-full">
                <button id="modalConfirm" class="w-full h-11 px-4 rounded-lg bg-primary text-white font-semibold text-sm hover:bg-primary-hover shadow-md shadow-primary/30 transition-all duration-200 hover:-translate-y-0.5">Confirm</button>
                <button id="modalCancel" class="w-full h-11 px-4 rounded-lg border-2 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-all duration-200">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('customModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalDescription = document.getElementById('modalDescription');
        const modalIcon = document.getElementById('modalIcon');
        const modalIconBg = document.getElementById('modalIconBg');
        const modalConfirm = document.getElementById('modalConfirm');
        const modalCancel = document.getElementById('modalCancel');
        const resetForm = document.getElementById('resetForm');

        let currentAction = null;

        function showModal(title, desc, icon, iconColorClass, action) {
            modalTitle.innerText = title;
            modalDescription.innerText = desc;
            modalIcon.innerText = icon;
            modalIconBg.className = `w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-1 ${iconColorClass}`;
            currentAction = action;
            modal.classList.remove('hidden');
        }

        modalCancel.addEventListener('click', () => {
            modal.classList.add('hidden');
            currentAction = null;
        });

        modalConfirm.addEventListener('click', () => {
            if (currentAction === 'send') {
                resetForm.submit(); 
            } else if (currentAction === 'back') {
                window.location.href = 'login.php';
            }
            modal.classList.add('hidden');
        });

        document.getElementById('sendBtnTrigger').addEventListener('click', () => {
            if (document.getElementById('email').checkValidity()) {
                showModal(
                    "Send Reset Link?", 
                    "Confirm sending instructions to the email provided.", 
                    "send", 
                    "bg-blue-50 dark:bg-blue-900/20 text-primary", 
                    "send"
                );
            } else {
                document.getElementById('email').reportValidity();
            }
        });

        document.getElementById('backBtnTrigger').addEventListener('click', () => {
            showModal(
                "Go Back?", 
                "Are you sure you want to return to the login page?", 
                "arrow_back", 
                "bg-slate-100 dark:bg-slate-800 text-slate-500", 
                "back"
            );
        });
    </script>
</body>
</html>
