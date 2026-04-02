<?php
session_start();
require_once "backend/config.php"; 

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'assistant') {
    header("Location: login.php");
    exit();
}

$fullName = $_SESSION['username'] ?? 'Assistant';
$displayFirstName = explode(' ', $_SESSION['full_name'] ?? $fullName)[0];
$countAppt = 0;       
$countBillings = 0;   
$countNew = 0;        
$resSchedule = false; 
$today = date('Y-m-d');
$currentTime = date('H:i');
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

// Fetch assistant profile picture (safely check if column exists)
$assistantID = $_SESSION['user_id'] ?? 0;
$checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
$columnExists = mysqli_query($conn, $checkColumnSQL);
if ($columnExists && mysqli_num_rows($columnExists) > 0) {
    $profileQuery = mysqli_query($conn, "SELECT profile_picture FROM patient_profiles WHERE user_id = '$assistantID'");
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

// --- SIDEBAR NOTIFICATION COUNTERS ---
$notifComplaints = 0;
$qComp = mysqli_query($conn, "SELECT id FROM patient_complaints WHERE status_id = (SELECT id FROM lookup_statuses WHERE status_name = 'Pending')");
if($qComp) $notifComplaints = mysqli_num_rows($qComp);

$notifInquiries = 0;
$qInq = mysqli_query($conn, "SELECT id FROM patient_inquiries WHERE status_id = (SELECT id FROM lookup_statuses WHERE status_name = 'Pending')");
if($qInq) $notifInquiries = mysqli_num_rows($qInq);

$notifBillingCount = 0;
$qBillCount = mysqli_query($conn, "SELECT a.id FROM appointments a JOIN lookup_statuses s ON a.status_id = s.id WHERE (s.status_name = 'Completed' OR s.status_name = 'Complete')");
if($qBillCount) $notifBillingCount = mysqli_num_rows($qBillCount);

$notifSchedule = 0;
$qSched = mysqli_query($conn, "SELECT id FROM appointments WHERE appointment_date = '$today' AND status_id = (SELECT id FROM lookup_statuses WHERE status_name = 'Pending')");
if($qSched) $notifSchedule = mysqli_num_rows($qSched);

$notifTreatmentCount = 0;
$qTreatment = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments a LEFT JOIN lookup_statuses s ON a.status_id = s.id LEFT JOIN treatment_records tr ON a.id = tr.appointment_id WHERE s.status_name = 'Paid' AND tr.appointment_id IS NULL");
if($qTreatment) {
    $tRow = mysqli_fetch_assoc($qTreatment);
    $notifTreatmentCount = $tRow['total'] ?? 0;
}
// ------------------------------------------

$filterDate = $_GET['date'] ?? $today; 
$search = $_GET['search'] ?? '';       

// --- DYNAMIC NEXT AVAILABLE SLOT LOGIC ---
$slots = ['09:00 AM','10:00 AM','11:00 AM','01:00 PM','02:00 PM','03:00 PM','04:00 PM'];
$nextSlot = "Fully Booked";

$found = false;
for ($i = 0; $i < 30; $i++) {
    $checkDate = date('Y-m-d', strtotime("+$i day"));
    $booked = [];
    $checkQ = mysqli_query($conn, "SELECT a.appointment_time 
                                   FROM appointments a 
                                   LEFT JOIN lookup_statuses s ON a.status_id = s.id 
                                   WHERE a.appointment_date = '$checkDate' 
                                   AND s.status_name NOT IN ('Rejected', 'Cancelled')");
    if ($checkQ) {
        while($row = mysqli_fetch_assoc($checkQ)) {
            $booked[] = $row['appointment_time'];
        }
    }
    foreach($slots as $s) {
        $slot24 = date('H:i', strtotime($s));
        if ($i == 0 && $slot24 <= $currentTime) continue;
        if (!in_array($s, $booked)) {
            if ($i == 0) { $nextSlot = $s; } 
            else { $nextSlot = date('M d', strtotime($checkDate)) . ", " . $s; }
            $found = true;
            break 2;
        }
    }
}

// Database Queries
if (!empty($search)) {
    $safeSearch = mysqli_real_escape_string($conn, $search);
    $searchTerms = explode(' ', $safeSearch);
    $conditions = [];
    
    foreach ($searchTerms as $term) {
        $term = trim($term);
        if ($term === '') continue;
        $conditions[] = "(
            a.patient_name LIKE '$term%' OR 
            u.first_name LIKE '$term%' OR 
            u.last_name LIKE '$term%' OR 
            CONCAT(u.first_name, ' ', u.last_name) LIKE '$term%' OR
            pr.procedure_name LIKE '$term%' OR
            s.status_name LIKE '$term%' OR
            a.appointment_time LIKE '$term%'
        )";
    }
    $whereSql = !empty($conditions) ? implode(' AND ', $conditions) : "1=1";

    $sql_appt = "SELECT a.*, 
                 u.first_name, u.last_name,
                 pr.procedure_name as reason, 
                 s.status_name as status
                 FROM appointments a 
                 LEFT JOIN users u ON a.patient_id = u.id 
                 LEFT JOIN procedures pr ON a.procedure_id = pr.id
                 LEFT JOIN lookup_statuses s ON a.status_id = s.id
                 WHERE ($whereSql) 
                 AND s.status_name != 'Rejected' 
                 ORDER BY a.patient_name ASC, a.appointment_date DESC, STR_TO_DATE(a.appointment_time, '%h:%i %p') ASC";
} else {
    $sql_appt = "SELECT a.*, 
                 u.first_name, u.last_name,
                 pr.procedure_name as reason, 
                 s.status_name as status
                 FROM appointments a 
                 LEFT JOIN users u ON a.patient_id = u.id 
                 LEFT JOIN procedures pr ON a.procedure_id = pr.id
                 LEFT JOIN lookup_statuses s ON a.status_id = s.id
                 WHERE a.appointment_date = '$filterDate' 
                 AND s.status_name NOT IN ('Paid', 'Rejected') 
                 ORDER BY STR_TO_DATE(a.appointment_time, '%h:%i %p') ASC, a.id ASC";
}

$result_appt = mysqli_query($conn, $sql_appt);
if ($result_appt) {
    $resSchedule = $result_appt; 
    $trueTodayQuery = mysqli_query($conn, "SELECT a.id FROM appointments a LEFT JOIN lookup_statuses s ON a.status_id = s.id WHERE a.appointment_date = '$today' AND s.status_name NOT IN ('Paid', 'Rejected')");
    if ($trueTodayQuery) $countAppt = mysqli_num_rows($trueTodayQuery);
}

// FIXED: Standardized Billing Calculation for Pending balance
$sql_bill = "SELECT 
                (SELECT IFNULL(SUM(pr.standard_cost), 0) 
                 FROM appointments a 
                 JOIN procedures pr ON a.procedure_id = pr.id
                 LEFT JOIN lookup_statuses s ON a.status_id = s.id 
                 WHERE (s.status_name = 'Completed' OR s.status_name = 'Complete' OR s.status_name = 'Paid')) - 
                (SELECT IFNULL(SUM(p.amount), 0) 
                 FROM payments p 
                 JOIN lookup_statuses s ON p.status_id = s.id 
                 WHERE (s.status_name = 'Completed' OR s.status_name = 'Complete')) as total";

$result_bill = @mysqli_query($conn, $sql_bill); 
if ($result_bill) {
    $row = mysqli_fetch_assoc($result_bill);
    $countBillings = (float)($row['total'] ?? 0);
    if ($countBillings < 0.01) $countBillings = 0;
}

$sql_new = "SELECT id FROM users WHERE role = 'patient' AND date(created_at) = '$today'";
$result_new = mysqli_query($conn, $sql_new);
if ($result_new) {
    $countNew = mysqli_num_rows($result_new);
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta charset="utf-8"/>
    <title>Assistant Dashboard - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
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
            --color-from: rgba(168, 85, 247, 0.08);
            --color-to: rgba(255, 255, 255, 0);
        }
        .grid > div:nth-child(4) { 
            animation-delay: 0.4s;
            --color-from: rgba(34, 197, 94, 0.08);
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

<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 font-display overflow-hidden text-sm transition-colors duration-200">
<div class="flex h-screen w-full overflow-hidden relative">

    <div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 bg-slate-900/50 z-30 hidden lg:hidden backdrop-blur-sm"></div>

    <aside id="sidebar" class="fixed lg:static inset-y-0 left-0 w-64 h-full flex flex-col bg-white dark:bg-[#1e293b] border-r border-slate-200 dark:border-slate-800 flex-shrink-0 z-40 hidden-mobile lg:translate-x-0 font-medium transition-colors duration-200 shadow-lg">
        <div class="p-4 flex items-center gap-3">
            <img src="assets/images/logo.png" alt="San Nicolas Dental Clinic" class="h-12 w-auto">
            <div>
                <h1 class="text-sm font-bold leading-tight text-slate-900 dark:text-white">San Nicolas</h1>
                <p class="text-[10px] text-slate-500 font-black">Assistant panel</p>
            </div>
        </div>

        <nav class="flex-1 px-4 py-4 gap-2 flex flex-col overflow-y-auto font-black text-sm">
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg bg-primary/10 text-primary shadow-sm transition-all" href="assistant-dashboard.php">
                <span class="material-symbols-outlined fill">dashboard</span>
                <span class="text-sm font-bold">Dashboard</span>
            </a>
            
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="my-profile.php">
                <span class="material-symbols-outlined">account_circle</span>
                <span class="text-sm font-bold">My Profile</span>
            </a>
            
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="schedule.php">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="text-sm font-bold">Schedule</span>
                <?php if($notifSchedule > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="schedBadge"><?php echo $notifSchedule; ?></span>
                <?php else: ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="schedBadge">0</span>
                <?php endif; ?>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="patients.php">
                <span class="material-symbols-outlined">groups</span>
                <span class="text-sm font-bold">Patient records</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="treatment-records.php">
                <span class="material-symbols-outlined">edit_document</span>
                <span class="text-sm font-bold">Treatment history</span>
                <?php if($notifTreatmentCount > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="treatmentBadge"><?php echo $notifTreatmentCount; ?></span>
                <?php else: ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="treatmentBadge">0</span>
                <?php endif; ?>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="record-payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="text-sm font-bold">Billing records</span>
                <?php if($notifBillingCount > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black"><?php echo $notifBillingCount; ?></span>
                <?php endif; ?>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="complaint.php">
                <span class="material-symbols-outlined">report_problem</span>
                <span class="text-sm font-bold">Patient complaints</span>
                <?php if($notifComplaints > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="complaintBadge"><?php echo $notifComplaints; ?></span>
                <?php else: ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="complaintBadge">0</span>
                <?php endif; ?>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-50 transition-colors" href="inquiry.php">
                <span class="material-symbols-outlined">contact_support</span>
                <span class="text-sm font-bold">Patient inquiries</span>
                <?php if($notifInquiries > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black" data-badge="inquiryBadge"><?php echo $notifInquiries; ?></span>
                <?php else: ?>
                    <span class="ml-auto bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] flex items-center justify-center rounded-full font-black hidden" data-badge="inquiryBadge">0</span>
                <?php endif; ?>
            </a>
        </nav>

        <div class="border-t border-slate-200 dark:border-slate-700 p-4">
            <a href="javascript:void(0);" onclick="openLogoutModal()" class="flex items-center gap-3 px-4 py-3 rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer transition-colors font-bold">
                <span class="material-symbols-outlined">logout</span>
                <span class="text-sm font-bold">Logout</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto h-full relative bg-[#f8fafc] dark:bg-background-dark text-slate-900 dark:text-white">
        <header class="lg:hidden flex items-center justify-between p-4 bg-white dark:bg-[#1e293b] border-b border-slate-200 dark:border-slate-800 sticky top-0 z-30 shadow-sm font-black">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center text-white shadow-sm">
                    <span class="material-symbols-outlined text-xl font-black">dentistry</span>
                </div>
                <span class="text-sm font-bold text-slate-900 dark:text-white">San Nicolas</span>
            </div>
            <button onclick="toggleSidebar()" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-lg shadow-inner transition-colors">
                <span class="material-symbols-outlined font-black">menu</span>
            </button>
        </header>

        <div class="p-6 md:p-10 max-w-[1600px] mx-auto flex flex-col gap-6 md:gap-10 animate-fade-in text-slate-900 dark:text-white">
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 animate-fade-in">
                <div class="space-y-1">
                    <h1 class="text-4xl md:text-5xl font-black tracking-tight">Hello, <?php echo htmlspecialchars($displayFirstName); ?> 👋</h1>
                    <p class="text-slate-500 text-lg font-bold">Practice overview and operational metrics.</p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right hidden sm:block">
                        <p class="text-sm font-bold"><?php echo date('l, M jS'); ?> • <span class="text-primary font-bold"><?php echo date('h:i A'); ?></span></p>
                        <p class="text-xs text-slate-500 font-bold">Staff ID: <span class="text-primary font-black">#<?php echo $_SESSION['user_id']; ?></span></p>
                    </div>
                    <div class="size-14 rounded-2xl bg-primary/10 text-primary flex items-center justify-center font-black text-xl border-2 border-white dark:border-slate-700 shadow-sm transition-transform hover:scale-110 uppercase overflow-hidden flex-shrink-0">
                        <?php if (!empty($profilePicture)): ?>
                            <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?php echo substr($fullName, 0, 1); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 font-bold">
                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-md relative overflow-hidden text-sm hover:shadow-lg transition-all">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-primary shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black">calendar_today</span></div>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black tracking-tight">Appointments today</p>
                    <h3 class="text-3xl md:text-4xl font-black mt-1 tracking-tight"><?php echo $countAppt; ?></h3>
                </div>

                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-sm shadow-md transition-all hover:scale-[1.02]">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black">payments</span></div>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black tracking-tight">Pending billings</p>
                    <h3 class="text-3xl md:text-4xl font-black mt-1 tracking-tight text-red-500">₱<?php echo number_format($countBillings, 0); ?></h3>
                </div>

                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-sm shadow-md transition-all hover:scale-[1.02]">
                    <div class="flex items-center justify-between mb-4">
                        <div class="size-12 rounded-xl bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center text-purple-500 shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black">person_add</span></div>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black tracking-tight">New patients (today)</p>
                    <h3 class="text-3xl md:text-4xl font-black mt-1 tracking-tight"><?php echo $countNew; ?></h3>
                </div>

                <div class="bg-white dark:bg-slate-800 p-6 md:p-8 rounded-[24px] border border-slate-200 dark:border-slate-700 shadow-sm ring-2 ring-primary/20 shadow-md transition-all hover:scale-[1.02]">
                    <div class="flex items-center justify-between mb-4 text-slate-900 dark:text-white">
                        <div class="size-12 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 shadow-sm transition-colors"><span class="material-symbols-outlined text-2xl font-black font-black">schedule</span></div>
                    </div>
                    <p class="text-primary text-[10px] font-black tracking-tight">Next available slot</p>
                    <h3 class="text-2xl md:text-3xl font-black mt-1 tracking-tighter tracking-tight"><?php echo $nextSlot; ?></h3>
                    <p class="text-[10px] text-slate-400 mt-1 font-bold italic transition-colors">Time: <?php echo date('h:i A'); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 md:gap-10">
                <div class="xl:col-span-2 flex flex-col gap-6">
                    <div class="flex items-center justify-between px-2 font-black">
                        <h3 class="text-xl flex items-center gap-2 tracking-tight font-black">
                            <span class="material-symbols-outlined text-primary font-black">event_list</span>
                            Daily schedule
                        </h3>
                        <a href="schedule.php" class="text-[10px] font-bold text-primary hover:underline transition-all tracking-tight">View full calendar</a>
                    </div>

                    <form method="GET" class="px-2 mb-2 flex flex-col sm:flex-row gap-3 font-bold">
                        <div class="flex flex-col gap-1 w-full sm:w-auto text-[10px]">
                            <label class="text-[10px] font-bold text-slate-400 tracking-tight">Filter date</label>
                            <input type="date" name="date" value="<?php echo $filterDate; ?>" class="h-10 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-bold focus:ring-primary text-slate-900 dark:text-white shadow-sm transition-all tracking-tight">
                        </div>
                        <div class="flex flex-col gap-1 flex-1 text-[10px]">
                            <label class="text-[10px] font-bold text-slate-400 tracking-tight">Starts-with search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search visit name, procedure, or status..." class="h-10 w-full px-4 rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-bold focus:ring-primary shadow-sm tracking-tight">
                        </div>
                        <div class="flex items-end text-[10px]">
                            <button type="submit" class="h-10 w-full sm:w-auto px-6 rounded-xl bg-primary text-white font-black text-xs hover:bg-blue-600 shadow-lg shadow-blue-500/30 transition-all tracking-tight">Find</button>
                        </div>
                    </form>

                    <div class="bg-white dark:bg-slate-800 rounded-[32px] border border-slate-200 dark:border-slate-700 p-6 md:p-8 shadow-md flex flex-col gap-6 shadow-sm">
                        <?php if($resSchedule && mysqli_num_rows($resSchedule) > 0): ?>
                            <?php while($appt = mysqli_fetch_assoc($resSchedule)): 
                                $displayVisitName = !empty($appt['patient_name']) ? $appt['patient_name'] : ($appt['first_name'] . ' ' . $appt['last_name']);
                            ?>
                            <div class="flex flex-col sm:flex-row gap-4 sm:gap-6 items-start animate-fade-in font-bold transition-all">
                                <p class="w-full sm:w-24 text-[10px] font-black text-slate-400 mt-2 text-left sm:text-right tracking-tight transition-colors">
                                    <?php echo date('M d', strtotime($appt['appointment_date'])); ?><br>
                                    <span class="text-slate-900 dark:text-white font-black tracking-tight"><?php echo htmlspecialchars($appt['appointment_time']); ?></span>
                                </p>
                                <div class="flex-1 w-full p-4 md:p-6 rounded-2xl border-l-4 border-primary bg-slate-50/50 dark:bg-slate-900/50 flex items-center justify-between shadow-sm transition-all hover:scale-[1.01] hover:bg-slate-100 dark:hover:bg-slate-900">
                                    <div class="space-y-1 font-bold">
                                        <h4 class="text-lg font-black text-slate-800 dark:text-white tracking-tight"><?php echo htmlspecialchars($displayVisitName); ?></h4>
                                        <p class="text-xs font-bold text-slate-500 tracking-tight transition-colors">
                                            <?php echo htmlspecialchars($appt['reason']); ?> • 
                                            <span class="text-primary font-black tracking-tight transition-colors font-black"><?php echo htmlspecialchars($appt['status']); ?></span>
                                        </p>
                                    </div>
                                    <div class="size-10 md:size-12 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center font-bold text-slate-400 border-2 border-white dark:border-slate-700 shrink-0 ml-4 shadow-sm transition-transform hover:scale-110 tracking-tight transition-colors">
                                        <?php echo substr($displayVisitName, 0, 1); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-20 rounded-[32px] shadow-inner font-bold transition-all">
                                <span class="material-symbols-outlined text-slate-300 text-6xl mb-4 font-black transition-colors">event_busy</span>
                                <p class="text-slate-500 font-bold italic transition-colors text-[10px]">No active schedules found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex flex-col gap-4 font-black transition-all">
                    <h3 class="text-lg font-black px-2 tracking-tight transition-colors font-black">Quick actions</h3>
                    <a href="schedule.php" class="h-14 w-full bg-primary text-white rounded-2xl font-black shadow-xl shadow-blue-500/30 hover:bg-blue-600 transition-all flex items-center justify-center gap-2 text-sm tracking-tight font-black">
                        <span class="material-symbols-outlined font-black">add</span> Book appointment
                    </a>
                    <a href="record-payment.php" class="h-14 w-full bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-2xl font-black hover:bg-slate-50 dark:hover:bg-slate-700 transition-all flex items-center justify-center gap-2 shadow-sm font-bold text-sm tracking-tight font-black">
                        <span class="material-symbols-outlined text-primary text-[20px] font-black font-black">payments</span> Record payment
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="logoutModalUI" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 backdrop-blur-md">
    <div class="fixed inset-0 bg-slate-950/60" onclick="closeLogoutModal()"></div>
    <div class="relative w-full max-w-[500px] bg-white dark:bg-slate-900 rounded-[32px] shadow-2xl overflow-hidden animate-fade-in duration-300">
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
        const filterDate = new URLSearchParams(window.location.search).get('date') || '<?php echo $today; ?>';
        const search = new URLSearchParams(window.location.search).get('search') || '';
        
        const params = new URLSearchParams({
            date: filterDate,
            search: search
        });
        
        fetch(`api/get-dashboard-data.php?${params}`)
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
        updateStatCard('countAppt', data.countAppt, data.countAppt);
        updateStatCard('countBillings', '$' + data.countBillings.toLocaleString('en-US', {minimumFractionDigits: 2}), data.countBillings);
        updateStatCard('countNew', data.countNew, data.countNew);
        
        // Update sidebar badges
        updateSidebarBadge('schedBadge', data.notifSchedule);
        updateSidebarBadge('complaintBadge', data.notifComplaints);
        updateSidebarBadge('inquiryBadge', data.notifInquiries);
        updateSidebarBadge('treatmentBadge', data.notifTreatmentCount);
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
    
    function updateAppointmentsTable(appointments) {
        const tbody = document.querySelector('table tbody');
        if (!tbody) return;
        
        // Remove rows that no longer exist
        const rows = Array.from(tbody.querySelectorAll('tr[data-appt-id]'));
        const newIds = appointments.map(a => String(a.id));
        
        rows.forEach(row => {
            const id = row.dataset.apptId;
            if (!newIds.includes(id)) {
                row.style.animation = 'fadeOut 0.3s ease-out';
                setTimeout(() => row.remove(), 300);
            }
        });
        
        // Update or add appointments
        appointments.forEach(appt => {
            const existingRow = tbody.querySelector(`tr[data-appt-id="${appt.id}"]`);
            if (existingRow) {
                updateAppointmentRow(existingRow, appt);
            } else {
                appendAppointmentRow(tbody, appt);
            }
        });
    }
    
    function updateAppointmentRow(row, appt) {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 5) {
            cells[0].textContent = new Date(appt.appointment_date).toLocaleDateString('en-US', {month: 'short', day: '2-digit', year: 'numeric'});
            cells[1].textContent = appt.appointment_time;
            cells[2].innerHTML = `<p class="font-black">${appt.patient_name || (appt.first_name + ' ' + appt.last_name)}</p>`;
            cells[3].innerHTML = `<span class="text-[10px] font-black text-slate-700 dark:text-slate-300 uppercase">${appt.reason || 'N/A'}</span>`;
            cells[4].innerHTML = getStatusBadgeHTML(appt.status);
            
            row.style.backgroundColor = 'rgba(34, 197, 94, 0.05)';
            setTimeout(() => row.style.backgroundColor = '', 1000);
        }
    }
    
    function appendAppointmentRow(tbody, appt) {
        const row = document.createElement('tr');
        row.dataset.apptId = appt.id;
        row.className = 'hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-all';
        
        const displayName = appt.patient_name || (appt.first_name + ' ' + appt.last_name);
        
        row.innerHTML = `
            <td class="px-6 py-4 text-sm whitespace-nowrap">${new Date(appt.appointment_date).toLocaleDateString('en-US', {month: 'short', day: '2-digit', year: 'numeric'})}</td>
            <td class="px-6 py-4 font-black text-sm">${appt.appointment_time}</td>
            <td class="px-6 py-4"><p class="font-black">${displayName}</p></td>
            <td class="px-6 py-4"><span class="text-[10px] font-black text-slate-700 dark:text-slate-300 uppercase">${appt.reason || 'N/A'}</span></td>
            <td class="px-6 py-4">${getStatusBadgeHTML(appt.status)}</td>
        `;
        
        row.style.animation = 'slideInUp 0.4s ease-out';
        tbody.appendChild(row);
    }
    
    function getStatusBadgeHTML(status) {
        const statusColors = {
            'Confirmed': 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
            'Completed': 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
            'Paid': 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
            'Pending': 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300'
        };
        
        const color = statusColors[status] || statusColors['Pending'];
        return `<span class="px-3 py-1.5 rounded-full text-[10px] font-black uppercase ${color} inline-block">${status}</span>`;
    }
    
    // Start updates when page loads
    window.addEventListener('DOMContentLoaded', startDashboardUpdates);
</script>
</body>
</html>
