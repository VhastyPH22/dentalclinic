<?php 
session_start();
require_once 'backend/config.php';

// Set Timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Define simple treatment keywords (procedures that don't require downpayment)
$SIMPLE_TREATMENT_KEYWORDS = [
    "Consultation", "Online Consultation", "Face to Face Consultation",
    "Dental Cleaning", "Oral Prophylaxis", "Fluoride Treatment", "Pits and Fissures", "Perio Probing", 
    "Minor Restorative", "Light Cured Composite", "Temporary Filling", "Simple Radiology", 
    "Periapical X-ray", "Panoramic X-ray", "Cephalometric X-ray", 
    "Minor Orthodontic Adjustments", "Re-bonding of Bracket", "Bracket Replacement",
    "Preventive / Minor Procedures"
];

// --- 0. AUTO-MIGRATION: ENSURE STORAGE FOR INDIVIDUAL NAMES, DOWNPAYMENTS, AND BLOCKED DATES ---
$checkCol = mysqli_query($conn, "SHOW COLUMNS FROM `appointments` LIKE 'patient_name'");
if ($checkCol && mysqli_num_rows($checkCol) == 0) {
    mysqli_query($conn, "ALTER TABLE `appointments` ADD `patient_name` VARCHAR(255) NULL AFTER `patient_id` ");
}
$checkDP = mysqli_query($conn, "SHOW COLUMNS FROM `appointments` LIKE 'downpayment_ref'");
if ($checkDP && mysqli_num_rows($checkDP) == 0) {
    mysqli_query($conn, "ALTER TABLE `appointments` ADD `downpayment_ref` VARCHAR(100) NULL AFTER `status_id` ");
}
// NEW: Table for blocked dates (unavailability)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `availability_blocks` (
    `block_date` DATE PRIMARY KEY
)");

// 1. SECURITY & ACCESS
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$fullName = $_SESSION['full_name'] ?? 'User';
$username = $_SESSION['username'] ?? 'Staff';
$role = $_SESSION['role']; 
$userID = $_SESSION['user_id'] ?? 0;
$today = date('Y-m-d');
$currentTime = date('H:i');
$msg = "";
$msgType = "";

// 2. STATE CAPTURE
$m = $_GET['m'] ?? date('m');
$y = $_GET['y'] ?? date('Y');
$d = $_GET['d'] ?? date('d'); 
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$searchQueryStr = !empty($search) ? "&search=" . urlencode($search) : "";
$statusQueryStr = !empty($statusFilter) ? "&status=" . urlencode($statusFilter) : "";
$dateQueryStr = isset($_GET['d']) ? "&d=$d" : "";
$baseContext = "m=$m&y=$y$dateQueryStr$searchQueryStr$statusQueryStr";

// NEW: FETCH BLOCKED DATES
$blockedDates = [];
$getBlocks = mysqli_query($conn, "SELECT block_date FROM availability_blocks");
while($bRow = mysqli_fetch_assoc($getBlocks)) { $blockedDates[] = $bRow['block_date']; }

// Notifications
if (isset($_GET['notif'])) {
    if ($_GET['notif'] === 'added') { $msg = "Booking confirmed! Your slot is secured."; $msgType = "success"; }
    elseif ($_GET['notif'] === 'updated') { $msg = "Record updated successfully!"; $msgType = "success"; }
    elseif ($_GET['notif'] === 'status') { $msg = "Status updated successfully!"; $msgType = "success"; }
    elseif ($_GET['notif'] === 'deleted') { $msg = "Appointment record removed!"; $msgType = "success"; }
    elseif ($_GET['notif'] === 'availability') { $msg = "Clinic availability updated!"; $msgType = "success"; }
    elseif ($_GET['notif'] === 'past_date') { $msg = "Error: Cannot modify availability for past dates."; $msgType = "error"; }
}

$dashboardLink = 'patient-dashboard.php';
if ($role === 'dentist') $dashboardLink = 'dentist-dashboard.php';
elseif ($role === 'assistant') $dashboardLink = 'assistant-dashboard.php';

$isStaff = ($role === 'dentist' || $role === 'assistant');

// --- 3. VIEW LOGIC: CALENDAR STATE ---
$dateObj = DateTime::createFromFormat('!m-Y', "$m-$y");
$monthName = $dateObj->format('F');
$daysInMonth = (int)$dateObj->format('t');
$firstDayOfM = (int)$dateObj->format('w');
$formattedDate = "$y-" . str_pad($m, 2, "0", STR_PAD_LEFT) . "-" . str_pad($d, 2, "0", STR_PAD_LEFT);

// --- 4. HANDLE ACTIONS ---

// NEW: HANDLE AVAILABILITY TOGGLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_availability']) && $role === 'dentist') {
    $tDate = mysqli_real_escape_string($conn, $_POST['target_date']);
    $action = $_POST['action'];
    
    // VALIDATION: Prevent past dates
    if ($tDate < $today) {
        echo "<script>window.location.href='schedule.php?$baseContext&notif=past_date';</script>"; exit;
    }

    if ($action === 'block') {
        mysqli_query($conn, "INSERT IGNORE INTO availability_blocks (block_date) VALUES ('$tDate')");
    } else {
        mysqli_query($conn, "DELETE FROM availability_blocks WHERE block_date = '$tDate'");
    }
    echo "<script>window.location.href='schedule.php?$baseContext&notif=availability';</script>"; exit;
}

// A. DELETE / CANCEL APPOINTMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_appt'])) {
    $delID = mysqli_real_escape_string($conn, $_POST['appt_id']);
    if ($role === 'assistant') {
        $msg = "Access Denied: Assistant role is restricted to view-only."; $msgType = "error";
    } else {
        if ($role === 'patient') {
            $cancelStatQ = mysqli_query($conn, "SELECT id FROM lookup_statuses WHERE status_name = 'Cancelled' LIMIT 1");
            $cancelStatData = mysqli_fetch_assoc($cancelStatQ);
            $cancelStatID = $cancelStatData['id'] ?? 7;
            $sql = "UPDATE appointments SET status_id = '$cancelStatID' WHERE id = '$delID' AND patient_id = '$userID'";
        } else {
            $sql = "DELETE FROM appointments WHERE id = '$delID'";
        }
        if (mysqli_query($conn, $sql)) {
            echo "<script>window.location.href='schedule.php?$baseContext&notif=deleted';</script>"; exit;
        } else {
            $msg = "Error: " . mysqli_error($conn); $msgType = "error";
        }
    }
}

