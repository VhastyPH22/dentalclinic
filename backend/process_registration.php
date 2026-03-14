<?php
session_start();
require_once 'config.php';
require_once 'send_verification_email.php';

/**
 * 2NF LOCALHOST FIX:
 * The 'users' table stores account credentials.
 * The 'patient_profiles' table stores personal info (dob, phone).
 * This script ensures the 'dob' column is NOT inserted into 'users'.
 *
 * EMAIL VERIFICATION:
 * New accounts require email verification before gaining access.
 * Account is ONLY saved to database if verification email is sent successfully.
 */

// --- LOCALHOST AUTO-REPAIR BLOCK ---
// Ensures email verification columns exist in 'users' table
$checkUsersTable = mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'email_verification_token'");
if (mysqli_num_rows($checkUsersTable) == 0) {
    mysqli_query($conn, "ALTER TABLE `users` ADD `email_verification_token` VARCHAR(255) NULL AFTER `password`");
}

$checkVerifiedEmail = mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'email_verified'");
if (mysqli_num_rows($checkVerifiedEmail) == 0) {
    mysqli_query($conn, "ALTER TABLE `users` ADD `email_verified` TINYINT(1) DEFAULT 0 AFTER `email_verification_token`");
}

$checkVerificationDate = mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'verification_token_expiry'");
if (mysqli_num_rows($checkVerificationDate) == 0) {
    mysqli_query($conn, "ALTER TABLE `users` ADD `verification_token_expiry` DATETIME NULL AFTER `email_verified`");
}

// Ensures 'dob' exists in patient_profiles before we attempt registration.
$checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'patient_profiles'");
if (mysqli_num_rows($checkTable) > 0) {
    $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM `patient_profiles` LIKE 'dob'");
    if (mysqli_num_rows($checkCol) == 0) {
        // If 'dob' is missing from the table, add it automatically.
        mysqli_query($conn, "ALTER TABLE `patient_profiles` ADD `dob` DATE NULL AFTER `user_id`");
    }
} else {
    // If the profiles table is missing entirely, create it.
    $create = "CREATE TABLE `patient_profiles` (
        `user_id` INT(11) NOT NULL,
        `dob` DATE DEFAULT NULL,
        `phone` VARCHAR(20) DEFAULT NULL,
        `address` VARCHAR(255) DEFAULT NULL,
        `chief_complaint` TEXT DEFAULT NULL,
        PRIMARY KEY (`user_id`),
        CONSTRAINT `fk_profile_user_link` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    mysqli_query($conn, $create);
}

