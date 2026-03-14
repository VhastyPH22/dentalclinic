<?php
session_start();
require_once 'backend/config.php';

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: clinic-admin-dashboard.php');
    exit;
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $clinic_name = trim($_POST['clinic_name'] ?? '');
    $clinic_email = trim($_POST['clinic_email'] ?? '');
    $clinic_phone = trim($_POST['clinic_phone'] ?? '');
    $clinic_address = trim($_POST['clinic_address'] ?? '');
    $clinic_city = trim($_POST['clinic_city'] ?? '');
    $clinic_province = trim($_POST['clinic_province'] ?? '');
    $clinic_postal_code = trim($_POST['clinic_postal_code'] ?? '');
    
    $owner_first_name = trim($_POST['owner_first_name'] ?? '');
    $owner_last_name = trim($_POST['owner_last_name'] ?? '');
    $owner_email = trim($_POST['owner_email'] ?? '');
    $owner_username = trim($_POST['owner_username'] ?? '');
    $owner_password = $_POST['owner_password'] ?? '';
    $owner_password_confirm = $_POST['owner_password_confirm'] ?? '';

    // Validation
    if (empty($clinic_name) || empty($clinic_email) || empty($clinic_phone)) {
        $error = "Clinic name, email, and phone are required.";
    } elseif (empty($owner_first_name) || empty($owner_last_name) || empty($owner_email)) {
        $error = "Owner first name, last name, and email are required.";
    } elseif (empty($owner_username) || empty($owner_password)) {
        $error = "Owner username and password are required.";
    } elseif (strlen($owner_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($owner_password !== $owner_password_confirm) {
        $error = "Passwords do not match.";
    } else {
        // Check if clinic name already exists
        $checkClinicSql = "SELECT id FROM tenants WHERE LOWER(clinic_name) = LOWER('" . mysqli_real_escape_string($conn, $clinic_name) . "') LIMIT 1";
        $checkClinicResult = mysqli_query($conn, $checkClinicSql);
        
        if (mysqli_num_rows($checkClinicResult) > 0) {
            $error = "Clinic name already exists. Please choose a different name.";
        } else {
            // Check if username already exists
            $checkUsernameSql = "SELECT id FROM users WHERE LOWER(username) = LOWER('" . mysqli_real_escape_string($conn, $owner_username) . "') LIMIT 1";
            $checkUsernameResult = mysqli_query($conn, $checkUsernameSql);
            
            if (mysqli_num_rows($checkUsernameResult) > 0) {
                $error = "Username already exists. Please choose a different username.";
            } else {
                // Check if email already exists
                $checkEmailSql = "SELECT id FROM users WHERE LOWER(email) = LOWER('" . mysqli_real_escape_string($conn, $owner_email) . "') LIMIT 1";
                $checkEmailResult = mysqli_query($conn, $checkEmailSql);
                
                if (mysqli_num_rows($checkEmailResult) > 0) {
                    $error = "Email already registered. Please use a different email.";
                } else {
                    // Generate clinic code (6-character unique code)
                    $clinic_code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
                    
                    // Hash password
                    $hashed_password = password_hash($owner_password, PASSWORD_BCRYPT);
                    
                    // Start transaction
                    mysqli_begin_transaction($conn);
                    
                    try {
                        // Insert into tenants table with 'pending' status
                        $insertTenantSql = "INSERT INTO tenants 
                            (clinic_name, clinic_email, clinic_phone, clinic_address, clinic_city, clinic_province, clinic_postal_code, clinic_code, status, is_active, created_at, updated_at)
                            VALUES 
                            ('" . mysqli_real_escape_string($conn, $clinic_name) . "',
                             '" . mysqli_real_escape_string($conn, $clinic_email) . "',
                             '" . mysqli_real_escape_string($conn, $clinic_phone) . "',
                             '" . mysqli_real_escape_string($conn, $clinic_address) . "',
                             '" . mysqli_real_escape_string($conn, $clinic_city) . "',
                             '" . mysqli_real_escape_string($conn, $clinic_province) . "',
                             '" . mysqli_real_escape_string($conn, $clinic_postal_code) . "',
                             '$clinic_code',
                             'pending',
                             0,
                             NOW(),
                             NOW())";
                        
                        if (!mysqli_query($conn, $insertTenantSql)) {
                            throw new Exception("Error creating clinic: " . mysqli_error($conn));
                        }
                        
                        $tenant_id = mysqli_insert_id($conn);
                        
                        // Insert owner user into users table
                        $insertUserSql = "INSERT INTO users 
                            (first_name, last_name, email, username, password, role, tenant_id, is_archived, created_at)
                            VALUES 
                            ('" . mysqli_real_escape_string($conn, $owner_first_name) . "',
                             '" . mysqli_real_escape_string($conn, $owner_last_name) . "',
                             '" . mysqli_real_escape_string($conn, $owner_email) . "',
                             '" . mysqli_real_escape_string($conn, $owner_username) . "',
                             '$hashed_password',
                             'dentist',
                             $tenant_id,
                             0,
                             NOW())";
                        
                        if (!mysqli_query($conn, $insertUserSql)) {
                            throw new Exception("Error creating clinic owner: " . mysqli_error($conn));
                        }
                        
                        $owner_id = mysqli_insert_id($conn);
                        
                        // Update tenants table with owner_id
                        $updateTenantSql = "UPDATE tenants SET owner_id = $owner_id WHERE id = $tenant_id";
                        if (!mysqli_query($conn, $updateTenantSql)) {
                            throw new Exception("Error updating tenant: " . mysqli_error($conn));
                        }
                        
                        // Commit transaction
                        mysqli_commit($conn);
                        
                        $success = "✅ Clinic registration submitted successfully! Your clinic is pending approval by the super admin. You will receive an email confirmation once approved.";
                        
                        // Optionally send email notification to super admin
                        // You can implement email notification here
                        
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Register Clinic | San Nicolas Dental Clinic</title>
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
        main {
            animation: fadeInScale 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 20px 60px rgba(44, 62, 80, 0.15), 0 0 40px rgba(212, 175, 55, 0.05);
        }
        input, select, textarea {
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
            position: relative;
            border-color: #e5e7eb;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }
        input:hover, select:hover, textarea:hover {
            border-color: #2C3E50;
            box-shadow: 0 4px 12px rgba(44, 62, 80, 0.08);
        }
        input:focus, select:focus, textarea:focus {
            background: linear-gradient(135deg, #ffffff 0%, #ffffff 100%);
            box-shadow: 0 0 0 4px rgba(44, 62, 80, 0.12), 0 10px 30px rgba(44, 62, 80, 0.12);
            transform: translateY(-3px);
            border-color: #2C3E50;
        }
        button[type="submit"] {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            font-weight: 700;
            background: linear-gradient(135deg, #2C3E50 0%, #34495e 100%);
        }
        button[type="submit"]:hover:not(:disabled) {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px rgba(44, 62, 80, 0.35), 0 0 20px rgba(212, 175, 55, 0.2);
            background: linear-gradient(135deg, #1a252f 0%, #2C3E50 100%);
        }
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
        .form-section {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }
        .error-message {
            animation: slideDown 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        .success-message {
            animation: slideDown 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-gray-50 text-slate-900 min-h-screen flex items-center justify-center p-2 sm:p-3 md:p-4">
    <div class="w-full max-w-4xl">
        <!-- Back to Login Link -->
        <div class="mb-6 text-center">
            <a href="login.php" class="inline-flex items-center gap-2 text-brandBlue hover:text-brandGold transition-colors font-semibold">
                <span class="material-symbols-outlined">arrow_back</span>
                Back to Login
            </a>
        </div>

        <main class="bg-white shadow-2xl rounded-lg overflow-hidden p-6 sm:p-8 md:p-12">
            <!-- Header -->
            <div class="mb-8 text-center">
                <h1 class="text-3xl sm:text-4xl font-black text-slate-900 mb-2 tracking-tight">Register Your Clinic</h1>
                <p class="text-slate-500 text-sm sm:text-base font-medium">Complete this form to register your dental clinic. Your registration will be reviewed and approved by our super admin.</p>
            </div>

            <!-- Error Message -->
            <?php if (!empty($error)): ?>
            <div class="mb-6 p-5 rounded-lg border border-red-300 text-red-700 text-sm font-semibold error-message">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined flex-shrink-0">error</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if (!empty($success)): ?>
            <div class="mb-6 p-5 rounded-lg border border-green-300 text-green-700 text-sm font-semibold success-message">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined flex-shrink-0">check_circle</span>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-8">
                <!-- Clinic Information Section -->
                <div class="form-section" style="animation-delay: 0.1s;">
                    <h2 class="text-xl font-bold text-slate-900 mb-4 pb-3 border-b-2 border-brandGold">Clinic Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Clinic Name <span class="text-red-600">*</span></label>
                            <input type="text" name="clinic_name" placeholder="e.g., San Nicolas Dental Clinic" required value="<?php echo htmlspecialchars($_POST['clinic_name'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Clinic Email <span class="text-red-600">*</span></label>
                            <input type="email" name="clinic_email" placeholder="clinic@example.com" required value="<?php echo htmlspecialchars($_POST['clinic_email'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Clinic Phone <span class="text-red-600">*</span></label>
                            <input type="tel" name="clinic_phone" placeholder="+63 XXX XXX XXXX" required value="<?php echo htmlspecialchars($_POST['clinic_phone'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">City <span class="text-red-600">*</span></label>
                            <input type="text" name="clinic_city" placeholder="e.g., Pulilan" required value="<?php echo htmlspecialchars($_POST['clinic_city'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Province <span class="text-red-600">*</span></label>
                            <input type="text" name="clinic_province" placeholder="e.g., Bulacan" required value="<?php echo htmlspecialchars($_POST['clinic_province'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Postal Code <span class="text-red-600">*</span></label>
                            <input type="text" name="clinic_postal_code" placeholder="e.g., 3005" required value="<?php echo htmlspecialchars($_POST['clinic_postal_code'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Address <span class="text-red-600">*</span></label>
                        <textarea name="clinic_address" placeholder="Full clinic address" required rows="3" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm resize-none"><?php echo htmlspecialchars($_POST['clinic_address'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Clinic Owner Information Section -->
                <div class="form-section" style="animation-delay: 0.2s;">
                    <h2 class="text-xl font-bold text-slate-900 mb-4 pb-3 border-b-2 border-brandGold">Clinic Owner Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">First Name <span class="text-red-600">*</span></label>
                            <input type="text" name="owner_first_name" placeholder="John" required value="<?php echo htmlspecialchars($_POST['owner_first_name'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Last Name <span class="text-red-600">*</span></label>
                            <input type="text" name="owner_last_name" placeholder="Doe" required value="<?php echo htmlspecialchars($_POST['owner_last_name'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Email <span class="text-red-600">*</span></label>
                        <input type="email" name="owner_email" placeholder="owner@example.com" required value="<?php echo htmlspecialchars($_POST['owner_email'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                    </div>
                </div>

                <!-- Account Credentials Section -->
                <div class="form-section" style="animation-delay: 0.3s;">
                    <h2 class="text-xl font-bold text-slate-900 mb-4 pb-3 border-b-2 border-brandGold">Account Credentials</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Username <span class="text-red-600">*</span></label>
                            <input type="text" name="owner_username" placeholder="username" required value="<?php echo htmlspecialchars($_POST['owner_username'] ?? ''); ?>" class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                            <p class="text-xs text-slate-500 mt-1">Username must be unique</p>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Password <span class="text-red-600">*</span></label>
                            <input type="password" name="owner_password" placeholder="••••••••" required class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                            <p class="text-xs text-slate-500 mt-1">Minimum 8 characters</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Confirm Password <span class="text-red-600">*</span></label>
                        <input type="password" name="owner_password_confirm" placeholder="••••••••" required class="w-full px-4 py-2.5 rounded-lg border-2 border-gray-200 focus:border-primary focus:ring-0 outline-none font-medium text-sm"/>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="form-section" style="animation-delay: 0.4s;">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <p class="text-sm text-blue-800">
                            <span class="material-symbols-outlined align-middle text-base">info</span>
                            By registering, you agree to our terms. Your clinic will be reviewed and must be approved by the super admin before you can access the system.
                        </p>
                    </div>
                    <button type="submit" class="w-full bg-brandBlue hover:bg-blue-700 active:bg-blue-800 text-white font-bold py-3 px-4 rounded-lg shadow-lg hover:shadow-xl transition-all text-base">
                        Register Clinic
                    </button>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
