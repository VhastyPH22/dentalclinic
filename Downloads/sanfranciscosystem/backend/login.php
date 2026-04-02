<?php
session_start();
require_once "config.php"; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (isset($_POST['identity']) && isset($_POST['password'])) {
        
        $identity = trim($_POST['identity']);
        $password = trim($_POST['password']);

        // Check DB for Username or Email
        $sql = "SELECT * FROM users WHERE username='$identity' OR email='$identity'";
        $result = mysqli_query($conn, $sql);

        if (mysqli_num_rows($result) === 1) {
            $row = mysqli_fetch_assoc($result);
            
            // Verify Password
            if (password_verify($password, $row['password'])) {

                // --- ARCHIVED ACCOUNT CHECK ---
                // Prevent archived accounts from logging in
                if (isset($row['is_archived']) && $row['is_archived'] == 1) {
                    header("Location: ../login.php?error=This account has been archived and cannot be used");
                    exit();
                }

                // --- EMAIL VERIFICATION CHECK ---
                // Ensure email is verified before allowing login
                if (!isset($row['email_verified']) || $row['email_verified'] == 0) {
                    // Email not verified - redirect to verification pending page
                    header("Location: ../email-verification-pending.php?email=" . urlencode($row['email']) . "&username=" . urlencode($row['username']));
                    exit();
                }

                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];

                // --- IMPORTANT: Role Check ---
                // We convert to lowercase to avoid "Dentist" vs "dentist" issues
                $role = strtolower($row['role']); 

                if ($role == 'dentist') {
                    // Path based on your screenshot
                    header("Location: ../dentist-dashboard.php");
                
                } else if ($role == 'assistant') {
                    // Path based on your screenshot
                    header("Location: ../assistant-dashboard.php");
                
                } else if ($role == 'patient') {
                    // Path based on your screenshot
                    header("Location: ../patient-dashboard.php");
                
                } else {
                    // SAFE FALLBACK:
                    // If the role is unknown, go back to LOGIN (not index.php)
                    // and show the error so we know what happened.
                    header("Location: ../login.php?error=Unknown Role Detected: " . $row['role']);
                }
                exit();

            } else {
                header("Location: ../login.php?error=Incorrect password");
                exit();
            }
        } else {
            header("Location: ../login.php?error=User not found");
            exit();
        }
    }
} else {
    header("Location: ../login.php");
    exit();
}
?>