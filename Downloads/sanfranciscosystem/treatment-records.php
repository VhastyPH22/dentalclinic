<?php 
// 1. Initialize Security
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone for accurate date/time operations
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/backend/config.php'; 

// --- 0. AUTO-MIGRATION: ENSURE INDIVIDUAL TRACKING LINK ---
$checkCol = mysqli_query($conn, "SHOW COLUMNS FROM `treatment_records` LIKE 'appointment_id'");
if ($checkCol && mysqli_num_rows($checkCol) == 0) {
    mysqli_query($conn, "ALTER TABLE `treatment_records` ADD `appointment_id` INT(11) NULL AFTER `id`, ADD INDEX (`appointment_id`) ");
}

// Load Middleware
$middlewarePath = file_exists(__DIR__ . '/backend/middleware.php') ? __DIR__ . '/backend/middleware.php' : __DIR__ . '/middleware.php';
if (file_exists($middlewarePath)) require_once $middlewarePath;

// --- ACCESS CONTROL ---
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['dentist', 'assistant', 'patient'])) {
    header("Location: login.php");
    exit();
}

// 2. Data Identification
$role = $_SESSION['role'] ?? 'patient';
$fullName = $_SESSION['full_name'] ?? 'User';
$currentDentistID = $_SESSION['user_id'] ?? 0;
$msg = "";
$msgType = "";

// --- DETERMINE VIEW MODE & TARGET ---
if ($role === 'dentist' || $role === 'assistant') {
    $targetPatientID = $_GET['id'] ?? 0;
} else {
    $targetPatientID = $_SESSION['user_id'] ?? 0;
}

// Fetch Patient Name
$targetPatientName = "All Patients";
if ($targetPatientID > 0) {
    $pNameQuery = mysqli_query($conn, "SELECT first_name, last_name FROM users WHERE id = '$targetPatientID'");
    $pNameData = mysqli_fetch_assoc($pNameQuery);
    $targetPatientName = $pNameData ? $pNameData['first_name'] . ' ' . $pNameData['last_name'] : "Unknown Patient";
}

// Determine Dashboard Link
$dashboardLink = 'patient-dashboard.php';
if ($role === 'dentist') $dashboardLink = 'dentist-dashboard.php';
elseif ($role === 'assistant') $dashboardLink = 'assistant-dashboard.php';

// Check for success notifications from URL
if (isset($_GET['notif'])) {
    if ($_GET['notif'] === 'added') { $msg = "Clinical record saved successfully!"; $msgType = "success"; }
    elseif ($_GET['notif'] === 'updated') { $msg = "Clinical record updated successfully!"; $msgType = "success"; }
    elseif ($_GET['notif'] === 'deleted') { $msg = "Clinical record removed successfully!"; $msgType = "success"; }
}

  // Count unseen treatment records for notification (only if column exists on hosting)
  $notifTreatmentCount = 0;
  if ($role !== 'patient') {
      // Check if is_seen column exists on hosting
      $checkSeenCol = @mysqli_query($conn, "SHOW COLUMNS FROM `treatment_records` LIKE 'is_seen'");
      if ($checkSeenCol && mysqli_num_rows($checkSeenCol) > 0) {
          $treatmentNotifQ = @mysqli_query($conn, "SELECT COUNT(*) as total FROM treatment_records WHERE is_seen = 0");
          if ($treatmentNotifQ) {
              $treatmentNotifData = mysqli_fetch_assoc($treatmentNotifQ);
              $notifTreatmentCount = $treatmentNotifData['total'] ?? 0;
          }
      }
  }

// Count paid appointments awaiting treatment records
$paidAppointmentCount = 0;
if ($role !== 'patient') {
    $paidApptQ = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments a 
                                        LEFT JOIN lookup_statuses s ON a.status_id = s.id 
                                        LEFT JOIN treatment_records tr ON a.id = tr.appointment_id
                                        WHERE s.status_name = 'Paid' AND tr.appointment_id IS NULL");
    $paidApptData = mysqli_fetch_assoc($paidApptQ);
    $paidAppointmentCount = $paidApptData['total'] ?? 0;
}

// --- 3. HANDLE ACTIONS (Staff Only) ---

