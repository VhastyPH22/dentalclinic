<?php
/**
 * Check if email exists in the system
 * Used for real-time validation during registration
 */
header('Content-Type: application/json');
require_once 'config.php';

$response = ['exists' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Use prepared statement for security
        $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $response['exists'] = true;
            }
            
            $stmt->close();
        }
    }
}

echo json_encode($response);
?>
