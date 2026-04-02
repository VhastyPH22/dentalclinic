<?php
session_start();
require_once 'backend/config.php';
require_once 'backend/middleware.php';

checkAccess(['dentist', 'assistant', 'patient']);

$role = $_SESSION['role'] ?? 'patient';
$currentUserID = $_SESSION['user_id'] ?? 0;
$message = "";

// Check for profile update success message from previous request (for JavaScript toast)
$showSuccessToast = isset($_SESSION['profile_update_success']) ? true : false;
if (isset($_SESSION['profile_update_success'])) {
    unset($_SESSION['profile_update_success']);
}

// Get user's own profile
$userQuery = mysqli_query($conn, "SELECT * FROM users WHERE id = '$currentUserID'");
$user = mysqli_fetch_assoc($userQuery);
$firstName = $user['first_name'] ?? '';
$lastName = $user['last_name'] ?? '';
$email = $user['email'] ?? '';
$username = $user['username'] ?? '';

// Ensure patient_profiles record exists (auto-create if missing)
// First, ensure the profile_picture column exists
$checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
$columnCheckResult = @mysqli_query($conn, $checkColumnSQL);

if (!$columnCheckResult || mysqli_num_rows($columnCheckResult) === 0) {
    // Column doesn't exist, add it
    $addColumnSQL = "ALTER TABLE patient_profiles ADD COLUMN profile_picture VARCHAR(255) NULL DEFAULT ''";
    @mysqli_query($conn, $addColumnSQL);
}

// Now check if patient_profiles record exists for current user
$profileQuery = mysqli_query($conn, "SELECT user_id FROM patient_profiles WHERE user_id = '$currentUserID'");
if (!$profileQuery || mysqli_num_rows($profileQuery) === 0) {
    // Create patient_profiles record if it doesn't exist
    $insertProfile = "INSERT INTO patient_profiles (user_id, dob, phone, address, occupation, marital_status, gender, chief_complaint, profile_picture) 
                     VALUES ('$currentUserID', '2000-01-01', '', '', '', 'single', 'Not Specified', '', '')";
    @mysqli_query($conn, $insertProfile);
}

// Helper function to clean profile picture paths from database
function cleanProfilePicturePath($path) {
    // Remove leading/trailing whitespace
    $path = trim($path);
    // Normalize path separators
    $path = str_replace('\\', '/', $path);
    // Remove any /htdocs/ prefix or similar incorrect prefixes
    $path = preg_replace('|^.*?/?(assets/images/profiles/)|', '$1', $path);
    // Remove leading slashes
    $path = ltrim($path, '/');
    return $path;
}

// Get profile picture with correct path for both localhost and hosting
$profilePicture = '';
$profilePictureTime = time(); // Use server time for cache busting
$profilePictureDebug = ['step' => '1_init']; // Debug tracking

// Column was just ensured to exist, so we can query it
$profileQuery = mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '$currentUserID'");
$profilePictureDebug['step'] = '2_query_executed';

if ($profileQuery) {
    $profilePictureDebug['step'] = '3_query_ok';
    $profileData = mysqli_fetch_assoc($profileQuery);
    $profilePictureRaw = $profileData['profile_picture'] ?? '';
    $profilePictureDebug['db_value'] = $profilePictureRaw;
    
    // Use absolute URL path with cache-busting
    if (!empty($profilePictureRaw)) {
        $profilePictureDebug['step'] = '4_raw_path_exists';
        // Clean the path (removes /htdocs/ and other incorrect prefixes)
        $normalizedPath = cleanProfilePicturePath($profilePictureRaw);
        
        // Use cleaned path directly
        $cleanPath = $normalizedPath;
        $profilePictureDebug['clean_path'] = $cleanPath;
        
        // Get file's last modified time for accurate cache busting
        $appRoot = __DIR__;  // my-profile.php is at root, so __DIR__ is correct
        $fullPath = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath);
        $profilePictureDebug['full_path_check'] = $fullPath;
        
        if (file_exists($fullPath)) {
            $profilePictureTime = filemtime($fullPath);
            $profilePictureDebug['file_exists'] = true;
            $profilePictureDebug['file_time'] = $profilePictureTime;
        } else {
            $profilePictureDebug['file_exists'] = false;
            $profilePictureDebug['step'] = '5_file_not_found';
        }
        
        // Construct URL path relative to application root
        $profilePicture = $cleanPath . '?t=' . $profilePictureTime;
        $profilePictureDebug['final_url'] = $profilePicture;
    } else {
        $profilePictureDebug['step'] = '4_no_raw_path';
    }
} else {
    $profilePictureDebug['step'] = '3_query_failed';
}