// B. CREATE OR UPDATE APPOINTMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_appointment'])) {
    $date = mysqli_real_escape_string($conn, trim($_POST['app_date'] ?? $formattedDate));
    
    // Safety check for blocked dates for patients
    if ($role === 'patient' && in_array($date, $blockedDates)) {
        echo "<script>alert('Error: This date is unavailable.'); window.location.href='schedule.php?$baseContext';</script>"; exit;
    }

    $time = mysqli_real_escape_string($conn, trim($_POST['app_time'] ?? '09:00 AM'));
    if(empty($time)) $time = '09:00 AM'; 

    $typedName = mysqli_real_escape_string($conn, trim($_POST['patient_profile_name'] ?? ''));
    $reasonStr = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
    $statusStr = $_POST['status'] ?? 'Confirmed'; 
    $dpRef = mysqli_real_escape_string($conn, trim($_POST['dp_ref'] ?? ''));
    
    $isUpdate = isset($_POST['is_update']) && $_POST['is_update'] == 1;
    $editID = $_POST['record_id'] ?? 0;

    if ($isUpdate) {
        $getPID = mysqli_query($conn, "SELECT patient_id FROM appointments WHERE id = '$editID' LIMIT 1");
        $assigned = mysqli_fetch_assoc($getPID);
        $targetPatientID = $assigned['patient_id'] ?? $userID;
    } else {
        $targetPatientID = ($role === 'patient') ? $userID : null;
        if ($isStaff && !empty($typedName)) {
            $nameParts = explode(' ', $typedName, 2);
            $f = $nameParts[0]; $l = $nameParts[1] ?? '';
            $findU = mysqli_query($conn, "SELECT id FROM users WHERE (first_name='$f' AND last_name='$l') OR first_name='$typedName' LIMIT 1");
            if ($uRow = mysqli_fetch_assoc($findU)) {
                $targetPatientID = $uRow['id'];
            } else {
                $tempUser = "patient_" . time();
                mysqli_query($conn, "INSERT INTO users (first_name, last_name, username, role) VALUES ('$f', '$l', '$tempUser', 'patient')");
                $targetPatientID = mysqli_insert_id($conn);
            }
        }
    }

    $procQ = mysqli_query($conn, "SELECT id FROM procedures WHERE procedure_name = '$reasonStr' LIMIT 1");
    $procData = mysqli_fetch_assoc($procQ);
    $procID = $procData['id'] ?? null;

    // AUTOMATIC APPROVAL LOGIC
    $finalStatusStr = ($role === 'patient') ? 'Confirmed' : $statusStr;
    $statQ = mysqli_query($conn, "SELECT id FROM lookup_statuses WHERE status_name = '$finalStatusStr' LIMIT 1");
    $statData = mysqli_fetch_assoc($statQ);
    $statID = $statData['id'] ?? 2; // Default to Confirmed (ID 2 usually)

    // Check if procedure is a simple treatment (no downpayment required)
    $isSimpleTreatment = false;
    foreach ($SIMPLE_TREATMENT_KEYWORDS as $keyword) {
        if (stripos($reasonStr, $keyword) !== false) {
            $isSimpleTreatment = true;
            break;
        }
    }

    if ($role === 'assistant') {
        $msg = "Access Denied: Assistant role is restricted to view-only."; $msgType = "error";
    } elseif (empty($reasonStr)) {
        $msg = "Please select a service requirement to continue."; $msgType = "error";
    } elseif ($role === 'patient' && empty($dpRef) && !$isSimpleTreatment) {
        $msg = "Payment Reference Number is required to prevent fake bookings."; $msgType = "error";
    } else {
        $finalProcID = ($procID) ? "'$procID'" : "NULL";
        if ($isUpdate && $role === 'dentist') {
            $sql = "UPDATE appointments SET patient_id='$targetPatientID', patient_name='$typedName', appointment_date='$date', appointment_time='$time', procedure_id=$finalProcID, status_id='$statID', downpayment_ref='$dpRef' WHERE id='$editID'";
        } else {
            $sql = "INSERT INTO appointments (patient_id, patient_name, appointment_date, appointment_time, procedure_id, status_id, downpayment_ref) 
                    VALUES ('$targetPatientID', '$typedName', '$date', '$time', $finalProcID, '$statID', '$dpRef')";
        }

        if (mysqli_query($conn, $sql)) {
            $notifType = $isUpdate ? 'updated' : 'added';
            echo "<script>window.location.href='schedule.php?$baseContext&notif=$notifType';</script>"; exit;
        } else {
            $msg = "Database Error: " . mysqli_error($conn); $msgType = "error";
        }
    }
}

// C. QUICK STATUS UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status_modal'])) {
    if ($role === 'dentist') {
        $apptID = mysqli_real_escape_string($conn, $_POST['appt_id']);
        $newStatusName = mysqli_real_escape_string($conn, $_POST['new_status']);
        $statQ = mysqli_query($conn, "SELECT id FROM lookup_statuses WHERE status_name = '$newStatusName' LIMIT 1");
        $statData = mysqli_fetch_assoc($statQ);
        $statID = $statData['id'] ?? 1;

        // Backend security check for marking completed
        if ($newStatusName === 'Completed') {
            $dateCheck = mysqli_query($conn, "SELECT appointment_date FROM appointments WHERE id = '$apptID' LIMIT 1");
            $dCheck = mysqli_fetch_assoc($dateCheck);
            if ($dCheck['appointment_date'] !== $today) {
                echo "<script>alert('Error: This appointment is not scheduled for today and cannot be marked as complete yet.'); window.location.href='schedule.php?$baseContext';</script>";
                exit;
            }
        }

        $sql = "UPDATE appointments SET status_id = '$statID' WHERE id = '$apptID'";
        if (mysqli_query($conn, $sql)) {
            echo "<script>window.location.href='schedule.php?$baseContext&notif=status';</script>"; exit;
        }
    }
}

// FETCH PROCEDURES FOR DROPDOWN
$allProcedures = [];
$procResult = mysqli_query($conn, "SELECT id, category, procedure_name FROM procedures ORDER BY category, procedure_name");
while($row = mysqli_fetch_assoc($procResult)) {
    $allProcedures[$row['category']][] = $row;
}

// PREPARE DATA FOR UI - CAPTURE ADVANCED FILTERS
$editMode = false; $editData = [];
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterProcedure = $_GET['procedure'] ?? '';
$filterTimeFrom = $_GET['time_from'] ?? '';
$filterTimeTo = $_GET['time_to'] ?? '';

