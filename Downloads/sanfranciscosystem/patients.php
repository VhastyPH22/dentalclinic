<?php 
// 1. SETUP & SECURITY
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/backend/config.php'; 
require_once __DIR__ . '/backend/middleware.php';

// Conditional PHPMailer loading - only if files exist
$phpmailerAvailable = false;
if (file_exists(__DIR__ . '/PHPMailer/src/PHPMailer.php') &&
    file_exists(__DIR__ . '/PHPMailer/src/Exception.php') &&
    file_exists(__DIR__ . '/PHPMailer/src/SMTP.php')) {
    
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
    $phpmailerAvailable = true;
} 

// Check Access
checkAccess(['dentist', 'assistant', 'patient']);

$role = $_SESSION['role'] ?? 'patient';
$currentUserID = $_SESSION['user_id'] ?? 0;
$message = "";
$redirectUrl = null;

// Check for profile update success message from previous request (for JavaScript toast)
$showSuccessToast = isset($_SESSION['profile_update_success']) ? true : false;
if (isset($_SESSION['profile_update_success'])) {
    unset($_SESSION['profile_update_success']);
}

// Debug: Show current view on page
error_log("DEBUG: Role=$role, CurrentUserID=$currentUserID, View will be determined next");

// Determine Dashboard Link
$dashboardLink = 'patient-dashboard.php';
if ($role === 'dentist') $dashboardLink = 'dentist-dashboard.php';
elseif ($role === 'assistant') $dashboardLink = 'assistant-dashboard.php';

// --- 2. LOGIC: DETERMINE VIEW MODE ---
$view = ($role === 'patient') ? 'edit' : 'list'; 
$showArchived = isset($_GET['archived']) && $_GET['archived'] == 1;

if (isset($_GET['view']) && $_GET['view'] === 'add' && $role !== 'patient') {
    $view = 'add';
} elseif (isset($_GET['id'])) {
    if ($role === 'dentist' || $role === 'assistant') {
        $view = 'edit';
        $targetID = $_GET['id'];
    } elseif ($role === 'patient') {
        $view = 'edit';
        $targetID = $currentUserID;
    }
} elseif ($role === 'patient') {
    $targetID = $currentUserID;
}

/**
 * 3. HANDLE FORM SUBMISSIONS
 */

// A. CREATE NEW PATIENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_patient'])) {
    $fname = mysqli_real_escape_string($conn, trim($_POST['first_name'] ?? ''));
    $lname = mysqli_real_escape_string($conn, trim($_POST['last_name'] ?? ''));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $username = mysqli_real_escape_string($conn, trim($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    
    $phone     = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $complaint = mysqli_real_escape_string($conn, trim($_POST['chief_complaint'] ?? '')); 
    $addr      = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));
    $bday      = mysqli_real_escape_string($conn, $_POST['dob'] ?? ''); 
    $occup     = mysqli_real_escape_string($conn, trim($_POST['occupation'] ?? ''));
    $marital   = mysqli_real_escape_string($conn, $_POST['marital_status'] ?? 'single');
    $gender    = mysqli_real_escape_string($conn, $_POST['gender'] ?? 'female');
    $hashedPass = password_hash($password, PASSWORD_DEFAULT);
    
    // Store values for form display in case of errors
    $firstName = $fname;
    $lastName = $lname;
    $address = $addr;
    $birthdate = $bday;
    $occupation = $occup;
    $maritalStatus = $marital;
    // Note: $email, $phone, $username, $complaint, $gender already have correct variable names 

    $errors = [];
    
    if (empty($fname) || empty($lname) || empty($bday) || empty($phone) || empty($email) || empty($username) || empty($password) || empty($addr)) {
        $errors[] = "All required fields must be filled.";
    }
    if ($bday > date('Y-m-d')) $errors[] = "Date of birth cannot be in the future.";

    $uCheck = mysqli_query($conn, "SELECT id FROM users WHERE username = '$username' OR email = '$email'");
    if(mysqli_num_rows($uCheck) > 0) $errors[] = "Username or Email is already registered.";
    
    $pCheck = mysqli_query($conn, "SELECT user_id FROM patient_profiles WHERE phone = '$phone'");
    if(mysqli_num_rows($pCheck) > 0) $errors[] = "Phone number is already associated with another account.";

    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password does not meet security requirements.";
    }

    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        try {
            $sqlUser = "INSERT INTO users (role, first_name, last_name, email, username, password, is_archived, email_verified) 
                        VALUES ('patient', '$fname', '$lname', '$email', '$username', '$hashedPass', 0, 0)";
            if (!mysqli_query($conn, $sqlUser)) throw new Exception(mysqli_error($conn));
            
            $newID = mysqli_insert_id($conn);

            $sqlProfile = "INSERT INTO patient_profiles (user_id, dob, phone, address, occupation, marital_status, gender, chief_complaint) 
                           VALUES ('$newID', '$bday', '$phone', '$addr', '$occup', '$marital', '$gender', '$complaint')";
            if (!mysqli_query($conn, $sqlProfile)) throw new Exception(mysqli_error($conn));

            mysqli_commit($conn);
            
            // Send verification email
            $verificationToken = bin2hex(random_bytes(32));
            $verifyExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $tokenQuery = "UPDATE users SET email_verification_token = '$verificationToken', verification_token_expiry = '$verifyExpiry' WHERE id = '$newID'";
            mysqli_query($conn, $tokenQuery);
            
            // Send verification email via PHPMailer (only if available)
            if ($phpmailerAvailable) {
                $verifyLink = "http://" . $_SERVER['HTTP_HOST'] . "/sanfranciscosystem/backend/verify-email.php?token=" . $verificationToken;
                $emailSubject = "Verify Your Email - San Nicolas Dental Clinic";
                $emailBody = "<html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head><body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f8fafc;'><div style='max-width: 600px; margin: 0 auto; padding: 20px;'><div style='background-color: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'><h2 style='color: #1e3a5f; font-size: 24px; margin-top: 0;'>Welcome to San Nicolas Dental Clinic!</h2><p style='color: #333; font-size: 16px; line-height: 1.6;'>Please verify your email address by clicking the button below:</p><div style='text-align: center; margin: 30px 0;'><a href='" . $verifyLink . "' style='background-color: #1e3a5f; color: white; padding: 16px 40px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px; display: inline-block; cursor: pointer;'>Verify Email Address</a></div><p style='color: #666; font-size: 14px; line-height: 1.6;'>Or copy and paste this link in your browser:<br><a href='" . $verifyLink . "' style='color: #1e3a5f; word-break: break-all;'>" . htmlspecialchars($verifyLink) . "</a></p><hr style='border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;'><p style='color: #666; font-size: 13px; line-height: 1.6;'>This link will expire in 24 hours.<br>If you did not create this account, please ignore this email.</p><p style='font-size: 12px; color: #94a3b8; text-align: center; margin-top: 30px;'>© San Nicolas Dental Clinic. All rights reserved.</p></div></div></body></html>";
                
                try {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'timoteovhasty@gmail.com';
                    $mail->Password = 'xpiz rojp pryc kbbc';
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->setFrom('timoteovhasty@gmail.com', 'San Nicolas Dental Clinic');
                    $mail->addAddress($email);
                    $mail->isHTML(true);
                    $mail->Subject = $emailSubject;
                    $mail->Body = $emailBody;
                    $mail->send();
                } catch (Exception $e) {
                    error_log("Verification email failed: " . $e->getMessage());
                }
            }
            
            $message = "<div class='mb-6 p-4 bg-green-100 text-green-700 rounded-lg font-bold flex items-center gap-2 animate-fade-in border border-green-200 shadow-sm'><span class='material-symbols-outlined'>check_circle</span> Patient record created successfully! A verification email has been sent to <strong>" . htmlspecialchars($email) . "</strong></div>";
            $view = 'list'; 
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "<div class='mb-6 p-4 bg-red-100 text-red-700 rounded-lg font-bold border border-red-200'>Error: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='mb-6 p-4 bg-red-100 text-red-700 rounded-lg font-bold border border-red-200 shadow-sm animate-fade-in'>" . implode('<br>', $errors) . "</div>";
    }
}

