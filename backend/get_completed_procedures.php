<?php
session_start();
require_once 'config.php';
require_once 'middleware.php';

// Verify user is authorized
checkAccess(['dentist', 'assistant'], true);

$patientID = (int)($_GET['patient_id'] ?? 0);

if (!$patientID) {
    echo json_encode([]);
    exit;
}

// SECURITY: Verify patient belongs to user's tenant
if (!canAccessPatient($patientID)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access to patient data']);
    exit;
}

// Fetch completed appointments with procedures (with tenant filter)
$sql = "SELECT a.*, pr.procedure_name, a.appointment_date as completion_date    
        FROM appointments a
        LEFT JOIN procedures pr ON a.procedure_id = pr.id
        LEFT JOIN lookup_statuses ls ON a.status_id = ls.id
        WHERE a.patient_id = $patientID
        AND ls.status_name IN ('Completed', 'Complete')
        AND a.tenant_id = " . getTenantId() . "
        ORDER BY a.appointment_date DESC";

$result = mysqli_query($conn, $sql);
$procedures = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $procedures[] = [
            'procedure_name' => htmlspecialchars($row['procedure_name'] ?? 'N/A'),  
            'completion_date' => date('M d, Y', strtotime($row['completion_date']))
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($procedures);
?>