if (isset($_GET['edit_id']) && $role === 'dentist') { 
    $editID = mysqli_real_escape_string($conn, $_GET['edit_id']);
    $res = mysqli_query($conn, "SELECT a.*, pr.procedure_name as reason, s.status_name as status, u.first_name, u.last_name 
                                FROM appointments a 
                                LEFT JOIN users u ON a.patient_id = u.id
                                LEFT JOIN procedures pr ON a.procedure_id = pr.id
                                LEFT JOIN lookup_statuses s ON a.status_id = s.id
                                WHERE a.id='$editID'");
    if ($editData = mysqli_fetch_assoc($res)) {
        $editMode = true; $formattedDate = $editData['appointment_date'];
        $d = date('d', strtotime($formattedDate));
    }
}

$sqlList = "SELECT a.*, u.username as acc_u, u.first_name, u.last_name, pr.procedure_name as reason, s.status_name as status,
                    IFNULL(a.patient_name, CONCAT(u.first_name, ' ', u.last_name)) as display_patient_name
            FROM appointments a 
            LEFT JOIN users u ON a.patient_id = u.id 
            LEFT JOIN procedures pr ON a.procedure_id = pr.id
            LEFT JOIN lookup_statuses s ON a.status_id = s.id
            WHERE 1=1";

if ($role === 'patient') $sqlList .= " AND a.patient_id = '$userID'";
if (isset($_GET['d'])) $sqlList .= " AND a.appointment_date = '$formattedDate'";
if (!empty($search)) {
    $safeSearch = mysqli_real_escape_string($conn, $search);
    $sqlList .= " AND (a.patient_name LIKE '%$search%' OR pr.procedure_name LIKE '%$search%' OR u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%' OR CONCAT(u.first_name, ' ', u.last_name) LIKE '%$search%')";
}

if (!empty($statusFilter)) {
    $safeStatus = mysqli_real_escape_string($conn, $statusFilter);
    $sqlList .= " AND s.status_name = '$safeStatus'";
}

// ADVANCED FILTERS
if (!empty($filterDateFrom)) {
    $safeDateFrom = mysqli_real_escape_string($conn, $filterDateFrom);
    $sqlList .= " AND a.appointment_date >= '$safeDateFrom'";
}
if (!empty($filterDateTo)) {
    $safeDateTo = mysqli_real_escape_string($conn, $filterDateTo);
    $sqlList .= " AND a.appointment_date <= '$safeDateTo'";
}
if (!empty($filterProcedure)) {
    $safeProcedure = mysqli_real_escape_string($conn, $filterProcedure);
    $sqlList .= " AND pr.procedure_name = '$safeProcedure'";
}
if (!empty($filterTimeFrom)) {
    $safeTimeFrom = mysqli_real_escape_string($conn, $filterTimeFrom);
    $sqlList .= " AND STR_TO_DATE(a.appointment_time, '%h:%i %p') >= STR_TO_DATE('$safeTimeFrom:00', '%H:%i:%s')";
}
if (!empty($filterTimeTo)) {
    $safeTimeTo = mysqli_real_escape_string($conn, $filterTimeTo);
    $sqlList .= " AND STR_TO_DATE(a.appointment_time, '%h:%i %p') <= STR_TO_DATE('$safeTimeTo:59', '%H:%i:%s')";
}

$sqlList .= " ORDER BY a.appointment_date ASC, STR_TO_DATE(a.appointment_time, '%h:%i %p') ASC, a.id ASC LIMIT 50";
$bookings = mysqli_query($conn, $sqlList);

$fName = $editMode ? ($editData['patient_name'] ?: ($editData['first_name'] . ' ' . $editData['last_name'])) : (($role === 'patient') ? $fullName : '');
$fReason = $editMode ? $editData['reason'] : '';
$fTime = $editMode ? $editData['appointment_time'] : '';
$fStatus = $editMode ? $editData['status'] : 'Confirmed';
$fDP = $editMode ? $editData['downpayment_ref'] : '';
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>Schedule - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" }, fontFamily: { "display": ["Manrope", "sans-serif"] } } }
        }
    </script>
    <style>
        .animate-fade-in { animation: fadeIn 0.5s ease-out forwards; }
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(10px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        
        .calendar-day { 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            position: relative; 
        }
        
        .time-slot { 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        
        .time-slot:not(:disabled):hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 16px rgba(0,0,0,0.1); 
        }
        
        table tbody tr { 
            transition: all 0.2s ease; 
        }
        
        table tbody tr:hover { 
            background-color: rgba(19, 127, 236, 0.03); 
        }
        
        .status-badge { 
            transition: all 0.3s ease; 
        }
        
        .action-btn { 
            transition: all 0.2s ease; 
            opacity: 0.7; 
        }
        
        .action-btn:hover { 
            opacity: 1; 
            transform: scale(1.1); 
        }
        
        .card-hover { 
            transition: all 0.3s ease; 
        }
        
        .card-hover:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 12px 24px rgba(0,0,0,0.08); 
        }
        
        input:focus, select:focus {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(19, 127, 236, 0.15);
        }
    </style>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-white font-display antialiased overflow-hidden text-sm">

<div id="validationUI" class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[200] hidden items-center gap-3 px-6 py-4 rounded-2xl bg-slate-900/90 backdrop-blur-md text-white border border-slate-700 shadow-2xl animate-fade-in">
    <span class="material-symbols-outlined text-orange-400">warning</span>
    <span id="validationMsg" class="font-black text-[10px] uppercase tracking-widest"></span>
</div>