// A. SAVE or UPDATE TREATMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_treatment'])) {
    if ($role === 'patient') {
        $msg = "Unauthorized action."; $msgType = "error";
    } else {
        $formPatientID = $_POST['patient_id'] ?? $targetPatientID;
        $procID = mysqli_real_escape_string($conn, $_POST['procedure_id'] ?? '');
        $date = mysqli_real_escape_string($conn, $_POST['date'] ?? '');
        $costRaw = $_POST['cost'] ?? '0';
        $cost = filter_var($costRaw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        if(empty($cost)) $cost = 0;
        
        $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
        $recordID = $_POST['record_id'] ?? ''; 
        $appointmentID = mysqli_real_escape_string($conn, $_POST['appointment_id'] ?? '');
        
        if(empty($procID) || empty($date) || empty($formPatientID)) {
            $msg = "Patient, a valid procedure, and date are required."; $msgType = "error";
        } else {
            if (!empty($recordID)) {
                $sql = "UPDATE treatment_records SET appointment_id='$appointmentID', procedure_id='$procID', treatment_date='$date', actual_cost='$cost', notes='$notes' WHERE id='$recordID'";
                $notif = "updated";
            } else {
                $sql = "INSERT INTO treatment_records (appointment_id, patient_id, procedure_id, dentist_id, treatment_date, actual_cost, notes) 
                        VALUES ('$appointmentID', '$formPatientID', '$procID', '$currentDentistID', '$date', '$cost', '$notes')";
                $notif = "added";

                // --- KADEBU AUTOMATED RETURN VISIT SCHEDULER (UPDATED BY TABLE) ---
                // Fetch the current appointment's patient_name to preserve patient profile name
                $currentApptQuery = mysqli_query($conn, "SELECT patient_name FROM appointments WHERE id = '$appointmentID' LIMIT 1");
                $currentApptData = mysqli_fetch_assoc($currentApptQuery);
                $patientProfileName = $currentApptData['patient_name'] ?? '';
                
                $procLookup = mysqli_query($conn, "SELECT procedure_name FROM procedures WHERE id = '$procID'");
                $procRow = mysqli_fetch_assoc($procLookup);
                $procStr = $procRow['procedure_name'] ?? '';
                
                $interval = null;
                $procLower = strtolower($procStr);
                
                // Consultation: No Return Required
                if (strpos($procLower, 'consultation') !== false) $interval = null;
                // Prophylaxis & Prevention
                elseif (strpos($procLower, 'heavy') !== false && strpos($procLower, 'prophylaxis') !== false) $interval = '+3 months';
                elseif (strpos($procLower, 'prophylaxis') !== false) $interval = '+6 months';
                elseif (strpos($procLower, 'fluoride') !== false) $interval = '+6 months';
                elseif (strpos($procLower, 'pits and fissures') !== false) $interval = '+6 months';
                // Restorative
                elseif (strpos($procLower, 'light-cured') !== false) $interval = '+1 week';
                elseif (strpos($procLower, 'temporary filling') !== false) $interval = '+1 week';
                elseif (strpos($procLower, 'direct composite') !== false) $interval = '+1 week';
                elseif (strpos($procLower, 'indirect composite') !== false) $interval = '+1 week';
                // Surgery
                elseif (strpos($procLower, 'simple extraction') !== false) $interval = '+7 days';
                elseif (strpos($procLower, 'odontectomy') !== false) $interval = '+7 days';
                elseif (strpos($procLower, 'frenectomy') !== false) $interval = '+7 days';
                elseif (strpos($procLower, 'gingivectomy') !== false || strpos($procLower, 'soft tissue') !== false) $interval = '+7 days';
                elseif (strpos($procLower, 'alveolectomy') !== false || strpos($procLower, 'exostosis') !== false) $interval = '+7 days';
                elseif (strpos($procLower, 'implant') !== false) $interval = '+7 days';
                // Endodontics & Crowns
                elseif (strpos($procLower, 'root canal') !== false) $interval = '+1 week';
                elseif (strpos($procLower, 'crown') !== false) $interval = '+1 week';
                elseif (strpos($procLower, 're-cementation') !== false) $interval = '+1 week';
                // Prosthodontics (Dentures)
                elseif (strpos($procLower, 'denture') !== false) $interval = '+2 days';
                elseif (strpos($procLower, 'rpd') !== false) $interval = '+1 week';
                // Radiology
                elseif (strpos($procLower, 'radiology') !== false || strpos($procLower, 'x-ray') !== false) $interval = null;
                // Orthodontics
                elseif (strpos($procLower, 'self-ligating') !== false) $interval = '+6 weeks';
                elseif (strpos($procLower, 'braces') !== false) $interval = '+4 weeks';
                elseif (strpos($procLower, 'aligners') !== false) $interval = '+4 weeks';
                elseif (strpos($procLower, 're-bonding') !== false || strpos($procLower, 'bracket replacement') !== false) $interval = '+4 weeks';
                elseif (strpos($procLower, 'tads') !== false || strpos($procLower, 'ortho exposure') !== false) $interval = '+7 days';
                // Periodontics
                elseif (strpos($procLower, 'deep scaling') !== false) $interval = '+1 week';
                elseif (strpos($procLower, 'perio surgery') !== false) $interval = '+7 days';
                elseif (strpos($procLower, 'perio probing') !== false) $interval = '+3 months';
                // TMJ
                elseif (strpos($procLower, 'tmj') !== false || strpos($procLower, 'splint') !== false) $interval = '+1 week';

                if ($interval) {
                    $returnDate = date('Y-m-d', strtotime($date . ' ' . $interval));

                    // Fetch "Confirmed" Status ID for automatic return visit (status_id = 2 is typically Confirmed)
                    $confirmedStatusQ = mysqli_query($conn, "SELECT id FROM lookup_statuses WHERE status_name = 'Confirmed' LIMIT 1");
                    $confirmedStatusRow = mysqli_fetch_assoc($confirmedStatusQ);
                    $confirmedStatusID = $confirmedStatusRow['id'] ?? 2;

                    // Fetch Patient Name for the new record - use patient profile name from current appointment
                    if (!empty($patientProfileName)) {
                        $patName = $patientProfileName;
                    } else {
                        $patLookup = mysqli_query($conn, "SELECT first_name, last_name FROM users WHERE id = '$formPatientID'");
                        $patRow = mysqli_fetch_assoc($patLookup);
                        $patName = ($patRow['first_name'] ?? '') . ' ' . ($patRow['last_name'] ?? '');
                    }

                    mysqli_query($conn, "INSERT INTO appointments (patient_id, patient_name, appointment_date, appointment_time, procedure_id, status_id)
                                        VALUES ('$formPatientID', '$patName', '$returnDate', '09:00:00', '$procID', '$confirmedStatusID')");
                }
            }
            
            if (mysqli_query($conn, $sql)) {
                $redirectURL = 'treatment-records.php?notif=' . $notif;
                if ($targetPatientID > 0) $redirectURL .= "&id=$targetPatientID";
                echo "<script>window.location.href='$redirectURL';</script>"; exit;
            } else {
                $msg = "Database Error: " . mysqli_error($conn); $msgType = "error";
            }
        }
    }
}

// B. DELETE TREATMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_treatment'])) {
    if ($role === 'patient') {
        $msg = "Unauthorized action."; $msgType = "error";
    } else {
        $delID = mysqli_real_escape_string($conn, $_POST['record_id'] ?? '');
        if (mysqli_query($conn, "DELETE FROM treatment_records WHERE id='$delID'")) {
            $redirectURL = 'treatment-records.php?notif=deleted';
            if ($targetPatientID > 0) $redirectURL .= "&id=$targetPatientID";
            echo "<script>window.location.href='$redirectURL';</script>"; exit;
        } else {
            $msg = "Error deleting record: " . mysqli_error($conn); $msgType = "error";
        }
    }
}

