<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['type'])) exit;

$uid = $_SESSION['user_id'];
$type = $_GET['type'];

if ($type === 'appt') {
    @mysqli_query($conn, "UPDATE appointments SET is_seen = 1 WHERE patient_id = '$uid' AND status_id IN (1, 2)");
} elseif ($type === 'billing') {
    @mysqli_query($conn, "UPDATE appointments SET is_seen = 1 WHERE patient_id = '$uid' AND status_id = 3");
} elseif ($type === 'comp') {
    @mysqli_query($conn, "UPDATE patient_complaints SET is_seen = 1 WHERE patient_id = '$uid' AND status_id = 6");
} elseif ($type === 'inq') {
    @mysqli_query($conn, "UPDATE patient_inquiries SET is_seen = 1 WHERE patient_id = '$uid' AND status_id = 6");
} elseif ($type === 'treatment') {
    $checkCol = @mysqli_query($conn, "SHOW COLUMNS FROM `treatment_records` LIKE 'is_seen'");
    if ($checkCol && mysqli_num_rows($checkCol) > 0) {
        @mysqli_query($conn, "UPDATE treatment_records SET is_seen = 1 WHERE is_seen = 0");
    }
}
?>