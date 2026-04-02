<?php 
session_start();
require_once 'backend/config.php'; 
require_once 'backend/middleware.php'; 

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit();
}

$fullName = $_SESSION['full_name'] ?? 'User';
$firstName = explode(' ', $fullName)[0];
$profilePicture = '';

// Helper function to clean profile picture paths from database
function cleanProfilePicturePath($path) {
    // Remove leading/trailing whitespace
    $path = trim($path);
    // Normalize path separators
    $path = str_replace('\\', '/', $path);
    // Remove any /htdocs/ prefix or similar incorrect prefixes
    $path = preg_replace('|^.*?/?(assets/images/profiles/)|', '$1', $path);
    // Remove leading slashes
    $path = ltrim($path, '/');
    return $path;
}

// Fetch patient profile picture (safely check if column exists)
$patientID = $_SESSION['user_id'] ?? 0;
$checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
$columnExists = mysqli_query($conn, $checkColumnSQL);
if ($columnExists && mysqli_num_rows($columnExists) > 0) {
    $profileQuery = mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '$patientID'");
    if ($profileQuery) {
        $profileData = mysqli_fetch_assoc($profileQuery);
        $profilePictureRaw = $profileData['profile_picture'] ?? '';
        // Use absolute path with cache-busting
        if (!empty($profilePictureRaw)) {
            // Clean the path (removes /htdocs/ and other incorrect prefixes)
            $cleanedPath = cleanProfilePicturePath($profilePictureRaw);
            $appRoot = __DIR__;  // This file is at the app root
            $fullPath = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanedPath);
            $fileTime = @filemtime($fullPath);
            // Use cleaned path for URL
            $profilePicture = $cleanedPath . '?t=' . ($fileTime ? intval($fileTime) : intval(microtime(true) * 1000));
        }
    }
} 
$patientID = $_SESSION['user_id'];
$today = date('Y-m-d');
$currentTime = date('H:i');

// --- SIDEBAR NOTIFICATION COUNTERS ---
$notifApptCount = 0;
$qAppt = mysqli_query($conn, "SELECT id FROM appointments WHERE patient_id = '$patientID' AND status_id IN (1, 2) AND appointment_date >= '$today' AND is_seen = 0");
if($qAppt) $notifApptCount = mysqli_num_rows($qAppt);

$notifBillingCount = 0;
$qBill = mysqli_query($conn, "SELECT id FROM appointments WHERE patient_id = '$patientID' AND status_id = 3 AND is_seen = 0");
if($qBill) $notifBillingCount = mysqli_num_rows($qBill);

$notifCompCount = 0;
$qComp = mysqli_query($conn, "SELECT id FROM patient_complaints WHERE patient_id = '$patientID' AND status_id = 6 AND is_seen = 0");
if($qComp) $notifCompCount = mysqli_num_rows($qComp);

$notifInqCount = 0;
$qInq = mysqli_query($conn, "SELECT id FROM patient_inquiries WHERE patient_id = '$patientID' AND status_id = 6 AND is_seen = 0");
if($qInq) $notifInqCount = mysqli_num_rows($qInq);

$notifTreatmentCount = 0;
$qTreatment = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments a LEFT JOIN lookup_statuses s ON a.status_id = s.id LEFT JOIN treatment_records tr ON a.id = tr.appointment_id WHERE a.patient_id = '$patientID' AND s.status_name = 'Paid' AND tr.appointment_id IS NULL");
if($qTreatment) {
    $tRow = mysqli_fetch_assoc($qTreatment);
    $notifTreatmentCount = $tRow['total'] ?? 0;
}

// Slots logic...
$slots = ['09:00 AM','10:00 AM','11:00 AM','01:00 PM','02:00 PM','03:00 PM','04:00 PM'];
$nextSlot = "Fully Booked";
$found = false;
for ($i = 0; $i < 30; $i++) {
    $checkDate = date('Y-m-d', strtotime("+$i day"));
    $booked = [];
    $checkQ = mysqli_query($conn, "SELECT a.appointment_time FROM appointments a JOIN lookup_statuses s ON a.status_id = s.id WHERE a.appointment_date = '$checkDate' AND s.status_name NOT IN ('Rejected', 'Cancelled')");
    if ($checkQ) { while($row = mysqli_fetch_assoc($checkQ)) { $booked[] = $row['appointment_time']; } }
    foreach($slots as $s) {
        $slot24 = date('H:i', strtotime($s));
        if ($i == 0 && $slot24 <= $currentTime) continue;
        if (!in_array($s, $booked)) { $nextSlot = $s; $found = true; break 2; }
    }
}

$visitQuery = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments a JOIN lookup_statuses s ON a.status_id = s.id WHERE a.patient_id = '$patientID' AND (s.status_name = 'Completed' OR s.status_name = 'Complete')");
$visitData = mysqli_fetch_assoc($visitQuery);
$totalVisits = $visitData['total'] ?? 0;

