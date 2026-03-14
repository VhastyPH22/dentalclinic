<?php
session_start();
require_once 'config.php';
require_once 'middleware.php';

// Verify user is authorized
checkAccess(['dentist', 'assistant', 'patient'], true);

$tenantId = getTenantId();
$userId = $_SESSION['user_id'];

if (!isset($_POST['type']) || !isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

$type = mysqli_real_escape_string($conn, $_POST['type']);
$id = (int)$_POST['id'];

// Validate record type and verify tenant ownership
switch ($type) {
    case 'appointment':
        $sql = "UPDATE appointments 
                SET is_seen = 1 
                WHERE id = $id AND tenant_id = $tenantId";
        // Verify user can access this appointment
        $checkSql = "SELECT id FROM appointments WHERE id = $id AND tenant_id = $tenantId LIMIT 1";
        $checkResult = mysqli_query($conn, $checkSql);
        if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized: Appointment not found']);
            exit;
        }
        break;

    case 'treatment_record':
        $sql = "UPDATE treatment_records 
                SET is_seen = 1 
                WHERE id = $id AND tenant_id = $tenantId";
        // Verify user can access this record
        $checkSql = "SELECT id FROM treatment_records WHERE id = $id AND tenant_id = $tenantId LIMIT 1";
        $checkResult = mysqli_query($conn, $checkSql);
        if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized: Treatment record not found']);
            exit;
        }
        break;

    case 'billing':
        $sql = "UPDATE billing 
                SET is_seen = 1 
                WHERE id = $id AND tenant_id = $tenantId";
        // Verify user can access this billing record
        $checkSql = "SELECT id FROM billing WHERE id = $id AND tenant_id = $tenantId LIMIT 1";
        $checkResult = mysqli_query($conn, $checkSql);
        if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized: Billing record not found']);
            exit;
        }
        break;

    case 'complaint':
        $sql = "UPDATE complaints 
                SET is_seen = 1 
                WHERE id = $id AND tenant_id = $tenantId";
        // Verify user can access this complaint
        $checkSql = "SELECT id FROM complaints WHERE id = $id AND tenant_id = $tenantId LIMIT 1";
        $checkResult = mysqli_query($conn, $checkSql);
        if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized: Complaint not found']);
            exit;
        }
        break;

    case 'inquiry':
        $sql = "UPDATE inquiries 
                SET is_seen = 1 
                WHERE id = $id AND tenant_id = $tenantId";
        // Verify user can access this inquiry
        $checkSql = "SELECT id FROM inquiries WHERE id = $id AND tenant_id = $tenantId LIMIT 1";
        $checkResult = mysqli_query($conn, $checkSql);
        if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized: Inquiry not found']);
            exit;
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid record type']);
        exit;
}

// Execute the update
if (mysqli_query($conn, $sql)) {
    logTenantAudit('MARK_SEEN', $type, $id, [], ['is_seen' => 1]);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to mark as seen']);
}
?>