// --- PREPARE EDIT DATA ---
$editMode = false;
$editData = ['patient_id' => $targetPatientID, 'procedure_id'=>'', 'treatment_date'=>date('Y-m-d'), 'cost'=>'', 'notes'=>''];
if (isset($_GET['edit']) && ($role === 'dentist' || $role === 'assistant')) {
    $editID = mysqli_real_escape_string($conn, $_GET['edit']);
    $editQuery = mysqli_query($conn, "SELECT t.*, t.actual_cost as cost FROM treatment_records t WHERE t.id = '$editID'");
    if ($fetched = mysqli_fetch_assoc($editQuery)) {
        $editMode = true; $editData = $fetched;
    }
}

// FETCH PATIENT LIST (Individual tracking: Paid only and NO treatment record yet)
$patientsList = [];
if ($role !== 'patient') {
    $paidQ = mysqli_query($conn, "SELECT a.id as appointment_id, a.patient_id, a.procedure_id, a.appointment_date, a.patient_name, pr.procedure_name, u.first_name, u.last_name,
                                       IFNULL(a.patient_name, CONCAT(u.first_name, ' ', u.last_name)) as display_name
                                       FROM appointments a 
                                       LEFT JOIN lookup_statuses s ON a.status_id = s.id 
                                       LEFT JOIN procedures pr ON a.procedure_id = pr.id
                                       LEFT JOIN users u ON a.patient_id = u.id
                                       LEFT JOIN treatment_records tr ON a.id = tr.appointment_id
                                       WHERE s.status_name = 'Paid'
                                       AND tr.appointment_id IS NULL
                                       ORDER BY a.appointment_date DESC");
    while($row = mysqli_fetch_assoc($paidQ)) $patientsList[] = $row;
}

// FETCH PROCEDURES FOR DROPDOWN
$allProcedures = [];
$procResult = mysqli_query($conn, "SELECT id, category, procedure_name, standard_cost FROM procedures ORDER BY category, procedure_name");
while($row = mysqli_fetch_assoc($procResult)) {
    $allProcedures[$row['category']][] = $row;
}

// FETCH HISTORY WITH ADVANCED FILTERS
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$costFrom = $_GET['cost_from'] ?? '';
$costTo = $_GET['cost_to'] ?? '';

$historySql = "SELECT t.*, u_pat.first_name, u_pat.last_name, pr.procedure_name, 
               CONCAT(u_dent.first_name, ' ', u_dent.last_name) as dentist_display_name,
               t.actual_cost as cost,
               IFNULL(a.patient_name, CONCAT(u_pat.first_name, ' ', u_pat.last_name)) as display_patient_name
               FROM treatment_records t 
               LEFT JOIN users u_pat ON t.patient_id = u_pat.id 
               LEFT JOIN users u_dent ON t.dentist_id = u_dent.id
               LEFT JOIN procedures pr ON t.procedure_id = pr.id
               LEFT JOIN appointments a ON t.appointment_id = a.id
               WHERE 1=1";
if ($targetPatientID > 0) $historySql .= " AND t.patient_id = '$targetPatientID'";
if (!empty($search)) {
    $safeSearch = mysqli_real_escape_string($conn, $search);
    $historySql .= " AND (pr.procedure_name LIKE '%$safeSearch%' OR t.notes LIKE '%$safeSearch%' OR IFNULL(a.patient_name, CONCAT(u_pat.first_name, ' ', u_pat.last_name)) LIKE '%$safeSearch%')";
}
if (!empty($dateFrom)) {
    $safeDateFrom = mysqli_real_escape_string($conn, $dateFrom);
    $historySql .= " AND t.treatment_date >= '$safeDateFrom'";
}
if (!empty($dateTo)) {
    $safeDateTo = mysqli_real_escape_string($conn, $dateTo);
    $historySql .= " AND t.treatment_date <= '$safeDateTo'";
}
if (!empty($costFrom)) {
    $safeCostFrom = floatval($costFrom);
    $historySql .= " AND t.actual_cost >= $safeCostFrom";
}
if (!empty($costTo)) {
    $safeCostTo = floatval($costTo);
    $historySql .= " AND t.actual_cost <= $safeCostTo";
}
$historySql .= " ORDER BY t.treatment_date DESC, t.id DESC";
$historyResult = mysqli_query($conn, $historySql);
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>

    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no, maximum-scale=5.0, minimum-scale=1.0"/>
    <meta name="theme-color" content="#1e3a5f"/>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Treatment Records">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="San Nicolas Dental Clinic">
    <meta name="format-detection" content="telephone=no">
    <meta name="format-detection" content="email=no">

    <title>Treatment Records - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" }, fontFamily: { "display": ["Manrope", "sans-serif"] } } }
        }
    </script>
    <style>
        * { scroll-behavior: smooth; }
        html { scroll-behavior: smooth; }
        
        .animate-fade-in { 
            animation: fadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; 
        } 
        
        @keyframes fadeIn { 
            from { 
                opacity: 0; 
                transform: translateY(15px); 
            } 
            to { 
                opacity: 1; 
                transform: translateY(0); 
            } 
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        body {
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        a, button {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        header {
            animation: slideInDown 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        input, select, textarea {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        input:focus, select:focus, textarea:focus {
            transform: translateY(-1px);
        }
        
        .rounded-3xl, .rounded-2xl, .rounded-lg {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .shadow-2xl, .shadow-sm, .shadow-md {
            transition: box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .bg-white, .dark\:bg-slate-800, .dark\:bg-slate-900 {
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        [class*="hover:"] {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        table tbody tr {
            transition: all 0.2s ease;
        }
        
        table tbody tr:hover {
            transform: translateX(2px);
        }
        
        .border {
            transition: border-color 0.3s ease;
        }
    </style>
</head>
<body class="font-display bg-background-light dark:bg-background-dark text-slate-900 dark:text-white antialiased text-sm transition-colors duration-200">
<main class="min-h-screen flex flex-col">

    <header class="sticky top-0 z-30 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-b-2 border-slate-200 dark:border-slate-800 px-6 py-4">
        <div class="max-w-6xl mx-auto flex justify-between items-center text-slate-900 dark:text-white font-black uppercase tracking-tight">
            <div>
                <h1 class="text-2xl font-black">Treatment Records</h1>
             
                <p class="text-slate-500 text-[10px] font-black tracking-tight">San Nicolas Dental Clinic</p>
            </div>
            <div class="flex items-center gap-4">
                <button onclick="openBackModal()" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors text-sm font-bold shadow-sm font-black">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span> Dashboard
                </button>
            </div>
        </div>
    </header>

    <div class="p-6 max-w-6xl mx-auto w-full flex-1 animate-fade-in">
        <?php if($msg): ?>
            <div class="mb-6 p-4 rounded-xl border font-bold flex items-center gap-3 <?php echo ($msgType == 'success') ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'; ?>">
                <span class="material-symbols-outlined"><?php echo ($msgType == 'success') ? 'check_circle' : 'error'; ?></span>
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <header class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8 font-black">
            <div>
                <h2 class="text-slate-900 dark:text-white text-3xl font-black tracking-tight">Treatment history</h2>
                <?php if($targetPatientID > 0): ?>
                    <p class="text-slate-500 text-xs mt-1">Patient: <span class="text-primary font-bold tracking-tight"><?php echo htmlspecialchars($targetPatientName); ?></span></p>
                <?php else: ?>
                    <p class="text-slate-500 text-xs mt-1 tracking-tight">Viewing all clinical records</p>
                <?php endif; ?>
            </div>
        </header>

        <?php if($role !== 'patient'): ?>
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden mb-10 transition-all border-l-4 <?php echo $editMode ? 'border-l-orange-500' : 'border-l-primary'; ?> shadow-md">
            <div class="px-8 py-5 border-b border-slate-200 dark:border-slate-800 bg-slate-50/50 flex justify-between items-center text-slate-900 dark:text-white">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined <?php echo $editMode ? 'text-orange-500' : 'text-primary'; ?> font-black"><?php echo $editMode ? 'edit' : 'add_circle'; ?></span>
                    <h3 class="text-lg font-black tracking-tight"><?php echo $editMode ? 'Edit record' : 'New clinical entry'; ?></h3>
                    <?php if($editMode): ?>
                    <span class="ml-3 px-3 py-1 rounded-full bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300 text-xs font-black border border-orange-300 dark:border-orange-700/50">Editing</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-8">
                <form method="POST" class="flex flex-col gap-6 text-slate-900 dark:text-white font-black">
                    <input type="hidden" name="save_treatment" value="1">
                    <input type="hidden" name="appointment_id" id="appointmentIdInput">
                    <input type="hidden" name="patient_profile_name" id="patientProfileNameInput">
                    <?php if($editMode): ?><input type="hidden" name="record_id" value="<?php echo $editData['id']; ?>"><?php endif; ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">Patient account <span class="text-red-500">*</span></span>
                            <select id="patientSelect" name="patient_id" required class="w-full h-12 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold focus:ring-primary px-4 text-slate-900 dark:text-white shadow-inner tracking-tight">
                                <option value="">-- Select Patient --</option>
                                <?php foreach($patientsList as $p): ?>
                                    <option value="<?php echo $p['patient_id']; ?>"
                                            data-appointment-id="<?php echo htmlspecialchars($p['appointment_id']); ?>"
                                            data-procedure-id="<?php echo htmlspecialchars($p['procedure_id'] ?? ''); ?>"
                                            data-date="<?php echo htmlspecialchars($p['appointment_date'] ?? ''); ?>"
                                            data-patient-name="<?php echo htmlspecialchars($p['display_name']); ?>"
                                            <?php echo ($editData['patient_id'] == $p['patient_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['display_name']); ?> - <?php echo htmlspecialchars($p['procedure_name'] ?? 'No procedure'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">Procedure selection <span class="text-red-500">*</span></span>
                            <select id="procedureSelect" name="procedure_id" required class="w-full h-12 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold focus:ring-primary px-4 text-slate-900 dark:text-white shadow-inner tracking-tight" onchange="updatePrice(this)">
                                <option value="" data-price="">-- Select Procedure --</option>
                                <?php foreach($allProcedures as $category => $procs): ?>
                                    <optgroup label="<?php echo htmlspecialchars($category); ?>">
                                        <?php foreach($procs as $pr): ?>
                                            <option value="<?php echo $pr['id']; ?>" data-price="<?php echo $pr['standard_cost']; ?>" <?php echo ($editData['procedure_id'] == $pr['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($pr['procedure_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">Treatment date <span class="text-red-500">*</span></span>
                            <input name="date" value="<?php echo htmlspecialchars($editData['treatment_date'] ?? date('Y-m-d')); ?>" class="w-full h-12 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold px-4 text-slate-900 dark:text-white shadow-inner tracking-tight" required type="date"/>
                        </label>
                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">Cost (PHP)</span>
                            <div class="relative">
                                <span class="absolute left-4 top-3 text-slate-400 font-black">₱</span>
                                <input name="cost" id="costInput" value="<?php echo htmlspecialchars($editData['cost'] ?? ''); ?>" class="w-full h-12 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold px-4 pl-8 text-slate-900 dark:text-white shadow-inner tracking-tight" placeholder="0.00" type="number" step="0.01"/>
                            </div>
                        </label>
                    </div>
                    <label class="flex flex-col gap-2">
                        <span class="text-[10px] font-black text-slate-500 tracking-tight">Clinical notes</span>
                        <textarea name="notes" class="w-full rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 p-4 min-h-[80px] font-bold text-slate-900 dark:text-white shadow-inner tracking-tight" placeholder="Describe the treatment..."><?php echo htmlspecialchars($editData['notes'] ?? ''); ?></textarea>
                    </label>
                    <div class="flex justify-end pt-2">
                        <button class="text-white font-black h-14 px-10 rounded-xl shadow-xl transition-all flex items-center gap-2 text-xs tracking-tight <?php echo $editMode ? 'bg-orange-500 hover:bg-orange-600' : 'bg-primary hover:bg-blue-600'; ?>" type="submit">
                            <span class="material-symbols-outlined text-[18px] font-black"><?php echo $editMode ? 'update' : 'save'; ?></span> 
                            Save clinical record
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden mb-10 text-slate-900 dark:text-white shadow-md">
            <div class="px-8 py-5 border-b border-slate-200 dark:border-slate-800 bg-slate-50/50 flex flex-col sm:flex-row justify-between items-center gap-4 font-black">
                <h3 class="text-lg font-black flex items-center gap-2 tracking-tight"><span class="material-symbols-outlined text-primary font-black">history</span> Clinical history</h3>
            </div>
            
            <div class="px-8 py-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-800">
                <button type="button" onclick="toggleAdvancedSearch()" class="flex items-center gap-2 mb-4 text-sm font-bold text-primary hover:text-blue-600 transition-colors">
                    <span class="material-symbols-outlined text-[18px]" id="filterToggleIcon">expand_more</span>
                    <span id="filterToggleText">Show Advanced Filters</span>
                </button>
                
                <form method="GET" id="advancedSearchForm" class="hidden space-y-4">
                    <?php if($targetPatientID > 0): ?><input type="hidden" name="id" value="<?php echo $targetPatientID; ?>"><?php endif; ?>
                    
                    <!-- Basic Search -->
                    <div class="flex flex-col gap-2">
                        <label class="text-[10px] font-black text-slate-500 tracking-tight">Search by procedure, notes, or patient</label>
                        <div class="relative">
                            <input type="text" id="searchInput" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search history..." class="w-full pl-10 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-bold h-10 dark:bg-slate-700 text-slate-900 dark:text-white focus:ring-primary shadow-sm tracking-tight">
                            <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-sm font-black">search</span>
                        </div>
                    </div>

                    <!-- Advanced Filters Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Date Range -->
                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">From Date</span>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="w-full h-10 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold px-3 text-slate-900 dark:text-white shadow-inner tracking-tight">
                        </label>

                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">To Date</span>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="w-full h-10 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold px-3 text-slate-900 dark:text-white shadow-inner tracking-tight">
                        </label>

                        <!-- Cost Range -->
                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">Min Cost (₱)</span>
                            <input type="number" name="cost_from" value="<?php echo htmlspecialchars($costFrom); ?>" placeholder="0.00" step="0.01" class="w-full h-10 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold px-3 text-slate-900 dark:text-white shadow-inner tracking-tight">
                        </label>

                        <label class="flex flex-col gap-2">
                            <span class="text-[10px] font-black text-slate-500 tracking-tight">Max Cost (₱)</span>
                            <input type="number" name="cost_to" value="<?php echo htmlspecialchars($costTo); ?>" placeholder="0.00" step="0.01" class="w-full h-10 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold px-3 text-slate-900 dark:text-white shadow-inner tracking-tight">
                        </label>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-3 justify-end pt-2">
                        <button type="reset" class="px-6 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-bold text-sm hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">Reset</button>
                        <button type="submit" class="px-6 py-2 rounded-lg bg-primary hover:bg-blue-600 text-white font-bold text-sm shadow-md flex items-center gap-2 transition-colors">
                            <span class="material-symbols-outlined text-[16px]">search</span> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-900/50 text-[10px] font-black tracking-tight text-slate-400 border-b dark:border-slate-700">
                            <th class="p-6">Date</th>
                            <th class="p-6">Patient</th> 
                            <th class="p-6">Procedure / notes</th>
                            <th class="p-6">Dentist</th>
                            <th class="p-6 text-right">Cost</th>
                            <th class="p-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-bold">
                        <?php if(mysqli_num_rows($historyResult) > 0): ?>
                            <?php while($row = mysqli_fetch_assoc($historyResult)): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="p-6 text-sm whitespace-nowrap text-slate-900 dark:text-white tracking-tight"><?php echo date('M d, Y', strtotime($row['treatment_date'])); ?></td>
                                <td class="p-6 text-sm text-slate-800 dark:text-white font-black tracking-tight"><?php echo htmlspecialchars($row['display_patient_name'] ?? (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></td>
                                <td class="p-6">
                                    <div class="flex flex-col gap-1">
                                        <span class="font-black text-slate-900 dark:text-white text-xs tracking-tight"><?php echo htmlspecialchars($row['procedure_name'] ?? 'General Procedure'); ?></span>
                                        <?php if(!empty($row['notes'])): ?><p class="text-xs text-slate-500 font-bold italic tracking-tight">"<?php echo htmlspecialchars($row['notes']); ?>"</p><?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-6 text-xs text-slate-400 tracking-tight"><?php echo htmlspecialchars($row['dentist_display_name'] ?? 'Staff'); ?></td>
                                <td class="p-6 text-right font-black text-green-600 tracking-tight">₱<?php echo number_format($row['cost'] ?? 0, 2); ?></td>
                                <td class="p-6 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" onclick="openViewModal('<?php echo htmlspecialchars(json_encode($row)); ?>')" class="p-2 text-slate-400 hover:text-primary transition-colors"><span class="material-symbols-outlined text-[20px] font-black">visibility</span></button>
                                        <button type="button" onclick="printRecord('<?php echo htmlspecialchars(json_encode($row)); ?>')" class="p-2 text-slate-400 hover:text-blue-600 transition-colors"><span class="material-symbols-outlined text-[20px] font-black">print</span></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="p-12 text-center font-bold text-slate-400 italic text-[10px] tracking-tight">No medical history found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="viewModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-xl transform scale-95 transition-all duration-300 border-2 border-slate-100 dark:border-slate-700" id="viewContent">
        <div class="text-slate-900 dark:text-white font-black">
            <h3 class="text-2xl font-black mb-6 uppercase tracking-tight">Treatment Record Details</h3>
            <div class="space-y-4 mb-8">
                <div class="flex justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
                    <span class="text-slate-500 font-bold">Date:</span>
                    <span id="viewDate" class="font-black"></span>
                </div>
                <div class="flex justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
                    <span class="text-slate-500 font-bold">Patient:</span>
                    <span id="viewPatient" class="font-black"></span>
                </div>
                <div class="flex justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
                    <span class="text-slate-500 font-bold">Procedure:</span>
                    <span id="viewProcedure" class="font-black"></span>
                </div>
                <div class="flex justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
                    <span class="text-slate-500 font-bold">Dentist:</span>
                    <span id="viewDentist" class="font-black"></span>
                </div>
                <div class="flex justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
                    <span class="text-slate-500 font-bold">Cost:</span>
                    <span id="viewCost" class="font-black text-green-600"></span>
                </div>
                <div class="flex flex-col border-b border-slate-200 dark:border-slate-700 pb-3">
                    <span class="text-slate-500 font-bold mb-2">Notes:</span>
                    <span id="viewNotes" class="font-bold italic text-slate-600 dark:text-slate-400"></span>
                </div>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeM('viewModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Close</button>
                <button type="button" onclick="printCurrentRecord()" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 text-sm uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Print</button>
            </div>
        </div>
    </div>
</div>

<div id="deleteModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 border-2 border-slate-100 dark:border-slate-700" id="deleteContent">
        <div class="text-center font-black">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-red-100 to-red-50 dark:from-red-900/30 dark:to-red-900/20 mb-6 ring-2 ring-red-200 dark:ring-red-900/50"><span class="material-symbols-outlined text-3xl text-red-600 font-black">delete_forever</span></div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight\">Delete record?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-8 font-bold px-4 tracking-wider\">This clinical record will be removed from history logs.</p>
            <form method="POST" class="flex gap-3 justify-center">
                <input type="hidden" name="record_id" id="modalRecordId">
                <input type="hidden" name="delete_treatment" value="1">
                <button type="button" onclick="closeM('deleteModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all\">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white font-black shadow-lg shadow-red-500/30 text-sm uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95\">Delete</button>
            </form>
        </div>
    </div>
</div>

<div id="backModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl font-black border border-slate-100 dark:border-slate-700" id="backModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-900/20 mb-6 ring-2 ring-blue-200 dark:ring-blue-900/50">
                <span class="material-symbols-outlined text-3xl text-blue-600 font-black">arrow_back</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mb-3 uppercase tracking-tight">Are you sure?</h3>
            <p class="text-[11px] text-slate-600 dark:text-slate-400 mb-8 px-4 font-bold tracking-wider">Any unsaved progress will be lost.</p>
            <div class="flex gap-3 justify-center">
                <button onclick="closeM('backModal')" class="flex-1 py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 font-bold text-slate-700 dark:text-slate-300 text-sm tracking-tight uppercase hover:bg-slate-100 dark:hover:bg-slate-900 transition-all">Stay</button>
                <a id="backLink" href="<?php echo $dashboardLink; ?>" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black shadow-lg shadow-blue-500/30 flex items-center justify-center text-sm tracking-tight uppercase hover:shadow-xl hover:scale-105 transition-all active:scale-95">Go Back</a>
            </div>
        </div>
    </div>
</div>

<script>
    // Toggle Advanced Search
    function toggleAdvancedSearch() {
        const form = document.getElementById('advancedSearchForm');
        const icon = document.getElementById('filterToggleIcon');
        const text = document.getElementById('filterToggleText');
        
        if (form.classList.contains('hidden')) {
            form.classList.remove('hidden');
            icon.textContent = 'expand_less';
            text.textContent = 'Hide Advanced Filters';
        } else {
            form.classList.add('hidden');
            icon.textContent = 'expand_more';
            text.textContent = 'Show Advanced Filters';
        }
    }

    // Show advanced search only if user types in search field or if filters are applied
    window.addEventListener('load', function() {
        const searchInput = document.getElementById('searchInput');
        const advancedForm = document.getElementById('advancedSearchForm');
        const hasFilters = '<?php echo $dateFrom || $dateTo || $costFrom || $costTo ? "true" : "false"; ?>' === 'true';
        const hasSearchText = '<?php echo !empty($search) ? "true" : "false"; ?>' === 'true';
        
        // Auto-expand if filters are applied or search text exists
        if (hasFilters || hasSearchText) {
            advancedForm.classList.remove('hidden');
            document.getElementById('filterToggleIcon').textContent = 'expand_less';
            document.getElementById('filterToggleText').textContent = 'Hide Advanced Filters';
        }
        
        // Listen for user typing in search field
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                if (this.value.trim().length > 0) {
                    advancedForm.classList.remove('hidden');
                    document.getElementById('filterToggleIcon').textContent = 'expand_less';
                    document.getElementById('filterToggleText').textContent = 'Hide Advanced Filters';
                }
            });
        }
    });

    // Mark treatment records as seen on page load
    if (<?php echo ($role !== 'patient') ? 'true' : 'false'; ?>) {
        fetch('backend/mark_seen.php?type=treatment')
        .then(() => {
            // Notification cleared
        });
    }

    document.getElementById('patientSelect').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const appointmentId = selectedOption.getAttribute('data-appointment-id') || "";
        const appointmentProcedureID = selectedOption.getAttribute('data-procedure-id') || "";
        const patientProfileName = selectedOption.getAttribute('data-patient-name') || "";
        const procedureSelect = document.getElementById('procedureSelect');
        const appointmentIdInput = document.getElementById('appointmentIdInput');
        const patientProfileNameInput = document.getElementById('patientProfileNameInput');
        
        // Update hidden fields for exact appointment linking and patient profile name
        appointmentIdInput.value = appointmentId;
        patientProfileNameInput.value = patientProfileName;

        if (appointmentProcedureID !== "") {
            procedureSelect.value = appointmentProcedureID;
            updatePrice(procedureSelect);
        } else {
            // No procedure associated with this appointment: reset selection
            procedureSelect.selectedIndex = 0;
            updatePrice(procedureSelect);
        }
    });

    function updatePrice(select) {
        const costInput = document.getElementById('costInput');
        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value !== "") {
            costInput.value = selectedOption.getAttribute('data-price') || "";
        } else {
            costInput.value = ""; 
        }
    }

    function showM(mId, cId) {
        const m = document.getElementById(mId), c = document.getElementById(cId);
        if(!m || !c) return;
        m.classList.remove('hidden'); m.classList.add('flex');
        setTimeout(() => { m.classList.remove('opacity-0'); m.classList.add('opacity-100'); c.classList.remove('scale-95'); c.classList.add('scale-100'); }, 10);
    }
    function closeM(id) { 
        const el = document.getElementById(id);
        if(!el) return;
        el.classList.remove('opacity-100');
        el.classList.add('opacity-0'); 
        setTimeout(() => el.classList.add('hidden'), 300); 
    }
    function openBackModal() { showM('backModal', 'backModalContent'); }
    function openDeleteModal(id) { document.getElementById('modalRecordId').value = id; showM('deleteModal', 'deleteContent'); }

    let currentRecord = null;

    function openViewModal(jsonData) {
        try {
            currentRecord = JSON.parse(jsonData);
            document.getElementById('viewDate').textContent = new Date(currentRecord.treatment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
            document.getElementById('viewPatient').textContent = currentRecord.display_patient_name || ((currentRecord.first_name || '') + ' ' + (currentRecord.last_name || ''));
            document.getElementById('viewProcedure').textContent = currentRecord.procedure_name || 'General Procedure';
            document.getElementById('viewDentist').textContent = currentRecord.dentist_display_name || 'Staff';
            document.getElementById('viewCost').textContent = '₱' + parseFloat(currentRecord.cost || 0).toFixed(2);
            document.getElementById('viewNotes').textContent = currentRecord.notes || 'No notes provided';
            showM('viewModal', 'viewContent');
        } catch(e) {
            console.error('Error parsing record:', e);
        }
    }

    function printCurrentRecord() {
        if(!currentRecord) return;
        const printContent = `
            <html>
            <head>
                <title>Treatment Record - Print</title>
                <style>
                    * { margin: 0; padding: 0; }
                    body { font-family: Arial, sans-serif; padding: clamp(20px, 5vw, 40px); background: #fff; }
                    .container { width: 100%; max-width: 800px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
                    @media (max-width: 767px) {
                        .container { padding: 0 0.75rem; max-width: 100%; }
                        body { padding: clamp(15px, 4vw, 30px); }
                    }
                    @media (max-width: 479px) {
                        .container { padding: 0 0.5rem; }
                        body { padding: 15px; }
                    }
                    .header { text-align: center; margin-bottom: clamp(20px, 5vw, 40px); border-bottom: 3px solid #000; padding-bottom: 20px; }
                    .title { font-size: clamp(22px, 6vw, 28px); font-weight: bold; margin-bottom: 5px; }
                    .clinic { font-size: clamp(12px, 2vw, 13px); color: #666; font-weight: 600; }
                    .content { margin: clamp(20px, 4vw, 30px) 0; }
                    .row { display: flex; flex-wrap: wrap; justify-content: space-between; margin: clamp(10px, 2vw, 15px) 0; padding: clamp(8px, 2vw, 12px) 0; border-bottom: 1px solid #ddd; gap: 1rem; }
                    .label { font-weight: bold; width: auto; color: #333; flex: 0 1 auto; min-width: 0; }
                    @media (min-width: 768px) {
                        .label { width: 180px; flex: 0 0 180px; }
                    }
                    .value { flex: 1; text-align: right; font-weight: 500; }
                    .notes { margin-top: clamp(20px, 4vw, 30px); padding: clamp(15px, 3vw, 20px); background: #f9f9f9; border-left: 4px solid #137fec; border-radius: 3px; }
                    .notes strong { display: block; margin-bottom: 10px; color: #333; }
                    .footer { margin-top: clamp(30px, 5vw, 50px); text-align: center; font-size: clamp(10px, 2vw, 11px); color: #999; border-top: 1px solid #ddd; padding-top: 20px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <div class="title">Treatment Record</div>
                        <div class="clinic">San Nicolas Dental Clinic</div>
                    </div>
                    <div class="content">
                        <div class="row">
                            <div class="label">Date:</div>
                            <div class="value">${new Date(currentRecord.treatment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: '2-digit' })}</div>
                        </div>
                        <div class="row">
                            <div class="label">Patient:</div>
                             <div class="value">${currentRecord.display_patient_name || ((currentRecord.first_name || '') + ' ' + (currentRecord.last_name || ''))}</div>
                        </div>
                        <div class="row">
                            <div class="label">Procedure:</div>
                            <div class="value">${currentRecord.procedure_name || 'General Procedure'}</div>
                        </div>
                        <div class="row">
                            <div class="label">Dentist:</div>
                            <div class="value">${currentRecord.dentist_display_name || 'Staff'}</div>
                        </div>
                        <div class="row">
                            <div class="label">Cost:</div>
                            <div class="value">₱${parseFloat(currentRecord.cost || 0).toFixed(2)}</div>
                        </div>
                    </div>
                    ${currentRecord.notes ? `<div class="notes"><strong>Clinical Notes:</strong>${currentRecord.notes}</div>` : ''}
                    <div class="footer">
                        <p>This document was generated on ${new Date().toLocaleString()}</p>
                    </div>
                </div>
            </body>
            </html>
        `;
        const w = 1000, h = 800;
        const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX;
        const dualScreenTop = window.screenTop !== undefined ? window.screenTop : window.screenY;
        const width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
        const height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;
        const systemZoom = 1;
        const left = (width - w) / 2 + dualScreenLeft;
        const top = (height - h) / 2 + dualScreenTop;
        const printWindow = window.open('', '', `toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=${w},height=${h},top=${top},left=${left}`);
        if (printWindow) {
            printWindow.document.write(printContent);
            printWindow.document.close();
            setTimeout(() => { printWindow.focus(); printWindow.print(); }, 500);
        }
    }

    function printRecord(jsonData) {
        try {
            const record = JSON.parse(jsonData);
            const printContent = `
                <html>
                <head>
                    <title>Treatment Record - Print</title>
                    <style>
                        * { margin: 0; padding: 0; }
                        body { font-family: Arial, sans-serif; padding: clamp(20px, 5vw, 40px); background: #fff; }
                        .container { width: 100%; max-width: 800px; margin: 0 auto; padding: 0 1rem; box-sizing: border-box; }
                        @media (max-width: 767px) {
                            .container { padding: 0 0.75rem; max-width: 100%; }
                            body { padding: clamp(15px, 4vw, 30px); }
                        }
                        @media (max-width: 479px) {
                            .container { padding: 0 0.5rem; }
                            body { padding: 15px; }
                        }
                        .header { text-align: center; margin-bottom: clamp(20px, 5vw, 40px); border-bottom: 3px solid #000; padding-bottom: 20px; }
                        .title { font-size: clamp(22px, 6vw, 28px); font-weight: bold; margin-bottom: 5px; }
                        .clinic { font-size: clamp(12px, 2vw, 13px); color: #666; font-weight: 600; }
                        .content { margin: clamp(20px, 4vw, 30px) 0; }
                        .row { display: flex; flex-wrap: wrap; justify-content: space-between; margin: clamp(10px, 2vw, 15px) 0; padding: clamp(8px, 2vw, 12px) 0; border-bottom: 1px solid #ddd; gap: 1rem; }
                        .label { font-weight: bold; width: auto; color: #333; flex: 0 1 auto; min-width: 0; }
                        @media (min-width: 768px) {
                            .label { width: 180px; flex: 0 0 180px; }
                        }
                        .value { flex: 1; text-align: right; font-weight: 500; }
                        .notes { margin-top: clamp(20px, 4vw, 30px); padding: clamp(15px, 3vw, 20px); background: #f9f9f9; border-left: 4px solid #137fec; border-radius: 3px; }
                        .notes strong { display: block; margin-bottom: 10px; color: #333; }
                        .footer { margin-top: clamp(30px, 5vw, 50px); text-align: center; font-size: clamp(10px, 2vw, 11px); color: #999; border-top: 1px solid #ddd; padding-top: 20px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <div class="title">Treatment Record</div>
                            <div class="clinic">San Nicolas Dental Clinic</div>
                        </div>
                        <div class="content">
                            <div class="row">
                                <div class="label">Date:</div>
                                <div class="value">${new Date(record.treatment_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: '2-digit' })}</div>
                            </div>
                            <div class="row">
                                <div class="label">Patient:</div>
                                 <div class="value">${record.display_patient_name || ((record.first_name || '') + ' ' + (record.last_name || ''))}</div>
                            </div>
                            <div class="row">
                                <div class="label">Procedure:</div>
                                <div class="value">${record.procedure_name || 'General Procedure'}</div>
                            </div>
                            <div class="row">
                                <div class="label">Dentist:</div>
                                <div class="value">${record.dentist_display_name || 'Staff'}</div>
                            </div>
                            <div class="row">
                                <div class="label">Cost:</div>
                                <div class="value">₱${parseFloat(record.cost || 0).toFixed(2)}</div>
                            </div>
                        </div>
                        ${record.notes ? `<div class="notes"><strong>Clinical Notes:</strong>${record.notes}</div>` : ''}
                        <div class="footer">
                            <p>This document was generated on ${new Date().toLocaleString()}</p>
                        </div>
                    </div>
                </body>
                </html>
                           `;
            const w = 1000, h = 800;
            const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX;
            const dualScreenTop = window.screenTop !== undefined ? window.screenTop : window.screenY;
            const width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
            const height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;
            const systemZoom = 1;
            const left = (width - w) / 2 + dualScreenLeft;
            const top = (height - h) / 2 + dualScreenTop;
            const printWindow = window.open('', '', `toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=${w},height=${h},top=${top},left=${left}`);
            if (printWindow) {
                printWindow.document.write(printContent);
                printWindow.document.close();
                setTimeout(() => { printWindow.focus(); printWindow.print(); }, 500);
            }
        } catch(e) {
            console.error('Error printing record:', e);
        }
    }
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            if (sidebar.classList.contains('hidden-mobile')) {
                sidebar.classList.remove('hidden-mobile');
                sidebar.classList.add('visible-mobile');
                overlay.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else {
                sidebar.classList.add('hidden-mobile');
                sidebar.classList.remove('visible-mobile');
                overlay.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        }
    }
</script>
</body>
</html>