// FIXED: Standardized PENDING BALANCE calculation for Patient (Sum Procedures - Sum Payments)
$balanceQuery = mysqli_query($conn, "SELECT 
                (SELECT IFNULL(SUM(pr.standard_cost), 0) 
                 FROM appointments a 
                 JOIN procedures pr ON a.procedure_id = pr.id 
                 JOIN lookup_statuses s ON a.status_id = s.id 
                 WHERE a.patient_id = '$patientID' AND (s.status_name = 'Completed' OR s.status_name = 'Complete' OR s.status_name = 'Paid')) - 
                (SELECT IFNULL(SUM(p.amount), 0) 
                 FROM payments p 
                 JOIN lookup_statuses s ON p.status_id = s.id 
                 WHERE p.patient_id = '$patientID' AND (s.status_name = 'Completed' OR s.status_name = 'Complete')) as total");
$balanceData = mysqli_fetch_assoc($balanceQuery);
$pendingInvoices = (float)($balanceData['total'] ?? 0);
if ($pendingInvoices < 0.01) $pendingInvoices = 0;

$nextApptQuery = mysqli_query($conn, "SELECT a.*, pr.procedure_name as reason, s.status_name as status, CONCAT(u.first_name, ' ', u.last_name) as patient_display_name FROM appointments a JOIN users u ON a.patient_id = u.id JOIN procedures pr ON a.procedure_id = pr.id JOIN lookup_statuses s ON a.status_id = s.id WHERE a.patient_id = '$patientID' AND s.status_name IN ('Pending', 'Confirmed') AND (a.appointment_date > '$today' OR (a.appointment_date = '$today' AND STR_TO_DATE(a.appointment_time, '%h:%i %p') > STR_TO_DATE('$currentTime', '%H:%i'))) ORDER BY a.appointment_date ASC, STR_TO_DATE(a.appointment_time, '%h:%i %p') ASC LIMIT 1");
$nextAppt = mysqli_fetch_assoc($nextApptQuery);

$activityQuery = mysqli_query($conn, "SELECT t.*, pr.procedure_name FROM treatment_records t JOIN procedures pr ON t.procedure_id = pr.id WHERE t.patient_id = '$patientID' ORDER BY t.treatment_date DESC LIMIT 5");
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta charset="utf-8"/>
    <title>Patient Dashboard - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
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
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f0f4f8 50%, #fef9f3 100%);
        }
        
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
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-25px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.92);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        body {
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        a, button {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        button {
            position: relative;
            overflow: hidden;
        }
        
        button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.25);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        button:active::before {
            width: 300px;
            height: 300px;
        }
        
        div[class*="bg-"], div[class*="border"], div[class*="shadow"] {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .bg-white, .dark\:bg-slate-800, .dark\:bg-slate-900 {
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        [class*="hover:"] {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        header {
            animation: slideInDown 0.7s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .grid > div {
            animation: scaleIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation-fill-mode: both;
            background: linear-gradient(135deg, var(--color-from), var(--color-to));
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        
        .grid > div:nth-child(1) { 
            animation-delay: 0.1s;
            --color-from: rgba(59, 130, 246, 0.08);
            --color-to: rgba(255, 255, 255, 0);
        }
        .grid > div:nth-child(2) { 
            animation-delay: 0.2s;
            --color-from: rgba(249, 115, 22, 0.08);
            --color-to: rgba(255, 255, 255, 0);
        }
        .grid > div:nth-child(3) { 
            animation-delay: 0.3s;
            --color-from: rgba(34, 197, 94, 0.08);
            --color-to: rgba(255, 255, 255, 0);
        }
        .grid > div:nth-child(4) { 
            animation-delay: 0.4s;
            --color-from: rgba(168, 85, 247, 0.08);
            --color-to: rgba(255, 255, 255, 0);
        }
        
        .grid > div:hover {
            transform: translateY(-6px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
        }
        
        section {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.5s forwards;
            opacity: 0;
        }
        
        .space-y-4 {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.6s forwards;
            opacity: 0;
        }
        
        tbody tr {
            animation: slideInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation-fill-mode: both;
            transition: all 0.3s ease;
        }
        
        tbody tr:nth-child(1) { animation-delay: 0.05s; }
        tbody tr:nth-child(2) { animation-delay: 0.1s; }
        tbody tr:nth-child(3) { animation-delay: 0.15s; }
        tbody tr:nth-child(4) { animation-delay: 0.2s; }
        tbody tr:nth-child(5) { animation-delay: 0.25s; }
        
        tbody tr:hover {
            transform: translateX(6px);
            background-color: rgba(59, 130, 246, 0.08);
        }
        
        .rounded-2xl, .rounded-3xl, .rounded-\[24px\], .rounded-\[32px\] {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .shadow-md, .shadow-2xl {
            transition: box-shadow 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        [class*="text-slate-"], [class*="text-primary"], [class*="text-red-"], [class*="text-white"] {
            transition: color 0.3s ease;
        }
        
        .size-10, .size-14 {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .size-14:hover {
            transform: scale(1.05);
        }
        
        aside nav a {
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        aside nav a:hover {
            transform: translateX(4px);
        }

        aside {
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.08);
        }

        #sidebar { transition: transform 0.3s ease-in-out; }
        @media (max-width: 1024px) {
            #sidebar.hidden-mobile { transform: translateX(-100%); }
            #sidebar.visible-mobile { transform: translateX(0); }
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 font-display text-sm overflow-hidden">
<div class="flex h-screen w-full overflow-hidden relative">

    <div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 bg-slate-900/50 z-30 hidden lg:hidden backdrop-blur-sm"></div>

    <aside id="sidebar" class="fixed lg:static inset-y-0 left-0 w-64 h-full flex flex-col bg-white dark:bg-[#1e293b] border-r border-slate-200 dark:border-slate-800 flex-shrink-0 z-40 hidden-mobile lg:translate-x-0 font-medium transition-colors duration-200 shadow-lg">
        <div class="flex flex-col h-full justify-between p-4">
            <div class="flex flex-col gap-6">
                <div class="flex items-center gap-3 px-2 py-2">
                    <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-12 w-auto">
                    <div class="flex flex-col overflow-hidden">
                        <h1 class="text-slate-900 dark:text-white text-sm font-bold truncate">San Nicolas</h1>
                        <p class="text-slate-500 text-[10px] font-black uppercase">Patient portal</p>
                    </div>
                </div>
                <nav class="flex flex-col gap-2 flex-1 overflow-y-auto">
                    <a class="flex items-center gap-3 px-3 py-3 rounded-lg bg-primary/10 text-primary shadow-sm" href="patient-dashboard.php">
                        <span class="material-symbols-outlined">dashboard</span><p class="font-bold">Dashboard</p>
                    </a>
                    <a class="flex items-center gap-3 px-3 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" href="schedule.php" onclick="clearNotification('appt')">
                        <span class="material-symbols-outlined">calendar_month</span><p class="font-medium">Appointments</p>
                        <?php if($notifApptCount > 0): ?>
                            <span id="badge-appt" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="apptBadge"><?php echo $notifApptCount; ?></span>
                        <?php else: ?>
                            <span id="badge-appt" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="apptBadge">0</span>
                        <?php endif; ?>
                    </a>
                    <a class="flex items-center gap-3 px-3 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" href="patients.php">
                        <span class="material-symbols-outlined">person</span><p class="font-medium">My profile</p>
                    </a>
                    <a class="flex items-center gap-3 px-3 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" href="treatment-records.php">
                        <span class="material-symbols-outlined">history_edu</span><p class="font-medium">Treatment history</p>
                        <?php if($notifTreatmentCount > 0): ?>
                            <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="treatmentBadge"><?php echo $notifTreatmentCount; ?></span>
                        <?php else: ?>
                            <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="treatmentBadge">0</span>
                        <?php endif; ?>
                    </a>
                    <a class="flex items-center gap-3 px-3 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" href="record-payment.php" onclick="clearNotification('billing')">
                        <span class="material-symbols-outlined">credit_card</span><p class="font-medium">Billing</p>
                        <?php if($notifBillingCount > 0): ?>
                            <span id="badge-billing" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="billingBadge"><?php echo $notifBillingCount; ?></span>
                        <?php else: ?>
                            <span id="badge-billing" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="billingBadge">0</span>
                        <?php endif; ?>
                    </a>
                    <a class="flex items-center gap-3 px-3 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" href="complaint.php" onclick="clearNotification('comp')">
                        <span class="material-symbols-outlined">report_problem</span><p class="font-medium">File a complaint</p>
                        <?php if($notifCompCount > 0): ?>
                            <span id="badge-comp" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="complaintBadge"><?php echo $notifCompCount; ?></span>
                        <?php else: ?>
                            <span id="badge-comp" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="complaintBadge">0</span>
                        <?php endif; ?>
                    </a>
                    <a class="flex items-center gap-3 px-3 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" href="inquiry.php" onclick="clearNotification('inq')">
                        <span class="material-symbols-outlined">contact_support</span><p class="font-medium">Submit an inquiry</p>
                        <?php if($notifInqCount > 0): ?>
                            <span id="badge-inq" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="inquiryBadge"><?php echo $notifInqCount; ?></span>
                        <?php else: ?>
                            <span id="badge-inq" class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="inquiryBadge">0</span>
                        <?php endif; ?>
                    </a>
                </nav>
            </div>
            <div class="border-t border-slate-200 dark:border-slate-700 p-4 space-y-3">
                <div class="flex flex-col gap-2 text-[10px] px-1">
                    <button onclick="openTermsModal()" class="text-primary hover:underline font-bold transition-all text-left">Terms & Conditions</button>
                    <button onclick="openPrivacyModal()" class="text-primary hover:underline font-bold transition-all text-left">Privacy Policy</button>
                </div>
                <button onclick="openLogoutModal()" class="flex items-center gap-3 px-3 py-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors w-full text-left font-bold">
                    <span class="material-symbols-outlined">logout</span>
                    <span class="text-sm font-bold">Log out</span>
                </button>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto h-full relative bg-[#f8fafc] dark:bg-background-dark">
        <header class="lg:hidden flex items-center justify-between p-4 bg-white dark:bg-[#1e293b] border-b border-slate-200 dark:border-slate-800 sticky top-0 z-30 shadow-sm font-black">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center text-white shadow-sm">
                    <span class="material-symbols-outlined text-xl font-black">health_and_safety</span>
                </div>
                <span class="text-sm font-bold text-slate-900 dark:text-white">San Nicolas</span>
            </div>
            <button onclick="toggleSidebar()" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-lg shadow-inner transition-colors">
                <span class="material-symbols-outlined font-black">menu</span>
            </button>
        </header>

        <div class="p-4 md:p-6 lg:p-10 max-w-[1600px] mx-auto flex flex-col gap-4 md:gap-6 lg:gap-10 pb-12">
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-4 md:gap-6 animate-fade-in text-slate-900 dark:text-white">
                <div class="space-y-1">
                    <h1 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-black tracking-tight">Hello, <?php echo htmlspecialchars($firstName); ?> 👋</h1>
                    <p class="text-slate-500 text-lg font-medium">Welcome back to your clinic portal.</p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right hidden sm:block">
                        <p class="text-sm font-bold"><?php echo date('l, M jS'); ?> • <span class="text-primary"><?php echo date('h:i A'); ?></span></p>
                        <p class="text-xs text-slate-500 font-medium uppercase">Account ID: <span class="text-primary font-bold">#<?php echo $patientID; ?></span></p>
                    </div>
                    <div class="size-14 rounded-2xl bg-primary/10 text-primary flex items-center justify-center font-black text-xl border-2 border-white dark:border-slate-700 shadow-sm transition-transform hover:scale-110 overflow-hidden flex-shrink-0">
                        <?php if (!empty($profilePicture)): ?>
                            <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?php echo substr($fullName, 0, 1); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </header>   

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 animate-fade-in font-bold" style="animation-delay: 0.1s;">
                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-sm shadow-md transition-all hover:scale-[1.02] relative overflow-hidden text-sm" data-stat="totalVisits">
                    <div class="size-12 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-primary mb-4 shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black">calendar_today</span></div>
                    <p class="text-slate-500 text-[10px] font-black uppercase">Total visits</p>
                    <h3 class="text-3xl md:text-4xl font-black mt-1 tracking-tight text-slate-900 dark:text-white stat-value"><?php echo $totalVisits; ?></h3>
                </div>
                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-sm shadow-md transition-all hover:scale-[1.02] relative overflow-hidden text-sm" data-stat="pendingBalance">
                    <div class="size-12 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 mb-4 shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black">payments</span></div>
                    <p class="text-slate-500 text-[10px] font-black uppercase">Pending balance</p>
                    <h3 class="text-3xl md:text-4xl font-black mt-1 tracking-tight text-red-500 stat-value">₱<?php echo number_format($pendingInvoices, 0); ?></h3>
                </div>
                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-sm shadow-md transition-all hover:scale-[1.02] ring-2 <?php echo ($nextSlot == 'Fully Booked') ? 'ring-red-500/20' : 'ring-primary/20'; ?> relative overflow-hidden text-sm">
                    <div class="size-12 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 mb-4 shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black">schedule</span></div>
                    <p class="<?php echo ($nextSlot == 'Fully Booked') ? 'text-red-500' : 'text-primary'; ?> text-[10px] font-black uppercase">Next available slot</p>
                    <h3 class="text-3xl md:text-4xl font-black mt-1 tracking-tight text-slate-900 dark:text-white"><?php echo $nextSlot; ?></h3>
                    <p class="text-[10px] text-slate-400 mt-1 font-bold italic">PH time: <?php echo date('h:i A'); ?></p>
                </div>
                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-sm shadow-md transition-all hover:scale-[1.02] relative overflow-hidden text-sm">
                    <div class="size-12 rounded-xl bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center text-purple-500 mb-4 shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black">favorite</span></div>
                    <p class="text-slate-500 text-[10px] font-black uppercase">Health score</p>
                    <h3 class="text-3xl md:text-4xl font-black mt-1 tracking-tight text-blue-500">Active</h3>
                </div>
            </div>

            <section class="animate-fade-in" style="animation-delay: 0.2s;">
                <div class="bg-gradient-to-br from-primary via-primary to-blue-700 rounded-3xl p-1 shadow-2xl">
                    <div class="bg-white dark:bg-slate-900 rounded-[22px] p-6 md:p-10 relative overflow-hidden shadow-inner">
                        <?php if ($nextAppt): ?>
                            <div class="relative flex flex-col lg:flex-row gap-10 items-center justify-between">
                                <div class="space-y-6">
                                    <span class="px-4 py-1.5 rounded-full bg-primary/10 text-primary font-black text-[10px] uppercase">Upcoming visit</span>
                                    <h2 class="text-3xl md:text-4xl font-black text-slate-900 dark:text-white tracking-tight"><?php echo htmlspecialchars($nextAppt['reason']); ?></h2>
                                    <div class="flex flex-wrap gap-6 text-slate-500 font-bold uppercase text-[10px]">
                                        <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary">event</span> <?php echo date('M d, Y', strtotime($nextAppt['appointment_date'])); ?></div>
                                        <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary">schedule</span> <?php echo $nextAppt['appointment_time']; ?></div>
                                        <div class="flex items-center gap-2"><span class="material-symbols-outlined text-primary">person</span> Patient: <?php echo htmlspecialchars($nextAppt['patient_display_name']); ?></div>
                                    </div>
                                </div>
                                <a href="schedule.php" class="h-14 px-8 rounded-2xl bg-primary text-white flex items-center justify-center font-bold shadow-xl shadow-blue-500/30 hover:bg-blue-600 transition-all uppercase text-xs">New appointment</a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <p class="text-slate-500 font-bold mb-4">No upcoming visits found in your queue.</p>
                                <a href="schedule.php" class="inline-flex h-12 px-6 rounded-xl bg-primary text-white items-center font-bold shadow-lg shadow-blue-500/30 text-xs uppercase">Book now</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 md:gap-6 lg:gap-10">
                <div class="xl:col-span-2 flex flex-col gap-6">
                    <div class="flex justify-between items-end">
                        <h3 class="text-xl font-black px-1 text-slate-900 dark:text-white uppercase tracking-tight">Recent treatment history</h3>
                        <a href="treatment-records.php" class="text-xs font-bold text-primary hover:underline transition-all uppercase">View all history</a>
                    </div>
                    <div class="bg-white dark:bg-slate-800 rounded-2xl md:rounded-3xl border border-slate-200 dark:border-slate-700 overflow-x-auto shadow-md">
                        <table class="w-full text-left border-collapse min-w-[320px]">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-900/50 text-slate-400 text-[9px] sm:text-[10px] font-black uppercase tracking-widest">
                                    <th class="px-3 sm:px-4 md:px-6 py-3 sm:py-4">Procedure</th><th class="px-3 sm:px-4 md:px-6 py-3 sm:py-4">Date</th><th class="px-3 sm:px-4 md:px-6 py-3 sm:py-4 text-right">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y dark:divide-slate-700 font-bold text-[11px] sm:text-xs">
                                <?php if (mysqli_num_rows($activityQuery) > 0): ?>
                                    <?php while($row = mysqli_fetch_assoc($activityQuery)): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/40 transition-colors">
                                        <td class="px-3 sm:px-4 md:px-6 py-3 sm:py-4 text-slate-800 dark:text-white"><?php echo htmlspecialchars($row['procedure_name']); ?></td>
                                        <td class="px-3 sm:px-4 md:px-6 py-3 sm:py-4 text-slate-400"><?php echo date('M d, Y', strtotime($row['treatment_date'])); ?></td>
                                        <td class="px-3 sm:px-4 md:px-6 py-3 sm:py-4 text-right text-green-600 font-black">₱<?php echo number_format($row['actual_cost'] ?? 0, 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="px-3 sm:px-4 md:px-6 py-8 sm:py-12 text-center text-slate-400 italic font-bold uppercase text-[11px] sm:text-xs">No dental history recorded.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-col gap-3 md:gap-4 font-black transition-all">
                    <h3 class="text-lg font-black px-2 tracking-tight transition-colors font-black">Quick actions</h3>
                    <a href="schedule.php" class="h-12 sm:h-14 w-full bg-primary text-white rounded-xl sm:rounded-2xl font-black shadow-xl shadow-blue-500/30 hover:bg-blue-600 transition-all flex items-center justify-center gap-2 text-xs sm:text-sm tracking-tight font-black">
                        <span class="material-symbols-outlined font-black">add</span> Book appointment
                    </a>
                    <a href="record-payment.php" class="h-12 sm:h-14 w-full bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-xl sm:rounded-2xl font-black hover:bg-slate-50 dark:hover:bg-slate-700 transition-all flex items-center justify-center gap-2 shadow-sm font-bold text-xs sm:text-sm tracking-tight font-black">
                        <span class="material-symbols-outlined text-primary text-[20px] font-black font-black">payments</span> Payment history
                    </a>
                </div>
            </div>

        </div>
    </main>
</div>

<div id="termsModalUI" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 backdrop-blur-md">
    <div class="fixed inset-0 bg-slate-950/60" onclick="closeTermsModal()"></div>
    <div class="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-[32px] shadow-2xl overflow-hidden max-h-[85vh] flex flex-col animate-fade-in">
        <!-- Header -->
        <div class="bg-gradient-to-br from-primary via-primary to-blue-700 px-10 pt-10 pb-6 flex flex-col items-center text-center relative overflow-hidden sticky top-0">
            <div class="absolute inset-0 opacity-15" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 20px 20px;"></div>
            <div class="relative w-full flex flex-col items-center justify-center">
                <div class="size-16 rounded-full bg-white/20 flex items-center justify-center mb-6 backdrop-blur-sm border-2 border-white/30">
                    <span class="material-symbols-outlined text-4xl text-white">description</span>
                </div>
                <h2 class="text-3xl font-black text-white mb-2">Terms & Conditions</h2>
                <p class="text-blue-100 text-sm font-semibold">San Nicolas Dental Clinic Patient Portal</p>
            </div>
        </div>
        
        <!-- Content body with scroll -->
        <div class="px-10 py-8 overflow-y-auto flex-1 text-slate-700 dark:text-slate-300 space-y-6 text-sm font-medium">
            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">1. Acceptance of Terms</h3>
                <p>By accessing and using the San Nicolas Dental Clinic Patient Portal, you agree to be bound by these Terms and Conditions. If you do not agree to abide by the above, please do not use this service.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">2. Use License</h3>
                <p>Permission is granted to temporarily download one copy of the materials (information or software) on the San Nicolas Dental Clinic Patient Portal for personal, non-commercial transitory viewing only. This is the grant of a license, not a transfer of title, and under this license you may not:</p>
                <ul class="list-disc list-inside mt-3 space-y-2 ml-2">
                    <li>Modify or copy the materials</li>
                    <li>Use the materials for any commercial purpose or for any public display</li>
                    <li>Attempt to decompile or reverse engineer any software contained on the portal</li>
                    <li>Remove any copyright or other proprietary notations from the materials</li>
                    <li>Transfer the materials to another person or "mirror" the materials on any other server</li>
                </ul>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">3. Patient Data & Privacy</h3>
                <p>All personal health information provided through this portal is protected and handled in accordance with applicable healthcare privacy laws. We are committed to maintaining the confidentiality of your medical records and personal information.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">4. Appointment Booking</h3>
                <p>When booking appointments through this portal, you agree to:</p>
                <ul class="list-disc list-inside mt-3 space-y-2 ml-2">
                    <li>Provide accurate and complete information</li>
                    <li>Arrive on time or notify the clinic at least 24 hours in advance of cancellation</li>
                    <li>Follow all clinic policies and procedures</li>
                </ul>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">5. Payment Terms</h3>
                <p>All dental services are charged according to the clinic's established fees. You are responsible for payment of services rendered. The clinic accepts various payment methods as displayed in the billing section. Late payments may incur additional charges as per clinic policy.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">6. Limitation of Liability</h3>
                <p>The materials on the San Nicolas Dental Clinic Patient Portal are provided on an 'as is' basis. San Nicolas Dental Clinic makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties including, without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">7. Disclaimer</h3>
                <p>Further, San Nicolas Dental Clinic does not warrant or make any representations concerning the accuracy, likely results, or reliability of the use of the materials on its portal or otherwise relating to such materials or on any sites linked to this portal.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">8. Modifications</h3>
                <p>San Nicolas Dental Clinic may revise these terms of service for its website at any time without notice. By using this website, you are agreeing to be bound by the then current version of these terms of service.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">9. Governing Law</h3>
                <p>These terms and conditions are governed by and construed in accordance with the laws of the Republic of the Philippines, and you irrevocably submit to the exclusive jurisdiction of the courts in that location.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">10. Contact Information</h3>
                <p>If you have any questions about these Terms and Conditions, please contact San Nicolas Dental Clinic through your patient portal or visit our clinic directly.</p>
            </div>

            <div class="text-xs text-slate-500 dark:text-slate-400 italic pt-4">
                <p>Last Updated: February 2026</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="border-t border-slate-200 dark:border-slate-700 px-10 py-6 flex justify-end gap-3 bg-slate-50 dark:bg-slate-800/50 sticky bottom-0">
            <button onclick="closeTermsModal()" class="h-12 px-6 rounded-xl border-2 border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 transition-all text-slate-700 dark:text-slate-200 font-bold flex items-center justify-center text-sm">
                Close
            </button>
            <button onclick="acceptTerms()" class="h-12 px-6 rounded-xl bg-primary hover:bg-blue-600 text-white font-bold shadow-lg transition-all flex items-center justify-center text-sm">
                I Accept
            </button>
        </div>
    </div>
</div>

<div id="logoutModalUI" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 backdrop-blur-md">
    <div class="fixed inset-0 bg-slate-950/60" onclick="closeLogoutModal()"></div>
    <div class="relative w-full max-w-[480px] bg-white dark:bg-slate-900 rounded-[32px] shadow-2xl overflow-hidden animate-fade-in duration-300">
        <!-- Header with gradient background -->
        <div class="bg-gradient-to-br from-red-600 via-red-600 to-red-700 dark:from-red-700 dark:via-red-700 dark:to-red-800 px-10 pt-10 pb-6 flex flex-col items-center text-center relative overflow-hidden">
            <div class="absolute inset-0 opacity-15" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 20px 20px;"></div>
            <div class="relative w-full flex flex-col items-center justify-center">
                <div class="size-16 rounded-full bg-white/20 flex items-center justify-center mb-6 backdrop-blur-sm border-2 border-white/30">
                    <span class="material-symbols-outlined text-4xl text-white">logout</span>
                </div>
                <h2 class="text-3xl font-black text-white mb-2">Sign out?</h2>
                <p class="text-red-100 text-sm font-semibold">End your session</p>
            </div>
        </div>
        
        <!-- Content body -->
        <div class="px-10 py-8 flex flex-col items-center text-center gap-8">
            <div class="space-y-3">
                <p class="text-slate-600 dark:text-slate-300 text-sm font-medium">Are you sure you want to sign out? You'll need to log in again to access your account.</p>
                <p class="text-slate-500 dark:text-slate-400 text-xs font-medium opacity-75">Your appointment data will be saved.</p>
            </div>
            
            <!-- Action buttons -->
            <div class="flex flex-col sm:flex-row gap-3 w-full font-black text-sm">
                <button onclick="closeLogoutModal()" class="flex-1 h-14 rounded-2xl border-2 border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition-all text-slate-700 dark:text-slate-200 font-bold flex items-center justify-center gap-2 group">
                    <span class="material-symbols-outlined text-lg group-hover:scale-110 transition-transform">close</span>
                    <span>Cancel</span>
                </button>
                <a href="backend/logout.php" class="flex-1 h-14 rounded-2xl bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white font-black shadow-lg hover:shadow-red-500/30 transition-all flex items-center justify-center gap-2 group active:scale-95">
                    <span class="material-symbols-outlined text-lg group-hover:translate-x-1 transition-transform">exit_to_app</span>
                    <span>Sign out</span>
                </a>
            </div>
        </div>
    </div>
</div>

<div id="privacyModalUI" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 backdrop-blur-md">
    <div class="fixed inset-0 bg-slate-950/60" onclick="closePrivacyModal()"></div>
    <div class="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-[32px] shadow-2xl overflow-hidden max-h-[85vh] flex flex-col animate-fade-in">
        <!-- Header -->
        <div class="bg-gradient-to-br from-primary via-primary to-blue-700 px-10 pt-10 pb-6 flex flex-col items-center text-center relative overflow-hidden sticky top-0">
            <div class="absolute inset-0 opacity-15" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 20px 20px;"></div>
            <div class="relative w-full flex flex-col items-center justify-center">
                <div class="size-16 rounded-full bg-white/20 flex items-center justify-center mb-6 backdrop-blur-sm border-2 border-white/30">
                    <span class="material-symbols-outlined text-4xl text-white">privacy_tip</span>
                </div>
                <h2 class="text-3xl font-black text-white mb-2">Privacy Policy</h2>
                <p class="text-blue-100 text-sm font-semibold">San Nicolas Dental Clinic Patient Portal</p>
            </div>
        </div>
        
        <!-- Content body with scroll -->
        <div class="px-10 py-8 overflow-y-auto flex-1 text-slate-700 dark:text-slate-300 space-y-6 text-sm font-medium">
            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">1. Introduction</h3>
                <p>Welcome to San Nicolas Dental Clinic. Your privacy and trust are paramount to us. This Privacy Policy outlines our commitment to protecting your personal health information and explaining how we collect, use, disclose, and safeguard your data when you access our patient portal and dental services. We comply with all applicable healthcare privacy laws and regulations to ensure your sensitive information remains confidential and secure. By using our portal, you consent to the practices described in this policy.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">2. Information We Collect</h3>
                <p>We collect information you provide directly to us, such as:</p>
                <ul class="list-disc list-inside mt-3 space-y-2 ml-2">
                    <li>Personal identification information (name, email, phone number, date of birth)</li>
                    <li>Medical and dental health information</li>
                    <li>Insurance information</li>
                    <li>Payment and billing information</li>
                    <li>Appointment history and preferences</li>
                </ul>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">3. How We Use Your Information</h3>
                <p>Your information is used to:</p>
                <ul class="list-disc list-inside mt-3 space-y-2 ml-2">
                    <li>Provide and improve dental care services</li>
                    <li>Schedule and manage appointments</li>
                    <li>Process payments and insurance claims</li>
                    <li>Send appointment reminders and important notices</li>
                    <li>Maintain accurate medical records</li>
                    <li>Comply with legal and regulatory requirements</li>
                </ul>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">4. Data Security</h3>
                <p>We implement appropriate technical and organizational measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction. All data is encrypted and transmitted securely. However, no method of transmission over the internet is 100% secure.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">5. HIPAA Compliance</h3>
                <p>As a healthcare provider, San Nicolas Dental Clinic complies with the Health Insurance Portability and Accountability Act (HIPAA). Your protected health information is handled in accordance with HIPAA regulations and will not be shared with third parties without your written consent, except as required by law.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">6. Information Sharing</h3>
                <p>We do not sell, trade, or rent your personal information to third parties. We may share your information only with:</p>
                <ul class="list-disc list-inside mt-3 space-y-2 ml-2">
                    <li>Healthcare providers involved in your treatment</li>
                    <li>Insurance companies for claim processing</li>
                    <li>Authorized service providers under confidentiality agreements</li>
                    <li>Law enforcement if required by law</li>
                </ul>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">7. Your Rights</h3>
                <p>You have the right to:</p>
                <ul class="list-disc list-inside mt-3 space-y-2 ml-2">
                    <li>Access your health information</li>
                    <li>Request corrections to your records</li>
                    <li>Receive an accounting of disclosures</li>
                    <li>Request restrictions on use and disclosure</li>
                    <li>Request confidential communications</li>
                    <li>Withdraw consent at any time</li>
                </ul>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">8. Cookies and Tracking</h3>
                <p>Our website may use cookies to enhance your experience. These are small files stored on your device that help us remember your preferences and analyze site usage. You can disable cookies in your browser settings.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">9. Children's Privacy</h3>
                <p>Our portal is not intended for individuals under 18 years of age. We do not knowingly collect information from children. If we become aware that we have collected information from a child, we will take steps to delete such information.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">10. Changes to This Policy</h3>
                <p>San Nicolas Dental Clinic may update this Privacy Policy periodically. We will notify you of any significant changes via email or a prominent notice on our website. Your continued use of the portal constitutes your acceptance of the updated policy.</p>
            </div>

            <div>
                <h3 class="font-black text-lg text-slate-900 dark:text-white mb-3">11. Contact Us</h3>
                <p>If you have questions or concerns about this Privacy Policy or our privacy practices, please contact us at our clinic or through the patient portal contact form. You can also file a complaint with your state's healthcare privacy authority if you believe your rights have been violated.</p>
            </div>

            <div class="text-xs text-slate-500 dark:text-slate-400 italic pt-4">
                <p>Last Updated: February 2026</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="border-t border-slate-200 dark:border-slate-700 px-10 py-6 flex justify-end gap-3 bg-slate-50 dark:bg-slate-800/50 sticky bottom-0">
            <button onclick="closePrivacyModal()" class="h-12 px-6 rounded-xl border-2 border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 transition-all text-slate-700 dark:text-slate-200 font-bold flex items-center justify-center text-sm">
                Close
            </button>
            <button onclick="acceptPrivacy()" class="h-12 px-6 rounded-xl bg-primary hover:bg-blue-600 text-white font-bold shadow-lg transition-all flex items-center justify-center text-sm">
                I Acknowledge
            </button>
        </div>
    </div>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar.classList.contains('hidden-mobile')) {
            sidebar.classList.remove('hidden-mobile'); sidebar.classList.add('visible-mobile');
            overlay.classList.remove('hidden'); document.body.style.overflow = 'hidden';
        } else {
            sidebar.classList.add('hidden-mobile'); sidebar.classList.remove('visible-mobile');
            overlay.classList.add('hidden'); document.body.style.overflow = 'auto';
        }
    }
    function openLogoutModal() { document.getElementById('logoutModalUI').classList.remove('hidden'); document.getElementById('logoutModalUI').classList.add('flex'); }
    function closeLogoutModal() { document.getElementById('logoutModalUI').classList.add('hidden'); document.getElementById('logoutModalUI').classList.remove('flex'); }

    function openTermsModal() { document.getElementById('termsModalUI').classList.remove('hidden'); document.getElementById('termsModalUI').classList.add('flex'); }
    function closeTermsModal() { document.getElementById('termsModalUI').classList.add('hidden'); document.getElementById('termsModalUI').classList.remove('flex'); }
    function acceptTerms() { closeTermsModal(); }

    function openPrivacyModal() { document.getElementById('privacyModalUI').classList.remove('hidden'); document.getElementById('privacyModalUI').classList.add('flex'); }
    function closePrivacyModal() { document.getElementById('privacyModalUI').classList.add('hidden'); document.getElementById('privacyModalUI').classList.remove('flex'); }
    function acceptPrivacy() { closePrivacyModal(); }

    function clearNotification(type) {
        fetch('backend/mark_seen.php?type=' + type)
        .then(() => {
            const badge = document.getElementById('badge-' + type);
            if (badge) badge.style.display = 'none';
        });
    }

    // ===== LIVE UPDATE SYSTEM =====
    const POLL_INTERVAL = 4000; // Poll every 4 seconds
    let updateInterval = null;
    
    function startDashboardUpdates() {
        // Initial load
        fetchDashboardData();
        
        // Poll for updates
        updateInterval = setInterval(fetchDashboardData, POLL_INTERVAL);
    }
    
    function fetchDashboardData() {
        fetch('api/get-patient-dashboard-data.php')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    updateDashboardUI(data);
                }
            })
            .catch(err => console.log('Dashboard update check...'));
    }
    
    function updateDashboardUI(data) {
        // Update stat cards
        updateStatCard('totalVisits', data.totalVisits);
        updateStatCard('pendingBalance', '₱' + data.pendingBalance.toLocaleString('en-US', {minimumFractionDigits: 0}));
        
        // Update sidebar badges
        updateSidebarBadge('apptBadge', data.notifApptCount);
        updateSidebarBadge('billingBadge', data.notifBillingCount);
        updateSidebarBadge('treatmentBadge', data.notifTreatmentCount);
        updateSidebarBadge('complaintBadge', data.notifCompCount);
        updateSidebarBadge('inquiryBadge', data.notifInqCount);
    }
    
    function updateStatCard(elementId, value) {
        const element = document.querySelector(`[data-stat="${elementId}"]`);
        if (element) {
            const valueElement = element.querySelector('.stat-value');
            if (valueElement && valueElement.textContent !== String(value)) {
                valueElement.textContent = value;
                valueElement.style.animation = 'none';
                setTimeout(() => {
                    valueElement.style.animation = 'fadeIn 0.4s ease-out';
                }, 10);
            }
        }
    }
    
    function updateSidebarBadge(badgeId, count) {
        const badge = document.querySelector(`[data-badge="${badgeId}"]`);
        if (badge) {
            if (count > 0) {
                badge.textContent = count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
    }
    
    // Start updates when page loads
    window.addEventListener('DOMContentLoaded', startDashboardUpdates);
</script>
</body>
</html>