// B. UPDATE PATIENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_changes'])) {
    $pid   = mysqli_real_escape_string($conn, $_POST['target_id'] ?? '');
    $fname = mysqli_real_escape_string($conn, trim($_POST['first_name'] ?? ''));
    $lname = mysqli_real_escape_string($conn, trim($_POST['last_name'] ?? ''));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $username = mysqli_real_escape_string($conn, trim($_POST['username'] ?? ''));
    
    $phone     = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $complaint = mysqli_real_escape_string($conn, trim($_POST['chief_complaint'] ?? ''));
    $addr      = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));
    $bday      = mysqli_real_escape_string($conn, $_POST['dob'] ?? '');
    $occup     = mysqli_real_escape_string($conn, trim($_POST['occupation'] ?? ''));
    $marital   = mysqli_real_escape_string($conn, $_POST['marital_status'] ?? 'single');
    $gender    = mysqli_real_escape_string($conn, $_POST['gender'] ?? 'female');

    $errors = [];
    if (empty($fname) || empty($lname) || empty($phone) || empty($email) || empty($username) || empty($bday) || empty($addr)) $errors[] = "All required fields must be filled.";
    if ($bday > date('Y-m-d')) $errors[] = "Date of birth cannot be in the future.";

    $uCheck = mysqli_query($conn, "SELECT id FROM users WHERE (username = '$username' OR email = '$email') AND id != '$pid'");
    if(mysqli_num_rows($uCheck) > 0) $errors[] = "Username or Email already used by another account.";
    
    $pCheck = mysqli_query($conn, "SELECT user_id FROM patient_profiles WHERE phone = '$phone' AND user_id != '$pid'");
    if(mysqli_num_rows($pCheck) > 0) $errors[] = "Phone number is already used by another patient.";

    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        try {
            // Debug: Log the UPDATE query
            error_log("DEBUG: Updating user $pid with fname=$fname, lname=$lname, email=$email, username=$username");
            
            $sqlU = "UPDATE users SET first_name='$fname', last_name='$lname', email='$email', username='$username' WHERE id='$pid'";
            $updateResult = mysqli_query($conn, $sqlU);
            if (!$updateResult) {
                error_log("ERROR: User update failed: " . mysqli_error($conn));
                throw new Exception("User update failed: " . mysqli_error($conn));
            }
            error_log("DEBUG: User update succeeded, rows affected: " . mysqli_affected_rows($conn));

            // Preserve existing profile picture when updating other fields
            $preservePicture = '';
            $checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
            $columnCheckResult = mysqli_query($conn, $checkColumnSQL);
            if ($columnCheckResult && mysqli_num_rows($columnCheckResult) > 0) {
                $currentPictureQuery = mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '$pid'");
                if ($currentPictureQuery) {
                    $pictureData = mysqli_fetch_assoc($currentPictureQuery);
                    $preservePicture = $pictureData['profile_picture'] ?? '';
                }
                if ($preservePicture) {
                    $sqlP = "UPDATE patient_profiles SET dob='$bday', phone='$phone', address='$addr', occupation='$occup', marital_status='$marital', gender='$gender', chief_complaint='$complaint', profile_picture='$preservePicture' WHERE user_id='$pid'";
                } else {
                    $sqlP = "UPDATE patient_profiles SET dob='$bday', phone='$phone', address='$addr', occupation='$occup', marital_status='$marital', gender='$gender', chief_complaint='$complaint' WHERE user_id='$pid'";
                }
            } else {
                $sqlP = "UPDATE patient_profiles SET dob='$bday', phone='$phone', address='$addr', occupation='$occup', marital_status='$marital', gender='$gender', chief_complaint='$complaint' WHERE user_id='$pid'";
            }
            if (!mysqli_query($conn, $sqlP)) throw new Exception(mysqli_error($conn));

            mysqli_commit($conn);
            error_log("DEBUG: Transaction committed successfully for user $pid");
            $_SESSION['profile_update_success'] = true;
            $message = "<div class='mb-6 p-4 bg-green-100 text-green-700 rounded-lg font-bold flex items-center gap-2 animate-fade-in border-4 border-green-500 shadow-lg' style='font-size: 18px;'><span class='material-symbols-outlined'>check_circle</span> ✓ PROFILE SAVED SUCCESSFULLY!</div>";
            // For patients (most common case), stay on page to see success message
            // For dentists/assistants, redirect after delay
            if ($pid == $currentUserID && $role === 'patient') {
                // Patients editing their own profile - stay on page
                $redirectUrl = null;
            } else if ($pid == $currentUserID) {
                // Other roles - redirect to their dashboard
                $redirectUrl = $dashboardLink;
            }
            if ($pid == $currentUserID) $_SESSION['full_name'] = "$fname $lname";
            
            // Reload profile picture from database after save
            $checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
            $columnExists = mysqli_query($conn, $checkColumnSQL);
            if ($columnExists && mysqli_num_rows($columnExists) > 0) {
                $profileQuery = mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '$pid'");
                if ($profileQuery) {
                    $profileData = mysqli_fetch_assoc($profileQuery);
                    $profilePicture = $profileData['profile_picture'] ?? '';
                }
            }
            
            // Set redirect flag (will output in HEAD section properly)
            if ($pid == $currentUserID) {
                $redirectUrl = $dashboardLink;
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            error_log("ERROR in save_changes: " . $e->getMessage());
            $message = "<div class='mb-6 p-4 bg-red-100 text-red-700 rounded-lg font-bold border border-red-200'>Error updating record: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        error_log("DEBUG: save_changes validation failed - errors: " . implode(", ", $errors));
        $message = "<div class='mb-6 p-4 bg-red-100 text-red-700 rounded-lg font-bold border border-red-200 shadow-sm animate-fade-in'>" . implode('<br>', $errors) . "</div>";
    }
}

