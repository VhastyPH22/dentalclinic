<?php
session_start();
require_once 'config.php';
require_once 'middleware.php';

// Verify user is authorized and check tenant access
checkAccess(['assistant', 'patient'], true);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect and sanitize form data
    $patient_id  = (int)$_POST['patient_id'];
    $amount      = (float)$_POST['amount'];
    $method      = mysqli_real_escape_string($conn, $_POST['method']);
    $reference   = mysqli_real_escape_string($conn, $_POST['invoice_ref']);
    $date        = mysqli_real_escape_string($conn, $_POST['date']);
    $recorded_by = mysqli_real_escape_string($conn, $_SESSION['full_name']);
    
    // SECURITY: Verify patient belongs to current tenant
    if (!canAccessPatient($patient_id)) {
        die("Unauthorized: Patient not found or does not belong to your clinic.");
    }

    // Database Insertion with tenant_id
    $tenant_id = getTenantId();
    $sql = "INSERT INTO payments (patient_id, amount, method, reference_no, payment_date, recorded_by, tenant_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("idsssi", $patient_id, $amount, $method, $reference, $date, $recorded_by, $tenant_id);

        if ($stmt->execute()) {
            logTenantAudit('PAYMENT_RECORDED', 'payments', $stmt->insert_id, [], ['patient_id' => $patient_id, 'amount' => $amount]);
            header("Location: ../record-payment.php?status=success&id=" . $patient_id);
        } else {
            header("Location: ../record-payment.php?status=error&msg=db_fail"); 
        }
        $stmt->close();
    }
    $conn->close();
} else {
    header("Location: ../record-payment.php");
}
?>