// Output debug info to browser console on initial page load
echo "
<script>
console.log('>> PHP Profile Picture Debug:', " . json_encode($profilePictureDebug) . ");
</script>
";

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newFirstName = mysqli_real_escape_string($conn, trim($_POST['first_name'] ?? ''));
    $newLastName = mysqli_real_escape_string($conn, trim($_POST['last_name'] ?? ''));
    $newEmail = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $newUsername = mysqli_real_escape_string($conn, trim($_POST['username'] ?? ''));
    
    $errors = [];
    
    if (empty($newFirstName) || empty($newLastName) || empty($newEmail) || empty($newUsername)) {
        $errors[] = "All fields are required.";
    }
    
    // Check if email is already used by someone else
    $emailCheck = mysqli_query($conn, "SELECT id FROM users WHERE email = '$newEmail' AND id != '$currentUserID'");
    if (mysqli_num_rows($emailCheck) > 0) {
        $errors[] = "Email is already in use.";
    }
    
    // Check if username is already used by someone else
    $usernameCheck = mysqli_query($conn, "SELECT id FROM users WHERE username = '$newUsername' AND id != '$currentUserID'");
    if (mysqli_num_rows($usernameCheck) > 0) {
        $errors[] = "Username is already in use.";
    }
    
    if (empty($errors)) {
        $updateSQL = "UPDATE users SET first_name='$newFirstName', last_name='$newLastName', email='$newEmail', username='$newUsername' WHERE id='$currentUserID'";
        if (mysqli_query($conn, $updateSQL)) {
            $_SESSION['full_name'] = "$newFirstName $newLastName";
            $_SESSION['username'] = $newUsername;
            $_SESSION['profile_update_success'] = true;
            $firstName = $newFirstName;
            $lastName = $newLastName;
            $email = $newEmail;
            $username = $newUsername;
            $message = "<div class='mb-6 p-4 bg-green-100 text-green-700 rounded-lg font-bold flex items-center gap-2 border-4 border-green-500 shadow-lg' style='font-size: 16px;'><span class='material-symbols-outlined'>check_circle</span> Profile updated successfully!</div>";
        } else {
            $message = "<div class='mb-6 p-4 bg-red-100 text-red-700 rounded-lg font-bold border-4 border-red-500'>Error updating profile: " . mysqli_error($conn) . "</div>";
        }
    } else {
        $message = "<div class='mb-6 p-4 bg-red-100 text-red-700 rounded-lg font-bold border-4 border-red-500'>" . implode('<br>', $errors) . "</div>";
    }
}