// C. ARCHIVE / RESTORE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_patient'])) {
    $deleteID = mysqli_real_escape_string($conn, $_POST['delete_id']);
    if (mysqli_query($conn, "UPDATE users SET is_archived = 1 WHERE id = '$deleteID' AND role = 'patient'")) {
        $message = "<div class='mb-6 p-4 bg-green-100 text-green-700 rounded-lg font-bold flex items-center gap-2 animate-fade-in border border-green-200 shadow-sm'><span class='material-symbols-outlined'>archive</span> Patient account archived.</div>";
        $view = 'list';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_patient'])) {
    $restoreID = mysqli_real_escape_string($conn, $_POST['restore_id']);
    if (mysqli_query($conn, "UPDATE users SET is_archived = 0 WHERE id = '$restoreID' AND role = 'patient'")) {
        $message = "<div class='mb-6 p-4 bg-blue-100 text-blue-700 rounded-lg font-bold flex items-center gap-2 animate-fade-in border border-blue-200 shadow-sm'><span class='material-symbols-outlined'>unarchive</span> Patient account restored.</div>";
        $view = 'list';
    }
}

/**
 * 4. DATA PREP
 */
$firstName = ''; $lastName = ''; $email = ''; $phone = ''; $username = ''; $complaint = ''; $address = ''; $birthdate = ''; $occupation = ''; $maritalStatus = 'single'; $gender = 'female'; $age = ''; $profilePicture = '';

if ($view === 'edit') {
    // Build query safely - check if profile_picture column exists
    $checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
    $columnExists = mysqli_query($conn, $checkColumnSQL);
    
    if ($columnExists && mysqli_num_rows($columnExists) > 0) {
        $sqlFetch = "SELECT u.*, p.dob as birthdate, p.phone, p.address, p.occupation, p.marital_status, p.gender, p.chief_complaint, p.profile_picture
                FROM users u 
                LEFT JOIN patient_profiles p ON u.id = p.user_id 
                WHERE u.id = '$targetID'";
    } else {
        $sqlFetch = "SELECT u.*, p.dob as birthdate, p.phone, p.address, p.occupation, p.marital_status, p.gender, p.chief_complaint
                FROM users u 
                LEFT JOIN patient_profiles p ON u.id = p.user_id 
                WHERE u.id = '$targetID'";
    }
    $result = mysqli_query($conn, $sqlFetch);
    if ($result) {
        $data = mysqli_fetch_assoc($result);
        if ($data) {
            $firstName = $data['first_name'] ?? ''; $lastName  = $data['last_name'] ?? '';
            $email     = $data['email'] ?? '';      $phone     = $data['phone'] ?? '';
            $username  = $data['username'] ?? '';   $complaint = $data['chief_complaint'] ?? '';
            $address   = $data['address'] ?? '';     $birthdate = $data['birthdate'] ?? '';
            $occupation = $data['occupation'] ?? ''; $maritalStatus = $data['marital_status'] ?? 'single';
            $gender    = $data['gender'] ?? 'female'; $profilePicture = $data['profile_picture'] ?? '';
        }
    }
}