// Set Timezone
date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Capture Data
    $firstName = mysqli_real_escape_string($conn, trim($_POST['first_name']));
    $lastName  = mysqli_real_escape_string($conn, trim($_POST['last_name']));
    $dob       = mysqli_real_escape_string($conn, $_POST['dob']);
    $phone     = mysqli_real_escape_string($conn, $_POST['phone']);
    $email     = mysqli_real_escape_string($conn, trim($_POST['email']));
    $username  = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password  = $_POST['password'];

    // 2. Validate Email Format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
        exit();
    }

    // 3. Validate Identity Uniqueness (Email & Username) - Using Prepared Statements
    $stmt = $conn->prepare("SELECT id, email, username FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) LIMIT 1");

    if ($stmt) {
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $existing = $result->fetch_assoc();

            // Determine which field caused the duplicate
            if (strtolower($existing['email']) === strtolower($email)) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'This email is already registered']);
                exit();
            } elseif (strtolower($existing['username']) === strtolower($username)) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'This username is already taken']);
                exit();
            }
        }
        $stmt->close();
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Generate verification token for email verification
    $verificationToken = bin2hex(random_bytes(32));
    $tokenExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // 3. TRANSACTION: Ensuring 2NF Integrity (Account first, then Profile)
    mysqli_begin_transaction($conn);

    try {
        // STEP A: CREATE ACCOUNT (Insert into 'users' ONLY)
        // Note: 'dob' and 'phone' are EXCLUDED here to prevent normalization errors.
        // Includes verification token and email verification flag
        // Using prepared statements for safety and reliability
        $sqlUser = "INSERT INTO `users` (first_name, last_name, username, password, email, role, email_verification_token, email_verified, verification_token_expiry)
                    VALUES (?, ?, ?, ?, ?, 'patient', ?, 0, ?)";
        
        $userStmt = $conn->prepare($sqlUser);
        if (!$userStmt) {
            throw new Exception("Users Statement Error: " . $conn->error);
        }
        
        // Bind parameters
        $userStmt->bind_param("sssssss", $firstName, $lastName, $username, $hashedPassword, $email, $verificationToken, $tokenExpiry);
        
        if (!$userStmt->execute()) {
            throw new Exception("Users Insert Error: " . $userStmt->error);
        }

        $newUserID = $userStmt->insert_id;
        $userStmt->close();
        
        // Log the insert_id for debugging
        error_log("Registration: insert_id returned: " . $newUserID . " for email: " . $email);
        
        // FALLBACK METHOD: Query the database to find the user by email
        // This is more reliable than relying on insert_id on some hosting environments
        if (!$newUserID || $newUserID === 0) {
            error_log("Registration: insert_id was 0 or false, using fallback query for email: " . $email);
            
            $fallbackStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            if (!$fallbackStmt) {
                throw new Exception("Fallback Query Error: " . $conn->error);
            }
            
            $fallbackStmt->bind_param("s", $email);
            if (!$fallbackStmt->execute()) {
                throw new Exception("Fallback Query Execute Error: " . $fallbackStmt->error);
            }
            
            $fallbackResult = $fallbackStmt->get_result();
            
            if ($fallbackResult->num_rows > 0) {
                $userRow = $fallbackResult->fetch_assoc();
                $newUserID = (int)$userRow['id'];
                error_log("Registration: Fallback found user ID: " . $newUserID);
            } else {
                throw new Exception("User record was created but cannot be retrieved. This may indicate a database connectivity issue.");
            }
            
            $fallbackStmt->close();
        }
        
        // CRITICAL VALIDATION: Must have valid user ID before proceeding
        if (!$newUserID || $newUserID === 0 || !is_numeric($newUserID)) {
            throw new Exception("FATAL: Invalid user ID '" . var_export($newUserID, true) . "'. Cannot proceed with profile creation.");
        }
        
        error_log("Registration: Proceeding with user_id: " . $newUserID . " for email: " . $email);

        // STEP B: CREATE PROFILE (Insert into 'patient_profiles' ONLY)
        // This is where 'dob' and 'phone' belong.
        // SAFETY CHECK: Prevent insertion with invalid user_id
        if (!isset($newUserID) || $newUserID === 0 || $newUserID === null || !is_numeric($newUserID)) {
            throw new Exception("SAFETY CHECK FAILED: Cannot insert profile with invalid user_id. Got: '" . var_export($newUserID, true) . "'");
        }
        
        error_log("Registration: Creating profile for user_id: " . $newUserID);
        
        $sqlProfile = "INSERT INTO `patient_profiles` (`user_id`, `dob`, `phone`) VALUES (?, ?, ?)";
        
        $profileStmt = $conn->prepare($sqlProfile);
        if (!$profileStmt) {
            throw new Exception("Profile Statement Error: " . $conn->error);
        }
        
        // Cast user_id to integer to ensure it's a number
        $newUserID = (int)$newUserID;
        
        $profileStmt->bind_param("iss", $newUserID, $dob, $phone);
        
        if (!$profileStmt->execute()) {
            $profileError = $profileStmt->error;
            error_log("Profile insert failed for user_id " . $newUserID . ": " . $profileError);
            throw new Exception("Profile Insert Error: " . $profileError);
        }
        
        $profileStmt->close();
        
        error_log("Registration: Profile created successfully for user_id: " . $newUserID);

        // STEP C: SEND VERIFICATION EMAIL
        // CRITICAL: Email must be sent successfully before account is saved to database
        $emailSent = sendVerificationEmail($email, $username, $firstName, $verificationToken);
        if (!$emailSent) {
            // Email sending failed - rollback the entire registration, don't save to database
            throw new Exception("Failed to send verification email. Please check your email address is correct and try again.");
        }

        // 4. Commit both insertions ONLY if email was sent successfully
        mysqli_commit($conn);

        // Return JSON response for AJAX
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Registration successful. Check your email for verification.']);
        exit();

    } catch (Exception $e) {
        // Rollback transaction if anything fails
        mysqli_rollback($conn);
        
        $errorMessage = $e->getMessage();
        error_log("REGISTRATION FAILED - Email: " . $email . " | Username: " . $username . " | Error: " . $errorMessage);
        
        // Return JSON error response for AJAX
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $errorMessage]);
        exit();
    }
} else {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}
?>
