<?php
session_start();
require_once 'config.php'; // Ensure your database connection is included

// 1. Check if the user is authorized
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['assistant', 'patient'])) {
    die("Unauthorized access.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 2. Collect and sanitize form data
    $patient_id  = $_POST['patient_id'];
    $amount      = $_POST['amount'];
    $method      = $_POST['method'];
    $reference   = $_POST['invoice_ref'];
    $date        = $_POST['date'];
    $recorded_by = $_SESSION['full_name'];

    // 3. Database Insertion
    $sql = "INSERT INTO payments (patient_id, amount, method, reference_no, payment_date, recorded_by) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("idssss", $patient_id, $amount, $method, $reference, $date, $recorded_by);
        
        if ($stmt->execute()) {
            // Success: Redirect back with a success status
            header("Location: ../record-payment.php?status=success&id=" . $patient_id);
        } else {
            // Database error
            header("Location: ../record-payment.php?status=error&msg=db_fail");
        }
        $stmt->close();
    }
    $conn->close();
} else {
    header("Location: ../record-payment.php");
}
?>