if ($view === 'list') {
    $search = $_GET['search'] ?? '';
    $archiveVal = $showArchived ? 1 : 0;
    $fromDate = $_GET['from_date'] ?? '';
    $toDate = $_GET['to_date'] ?? '';
    $filterGender = $_GET['filter_gender'] ?? '';
    $filterMarital = $_GET['filter_marital'] ?? '';
    $filterVerification = $_GET['filter_verification'] ?? '';
    
    // Check if profile_picture column exists
    $checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
    $columnCheckResult = mysqli_query($conn, $checkColumnSQL);
    $hasProfilePictureColumn = $columnCheckResult && mysqli_num_rows($columnCheckResult) > 0;
    
    // Build query based on column availability
    if ($hasProfilePictureColumn) {
        $sqlList = "SELECT u.*, p.phone, p.profile_picture, p.gender, p.marital_status
                    FROM users u
                    LEFT JOIN patient_profiles p ON u.id = p.user_id
                    WHERE u.role = 'patient' AND u.is_archived = '$archiveVal'";
    } else {
        $sqlList = "SELECT u.*, p.phone, p.gender, p.marital_status
                    FROM users u
                    LEFT JOIN patient_profiles p ON u.id = p.user_id
                    WHERE u.role = 'patient' AND u.is_archived = '$archiveVal'";
    }
    
    if (!empty($search)) {
        $safeSearch = mysqli_real_escape_string($conn, $search);
        $sqlList .= " AND (u.first_name LIKE '%$safeSearch%' OR u.last_name LIKE '%$safeSearch%' OR u.email LIKE '%$safeSearch%' OR p.phone LIKE '%$safeSearch%')";
    }
    
    // Date range filter
    if (!empty($fromDate)) {
        $safeFromDate = mysqli_real_escape_string($conn, $fromDate);
        $sqlList .= " AND DATE(u.created_at) >= '$safeFromDate'";
    }
    if (!empty($toDate)) {
        $safeToDate = mysqli_real_escape_string($conn, $toDate);
        $sqlList .= " AND DATE(u.created_at) <= '$safeToDate'";
    }
    
    // Gender filter
    if (!empty($filterGender)) {
        $safeGender = mysqli_real_escape_string($conn, $filterGender);
        $sqlList .= " AND p.gender = '$safeGender'";
    }
    
    // Marital status filter
    if (!empty($filterMarital)) {
        $safeMarital = mysqli_real_escape_string($conn, $filterMarital);
        $sqlList .= " AND p.marital_status = '$safeMarital'";
    }
    
    // Email verification filter
    if (!empty($filterVerification)) {
        if ($filterVerification === 'verified') {
            $sqlList .= " AND u.email_verified = 1";
        } else if ($filterVerification === 'unverified') {
            $sqlList .= " AND u.email_verified = 0";
        }
    }
    
    $sqlList .= " ORDER BY u.created_at DESC";
    $listResult = mysqli_query($conn, $sqlList);
    if (!$listResult) {
        $listResult = false;
    }
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>Patients - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" }, fontFamily: { "display": ["Manrope", "sans-serif"] } } }
        }
    </script>
    <style>
        * { scroll-behavior: smooth; }
        html { scroll-behavior: smooth; }
        
        .animate-fade-in { 
            animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; 
        } 
        
        @keyframes fadeIn { 
            from { 
                opacity: 0; 
                transform: translateY(15px); 
            } 
            to { 
                opacity: 1; 
                transform: translateY(0); 
            } 
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        body {
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        a, button {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        header {
            animation: slideInDown 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        input, select, textarea {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        input:focus, select:focus, textarea:focus {
            transform: translateY(-1px);
        }
        
        .rounded-3xl, .rounded-2xl, .rounded-lg {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .shadow-2xl, .shadow-sm, .shadow-md {
            transition: box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .bg-white, .dark\:bg-slate-800, .dark\:bg-slate-900 {
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        [class*="hover:"] {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        table tbody tr {
            transition: all 0.2s ease;
        }
        
        table tbody tr:hover {
            transform: translateX(2px);
        }
        
        .border {
            transition: border-color 0.3s ease;
        }
        
        .requirement-item.valid { color: #10b981; }
        .requirement-item.valid span { font-variation-settings: 'FILL' 1; }
        
        .tab-link {
            position: relative;
            overflow: visible;
            border-bottom: 3px solid transparent;
            transition: border-color 0.3s ease;
        }
        
        .tab-link.active {
            border-bottom-color: currentColor;
        }
        
        .patient-row {
            transition: all 0.2s ease;
        }
        
        .patient-row:hover {
            background-color: rgba(30, 58, 95, 0.03);
            transform: translateX(4px);
        }
        
        .action-icon {
            transition: all 0.2s ease;
            opacity: 0.6;
        }
        
        .action-icon:hover {
            opacity: 1;
            transform: scale(1.15);
        }
        
        .form-section {
            transition: all 0.3s ease;
        }
        
        .password-strength {
            transition: all 0.2s ease;
        }

        input[type="password"]:focus ~ .password-strength,
        input[type="text"]:focus ~ .password-strength {
            box-shadow: 0 8px 16px rgba(30, 58, 95, 0.15);
        }

        /* Form Focus Enhancements */
        input:required:valid {
            border-color: #10b981;
        }

        .modal-backdrop {
            animation: backdropFadeIn 0.3s ease-out forwards;
        }

        @keyframes backdropFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .modal-content {
            animation: modalSlideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Improved Table Hover States */
        .patient-row {
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .patient-row:hover {
            background-color: rgba(30, 58, 95, 0.05);
        }

        /* Better Button States */
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Improved Mobile Form Spacing */
        @media (max-width: 640px) {
            .form-section {
                padding: 1rem;
            }

            input, select, textarea {
                font-size: 16px !important;
            }

            .grid {
                gap: 1rem;
            }
        }
    </style>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
    <?php if ($redirectUrl): ?>
        <meta http-equiv="refresh" content="2;url=<?php echo htmlspecialchars($redirectUrl); ?>">
    <?php endif; ?>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-white font-display antialiased text-sm">
<main class="min-h-screen flex flex-col">

    <header class="sticky top-0 z-30 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 px-6 py-4">
        <div class="max-w-6xl mx-auto flex justify-between items-center text-slate-900 dark:text-white font-black tracking-tight">
            <div class="space-y-1">
                <h1 class="text-2xl font-black">
                    <?php 
                        if($view == 'add') echo 'Register patient';
                        elseif($view == 'edit') echo 'Clinical profile';
                        else echo 'Patient records';
                    ?>
                </h1>
                <p class="text-slate-500 text-[10px] font-black">Unified medical database</p>
            </div>
            
            <button onclick="openBackModal()" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 transition-colors text-sm font-bold shadow-sm tracking-tight">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Dashboard
            </button>
        </div>
    </header>

    <div class="p-6 max-w-6xl mx-auto w-full flex-1 animate-fade-in space-y-6">
        <?php echo $message; ?>

        <?php if ($view === 'list'): ?>
            <div class="flex items-center gap-0 mb-6 border-b-2 border-slate-200 dark:border-slate-800 font-black bg-gradient-to-r from-transparent to-slate-50 dark:to-slate-900/30 p-1 rounded-t-xl">
                <a href="patients.php" class="tab-link active px-8 py-4 text-sm font-black uppercase tracking-widest transition-all <?php echo !$showArchived ? 'text-primary border-primary' : 'text-slate-400 hover:text-slate-600'; ?>">
                    <span class="flex items-center gap-2"><span class="material-symbols-outlined text-lg">person</span> Active patients</span>
                </a>
                <a href="patients.php?archived=1" class="tab-link px-8 py-4 text-sm font-black uppercase tracking-widest transition-all <?php echo $showArchived ? 'text-orange-500 border-orange-500' : 'text-slate-400 hover:text-slate-600'; ?>">
                    <span class="flex items-center gap-2"><span class="material-symbols-outlined text-lg">archive</span> Archived patients</span>
                </a>
            </div>

            <div class="flex flex-col gap-4 mb-6 font-black">
                <div class="flex flex-col sm:flex-row justify-between gap-4">
                    <form method="GET" class="flex-1 relative max-w-2xl">
                        <?php if($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
                        <span class="material-symbols-outlined absolute left-4 top-3.5 text-slate-400 text-lg">search</span>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>" placeholder="Search by name, email, phone..." 
                               class="w-full pl-12 pr-4 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-bold focus:ring-2 focus:ring-primary focus:border-primary shadow-sm transition-all tracking-tight">
                    </form>
                    <div class="flex gap-3">
                        <button type="button" onclick="toggleAdvancedSearch()" class="h-12 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-black text-sm uppercase hover:bg-slate-200 transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">tune</span> Filters
                        </button>
                        <a href="patients.php?view=add" class="h-12 bg-gradient-to-r from-primary to-blue-600 text-white px-6 rounded-xl font-black text-sm flex items-center justify-center gap-2 shadow-lg shadow-blue-500/30 hover:shadow-xl hover:scale-[1.01] active:scale-95 transition-all tracking-tight whitespace-nowrap">
                            <span class="material-symbols-outlined text-lg">person_add</span> Add patient
                        </a>
                    </div>
                </div>

                <!-- Advanced Search Panel -->
                <div id="advancedSearchPanel" class="hidden animate-fade-in bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-6 font-black uppercase text-slate-900 dark:text-white">
                        <span class="material-symbols-outlined text-primary">filter_alt</span>
                        <h3 class="text-sm font-black">Advanced Filters</h3>
                        <button type="button" onclick="toggleAdvancedSearch()" class="ml-auto p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors">
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </button>
                    </div>

                    <form method="GET" class="space-y-5">
                        <?php if($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
                        
                        <!-- Date Range -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">From Date</label>
                                <input type="date" name="from_date" value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>" 
                                    class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">To Date</label>
                                <input type="date" name="to_date" value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>" 
                                    class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                            </div>
                        </div>

                        <!-- Gender Filter -->
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Gender</label>
                            <select name="filter_gender" class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                <option value="">-- All Genders --</option>
                                <option value="female" <?php echo (($_GET['filter_gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                                <option value="male" <?php echo (($_GET['filter_gender'] ?? '') === 'male') ? 'selected' : ''; ?>>Male</option>
                            </select>
                        </div>

                        <!-- Marital Status Filter -->
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Marital Status</label>
                            <select name="filter_marital" class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                <option value="">-- All Statuses --</option>
                                <option value="single" <?php echo (($_GET['filter_marital'] ?? '') === 'single') ? 'selected' : ''; ?>>Single</option>
                                <option value="married" <?php echo (($_GET['filter_marital'] ?? '') === 'married') ? 'selected' : ''; ?>>Married</option>
                                <option value="divorced" <?php echo (($_GET['filter_marital'] ?? '') === 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                                <option value="widowed" <?php echo (($_GET['filter_marital'] ?? '') === 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                            </select>
                        </div>

                        <!-- Email Verification Filter -->
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Email Status</label>
                            <select name="filter_verification" class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                <option value="">-- All Statuses --</option>
                                <option value="verified" <?php echo (($_GET['filter_verification'] ?? '') === 'verified') ? 'selected' : ''; ?>>Verified</option>
                                <option value="unverified" <?php echo (($_GET['filter_verification'] ?? '') === 'unverified') ? 'selected' : ''; ?>>Unverified</option>
                            </select>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-3 pt-4 border-t border-slate-100 dark:border-slate-700 font-black uppercase">
                            <button type="submit" class="flex-1 h-10 rounded-lg bg-primary text-white font-black text-xs uppercase shadow-lg hover:bg-blue-600 transition-all flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">search</span> Apply Filters
                            </button>
                            <a href="patients.php<?php echo $showArchived ? '?archived=1' : ''; ?>" class="flex-1 h-10 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-black text-xs uppercase hover:bg-slate-100 dark:hover:bg-slate-700 transition-all flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">refresh</span> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-lg">
                <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-900 dark:to-slate-800/50 text-slate-600 dark:text-slate-400 font-black text-[10px] tracking-widest border-b dark:border-slate-700">
                        <tr>
                            <th class="px-6 py-5">Patient name</th>
                            <th class="px-6 py-5">Contact</th>
                            <th class="px-6 py-5">Status</th>
                            <th class="px-6 py-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                        <?php if ($listResult && mysqli_num_rows($listResult) > 0): ?>
                            <?php while($row = mysqli_fetch_assoc($listResult)): ?>
                            <tr class="patient-row hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-all">
                                <td class="px-6 py-5">
                                    <div class="flex items-center gap-4">
                                        <div class="h-11 w-11 rounded-full bg-gradient-to-br from-primary to-blue-600 text-white flex items-center justify-center font-black text-sm shadow-md overflow-hidden flex-shrink-0">
                                            <?php 
                                                $profilePicture = $row['profile_picture'] ?? '';
                                                if ($profilePicture) {
                                                    // Add cache-busting timestamp to prevent stale image display
                                                    $imageUrl = $profilePicture . '?t=' . time();
                                                    echo '<img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($row['first_name'] ?? 'Patient') . '" class="w-full h-full object-cover">';
                                                } else {
                                                    echo strtoupper(substr($row['first_name'] ?? 'P', 0, 1));
                                                }
                                            ?>
                                        </div>
                                        <p class="text-slate-900 dark:text-white font-bold tracking-tight"><?php echo htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); ?></p>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <p class="text-slate-900 dark:text-white font-bold tracking-tight"><?php echo htmlspecialchars($row['email'] ?? 'No email'); ?></p>
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400 font-black tracking-tight mt-2"><?php echo htmlspecialchars($row['phone'] ?? 'N/A'); ?></p>
                                </td>
                                <td class="px-6 py-5">
                                    <?php if(($row['email_verified'] ?? 0) == 1): ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                            <span class="material-symbols-outlined text-sm">check_circle</span> Verified
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">
                                            <span class="material-symbols-outlined text-sm">pending</span> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="patients.php?id=<?php echo $row['id']; ?>" class="action-icon p-2.5 text-primary hover:bg-primary/10 rounded-lg transition-all" title="Edit profile"><span class="material-symbols-outlined text-[20px]">edit</span></a>
                                        <?php if(($row['email_verified'] ?? 0) == 0 && !$showArchived): ?>
                                            <button type="button" onclick="resendVerificationEmail('<?php echo htmlspecialchars($row['email']); ?>')" class="action-icon p-2.5 text-blue-500 hover:bg-blue-100/50 dark:hover:bg-blue-900/30 rounded-lg transition-all" title="Resend verification email"><span class="material-symbols-outlined text-[20px]">mail</span></button>
                                        <?php endif; ?>
                                        <?php if(!$showArchived): ?>
                                            <button type="button" onclick="openDeleteModal('<?php echo $row['id']; ?>')" class="action-icon p-2.5 text-orange-500 hover:bg-orange-100/50 dark:hover:bg-orange-900/30 rounded-lg transition-all" title="Archive account"><span class="material-symbols-outlined text-[20px]">archive</span></button>
                                        <?php else: ?>
                                            <button type="button" onclick="openRestoreModal('<?php echo $row['id']; ?>')" class="action-icon p-2.5 text-blue-500 hover:bg-blue-100/50 dark:hover:bg-blue-900/30 rounded-lg transition-all" title="Restore account"><span class="material-symbols-outlined text-[20px]">unarchive</span></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="px-6 py-12 text-center text-slate-400 font-black text-[10px]">No patient records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'edit' || $view === 'add'): ?>
            <div class="bg-white dark:bg-slate-800 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-lg overflow-hidden">
                <div class="p-8 border-b border-slate-200 dark:border-slate-800 bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 space-y-2">
                    <div class="flex items-center gap-4 text-slate-900 dark:text-white font-black">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-primary to-blue-600 text-white flex items-center justify-center shadow-lg shadow-blue-500/30">
                            <span class="material-symbols-outlined text-4xl"><?php echo ($view=='add') ? 'person_add' : 'person'; ?></span>
                        </div>
                        <div>
                            <h2 class="text-2xl font-black tracking-tight uppercase"><?php echo ($view=='add') ? 'New patient registration' : 'Clinical profile'; ?></h2>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 font-black tracking-widest mt-2">Unified medical database</p>
                        </div>
                    </div>
                </div>

                <form method="POST" id="patientForm" class="p-8 space-y-8 text-slate-900 dark:text-white font-black">
                    <?php if ($view === 'edit'): ?>
                        <input type="hidden" name="target_id" value="<?php echo $targetID; ?>">
                    <?php endif; ?>

                    <!-- PROFILE PICTURE SECTION -->
                    <div class="space-y-5">
                        <div class="flex items-center gap-3 pb-4 border-b-2 border-slate-200 dark:border-slate-700">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-purple-400/20 to-purple-400/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-purple-500 text-lg">photo_camera</span>
                            </div>
                            <h3 class="text-sm font-black uppercase tracking-widest text-slate-700 dark:text-slate-300">Profile Picture</h3>
                        </div>
                        <div class="flex flex-col items-center gap-6">
                            <div id="profilePictureContainer" class="w-32 h-32 rounded-full border-4 border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden shadow-lg">
                                <!-- Image element - always present for AJAX updates -->
                                <img id="profilePicturePreview" style="display: none; width: 100%; height: 100%; object-fit: cover;" alt="Profile Picture">
                                <!-- Icon fallback - shown by default -->
                                <span id="profilePictureIcon" class="material-symbols-outlined text-5xl text-slate-400">account_circle</span>
                            </div>
                            <!-- Initial setup script to load existing picture -->
                            <script>
                                (function() {
                                    const pic = <?php echo !empty($profilePicture) ? "'" . htmlspecialchars($profilePicture) . "'" : "null"; ?>;
                                    if (pic) {
                                        const img = document.getElementById('profilePicturePreview');
                                        const icon = document.getElementById('profilePictureIcon');
                                        img.src = pic + '?t=' + Date.now();
                                        img.onload = function() {
                                            img.style.display = 'block';
                                            if (icon) icon.style.display = 'none';
                                        };
                                        img.onerror = function() {
                                            img.style.display = 'none';
                                            icon.style.display = 'block';
                                        };
                                    }
                                })();
                            </script>
                            <div class="w-full max-w-sm">
                                <label class="block">
                                    <input type="file" id="profilePictureInput" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">
                                    <span class="w-full h-12 rounded-lg border-2 border-dashed border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 font-bold px-4 flex items-center justify-center cursor-pointer transition-colors text-slate-600 dark:text-slate-400 gap-2">
                                        <span class="material-symbols-outlined">upload</span>
                                        <span id="uploadButtonText">Choose Image</span>
                                    </span>
                                </label>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2 text-center">JPG, PNG, GIF, WebP (Max 5MB)</p>
                                <div id="uploadStatus" class="mt-3"></div>
                                <?php if (!empty($profilePicture)): ?>
                                    <button type="button" id="removePictureBtn" class="w-full mt-3 px-4 py-2 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 font-bold border-2 border-red-300 dark:border-red-600 text-xs tracking-tight uppercase hover:bg-red-200 dark:hover:bg-red-900/50 transition-all flex items-center justify-center gap-2 shadow-sm">
                                        <span class="material-symbols-outlined text-base">delete</span> Remove Picture
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- PERSONAL INFORMATION SECTION -->
                    <div class="space-y-5">
                        <div class="flex items-center gap-3 pb-4 border-b-2 border-slate-200 dark:border-slate-700">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-primary/20 to-primary/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-primary text-lg">person</span>
                            </div>
                            <h3 class="text-sm font-black uppercase tracking-widest text-slate-700 dark:text-slate-300">Personal Information</h3>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">First Name</label>
                                <input name="first_name" type="text" value="<?php echo htmlspecialchars($firstName); ?>" required placeholder="Enter first name" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Last Name</label>
                                <input name="last_name" type="text" value="<?php echo htmlspecialchars($lastName); ?>" required placeholder="Enter last name" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Date of Birth</label>
                                <input name="dob" type="date" value="<?php echo $birthdate; ?>" max="<?php echo date('Y-m-d'); ?>" required class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Phone Number</label>
                                <input name="phone" id="phone-field" value="<?php echo htmlspecialchars($phone); ?>" maxlength="11" pattern="09[0-9]{9}" required placeholder="09123456789" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Gender</label>
                                <select name="gender" required class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                                    <option value="" disabled <?php if($gender=='') echo 'selected'; ?>>Select gender</option>
                                    <option value="female" <?php if($gender=='female') echo 'selected'; ?>>Female</option>
                                    <option value="male" <?php if($gender=='male') echo 'selected'; ?>>Male</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Address</label>
                                <input name="address" type="text" value="<?php echo htmlspecialchars($address); ?>" required placeholder="Enter street address" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Occupation</label>
                                <input name="occupation" type="text" value="<?php echo htmlspecialchars($occupation); ?>" placeholder="Enter occupation" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Marital Status</label>
                                <select name="marital_status" required class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                                    <option value="single" <?php if($maritalStatus=='single') echo 'selected'; ?>>Single</option>
                                    <option value="married" <?php if($maritalStatus=='married') echo 'selected'; ?>>Married</option>
                                    <option value="divorced" <?php if($maritalStatus=='divorced') echo 'selected'; ?>>Divorced</option>
                                    <option value="widowed" <?php if($maritalStatus=='widowed') echo 'selected'; ?>>Widowed</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ACCOUNT DETAILS SECTION -->
                    <div class="space-y-5">
                        <div class="flex items-center gap-3 pb-4 border-b-2 border-slate-200 dark:border-slate-700">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500/20 to-blue-500/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-lg">lock</span>
                            </div>
                            <h3 class="text-sm font-black uppercase tracking-widest text-slate-700 dark:text-slate-300">Account Details</h3>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Email Address</label>
                                <input name="email" type="email" value="<?php echo htmlspecialchars($email); ?>" required placeholder="Enter email address" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Username</label>
                                <input name="username" type="text" value="<?php echo htmlspecialchars($username); ?>" required placeholder="Enter username" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                            </div>
                        </div>
                    </div>

                    <!-- CLINICAL INFORMATION SECTION -->
                    <div class="space-y-5">
                        <div class="flex items-center gap-3 pb-4 border-b-2 border-slate-200 dark:border-slate-700">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-green-500/20 to-green-500/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-lg">medical_information</span>
                            </div>
                            <h3 class="text-sm font-black uppercase tracking-widest text-slate-700 dark:text-slate-300">Clinical Information</h3>
                        </div>
                        <div>
                            <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Chief Complaint</label>
                            <textarea name="chief_complaint" placeholder="Describe the patient's chief complaint or reason for visit" rows="5" class="w-full rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 py-3 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight resize-none"><?php echo htmlspecialchars($complaint); ?></textarea>
                        </div>
                    </div>

                    <?php if($view === 'add'): ?>
                    <!-- SECURITY SECTION -->
                    <div class="space-y-5">
                        <div class="flex items-center gap-3 pb-4 border-b-2 border-slate-200 dark:border-slate-700">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-orange-500/20 to-orange-500/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-orange-600 dark:text-orange-400 text-lg">security</span>
                            </div>
                            <h3 class="text-sm font-black uppercase tracking-widest text-slate-700 dark:text-slate-300">Security</h3>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-start">
                            <div>
                                <label class="text-xs font-black uppercase tracking-widest text-slate-600 dark:text-slate-400 block mb-3">Create Password</label>
                                <div class="relative">
                                    <input id="password-field" name="password" type="password" required placeholder="Create a strong password" onkeyup="checkPasswordStrength(this.value)" class="w-full h-12 rounded-lg border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 font-bold px-4 pr-12 shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all tracking-tight">
                                    <button type="button" onclick="togglePassword()" class="absolute right-4 top-1/2 -translate-y-1/2">
                                        <span id="eye-icon" class="material-symbols-outlined text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">visibility_off</span>
                                    </button>
                                </div>
                            </div>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-200 dark:border-slate-700">
                                <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Security Strength</p>
                                <div class="space-y-2">
                                    <div id="req-length" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all">
                                        <span class="material-symbols-outlined text-[14px]">check_circle</span> <span>8+ Characters</span>
                                    </div>
                                    <div id="req-upper" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all">
                                        <span class="material-symbols-outlined text-[14px]">check_circle</span> <span>Uppercase letter</span>
                                    </div>
                                    <div id="req-lower" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all">
                                        <span class="material-symbols-outlined text-[14px]">check_circle</span> <span>Lowercase letter</span>
                                    </div>
                                    <div id="req-number" class="requirement-item flex items-center gap-2 text-xs font-bold transition-all">
                                        <span class="material-symbols-outlined text-[14px]">check_circle</span> <span>Numbers included</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ACTION BUTTONS -->
                    <div class="flex flex-col sm:flex-row items-center justify-end gap-3 pt-6 border-t-2 border-slate-200 dark:border-slate-700">
                        <?php if ($view === 'add'): ?>
                            <button type="button" onclick="openDiscardModal()" class="w-full sm:w-auto px-8 py-3 rounded-lg border-2 border-slate-200 dark:border-slate-700 font-black text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all active:scale-95">Cancel</button>
                            <button type="submit" name="create_patient" class="w-full sm:w-auto px-8 py-3 rounded-lg bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 active:scale-95 transition-all flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined">person_add</span> Register Patient
                            </button>
                        <?php else: ?>
                            <button type="button" onclick="openBackModal()" class="w-full sm:w-auto px-8 py-3 rounded-lg border-2 border-slate-200 dark:border-slate-700 font-black text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all active:scale-95">Cancel</button>
                            <button type="submit" name="save_changes" class="w-full sm:w-auto px-8 py-3 rounded-lg bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 active:scale-95 transition-all flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined">save</span> Save Changes
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</main>

<div id="removePictureModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-all opacity-0 pointer-events-none font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full mx-4 sm:mx-0 max-w-sm transform scale-95 transition-all duration-300 font-black border border-slate-100 dark:border-slate-700" id="removePictureModalContent">
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

<div id="deleteModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-all opacity-0 pointer-events-none font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full mx-4 sm:mx-0 max-w-sm transform scale-95 transition-all duration-300 font-black border border-slate-100 dark:border-slate-700 max-h-[90vh] overflow-y-auto" id="deleteModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-orange-100 to-orange-50 dark:from-orange-900/30 dark:to-orange-900/20 mb-6 ring-2 ring-orange-200 dark:ring-orange-900/50">
                <span class="material-symbols-outlined text-3xl text-orange-600 font-black">archive</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Archive account?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-6 px-4 font-bold tracking-wider">This patient will be moved to archives.</p>
            <form method="POST" class="flex gap-3 justify-center">
                <input type="hidden" name="delete_id" id="modalDeleteId">
                <input type="hidden" name="delete_patient" value="1">
                <button type="button" onclick="closeDeleteModal()" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 text-white font-black shadow-lg shadow-orange-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Yes, archive</button>
            </form>
        </div>
    </div>
</div>

<div id="restoreModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-all opacity-0 pointer-events-none font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full mx-4 sm:mx-0 max-w-sm transform scale-95 transition-all duration-300 font-black border border-slate-100 dark:border-slate-700 max-h-[90vh] overflow-y-auto" id="restoreModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-green-100 to-green-50 dark:from-green-900/30 dark:to-green-900/20 mb-6 ring-2 ring-green-200 dark:ring-green-900/50">
                <span class="material-symbols-outlined text-3xl text-green-600 font-black">unarchive</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Restore account?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-6 px-4 font-bold tracking-wider">This patient will be restored from archives.</p>
            <form method="POST" class="flex gap-3 justify-center">
                <input type="hidden" name="restore_id" id="modalRestoreId">
                <input type="hidden" name="restore_patient" value="1">
                <button type="button" onclick="closeRestoreModal()" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-green-500 to-green-600 text-white font-black shadow-lg shadow-green-500/30 text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Yes, restore</button>
            </form>
        </div>
    </div>
</div>

<div id="backModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-all opacity-0 pointer-events-none font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full mx-4 sm:mx-0 max-w-sm transform scale-95 transition-all duration-300 font-black border border-slate-100 dark:border-slate-700 max-h-[90vh] overflow-y-auto" id="backModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-900/20 mb-6 ring-2 ring-blue-200 dark:ring-blue-900/50">
                <span class="material-symbols-outlined text-3xl text-blue-600 font-black">arrow_back</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Are you sure?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-6 px-4 font-bold tracking-wider">Any unsaved progress will be lost.</p>
            <div class="flex gap-3 justify-center">
                <button onclick="closeBackModal()" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Stay</button>
                <a id="backLink" href="<?php echo $dashboardLink; ?>" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 flex items-center justify-center text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Go Back</a>
            </div>
        </div>
    </div>
</div>

<div id="discardModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-all opacity-0 pointer-events-none font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full mx-4 sm:mx-0 max-w-sm transform scale-95 transition-all duration-300 font-black border border-slate-100 dark:border-slate-700 max-h-[90vh] overflow-y-auto" id="discardModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-orange-100 to-orange-50 dark:from-orange-900/30 dark:to-orange-900/20 mb-6 ring-2 ring-orange-200 dark:ring-orange-900/50">
                <span class="material-symbols-outlined text-3xl text-orange-600 font-black">warning</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Discard entry?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-6 px-4 font-bold tracking-wider">Any unsaved changes will be lost.</p>
            <div class="flex gap-3 justify-center">
                <button onclick="closeDiscardModal()" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Stay</button>
                <a href="<?php echo $dashboardLink; ?>" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 text-white font-black shadow-lg shadow-orange-500/30 flex items-center justify-center text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Discard</a>
            </div>
        </div>
    </div>
</div>

<script>
    // Show temporary success toast on profile update
    const showSuccessToast = <?php echo json_encode($showSuccessToast); ?>;
    if (showSuccessToast) {
        showToast('Profile updated successfully!', 'success');
    }

    // Toast notification function
    function showToast(message, type = 'success') {
        const toastContainer = document.createElement('div');
        toastContainer.id = 'successToast';
        toastContainer.className = 'fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 z-50 animate-fade-in';
        
        const bgColor = type === 'success' ? 'bg-green-100' : 'bg-red-100';
        const textColor = type === 'success' ? 'text-green-700' : 'text-red-700';
        const borderColor = type === 'success' ? 'border-green-500' : 'border-red-500';
        const icon = type === 'success' ? 'check_circle' : 'error';
        
        toastContainer.innerHTML = `
            <div class="p-4 ${bgColor} ${textColor} rounded-lg font-bold flex items-center gap-2 border-4 ${borderColor} shadow-lg" style="font-size: 16px;">
                <span class="material-symbols-outlined">${icon}</span>
                ${message}
            </div>
        `;
        
        document.body.appendChild(toastContainer);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            toastContainer.style.opacity = '0';
            toastContainer.style.transition = 'opacity 0.5s ease';
            setTimeout(() => {
                toastContainer.remove();
            }, 500);
        }, 5000);
    }
    
    function togglePassword() {
        const field = document.getElementById('password-field');
        const icon = document.getElementById('eye-icon');
        const isPass = field.type === 'password';
        field.type = isPass ? 'text' : 'password';
        icon.textContent = isPass ? 'visibility' : 'visibility_off';
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
            if (reqs[key]) el.classList.add('valid');
            else el.classList.remove('valid');
        });
    }

    const phoneField = document.getElementById('phone-field');
    if(phoneField) {
        phoneField.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }

    function openDeleteModal(id) { document.getElementById('modalDeleteId').value = id; showModal('deleteModal', 'deleteModalContent'); }
    function closeDeleteModal() { hideModal('deleteModal', 'deleteModalContent'); }
    function openRestoreModal(id) { document.getElementById('modalRestoreId').value = id; showModal('restoreModal', 'restoreModalContent'); }
    function closeRestoreModal() { hideModal('restoreModal', 'restoreModalContent'); }
    function openBackModal() { showModal('backModal', 'backModalContent'); }
    function closeBackModal() { hideModal('backModal', 'backModalContent'); }
    function openDiscardModal() { showModal('discardModal', 'discardModalContent'); }
    function closeDiscardModal() { hideModal('discardModal', 'discardModalContent'); }

    function showModal(mId, cId) {
        const m = document.getElementById(mId);
        const c = document.getElementById(cId);
        m.classList.remove('hidden');
        m.classList.add('flex');
        m.classList.remove('pointer-events-none');
        setTimeout(() => {
            m.classList.remove('opacity-0');
            c.classList.remove('scale-95');
            c.classList.add('scale-100');
        }, 10);
        
        m.addEventListener('click', function backdropClick(e) {
            if (e.target === m) {
                hideModal(mId, cId);
                m.removeEventListener('click', backdropClick);
            }
        });
    }
    function hideModal(mId, cId) {
        const m = document.getElementById(mId);
        const c = document.getElementById(cId);
        m.classList.add('opacity-0');
        c.classList.remove('scale-100');
        c.classList.add('scale-95');
        setTimeout(() => {
            m.classList.add('hidden');
            m.classList.remove('flex');
            m.classList.add('pointer-events-none');
        }, 300);
    }

    function toggleAdvancedSearch() {
        const panel = document.getElementById('advancedSearchPanel');
        panel.classList.toggle('hidden');
        panel.classList.toggle('animate-fade-in');
    }

    function resendVerificationEmail(email) {
        if (confirm('Resend verification email to ' + email + '?')) {
            fetch('backend/resend_verification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'email=' + encodeURIComponent(email)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Verification email sent successfully!');
                    location.reload();
                } else {
                    alert('Failed to send verification email: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Error sending verification email');
            });
        }
    }

    // Form change tracking and profile picture handling
    const patientForm = document.getElementById('patientForm');
    const profilePictureInput = document.getElementById('profilePictureInput');
    let formChanged = false;
    let pendingProfilePicture = null;
    
    // Track form changes
    if (patientForm) {
        patientForm.addEventListener('change', () => { formChanged = true; });
        patientForm.addEventListener('input', () => { formChanged = true; });
    }
    
    // Profile Picture Upload Handler - Preview Only
    if (profilePictureInput) {
        profilePictureInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const uploadStatus = document.getElementById('uploadStatus');
            const uploadButtonText = document.getElementById('uploadButtonText');
            
            // Validate file size
            const maxSize = 5 * 1024 * 1024; // 5MB
            if (file.size > maxSize) {
                uploadStatus.innerHTML = '<div class="p-3 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-200 rounded-lg text-xs font-bold">File size exceeds 5MB limit.</div>';
                profilePictureInput.value = '';
                return;
            }

            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                uploadStatus.innerHTML = '<div class="p-3 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-200 rounded-lg text-xs font-bold">Invalid file type. Use JPG, PNG, GIF, or WebP.</div>';
                profilePictureInput.value = '';
                return;
            }

            // Preview image locally without uploading yet
            const preview = document.getElementById('profilePicturePreview');
            const icon = document.getElementById('profilePictureIcon');
            const reader = new FileReader();
            
            reader.onload = function(event) {
                pendingProfilePicture = file; // Store file for later upload on save
                preview.src = event.target.result;
                preview.style.display = 'block';
                if (icon) icon.style.display = 'none';
                uploadStatus.innerHTML = '<div class="p-3 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-200 rounded-lg text-xs font-bold">Image preview loaded. Click Save to apply changes.</div>';
                formChanged = true; // Mark form as changed
            };
            
            reader.readAsDataURL(file);
        });
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
            
            const targetUserID = <?php echo isset($_GET['id']) ? intval($_GET['id']) : 'null'; ?> || <?php echo $currentUserID; ?>;
            
            try {
                const response = await fetch('backend/delete-profile-picture.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'user_id=' + targetUserID
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
    
    // Remove Picture Modal Functions
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
            modal.classList.add('hidden', 'pointer-events-none');
            modal.classList.remove('flex');
        }, 300);
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
    if (patientForm) {
        patientForm.addEventListener('submit', async function(e) {
            if (pendingProfilePicture) {
                e.preventDefault();
                
                const uploadStatus = document.getElementById('uploadStatus');
                const uploadButtonText = document.getElementById('uploadButtonText');
                uploadButtonText.textContent = 'Saving...';
                uploadStatus.innerHTML = '';

                const formData = new FormData();
                formData.append('profile_picture', pendingProfilePicture);
                formData.append('user_id', <?php echo isset($targetID) ? $targetID : '$_SESSION["user_id"]'; ?>);

                try {
                    const response = await fetch('backend/upload-profile-picture.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        uploadStatus.innerHTML = '<div class="p-3 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-200 rounded-lg text-xs font-bold flex items-center gap-2"><span class="material-symbols-outlined text-sm">check_circle</span> Profile picture saved!</div>';
                        pendingProfilePicture = null;
                        uploadButtonText.textContent = 'Choose Image';
                        // Now submit the main form
                        patientForm.removeEventListener('submit', arguments.callee);
                        patientForm.submit();
                    } else {
                        uploadStatus.innerHTML = '<div class="p-3 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-200 rounded-lg text-xs font-bold">' + (data.message || 'Upload failed') + '</div>';
                        uploadButtonText.textContent = 'Choose Image';
                    }
                } catch (error) {
                    console.error('Upload error:', error);
                    uploadStatus.innerHTML = '<div class="p-3 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-200 rounded-lg text-xs font-bold">Error uploading file. Try again.</div>';
                    uploadButtonText.textContent = 'Choose Image';
                }
            }
        });
    }
    


</script>
</body>
</html>