$dashboardLink = 'patient-dashboard.php';
$requiresConfirmation = ($role === 'dentist' || $role === 'assistant');
if ($role === 'dentist') $dashboardLink = 'dentist-dashboard.php';
elseif ($role === 'assistant') $dashboardLink = 'assistant-dashboard.php';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>My Profile - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" }, fontFamily: { "display": ["Manrope", "sans-serif"] } } }
        }
    </script>
    <style>
        * { scroll-behavior: smooth; }
        .animate-fade-in { animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>

<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-white font-display antialiased text-sm">
<!-- Confirmation Dialog -->
<div id="removePictureModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-all opacity-0 pointer-events-none font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 font-black border border-slate-100 dark:border-slate-700" id="removePictureModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-red-100 to-red-50 dark:from-red-900/30 dark:to-red-900/20 mb-6 ring-2 ring-red-200 dark:ring-red-900/50">
                <span class="material-symbols-outlined text-3xl text-red-600 font-black">delete</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Remove Picture?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-8 px-4 font-bold tracking-wider">This action cannot be undone.</p>
            <div class="flex gap-3 justify-center">
                <button type="button" id="cancelRemovePictureBtn" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">No, Keep It</button>
                <button type="button" id="confirmRemovePictureBtn" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-red-600 to-red-700 text-white font-black shadow-lg shadow-red-500/30 flex items-center justify-center gap-2 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95"><span class="material-symbols-outlined">delete</span> Remove</button>
            </div>
        </div>
    </div>
</div>

<div id="backModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl font-black border border-slate-100 dark:border-slate-700" id="backModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-900/20 mb-6 ring-2 ring-blue-200 dark:ring-blue-900/50">
                <span class="material-symbols-outlined text-3xl text-blue-600 font-black">arrow_back</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Are you sure?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-8 px-4 font-bold tracking-wider">Any unsaved progress will be lost.</p>
            <div class="flex gap-3 justify-center">
              <button onclick="closeM('backModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Stay</button>
                <a id="backLink" href="<?php echo $dashboardLink; ?>" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 flex items-center justify-center text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Go Back</a>
            </div>
        </div>
    </div>
</div>

<main class="min-h-screen flex flex-col">
    <header class="sticky top-0 z-30 bg-gradient-to-r from-white to-slate-50 dark:from-slate-900 dark:to-slate-800 backdrop-blur-md border-b-2 border-slate-200 dark:border-slate-700 px-6 py-5 shadow-sm">
        <div class="max-w-6xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-primary to-blue-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-white text-xl">person</span>
                </div>
                <h1 class="text-3xl font-black bg-gradient-to-r from-primary to-blue-600 bg-clip-text text-transparent">My Profile</h1>
            </div>
            <button id="backBtn" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 transition-colors text-sm font-bold shadow-sm font-black">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Dashboard
            </button>
        </div>
    </header>

    <div class="p-4 max-w-3xl mx-auto w-full flex-1 animate-fade-in space-y-4 overflow-y-auto">
        <?php echo $message; ?>

        <div class="bg-white dark:bg-slate-800 rounded-3xl border-2 border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden hover:shadow-2xl transition-shadow duration-300">
            <div class="p-6 border-b-2 border-slate-200 dark:border-slate-700 bg-gradient-to-r from-slate-50 via-blue-50 to-slate-50 dark:from-slate-800 dark:via-slate-700 dark:to-slate-800">
                <div class="flex items-center gap-4 text-slate-900 dark:text-white">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-primary/20 to-blue-500/20 flex items-center justify-center text-3xl overflow-hidden flex-shrink-0 border-3 border-primary/30 dark:border-primary/50 shadow-md" id="profileContainer">
                        <img id="profilePicturePreview" <?php if (!empty($profilePicture)): ?>data-relative-path="<?php echo htmlspecialchars($profilePicture); ?>"<?php endif; ?> alt="Profile" class="w-full h-full object-cover" style="display: <?php echo !empty($profilePicture) ? 'block' : 'none'; ?>;">
                        <span id="profilePictureIcon" class="material-symbols-outlined text-4xl text-primary" style="display: <?php echo !empty($profilePicture) ? 'none' : 'block'; ?>;">account_circle</span>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-2xl font-black tracking-tight uppercase text-slate-900 dark:text-white"><?php echo htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="inline-block px-4 py-2 rounded-full bg-primary/10 dark:bg-primary/20 border-2 border-primary/30">
                                <p class="text-xs font-black tracking-widest text-primary uppercase"><?php echo ucfirst($role); ?> Account</p>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" id="profileForm" class="p-6 space-y-5 overflow-y-auto">
                <input type="hidden" name="update_profile" value="1">
                <!-- PROFILE PICTURE UPLOAD SECTION -->
                <div class="space-y-4 pb-4 border-b-2 border-slate-100 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-400/20 to-purple-500/20 flex items-center justify-center border-2 border-purple-300/50 dark:border-purple-500/30">
                            <span class="material-symbols-outlined text-purple-600 dark:text-purple-400 text-xl">photo_camera</span>
                        </div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white uppercase tracking-widest">Profile Picture</h3>
                    </div>
                    <div class="flex flex-col items-center gap-6 bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-700/50 dark:to-slate-800/50 p-8 rounded-2xl border-2 border-dashed border-slate-300 dark:border-slate-600">
                        <div class="flex flex-col items-center gap-4 w-full">
                            <input type="file" id="profilePictureInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
                            <button type="button" onclick="document.getElementById('profilePictureInput').click()" class="px-8 py-4 bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-xl font-black shadow-lg shadow-purple-500/40 hover:shadow-xl hover:scale-105 active:scale-95 transition-all flex items-center gap-2 border-2 border-purple-400/50">
                                <span class="material-symbols-outlined">upload</span>
                                <span id="uploadButtonText">Choose Image</span>
                            </button>
                            <p class="text-xs text-slate-600 dark:text-slate-400 font-bold tracking-wide">JPG, PNG, GIF, WebP (Max 3MB)</p>
                            <div id="uploadStatus"></div>
                            <?php if (!empty($profilePicture)): ?>
                                <button type="button" id="removePictureBtn" class="w-full mt-4 px-4 py-3 rounded-xl bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 font-bold border-2 border-red-300 dark:border-red-600 text-sm tracking-tight uppercase hover:bg-red-200 dark:hover:bg-red-900/50 transition-all flex items-center justify-center gap-2 shadow-sm">
                                    <span class="material-symbols-outlined text-lg">delete</span> Remove Picture
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- PERSONAL INFORMATION SECTION -->
                <div class="space-y-4 pb-4 border-b-2 border-slate-100 dark:border-slate-700">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-blue-500/20 flex items-center justify-center border-2 border-blue-300/50 dark:border-blue-500/30">
                            <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-xl">person</span>
                        </div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white uppercase tracking-widest">Personal Information</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">badge</span> First Name
                            </label>
                            <input type="text" name="first_name" value="<?php echo htmlspecialchars($firstName); ?>" required class="w-full h-12 rounded-xl border-2 border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-bold px-5 shadow-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all text-sm">
                        </div>
                        <div class="space-y-2">
                            <label class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">badge</span> Last Name
                            </label>
                            <input type="text" name="last_name" value="<?php echo htmlspecialchars($lastName); ?>" required class="w-full h-12 rounded-xl border-2 border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-bold px-5 shadow-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all text-sm">
                        </div>
                    </div>
                </div>

                <!-- ACCOUNT SECTION -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-green-400/20 to-green-500/20 flex items-center justify-center border-2 border-green-300/50 dark:border-green-500/30">
                            <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-xl">mail</span>
                        </div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white uppercase tracking-widest">Account Information</h3>
                    </div>
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                <span class="material-symbols-outlined text-green-600 text-sm">mail</span> Email Address
                            </label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required class="w-full h-12 rounded-xl border-2 border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-bold px-5 shadow-md focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all text-sm">
                        </div>
                        <div class="space-y-2">
                            <label class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest flex items-center gap-2">
                                <span class="material-symbols-outlined text-blue-600 text-sm">edit_square</span> Username
                            </label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($username); ?>" required class="w-full h-12 rounded-xl border-2 border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-bold px-5 shadow-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all text-sm">
                        </div>
                    </div>
                </div>

                <!-- ACTION BUTTONS -->
                <div class="flex gap-3 pt-4 border-t-2 border-slate-100 dark:border-slate-700">
                    <button type="button" id="cancelBtn2" class="flex-1 px-6 py-3 rounded-xl border-2 border-slate-300 dark:border-slate-600 font-black text-slate-700 dark:text-slate-300 text-xs tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-700 transition-all shadow-md">Cancel</button>
                    <button type="submit" name="update_profile" class="flex-1 px-6 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/40 text-xs tracking-tight uppercase hover:shadow-xl hover:scale-105 active:scale-95 transition-all flex items-center justify-center gap-2 border-2 border-blue-400/50">
                        <span class="material-symbols-outlined text-lg">save</span> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    // Show temporary success toast on profile update
    const showSuccessToast = <?php echo json_encode($showSuccessToast); ?>;
    if (showSuccessToast) {
        showToast('Profile updated successfully!', 'success');
    }

    // Toast notification function
    function showToast(message, type = 'success') {
        // Remove any existing toasts
        const existing = document.getElementById('successToast');
        if (existing) existing.remove();
        
        const toastContainer = document.createElement('div');
        toastContainer.id = 'successToast';
        toastContainer.className = 'fixed inset-x-0 top-0 z-[999] flex justify-center pt-6 animate-fade-in pointer-events-none';
        
        const bgColor = type === 'success' ? 'bg-green-500 dark:bg-green-600' : 'bg-red-500 dark:bg-red-600';
        const textColor = type === 'success' ? 'text-white' : 'text-white';
        const icon = type === 'success' ? 'check_circle' : 'error';
        
        toastContainer.innerHTML = `
            <div class="px-10 py-4 ${bgColor} ${textColor} rounded-full font-bold flex items-center gap-3 shadow-2xl backdrop-blur-sm max-w-2xl mx-4 border border-white/20 pointer-events-auto">
                <span class="material-symbols-outlined text-2xl flex-shrink-0">${icon}</span>
                <span class="text-center flex-1 text-base font-black tracking-wide">${message}</span>
            </div>
        `;
        
        document.body.appendChild(toastContainer);
        
        // Auto-dismiss after 8 seconds with smooth fade
        setTimeout(() => {
            if (toastContainer.parentNode) {
                toastContainer.style.opacity = '0';
                toastContainer.style.transform = 'translateY(-30px)';
                toastContainer.style.transition = 'all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)';
                setTimeout(() => {
                    if (toastContainer.parentNode) {
                        toastContainer.remove();
                    }
                }, 600);
            }
        }, 8000);
    }
    
    // Initialize profile picture on page load
    function initializeProfilePicture() {
        const preview = document.getElementById('profilePicturePreview');
        const icon = document.getElementById('profilePictureIcon');
        
        console.log('>> Initializing profile picture...');
        console.log('>> Preview element found:', !!preview);
        console.log('>> Icon element found:', !!icon);
        
        if (preview) {
            const relativePath = preview.dataset.relativePath;
            console.log('>> data-relative-path value:', relativePath);
            console.log('>> data-relative-path exists:', !!relativePath);
            
            if (relativePath) {
                console.log('>> Setting image src to:', relativePath);
                preview.src = relativePath;
                preview.style.display = 'block';
                
                // Add error handler to detect failed loads
                preview.onerror = function() {
                    console.error('>> Image failed to load from:', relativePath);
                    preview.style.display = 'none';
                    if (icon) icon.style.display = 'block';
                };
                
                // Add success handler
                preview.onload = function() {
                    console.log('>> Image loaded successfully from:', relativePath);
                };
                
                if (icon) {
                    icon.style.display = 'none';
                    console.log('>> Hidden icon element');
                }
            } else {
                console.log('>> No data-relative-path attribute found, showing icon');
                preview.style.display = 'none';
                if (icon) icon.style.display = 'block';
            }
        } else {
            console.error('>> Preview element not found!');
        }
    }
    
    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log('>> DOMContentLoaded fired');
        initializeProfilePicture();
    });
    
    // Also try to initialize immediately if DOM is already ready
    if (document.readyState === 'loading') {
        console.log('>> DOM still loading');
    } else {
        console.log('>> DOM already loaded, initializing now');
        initializeProfilePicture();
    }
    
    const dashboardLink = '<?php echo $dashboardLink; ?>';
    const requiresConfirmation = <?php echo json_encode($requiresConfirmation); ?>;
    const backModal = document.getElementById('backModal');
    const backModalContent = document.getElementById('backModalContent');
    const backBtn = document.getElementById('backBtn');
    const cancelBtn2 = document.getElementById('cancelBtn2');
    const profileForm = document.getElementById('profileForm');
    
    // Track form changes
    let formChanged = false;
    profileForm.addEventListener('change', () => { formChanged = true; });
    profileForm.addEventListener('input', () => { formChanged = true; });

    // Back button click handler
    function handleBackClick(e) {
        if (formChanged || requiresConfirmation) {
            e.preventDefault();
            backModal.classList.remove('hidden');
            backModal.classList.add('flex');
            setTimeout(() => {
                backModal.classList.remove('opacity-0');
                backModalContent.classList.remove('scale-95');
                backModalContent.classList.add('scale-100');
            }, 10);
        } else {
            window.location.href = dashboardLink;
        }
    }

    // Cancel button (form) click handler
    function handleCancelClick(e) {
        if (formChanged || requiresConfirmation) {
            e.preventDefault();
            backModal.classList.remove('hidden');
            backModal.classList.add('flex');
            setTimeout(() => {
                backModal.classList.remove('opacity-0');
                backModalContent.classList.remove('scale-95');
                backModalContent.classList.add('scale-100');
            }, 10);
        } else {
            window.location.href = dashboardLink;
        }
    }

    // Setup back button event listener
    backBtn.addEventListener('click', handleBackClick);
    cancelBtn2.addEventListener('click', handleCancelClick);

    // Close modal function
    function closeM(id) {
        const m = document.getElementById(id);
        m.classList.add('opacity-0');
        backModalContent.classList.add('scale-95');
        backModalContent.classList.remove('scale-100');
        setTimeout(() => {
            m.classList.add('hidden');
            m.classList.remove('flex');
        }, 300);
    }

    // Close modal when clicking outside
    backModal.addEventListener('click', function(e) {
        if (e.target === backModal) {
            closeM('backModal');
        }
    });

    // Remove Picture Confirmation Modal
    function openRemovePictureModal() {
        const modal = document.getElementById('removePictureModal');
        const modalContent = document.getElementById('removePictureModalContent');
        modal.classList.remove('hidden');
        modal.classList.remove('opacity-0');
        modal.classList.remove('pointer-events-none');
        modal.classList.add('flex');
        setTimeout(() => {
            modalContent.classList.remove('scale-95');
            modalContent.classList.add('scale-100');
        }, 10);
    }
    
    function closeRemovePictureModal() {
        const modal = document.getElementById('removePictureModal');
        const modalContent = document.getElementById('removePictureModalContent');
        modal.classList.add('opacity-0');
        modalContent.classList.remove('scale-100');
        modalContent.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.add('pointer-events-none');
            modal.classList.remove('flex');
        }, 300);
    }

    // Profile Picture Upload Handler
    const profilePictureInput = document.getElementById('profilePictureInput');
    let pendingProfilePicture = null; // Store file for upload on save
    let isSubmittingForm = false; // Flag to prevent recursive submission
    
    if (profilePictureInput) {
        profilePictureInput.addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const uploadStatus = document.getElementById('uploadStatus');
            const uploadButtonText = document.getElementById('uploadButtonText');

            // Validate file size
            const maxSize = 3 * 1024 * 1024; // 3MB
            if (file.size > maxSize) {
                uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> File size exceeds 3MB limit.</div>';
                profilePictureInput.value = '';
                return;
            }

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> Invalid file type. Use JPG, PNG, GIF, or WebP.</div>';
                profilePictureInput.value = '';
                return;
            }

            // Preview the image locally without uploading yet
            const preview = document.getElementById('profilePicturePreview');
            const icon = document.getElementById('profilePictureIcon');
            const reader = new FileReader();
            
            reader.onload = function(event) {
                pendingProfilePicture = file; // Store file for later upload on save
                preview.src = event.target.result;
                preview.style.display = 'block';
                if (icon) icon.style.display = 'none';
                uploadStatus.innerHTML = '<div class="p-4 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-xl text-xs font-bold border-2 border-blue-300 dark:border-blue-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">info</span> Image preview loaded. Click Save to apply changes.</div>';
                formChanged = true; // Mark form as changed
            };
            
            reader.readAsDataURL(file);
        });
    }
    
    // Function to upload profile picture and submit form
    async function uploadProfilePictureAndSubmit() {
        if (!pendingProfilePicture) {
            // No pending picture, proceed with normal form submission
            profileForm.submit();
            return;
        }

        isSubmittingForm = true;
        
        const uploadStatus = document.getElementById('uploadStatus');
        const uploadButtonText = document.getElementById('uploadButtonText');
        uploadButtonText.textContent = 'Saving...';
        uploadStatus.innerHTML = '';

        const formData = new FormData();
        formData.append('profile_picture', pendingProfilePicture);
        formData.append('user_id', <?php echo $currentUserID; ?>);

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 30000);

            const response = await fetch('backend/upload-profile-picture.php', {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            const contentType = response.headers.get('content-type');
            let data;

            try {
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600"><strong>Server Error:</strong><br>' + (text.substring(0, 100) || 'empty response') + '</div>';
                    uploadButtonText.textContent = 'Choose Image';
                    isSubmittingForm = false;
                    return false;
                }
            } catch (parseError) {
                uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> Server error (HTTP ' + response.status + ')</div>';
                uploadButtonText.textContent = 'Choose Image';
                isSubmittingForm = false;
                return false;
            }

            if (data.success) {
                // Image upload successful
                console.log('Image uploaded successfully:', data);
                uploadStatus.innerHTML = '<div class="p-4 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-xl text-xs font-bold border-2 border-green-300 dark:border-green-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">check_circle</span> Profile picture saved! Submitting form...</div>';
                pendingProfilePicture = null;
                uploadButtonText.textContent = 'Choose Image';
                
                // Now submit the form data via fetch to ensure it processes
                const allFormData = new FormData(profileForm);
                
                try {
                    console.log('Submitting form with all data...');
                    const submitResponse = await fetch(window.location.href, {
                        method: 'POST',
                        body: allFormData
                    });
                    
                    console.log('Form submission response status:', submitResponse.status);
                    
                    if (submitResponse.ok) {
                        console.log('Form submission successful, showing toast...');
                        showToast('Profile updated successfully!', 'success');
                        formChanged = false;
                        
                        // Reload page after successful submission to show updates
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        const responseText = await submitResponse.text();
                        console.error('Form submission failed:', submitResponse.status, responseText);
                        uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> Form submission failed. Please try again.</div>';
                        isSubmittingForm = false;
                    }
                } catch (submitError) {
                    console.error('Form submission error:', submitError);
                    uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> ' + submitError.message + '</div>';
                    isSubmittingForm = false;
                }
            } else {
                const errorMsg = data.message || 'Upload failed.';
                console.error('Image upload failed:', data);
                uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> ' + htmlEscape(errorMsg) + '</div>';
                uploadButtonText.textContent = 'Choose Image';
                isSubmittingForm = false;
            }
        } catch (error) {
            uploadStatus.innerHTML = '<div class="p-4 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-xl text-xs font-bold border-2 border-red-300 dark:border-red-600 flex items-center gap-2"><span class="material-symbols-outlined text-sm">error</span> Network error: ' + error.message + '</div>';
            uploadButtonText.textContent = 'Choose Image';
            isSubmittingForm = false;
        }
    }
    
    // Remove Profile Picture Handler
    const removePictureBtn = document.getElementById('removePictureBtn');
    if (removePictureBtn) {
        removePictureBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            openRemovePictureModal();
        });
    }
    
    // Confirm Remove Picture
    const confirmRemovePictureBtn = document.getElementById('confirmRemovePictureBtn');
    if (confirmRemovePictureBtn) {
        confirmRemovePictureBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            closeRemovePictureModal();
            
            try {
                const response = await fetch('backend/delete-profile-picture.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'user_id=<?php echo $currentUserID; ?>'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast('Profile picture removed successfully!', 'success');
                    
                    // Hide image and show default icon
                    const preview = document.getElementById('profilePicturePreview');
                    const icon = document.getElementById('profilePictureIcon');
                    if (preview) preview.style.display = 'none';
                    if (icon) icon.style.display = 'block';
                    
                    // Hide remove button
                    removePictureBtn.style.display = 'none';
                    
                    // Clear any pending upload
                    pendingProfilePicture = null;
                    
                    // Reset file input
                    const fileInput = document.getElementById('profilePictureInput');
                    if (fileInput) fileInput.value = '';
                    
                    // Reload page after brief delay to reflect changes
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast(data.message || 'Failed to remove picture. Please try again.', 'error');
                }
            } catch (error) {
                showToast('Error: ' + error.message, 'error');
            }
        });
    }
    
    // Cancel Remove Picture
    const cancelRemovePictureBtn = document.getElementById('cancelRemovePictureBtn');
    if (cancelRemovePictureBtn) {
        cancelRemovePictureBtn.addEventListener('click', function(e) {
            e.preventDefault();
            closeRemovePictureModal();
        });
    }
    
    // Close modal when clicking outside
    const removePictureModal = document.getElementById('removePictureModal');
    if (removePictureModal) {
        removePictureModal.addEventListener('click', function(e) {
            if (e.target === removePictureModal) {
                closeRemovePictureModal();
            }
        });
    }
    
    // Override form submission to handle profile picture upload on save
    profileForm.addEventListener('submit', async function(e) {
        if (isSubmittingForm) return; // Prevent recursive submission
        
        e.preventDefault();
        
        // If there's a pending profile picture, use async upload handler
        if (pendingProfilePicture) {
            await uploadProfilePictureAndSubmit();
        } else {
            // No picture upload - submit form normally
            const formData = new FormData(profileForm);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    // Show success toast immediately
                    showToast('Profile updated successfully!', 'success');
                    
                    // Reset form state
                    formChanged = false;
                    
                    // Reload page after a brief delay to show the toast
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast('Failed to update profile. Please try again.', 'error');
                }
            } catch (error) {
                showToast('Error: ' + error.message, 'error');
            }
        }
    });

    function htmlEscape(text) {
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return text.replace(/[&<>"']/g, m => map[m]);
    }
</script>
</body>
</html>
