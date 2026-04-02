<?php
/**
 * Check if username is already taken
 * Used for real-time validation during registration
 */
header('Content-Type: application/json');
require_once 'config.php';

$response = ['exists' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    
    if (!empty($username) && preg_match('/^[a-zA-Z0-9_]{3,}$/', $username)) {
        // Use prepared statement for security
        $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
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