<div class="flex h-screen w-full flex-col">
    <header class="sticky top-0 z-30 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 px-6 py-4">
        <div class="max-w-6xl mx-auto flex justify-between items-center text-slate-900 dark:text-white font-black uppercase tracking-tight">
            <div>
                <h1 class="text-2xl font-black">Clinic Schedule</h1>
                <p class="text-slate-500 text-[10px] font-black uppercase">Automatic Approval Enabled</p>
            </div>
            <button onclick="openBackModal()" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 transition-colors text-sm font-bold shadow-sm font-black">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Dashboard
            </button>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-6 md:p-10 max-w-7xl mx-auto w-full animate-fade-in">
        
        <?php if($msg): ?>
            <div class="mb-6 p-4 rounded-xl border font-bold flex items-center gap-3 <?php echo ($msgType == 'success') ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'; ?>">
                <span class="material-symbols-outlined"><?php echo ($msgType == 'success') ? 'check_circle' : 'error'; ?></span>
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <div class="flex flex-col lg:flex-row gap-8">
            <aside class="w-full lg:w-[360px] flex flex-col gap-6 shrink-0">
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 shadow-md">
                    <div class="flex items-center justify-between mb-6 text-slate-900 dark:text-white font-bold">
                        <a href="?m=<?php echo ($m==1?12:$m-1); ?>&y=<?php echo ($m==1?$y-1:$y); ?>&d=<?php echo $d; ?><?php echo $searchQueryStr . $statusQueryStr; ?>" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full transition-colors"><span class="material-symbols-outlined">chevron_left</span></a>
                        <span><?php echo "$monthName $y"; ?></span>
                        <a href="?m=<?php echo ($m==12?1:$m+1); ?>&y=<?php echo ($m==12?$y+1:$y); ?>&d=<?php echo $d; ?><?php echo $searchQueryStr . $statusQueryStr; ?>" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full transition-colors"><span class="material-symbols-outlined">chevron_right</span></a>
                    </div>
                    <div class="grid grid-cols-7 mb-2 text-center text-[10px] font-black text-slate-400 uppercase">
                        <?php foreach(['S','M','T','W','T','F','S'] as $day) echo "<div>$day</div>"; ?>
                    </div>
                    <div class="grid grid-cols-7 gap-2">
                        <?php for($i=0; $i < $firstDayOfM; $i++) echo "<div></div>"; ?>
                        <?php for($i=1; $i<=$daysInMonth; $i++): 
                            $loopDate = "$y-" . str_pad($m, 2, "0", STR_PAD_LEFT) . "-" . str_pad($i, 2, "0", STR_PAD_LEFT);
                            $isBlocked = in_array($loopDate, $blockedDates);
                            $isSelected = ($i == $d && isset($_GET['d']));
                            $isToday = ($loopDate === $today);
                            
                            $baseClass = "h-10 w-10 mx-auto flex items-center justify-center rounded-lg text-xs font-black transition-all calendar-day ";
                            if ($isBlocked) {
                                $class = $baseClass . "bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400 cursor-not-allowed opacity-60";
                                $href = ($role === 'dentist') ? "?d=$i&m=$m&y=$y$searchQueryStr$statusQueryStr" . ($editMode ? "&edit_id=$editID" : "") : "javascript:void(0)";
                            } else {
                                $class = $baseClass . ($isToday ? 'ring-2 ring-primary ring-offset-2 dark:ring-offset-slate-800 ' : '') . ($isSelected ? 'bg-gradient-to-br from-primary to-blue-600 text-white shadow-lg' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700');
                                $href = "?d=$i&m=$m&y=$y$searchQueryStr$statusQueryStr" . ($editMode ? "&edit_id=$editID" : "");
                            }
                        ?>
                            <a href="<?php echo $href; ?>" class="<?php echo $class; ?>" title="<?php echo $isToday ? 'Today' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>

                <?php if($role === 'dentist'): ?>
                <div class="bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 shadow-md card-hover">
                    <p class="text-[10px] font-black uppercase text-slate-500 dark:text-slate-400 mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-sm text-orange-500">calendar_month</span>Availability: <?php echo "$monthName $d"; ?></p>
                    
                    <?php if ($formattedDate >= $today): ?>
                        <form method="POST" class="flex flex-col gap-3">
                            <input type="hidden" name="toggle_availability" value="1">
                            <input type="hidden" name="target_date" value="<?php echo $formattedDate; ?>">
                            <?php if(in_array($formattedDate, $blockedDates)): ?>
                                <button type="submit" name="action" value="unblock" class="w-full py-3 rounded-xl bg-gradient-to-r from-green-500 to-emerald-600 text-white font-black text-[10px] uppercase flex items-center justify-center gap-2 transition-all hover:shadow-lg hover:scale-[1.01] active:scale-95 shadow-md">
                                    <span class="material-symbols-outlined text-[18px]">event_available</span> Reopen Clinic
                                </button>
                            <?php else: ?>
                                <button type="submit" name="action" value="block" class="w-full py-3 rounded-xl bg-gradient-to-r from-orange-500 to-red-600 text-white font-black text-[10px] uppercase flex items-center justify-center gap-2 transition-all hover:shadow-lg hover:scale-[1.01] active:scale-95 shadow-md">
                                    <span class="material-symbols-outlined text-[18px]">event_busy</span> Close Clinic
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <div class="p-4 bg-gradient-to-r from-slate-200 to-slate-300 dark:from-slate-700 dark:to-slate-800 rounded-xl border-2 border-dashed border-slate-400 dark:border-slate-600 text-center">
                            <p class="text-[10px] font-black text-slate-600 dark:text-slate-300 uppercase flex items-center justify-center gap-2"><span class="material-symbols-outlined text-sm">lock</span>Locked for past dates</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </aside>

            <div class="flex-1 flex flex-col gap-8 overflow-hidden">
                <?php if($role === 'patient' && in_array($formattedDate, $blockedDates)): ?>
                    <div class="bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/50 p-12 rounded-[32px] text-center shadow-inner">
                        <span class="material-symbols-outlined text-red-400 text-6xl mb-4">event_busy</span>
                        <h2 class="text-2xl font-black text-red-900 dark:text-red-400 uppercase">Clinic Unavailable</h2>
                        <p class="text-red-600 dark:text-red-500/80 font-bold mt-2">The dentist is not available on <?php echo date('M d, Y', strtotime($formattedDate)); ?>. Please select another date.</p>
                    </div>
                <?php elseif($role === 'patient' || ($role === 'dentist' && $editMode)): ?>
                <form id="appointmentForm" method="POST" class="bg-gradient-to-br from-white to-slate-50 dark:from-slate-800 dark:to-slate-900 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-700 p-8 shadow-lg card-hover">
                    <div class="mb-8 pb-6 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center text-slate-900 dark:text-white font-black">
                        <h3 class="text-2xl flex items-center gap-3 uppercase tracking-tight">
                            <span class="material-symbols-outlined text-primary text-3xl"><?php echo $editMode ? 'edit_calendar' : 'add_circle'; ?></span>
                            <span><?php echo $editMode ? 'Modify Record' : "Secure your Slot for $monthName $d"; ?></span>
                        </h3>
                    </div>

                    <input type="hidden" name="save_appointment" value="1">
                    <?php if($editMode): ?><input type="hidden" name="is_update" value="1"><input type="hidden" name="record_id" value="<?php echo $editID; ?>"><?php endif; ?>
                    <input type="hidden" name="app_date" value="<?php echo $formattedDate; ?>">
                    <input type="hidden" name="app_time" id="timeInput" value="<?php echo $fTime; ?>">

                    <div class="mb-8">
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-3 block">Time selection</label>
                        <div id="timeGridContainer" class="flex flex-wrap gap-3 transition-all rounded-xl">
                            <?php 
                            $slots = ['09:00 AM','10:00 AM','11:00 AM','01:00 PM','02:00 PM','03:00 PM','04:00 PM'];
                            foreach($slots as $t): 
                                $isPast = (strtotime("$formattedDate $t") < time());
                                $isSelected = ($fTime == $t);
                                $style = ($isSelected ? 'bg-gradient-to-br from-primary to-blue-600 text-white shadow-lg border-primary ring-2 ring-primary ring-offset-2 dark:ring-offset-slate-800 time-slot selected' : 'bg-white dark:bg-slate-800 border-2 border-slate-200 dark:border-slate-700 hover:border-primary text-slate-700 dark:text-slate-300 time-slot');
                                if($isPast) $style .= " opacity-50 cursor-not-allowed bg-slate-100 dark:bg-slate-900";
                            ?>
                                <button type="button" data-past="<?php echo $isPast ? 'true' : 'false'; ?>" <?php if($isPast) echo 'disabled'; else echo "onclick=\"selectTime(this, '$t')\""; ?> 
                                        class="px-6 py-3 rounded-lg text-xs font-black border transition-all flex flex-col items-center min-w-[110px] <?php echo $style; ?>">
                                    <span class="text-sm"><?php echo $t; ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 text-slate-900 dark:text-white font-black">
                        <label class="flex flex-col gap-3">
                            <span class="text-[11px] text-slate-500 dark:text-slate-400 uppercase font-black tracking-wider">👤 Patient Name</span>
                            <input id="patient_name_field" list="patientList" type="text" name="patient_profile_name" value="<?php echo htmlspecialchars($fName); ?>" <?php if($role === 'assistant') echo 'disabled'; ?> class="h-12 rounded-xl border-2 border-slate-200 dark:border-slate-600 <?php echo ($role === 'assistant') ? 'bg-slate-100 dark:bg-slate-900 text-slate-500' : 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white'; ?> font-bold px-4 focus:ring-primary focus:border-primary shadow-sm transition-all placeholder-slate-400">
                        </label>
                        <label class="flex flex-col gap-3">
                            <span class="text-[11px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider">🏥 Procedure / Service</span>
                            <select id="reason_select_field" name="reason" required class="h-12 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all" onchange="checkSimpleTreatment(this)">
                                <option value="">-- Select Service --</option>
                                <?php foreach($allProcedures as $category => $procs): ?>
                                    <optgroup label="<?php echo htmlspecialchars($category); ?>">
                                        <?php foreach($procs as $pr): ?>
                                            <option value="<?php echo htmlspecialchars($pr['procedure_name']); ?>" <?php echo ($fReason==$pr['procedure_name'])?'selected':''; ?>>
                                                <?php echo htmlspecialchars($pr['procedure_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-6 text-slate-900 dark:text-white font-black">
                        <label class="flex flex-col gap-3">
                            <span id="paymentRefLabel" class="text-[11px] text-slate-500 dark:text-slate-400 uppercase font-black tracking-wider">💳 Downpayment Ref # (GCash: 09123456789)</span>
                            <input id="dpRefInput" type="text" name="dp_ref" value="<?php echo htmlspecialchars($fDP); ?>" placeholder="Enter Reference Number" class="h-12 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm transition-all placeholder-slate-400">
                        </label>
                        <?php if($editMode): ?>
                        <label class="flex flex-col gap-3">
                            <span class="text-[11px] text-slate-500 dark:text-slate-400 uppercase font-black tracking-wider">⚙️ Workflow Status</span>
                            <select name="status" class="h-12 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold focus:ring-primary focus:border-primary px-4 shadow-sm text-slate-900 dark:text-white transition-all">
                                <?php foreach(['Confirmed','Completed','Paid'] as $s) echo "<option value='$s'".($fStatus==$s?'selected':'').">$s</option>"; ?>
                            </select>
                        </label>
                        <?php endif; ?>
                    </div>

                    <div class="flex gap-3 pt-4 border-t border-slate-200 dark:border-slate-700">
                        <button type="submit" id="mainSubmitBtn" class="w-full py-4 rounded-xl font-black text-white shadow-xl transition-all uppercase text-[10px] <?php echo $editMode ? 'bg-gradient-to-r from-orange-500 to-amber-600 hover:shadow-2xl' : 'bg-gradient-to-r from-primary to-blue-600 hover:shadow-2xl'; ?> hover:scale-[1.01] active:scale-95">
                            <span class="flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined"><?php echo $editMode ? 'update' : 'check_circle'; ?></span>
                                <?php echo $editMode ? 'Update operational record' : 'Confirm & Automatically Approve'; ?>
                            </span>
                        </button>
                    </div>
                </form>
                <?php endif; ?>

                <div class="bg-white dark:bg-slate-800 rounded-3xl border border-slate-200 dark:border-slate-700 overflow-hidden shadow-sm shadow-lg text-slate-900 dark:text-white card-hover">
                    <div class="p-6 border-b border-slate-200 dark:border-slate-700 bg-gradient-to-r from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 flex flex-col sm:flex-row justify-between items-center gap-4 font-black uppercase">
                        <h3 class="tracking-tight flex items-center gap-2 text-lg">
                            <span class="material-symbols-outlined text-primary text-2xl">calendar_today</span>
                            Active Schedule Queue
                        </h3>
                    </div>

                    <!-- ADVANCED SEARCH & FILTER PANEL -->
                    <div class="p-6 bg-gradient-to-b from-slate-50 to-white dark:from-slate-800 dark:to-slate-900 border-b border-slate-200 dark:border-slate-700">
                        <form method="GET" class="space-y-4">
                            <!-- Hidden fields to preserve month/year context -->
                            <input type="hidden" name="m" value="<?php echo $m; ?>">
                            <input type="hidden" name="y" value="<?php echo $y; ?>">

                            <!-- FILTER HEADER WITH TOGGLE -->
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-[11px] font-black uppercase text-slate-700 dark:text-slate-300 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg text-primary">filter_list</span>
                                    Advanced Filters
                                </h4>
                                <button type="button" onclick="toggleAdvancedFilters()" class="text-[10px] font-bold uppercase text-slate-500 hover:text-primary transition-colors flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base" id="filterToggleIcon">expand_more</span>
                                    <span id="filterToggleText">Show</span>
                                </button>
                            </div>

                            <!-- ADVANCED FILTERS SECTION (COLLAPSIBLE) -->
                            <div id="advancedFiltersPanel" class="hidden space-y-4 pt-4 pb-6 border-t border-slate-200 dark:border-slate-700">
                                <!-- ROW 1: DATE RANGE -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <label class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">calendar_month</span> From Date
                                        </span>
                                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" 
                                            class="h-10 rounded-lg border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all text-sm">
                                    </label>
                                    <label class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">calendar_month</span> To Date
                                        </span>
                                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>" 
                                            class="h-10 rounded-lg border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all text-sm">
                                    </label>
                                </div>

                                <!-- ROW 2: TIME RANGE & PROCEDURE -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <label class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">schedule</span> From Time
                                        </span>
                                        <input type="time" name="time_from" value="<?php echo htmlspecialchars($filterTimeFrom); ?>" 
                                            class="h-10 rounded-lg border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all text-sm">
                                    </label>
                                    <label class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">schedule</span> To Time
                                        </span>
                                        <input type="time" name="time_to" value="<?php echo htmlspecialchars($filterTimeTo); ?>" 
                                            class="h-10 rounded-lg border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all text-sm">
                                    </label>
                                    <label class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">medical_services</span> Service
                                        </span>
                                        <select name="procedure" 
                                            class="h-10 rounded-lg border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all text-sm">
                                            <option value="">-- All Services --</option>
                                            <?php foreach($allProcedures as $category => $procs): ?>
                                                <optgroup label="<?php echo htmlspecialchars($category); ?>">
                                                    <?php foreach($procs as $pr): ?>
                                                        <option value="<?php echo htmlspecialchars($pr['procedure_name']); ?>" 
                                                            <?php echo ($filterProcedure == $pr['procedure_name']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($pr['procedure_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>

                                <!-- ROW 3: SEARCH & STATUS -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <label class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">search</span> Patient / Reference
                                        </span>
                                        <input type="text" name="search" placeholder="Name, GCash ref, contact..." value="<?php echo htmlspecialchars($search); ?>" 
                                            class="h-10 rounded-lg border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all placeholder-slate-400 text-sm">
                                    </label>
                                    <label class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">flag</span> Status
                                        </span>
                                        <select name="status" 
                                            class="h-10 rounded-lg border-2 border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 font-bold px-4 focus:ring-primary focus:border-primary shadow-sm text-slate-900 dark:text-white transition-all text-sm">
                                            <option value="">-- All Statuses --</option>
                                            <?php foreach(['Confirmed', 'Completed', 'Paid', 'Pending'] as $s): ?>
                                                <option value="<?php echo $s; ?>" <?php echo ($statusFilter === $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>

                                <!-- ACTION BUTTONS -->
                                <div class="flex gap-3 pt-4 border-t border-slate-200 dark:border-slate-700">
                                    <button type="submit" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-primary to-blue-600 text-white font-black text-[10px] uppercase shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2 active:scale-95">
                                        <span class="material-symbols-outlined">search</span> Apply Filters
                                    </button>
                                    <button type="button" onclick="clearAllFilters()" class="flex-1 py-3 rounded-xl border-2 border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-black text-[10px] uppercase hover:bg-slate-100 dark:hover:bg-slate-800 transition-all flex items-center justify-center gap-2 active:scale-95">
                                        <span class="material-symbols-outlined">restart_alt</span> Clear All
                                    </button>
                                </div>
                            </div>
                        </form>

                        <!-- ACTIVE FILTERS DISPLAY -->
                        <?php if(!empty($search) || !empty($statusFilter) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterProcedure) || !empty($filterTimeFrom) || !empty($filterTimeTo)): ?>
                        <div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                            <p class="text-[10px] font-black uppercase text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-2">
                                <span class="material-symbols-outlined">check_circle</span> Active Filters:
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <?php if(!empty($search)): ?>
                                    <span class="px-3 py-1.5 rounded-full bg-gradient-to-r from-blue-100 to-blue-50 dark:from-blue-900/40 dark:to-blue-900/20 text-blue-700 dark:text-blue-300 text-[10px] font-bold flex items-center gap-2">
                                        <span class="material-symbols-outlined text-sm">search</span> "<?php echo htmlspecialchars(substr($search, 0, 20)); ?><?php echo strlen($search) > 20 ? '...' : ''; ?>"
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($filterDateFrom)): ?>
                                    <span class="px-3 py-1.5 rounded-full bg-gradient-to-r from-emerald-100 to-emerald-50 dark:from-emerald-900/40 dark:to-emerald-900/20 text-emerald-700 dark:text-emerald-300 text-[10px] font-bold flex items-center gap-2">
                                        <span class="material-symbols-outlined text-sm">calendar_month</span> From <?php echo date('M d, Y', strtotime($filterDateFrom)); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($filterDateTo)): ?>
                                    <span class="px-3 py-1.5 rounded-full bg-gradient-to-r from-emerald-100 to-emerald-50 dark:from-emerald-900/40 dark:to-emerald-900/20 text-emerald-700 dark:text-emerald-300 text-[10px] font-bold flex items-center gap-2">
                                        <span class="material-symbols-outlined text-sm">calendar_month</span> To <?php echo date('M d, Y', strtotime($filterDateTo)); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($filterProcedure)): ?>
                                    <span class="px-3 py-1.5 rounded-full bg-gradient-to-r from-purple-100 to-purple-50 dark:from-purple-900/40 dark:to-purple-900/20 text-purple-700 dark:text-purple-300 text-[10px] font-bold flex items-center gap-2">
                                        <span class="material-symbols-outlined text-sm">medical_services</span> <?php echo htmlspecialchars(substr($filterProcedure, 0, 20)); ?><?php echo strlen($filterProcedure) > 20 ? '...' : ''; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($filterTimeFrom)): ?>
                                    <span class="px-3 py-1.5 rounded-full bg-gradient-to-r from-orange-100 to-orange-50 dark:from-orange-900/40 dark:to-orange-900/20 text-orange-700 dark:text-orange-300 text-[10px] font-bold flex items-center gap-2">
                                        <span class="material-symbols-outlined text-sm">schedule</span> After <?php echo htmlspecialchars($filterTimeFrom); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($filterTimeTo)): ?>
                                    <span class="px-3 py-1.5 rounded-full bg-gradient-to-r from-orange-100 to-orange-50 dark:from-orange-900/40 dark:to-orange-900/20 text-orange-700 dark:text-orange-300 text-[10px] font-bold flex items-center gap-2">
                                        <span class="material-symbols-outlined text-sm">schedule</span> Before <?php echo htmlspecialchars($filterTimeTo); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if(!empty($statusFilter)): ?>
                                    <span class="px-3 py-1.5 rounded-full bg-gradient-to-r from-cyan-100 to-cyan-50 dark:from-cyan-900/40 dark:to-cyan-900/20 text-cyan-700 dark:text-cyan-300 text-[10px] font-bold flex items-center gap-2">
                                        <span class="material-symbols-outlined text-sm">flag</span> <?php echo htmlspecialchars($statusFilter); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 font-black text-[10px] border-b border-slate-100 uppercase tracking-widest">
                                <tr>
                                    <th class="px-6 py-4 w-24">Date</th>
                                    <th class="px-6 py-4 w-20">Slot</th>
                                    <th class="px-6 py-4">Patient profile</th>
                                    <th class="px-6 py-4">Payment Ref</th>
                                    <th class="px-6 py-4 text-center">Status</th>
                                    <th class="px-6 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-900 dark:text-white font-bold">
                                <?php if ($bookings && mysqli_num_rows($bookings) > 0): ?>
                                    <?php while($row = mysqli_fetch_assoc($bookings)): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                                        <td class="px-6 py-4 text-sm whitespace-nowrap"><?php echo date('M d, Y', strtotime($row['appointment_date'])); ?></td>
                                        <td class="px-6 py-4 font-black"><?php echo $row['appointment_time']; ?></td>
                                        <td class="px-6 py-4">
                                              <p class="font-black"><?php echo htmlspecialchars($row['display_patient_name'] ?? ($row['patient_name'] ?: ($row['first_name'] . ' ' . $row['last_name']))); ?></p>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="text-[10px] font-black text-slate-500 uppercase"><?php echo $row['downpayment_ref'] ?: 'N/A'; ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <span class="px-3 py-1.5 rounded-full text-[10px] font-black shadow-md uppercase status-badge inline-flex items-center gap-1
                                                <?php echo ($row['status']=='Confirmed') ? 'bg-gradient-to-r from-blue-100 to-blue-50 text-blue-700 dark:from-blue-900/40 dark:to-blue-900/20 dark:text-blue-300' : 
                                                          (($row['status']=='Completed') ? 'bg-gradient-to-r from-green-100 to-emerald-50 text-green-700 dark:from-green-900/40 dark:to-green-900/20 dark:text-green-300' : 
                                                          (($row['status']=='Paid') ? 'bg-gradient-to-r from-indigo-100 to-indigo-50 text-indigo-700 dark:from-indigo-900/40 dark:to-indigo-900/20 dark:text-indigo-300' : 'bg-gradient-to-r from-orange-100 to-amber-50 text-orange-700 dark:from-orange-900/40 dark:to-orange-900/20 dark:text-orange-300')); ?>">
                                                <span class="material-symbols-outlined text-sm">
                                                    <?php echo ($row['status']=='Confirmed') ? 'schedule' : 
                                                              (($row['status']=='Completed') ? 'task_alt' : 
                                                              (($row['status']=='Paid') ? 'paid' : 'pending')); ?>
                                                </span>
                                                <?php echo $row['status']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex justify-end gap-1">
                                                <?php if($role === 'dentist'): ?>
                                                    <?php if($row['status'] == 'Confirmed' && $row['appointment_date'] == $today): ?>
                                                        <button type="button" onclick="confirmStatusAction('<?php echo $row['id']; ?>', 'Completed')" class="action-btn p-2 text-green-600 hover:bg-green-100/50 dark:hover:bg-green-900/30 rounded-lg transition-all" title="Mark Completed"><span class="material-symbols-outlined text-[20px]">task_alt</span></button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if($role === 'patient' && $row['status'] == 'Confirmed'): ?>
                                                    <button type="button" onclick="cancelAppointmentAction('<?php echo $row['id']; ?>')" class="action-btn px-3 py-2 text-sm font-bold rounded-lg transition-all flex items-center gap-2 bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800/50 hover:from-red-100 hover:to-orange-100 dark:hover:from-red-900/40 dark:hover:to-orange-900/40 hover:border-red-300 dark:hover:border-red-700/50 shadow-sm hover:shadow-md hover:scale-105 active:scale-95" title="Cancel Appointment"><span class="material-symbols-outlined text-[18px]">cancel</span><span class="hidden sm:inline text-xs font-black uppercase tracking-tight">Cancel</span></button>
                                                <?php endif; ?>
                                                <button type="button" onclick='openViewModal(<?php echo htmlspecialchars(json_encode(array_merge($row, ["booked_by_name" => ($row["patient_name"] ?: ($row["first_name"] . " " . $row["last_name"]))])), ENT_QUOTES, "UTF-8"); ?>)' class="action-btn p-2 text-primary hover:bg-primary/10 dark:hover:bg-primary/20 rounded-lg transition-all" title="View details"><span class="material-symbols-outlined text-[20px]">visibility</span></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="px-6 py-12 text-center text-slate-400 font-bold italic shadow-inner uppercase text-[10px]">No schedule entries found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="confirmationModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 no-print">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl" id="modalContent">
        <div class="text-center font-black">
            <div id="modalIconContainer" class="mx-auto flex h-16 w-16 items-center justify-center rounded-full mb-6 shadow-sm"><span id="modalIcon" class="material-symbols-outlined text-3xl">warning</span></div>
            <h3 class="text-xl font-black text-slate-900 dark:text-white mb-2 uppercase" id="modalTitle">Confirm action</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 px-4 font-bold text-[10px] uppercase" id="modalMessage">Proceed with this update?</p>
            <form method="POST" action="schedule.php?<?php echo $baseContext; ?>" class="flex flex-col gap-4">
                <input type="hidden" name="appt_id" id="modalApptId">
                <input type="hidden" name="new_status" id="modalStatusInput">
                <div class="flex gap-3 justify-center px-4">
                    <button type="button" onclick="closeModal()" class="flex-1 py-3 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-black text-[10px] uppercase">Cancel</button>
                    <button type="submit" name="update_status_modal" id="modalSubmitBtn" class="flex-1 py-3 rounded-xl font-black text-white bg-primary shadow-lg text-[10px] uppercase">Confirm</button>
                </div>
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

<div id="viewDetailsModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 no-print">
    <div class="bg-white dark:bg-slate-800 rounded-[32px] shadow-2xl p-8 w-full max-md:mx-4 max-w-md transform scale-95 transition-all duration-300 shadow-2xl" id="viewModalContent">
        <div class="flex justify-between items-center mb-6 text-slate-900 dark:text-white font-black">
            <h3 class="text-xl font-black uppercase">Appointment details</h3>
            <button onclick="closeViewModal()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full transition-colors text-slate-400"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="space-y-6 text-left" id="viewModalBody"></div>
        <div class="grid grid-cols-2 gap-3 mt-8 font-black uppercase text-[10px]" id="modalStatusFormContainer"></div>
    </div>
</div>

<script>
    const SIMPLE_TREATMENT_KEYWORDS = [
        "Consultation", "Online Consultation", "Face to Face Consultation",
        "Dental Cleaning", "Oral Prophylaxis", "Fluoride Treatment", "Pits and Fissures", "Perio Probing", 
        "Minor Restorative", "Light Cured Composite", "Temporary Filling", "Simple Radiology", 
        "Periapical X-ray", "Panoramic X-ray", "Cephalometric X-ray", 
        "Minor Orthodontic Adjustments", "Re-bonding of Bracket", "Bracket Replacement",
        "Preventive / Minor Procedures"
    ];

    function checkSimpleTreatment(select) {
        const val = select.value;
        const label = document.getElementById('paymentRefLabel');
        const dpInput = document.getElementById('dpRefInput');
        const isSimple = SIMPLE_TREATMENT_KEYWORDS.some(k => val.toLowerCase().includes(k.toLowerCase()));
        if (label && dpInput) {
            if (isSimple) {
                label.innerText = "Full Payment Ref # (No Installment allowed)";
                label.classList.remove('text-slate-400');
                label.classList.add('text-primary');
                dpInput.disabled = true;
                dpInput.value = '';
                dpInput.classList.add('opacity-60', 'cursor-not-allowed');
                dpInput.style.backgroundColor = 'var(--bg-disabled, #f3f4f6)';
            } else {
                label.innerText = "Downpayment Ref # (GCash: 09123456789)";
                label.classList.remove('text-primary');
                label.classList.add('text-slate-400');
                dpInput.disabled = false;
                dpInput.classList.remove('opacity-60', 'cursor-not-allowed');
                dpInput.style.backgroundColor = '';
            }
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('reason_select_field');
        if (select) checkSimpleTreatment(select);
    });

    function triggerUIError(message) {
        const toast = document.getElementById('validationUI');
        const text = document.getElementById('validationMsg');
        text.innerText = message;
        toast.classList.remove('hidden'); toast.classList.add('flex');
        setTimeout(() => { toast.classList.add('hidden'); }, 3000);
    }

    document.getElementById('appointmentForm')?.addEventListener('submit', function(e) {
        const timeVal = document.getElementById('timeInput').value;
        const nameField = document.getElementById('patient_name_field');
        const dpField = document.querySelector('input[name="dp_ref"]');
        if (!timeVal) { e.preventDefault(); triggerUIError("Select a time slot"); return; }
        if (nameField && nameField.value.trim().length < 2) { e.preventDefault(); triggerUIError("Patient name is required"); return; }
        if (dpField && dpField.value.trim() === "" && !dpField.disabled && "<?php echo $role; ?>" === "patient") { e.preventDefault(); triggerUIError("Payment Ref Number is required"); return; }
    });

    function selectTime(btn, time) {
        document.getElementById('timeInput').value = time;
        document.querySelectorAll('#timeGridContainer button').forEach(b => {
            if (b.dataset.past === 'true') {
                b.className = "px-5 py-3 rounded-xl text-xs font-black border transition-all flex flex-col items-center min-w-[100px] bg-white dark:bg-slate-800 border dark:border-slate-700 hover:border-primary text-slate-700 dark:text-slate-300 opacity-50 cursor-not-allowed";
            } else {
                b.className = "px-5 py-3 rounded-xl text-xs font-black border transition-all flex flex-col items-center min-w-[100px] bg-white dark:bg-slate-800 border dark:border-slate-700 hover:border-primary text-slate-700 dark:text-slate-300";
            }
        });
        btn.className = "px-5 py-3 rounded-xl text-xs font-black bg-primary text-white shadow-lg border-primary ring-2 ring-primary ring-offset-2 transition-all flex flex-col items-center min-w-[100px]";
    }

    function confirmStatusAction(id, status) {
        closeViewModal();
        const m = document.getElementById('confirmationModal'); const c = document.getElementById('modalContent');
        const submitBtn = document.getElementById('modalSubmitBtn');
        const icon = document.getElementById('modalIcon');
        const iconContainer = document.getElementById('modalIconContainer');
        document.getElementById('modalApptId').value = id;
        document.getElementById('modalStatusInput').value = status;
        document.getElementById('modalTitle').innerText = "Mark as Completed?";
        submitBtn.className = "flex-1 py-3 rounded-xl font-black text-white shadow-lg bg-green-600 text-[10px]";
        icon.innerText = "task_alt";
        iconContainer.className = "mx-auto flex h-16 w-16 items-center justify-center rounded-full mb-6 bg-green-50 text-green-600 shadow-sm";
        submitBtn.name = "update_status_modal";
        m.classList.remove('hidden'); m.classList.add('flex');
        setTimeout(() => { m.classList.remove('opacity-0'); c.classList.remove('scale-95'); c.classList.add('scale-100'); }, 10);
    }

    function cancelAppointmentAction(id) {
        document.getElementById('modalApptId').value = id;
        const modal = document.getElementById('confirmationModal'); const content = document.getElementById('modalContent');
        const submitBtn = document.getElementById('modalSubmitBtn');
        const icon = document.getElementById('modalIcon');
        const iconContainer = document.getElementById('modalIconContainer');
        document.getElementById('modalTitle').innerText = "Cancel Appointment?";
        document.getElementById('modalMessage').innerText = "You will lose this appointment slot. This action cannot be undone.";
        submitBtn.name = "delete_appt";
        submitBtn.className = "flex-1 py-3 rounded-xl font-black text-white bg-gradient-to-r from-red-500 to-red-600 shadow-lg hover:shadow-xl text-[10px] transition-all hover:scale-105 active:scale-95";
        submitBtn.innerHTML = '<span class="flex items-center justify-center gap-2"><span class="material-symbols-outlined text-lg">cancel</span> Cancel Appointment</span>';
        icon.innerText = "event_busy";
        iconContainer.className = "mx-auto flex h-16 w-16 items-center justify-center rounded-full mb-6 bg-red-100 text-red-600 shadow-md ring-2 ring-red-200 dark:ring-red-900/50";
        modal.classList.remove('hidden'); modal.classList.add('flex');
        setTimeout(() => { modal.classList.remove('opacity-0'); content.classList.remove('scale-95'); content.classList.add('scale-100'); }, 10);
    }

    function deleteAppointmentAction(id) {
        document.getElementById('modalApptId').value = id;
        const modal = document.getElementById('confirmationModal'); const content = document.getElementById('modalContent');
        const submitBtn = document.getElementById('modalSubmitBtn');
        const icon = document.getElementById('modalIcon');
        const iconContainer = document.getElementById('modalIconContainer');
        document.getElementById('modalTitle').innerText = "Delete entry?";
        submitBtn.name = "delete_appt";
        submitBtn.className = "flex-1 py-3 rounded-xl font-black text-white bg-red-500 shadow-lg text-[10px]";
        icon.innerText = "delete_forever";
        iconContainer.className = "mx-auto flex h-16 w-16 items-center justify-center rounded-full mb-6 bg-red-100 text-red-600 shadow-sm";
        modal.classList.remove('hidden'); modal.classList.add('flex');
        setTimeout(() => { modal.classList.remove('opacity-0'); content.classList.add('scale-100'); }, 10);
    }

    function closeModal() { document.getElementById('confirmationModal').classList.add('opacity-0'); setTimeout(() => document.getElementById('confirmationModal').classList.add('hidden'), 300); }
    function openBackModal() { document.getElementById('backModal').classList.remove('hidden'); document.getElementById('backModal').classList.add('flex'); setTimeout(() => document.getElementById('backModal').classList.remove('opacity-0'), 10); }
    function closeM(id) { const m = document.getElementById(id); m.classList.add('opacity-0'); setTimeout(() => m.classList.add('hidden'), 300); }
    
    function toggleAdvancedFilters() {
        const panel = document.getElementById('advancedFiltersPanel');
        const icon = document.getElementById('filterToggleIcon');
        const text = document.getElementById('filterToggleText');
        
        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            icon.innerText = 'expand_less';
            text.innerText = 'Hide';
        } else {
            panel.classList.add('hidden');
            icon.innerText = 'expand_more';
            text.innerText = 'Show';
        }
    }

    function clearAllFilters() {
        const url = new URL(window.location);
        const m = url.searchParams.get('m') || '<?php echo $m; ?>';
        const y = url.searchParams.get('y') || '<?php echo $y; ?>';
        window.location.href = `schedule.php?m=${m}&y=${y}`;
    }

    // Keep panel open if filters are active
    window.addEventListener('DOMContentLoaded', () => {
        const hasFilters = <?php echo (!empty($search) || !empty($statusFilter) || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterProcedure) || !empty($filterTimeFrom) || !empty($filterTimeTo)) ? 'true' : 'false'; ?>;
        if (hasFilters) {
            const panel = document.getElementById('advancedFiltersPanel');
            const icon = document.getElementById('filterToggleIcon');
            const text = document.getElementById('filterToggleText');
            panel.classList.remove('hidden');
            icon.innerText = 'expand_less';
            text.innerText = 'Hide';
        }
    });
    
    function setStatusFilter(status) {
        const url = new URL(window.location);
        const m = url.searchParams.get('m') || '<?php echo $m; ?>';
        const y = url.searchParams.get('y') || '<?php echo $y; ?>';
        const d = url.searchParams.get('d') || '';
        const search = url.searchParams.get('search') || '';
        
        let newUrl = `schedule.php?m=${m}&y=${y}`;
        if (d) newUrl += `&d=${d}`;
        if (search) newUrl += `&search=${encodeURIComponent(search)}`;
        newUrl += `&status=${encodeURIComponent(status)}`;
        
        window.location.href = newUrl;
    }
    
    function clearStatusFilter() {
        const url = new URL(window.location);
        const m = url.searchParams.get('m') || '<?php echo $m; ?>';
        const y = url.searchParams.get('y') || '<?php echo $y; ?>';
        const d = url.searchParams.get('d') || '';
        const search = url.searchParams.get('search') || '';
        
        let newUrl = `schedule.php?m=${m}&y=${y}`;
        if (d) newUrl += `&d=${d}`;
        if (search) newUrl += `&search=${encodeURIComponent(search)}`;
        
        window.location.href = newUrl;
    }

    function openViewModal(data) {
        const modal = document.getElementById('viewDetailsModal');
        const body = document.getElementById('viewModalBody');
        const isToday = data.appointment_date === "<?php echo $today; ?>";
        body.innerHTML = `
            <div class="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-inner">
                <p class="text-[10px] font-black mb-3 uppercase text-slate-600 dark:text-slate-300 flex items-center gap-2"><span class="material-symbols-outlined text-sm text-primary">account_circle</span>Patient Profile</p>
                <p class="font-black text-slate-900 dark:text-white text-base mb-2">${data.booked_by_name}</p>
                <p class="text-[10px] font-black text-slate-700 dark:text-slate-300 uppercase flex items-center gap-1">Account: <span class="text-primary font-black">${data.acc_u || 'N/A'}</span></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-primary/10 to-blue-50 dark:from-primary/20 dark:to-slate-900 rounded-2xl border-2 border-primary/30 dark:border-primary/40 shadow-inner">
                <p class="text-[10px] font-black mb-2 uppercase text-slate-600 dark:text-slate-300 flex items-center gap-2"><span class="material-symbols-outlined text-sm text-primary">payment</span>Payment Reference</p>
                <p class="font-black text-primary text-sm px-2 py-1">${data.downpayment_ref || 'NO PAYMENT PROVIDED'}</p>
            </div>
            <div class="grid grid-cols-2 gap-4 uppercase font-black">
                <div class="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-inner">
                    <p class="text-[10px] font-black text-slate-400 uppercase">Schedule</p>
                    <p class="font-black text-slate-700 dark:text-white text-sm">${data.appointment_date}</p>
                    <p class="font-bold text-primary text-xs">${data.appointment_time}</p>
                </div>
                <div class="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-inner">
                    <p class="text-[10px] font-black text-slate-400 uppercase">Status</p>
                    <span class="inline-block px-2 py-0.5 rounded-lg text-[10px] font-black bg-blue-100 text-blue-700 uppercase">${data.status}</span>
                </div>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-inner">
                <p class="text-[10px] font-black mb-1 uppercase text-slate-400">Service</p>
                <p class="font-black text-slate-700 dark:text-white text-sm">${data.reason}</p>
            </div>
        `;
        const container = document.getElementById('modalStatusFormContainer');
        container.innerHTML = "";
        if ("<?php echo $role; ?>" === 'dentist') {
            if (data.status === 'Confirmed' && isToday) {
                container.innerHTML = `<button type="button" onclick="confirmStatusAction(${data.id}, 'Completed')" class="col-span-2 py-3 rounded-xl bg-green-600 text-white font-black shadow-sm uppercase mb-2 w-full">Mark completed</button>`;
            } else if (data.status === 'Confirmed' && !isToday) {
                container.innerHTML = `<div class="col-span-2 p-3 bg-orange-50 border border-orange-100 rounded-xl text-[10px] text-orange-600 font-black text-center uppercase tracking-tight">Processing restricted until appointment date</div>`;
            }
        }
        modal.classList.remove('hidden'); modal.classList.add('flex');
        setTimeout(() => { modal.classList.remove('opacity-0'); document.getElementById('viewModalContent').classList.add('scale-100'); }, 10);
    }

    function closeViewModal() { document.getElementById('viewDetailsModal').classList.add('opacity-0'); setTimeout(() => document.getElementById('viewDetailsModal').classList.add('hidden'), 300); }
</script>
</body>
</html>
