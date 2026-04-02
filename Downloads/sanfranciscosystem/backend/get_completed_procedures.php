<?php
// API endpoint to fetch completed procedures for a patient
session_start();
require_once 'config.php';

// Check authorization
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['dentist', 'assistant'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$patientID = mysqli_real_escape_string($conn, $_GET['patient_id'] ?? 0);

if (!$patientID) {
    echo json_encode([]);
    exit;
}

// Fetch completed appointments with procedures
$sql = "SELECT a.*, pr.procedure_name, a.appointment_date as completion_date
        FROM appointments a 
        LEFT JOIN procedures pr ON a.procedure_id = pr.id
        LEFT JOIN lookup_statuses ls ON a.status_id = ls.id
        WHERE a.patient_id = '$patientID' 
        AND ls.status_name = 'Completed'
        ORDER BY a.appointment_date DESC";

$result = mysqli_query($conn, $sql);
$procedures = [];

while ($row = mysqli_fetch_assoc($result)) {
    $procedures[] = [
        'procedure_name' => htmlspecialchars($row['procedure_name'] ?? 'N/A'),
        'completion_date' => date('M d, Y', strtotime($row['completion_date']))
    ];
}

header('Content-Type: application/json');
echo json_encode($procedures);
?>
