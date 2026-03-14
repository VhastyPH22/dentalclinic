<?php 
session_start();
require_once 'backend/config.php';
require_once 'backend/middleware.php';

// Set Timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Use middleware for proper tenant isolation
checkAccess(['dentist', 'staff'], true);

$role = $_SESSION['role'];
$fullName = $_SESSION['full_name'] ?? 'Staff';

$msg = "";
$msgType = "";

// 2. FILTER CAPTURE
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-t');
$patientSearch = $_GET['patient_name'] ?? '';
$procFilter = $_GET['procedure_id'] ?? '';
$methodFilter = $_GET['payment_method_id'] ?? '';
$minAmtInput = $_GET['min_amt'] ?? '';
$maxAmtInput = $_GET['max_amt'] ?? '';

// Convert to float for logic checks
$minAmt = ($minAmtInput !== '') ? (float)$minAmtInput : 0;
$maxAmt = ($maxAmtInput !== '') ? (float)$maxAmtInput : 99999999;

// --- SERVER-SIDE VALIDATION ---
if ($fromDate > $toDate) {
    $msg = "Warning: Start date cannot be later than end date. Range reset to current month.";
    $msgType = "error";
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
}

// Check for negative numbers
if (($minAmtInput !== '' && $minAmt < 0) || ($maxAmtInput !== '' && $maxAmt < 0)) {
    $msg = "Warning: Financial amounts cannot be negative. Negative values have been adjusted to 0.";
    $msgType = "error";
    if ($minAmt < 0) { $minAmt = 0; $minAmtInput = '0'; }
    if ($maxAmt < 0) { $maxAmt = 0; $maxAmtInput = '0'; }
}

if (!empty($minAmtInput) && !empty($maxAmtInput) && $minAmt > $maxAmt) {
    $msg = "Warning: Minimum amount cannot be greater than maximum amount. Filters ignored.";
    $msgType = "error";
    $minAmt = 0; $maxAmt = 99999999;
}

// Calculate Display Labels
$displayPeriod = date("M d, Y", strtotime($fromDate)) . " - " . date("M d, Y", strtotime($toDate));

// --- 3. DYNAMIC QUERY BUILDING ---

// A. Filter Condition String (Payments) - with proper escaping and date handling
// Convert UTC timestamps to Manila timezone (UTC+8) before filtering
$payConditions = "p.tenant_id = " . getTenantId() . " AND DATE(CONVERT_TZ(p.payment_date, '+00:00', '+08:00')) BETWEEN '" . mysqli_real_escape_string($conn, $fromDate) . "' AND '" . mysqli_real_escape_string($conn, $toDate) . "'";
if(!empty($patientSearch)) $payConditions .= " AND (u.first_name LIKE '" . mysqli_real_escape_string($conn, "%$patientSearch%") . "' OR u.last_name LIKE '" . mysqli_real_escape_string($conn, "%$patientSearch%") . "')";
if($minAmt > 0 || $maxAmt < 99999999) $payConditions .= " AND p.amount BETWEEN " . (float)$minAmt . " AND " . (float)$maxAmt;
if(!empty($methodFilter)) $payConditions .= " AND p.payment_method_id = " . (int)mysqli_real_escape_string($conn, $methodFilter);

// B. Filter Condition String (Treatments/Analytics) - with proper escaping and date handling
// Convert UTC timestamps to Manila timezone (UTC+8) before filtering
$treatConditions = "tr.tenant_id = " . getTenantId() . " AND DATE(CONVERT_TZ(tr.treatment_date, '+00:00', '+08:00')) BETWEEN '" . mysqli_real_escape_string($conn, $fromDate) . "' AND '" . mysqli_real_escape_string($conn, $toDate) . "'";
if(!empty($procFilter)) $treatConditions .= " AND tr.procedure_id = " . (int)mysqli_real_escape_string($conn, $procFilter);

// --- 4. DATA AGGREGATION QUERIES ---

// I. Revenue
$revenueSql = "SELECT COALESCE(SUM(p.amount), 0) as total FROM payments p LEFT JOIN users u ON p.patient_id = u.id WHERE $payConditions" . getTenantFilter('p');
$revQ = mysqli_query($conn, $revenueSql);
if(!$revQ) {
    $totalRevenue = 0;
    error_log("Revenue query error: " . mysqli_error($conn) . " | SQL: " . $revenueSql);
} else {
    $revRow = mysqli_fetch_assoc($revQ);
    $totalRevenue = (float)($revRow['total'] ?? 0);
}

// II. Appointments count
// Convert UTC timestamps to Manila timezone (UTC+8) before filtering
$apptSql = "SELECT COUNT(*) as total FROM appointments WHERE DATE(CONVERT_TZ(appointment_date, '+00:00', '+08:00')) BETWEEN '" . mysqli_real_escape_string($conn, $fromDate) . "' AND '" . mysqli_real_escape_string($conn, $toDate) . "'" . getTenantFilter();
$apptQ = mysqli_query($conn, $apptSql);
if(!$apptQ) {
    $totalAppts = 0;
    error_log("Appointments query error: " . mysqli_error($conn) . " | SQL: " . $apptSql);
} else {
    $apptRow = mysqli_fetch_assoc($apptQ);
    $totalAppts = (int)($apptRow['total'] ?? 0);
}

// III. New Patients Added (Count unique patients with appointments in date range)
// Convert UTC timestamps to Manila timezone (UTC+8) before filtering
$patSql = "SELECT COUNT(DISTINCT a.patient_id) as total FROM appointments a WHERE DATE(CONVERT_TZ(a.appointment_date, '+00:00', '+08:00')) BETWEEN '" . mysqli_real_escape_string($conn, $fromDate) . "' AND '" . mysqli_real_escape_string($conn, $toDate) . "'" . getTenantFilter('a');
$patQ = mysqli_query($conn, $patSql);
if(!$patQ) {
    $newPatients = 0;
    error_log("New patients query error: " . mysqli_error($conn) . " | SQL: " . $patSql);
} else {
    $newPatientsData = mysqli_fetch_assoc($patQ);
    $newPatients = (int)($newPatientsData['total'] ?? 0);
}

// IV. Detailed Transaction Table (removed LIMIT to show all matching records for accuracy)
$summarySql = "SELECT p.*, u.first_name, u.last_name, pm.method_name as method 
                FROM payments p
                LEFT JOIN users u ON p.patient_id = u.id
                LEFT JOIN lookup_payment_methods pm ON p.payment_method_id = pm.id
WHERE $payConditions
                ORDER BY p.payment_date DESC";
$paymentsResult = mysqli_query($conn, $summarySql);

// V. Service Analytics (removed LIMIT to show all services for complete accuracy)
$analyticsSql = "SELECT pr.procedure_name, COUNT(tr.id) as bookings, SUM(tr.actual_cost) as revenue
                 FROM treatment_records tr
                 JOIN procedures pr ON tr.procedure_id = pr.id
                 WHERE $treatConditions
                 GROUP BY pr.id, pr.procedure_name
                 ORDER BY revenue DESC";
$analyticsResult = mysqli_query($conn, $analyticsSql);

// VI. Fetch Procedures for filter dropdown
$procListQ = mysqli_query($conn, "SELECT id, procedure_name FROM procedures ORDER BY procedure_name ASC");

// VII. Fetch Payment Methods for filter dropdown (dynamic, not hardcoded)
$methodListQ = mysqli_query($conn, "SELECT id, method_name FROM lookup_payment_methods ORDER BY method_name ASC");

// Navigation link back to sidebar-enabled dashboard
$dashboardLink = ($role === 'dentist') ? 'dentist-dashboard.php' : 'assistant-dashboard.php';
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>Advanced Clinic Reports - San Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: { "primary": "#1e3a5f", "primary-hover": "#152a45", "accent": "#d4a84b", "background-light": "#f6f7f8", "background-dark": "#101922" },
                    fontFamily: { "display": ["Manrope", "sans-serif"] }
                }
            }
        }
    </script>
    <style>
        * { scroll-behavior: smooth; }
        html { scroll-behavior: smooth; }
        
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        
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
                transform: translateY(25px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .stat-card {
            animation: scaleIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        
        .content-section {
            animation: slideInUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        
        .content-section:nth-child(5) { animation-delay: 0.1s; }
        .content-section:nth-child(6) { animation-delay: 0.2s; }
        
        body {
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        a, button {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        header {
            animation: slideInDown 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        input, select {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        input:focus, select:focus {
            transform: translateY(-1px);
        }
        
        .rounded-\[32px\], .rounded-3xl, .rounded-2xl, .rounded-lg {
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
        
        .custom-scrollbar::-webkit-scrollbar { width: 8px; height: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #137fec; }
        
        /* Enhanced Table Styling */
        table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
        }
        table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .dark table tbody tr:nth-child(even) {
            background-color: #1e293b;
        }
        table tbody tr:hover {
            background-color: #eff6ff;
            transform: none;
        }
        .dark table tbody tr:hover {
            background-color: #0f172a;
        }
        
        /* Badge Enhancement */
        .badge-stat {
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.05em;
            padding: 6px 12px;
            border-radius: 20px;
            display: inline-block;
        }
        
        /* Table header enhancement */
        table thead th {
            font-weight: 900;
            font-size: 11px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            background-color: #f1f5f9;
            color: #64748b;
            padding: 16px 12px;
            text-align: left;
        }
        .dark table thead th {
            background-color: #1e293b;
            color: #94a3b8;
        }
        @media print {
            .no-print { display: none !important; }
            body, html { height: auto !important; overflow: visible !important; background: white !important; color: black !important; }
            .flex-col { display: block !important; }
            main { padding: 0 !important; max-width: 100% !important; margin: 0 !important; overflow: visible !important; height: auto !important; }
            header { border: none !important; padding: clamp(12px, 3vw, 20px) 0 !important; position: relative !important; }
            .grid { display: grid !important; }
            .bg-white { background-color: white !important; border: 1px solid #e2e8f0 !important; }
            .bg-slate-50 { background-color: #f8fafc !important; }
            h2, h3, h4, p { color: black !important; }
            .max-h-\[500px\] { max-height: none !important; overflow: visible !important; }
        }
    </style>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-[#0d141b] dark:text-slate-100 h-screen overflow-hidden text-sm transition-colors">

<div class="flex flex-col h-full w-full">
    <header class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-10 py-4 sticky top-0 z-50 shrink-0">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-3">
                <div class="size-8 bg-primary rounded-lg flex items-center justify-center text-white shadow-sm">
                    <span class="material-symbols-outlined text-xl font-black">dentistry</span>
                </div>
                <h2 class="text-slate-900 dark:text-white text-lg font-black uppercase tracking-tight">Clinical Reports</h2>
            </div>
            <div class="h-6 w-px bg-slate-200 dark:bg-slate-800 no-print"></div>
            <span class="text-slate-500 font-bold uppercase text-[10px] tracking-widest transition-colors"><?php echo $displayPeriod; ?></span>
        </div>
        
        <div class="flex items-center gap-4 no-print">
            <button onclick="window.print()" class="flex items-center gap-2 px-6 py-2.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold text-[10px] uppercase shadow-sm hover:bg-slate-200 dark:hover:bg-slate-700 transition-all active:scale-95">
                <span class="material-symbols-outlined text-sm font-black">print</span>
                Print Report
            </button>
            <button onclick="openBackModal()" class="flex items-center gap-2 px-6 py-2.5 rounded-lg bg-gradient-to-r from-blue-600 to-blue-700 text-white font-bold text-[10px] uppercase shadow-lg hover:from-blue-700 hover:to-blue-800 hover:shadow-xl transition-all active:scale-95">
                <span class="material-symbols-outlined text-sm font-black">arrow_back</span>
                Dashboard
            </button>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto custom-scrollbar">
        <div class="max-w-7xl mx-auto w-full px-10 py-8 flex flex-col gap-8 animate-fade-in">
            
            <?php if (!empty($msg)): ?>
                <div class="p-4 rounded-2xl border-2 flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 animate-fade-in">
                    <span class="material-symbols-outlined text-red-600 text-2xl font-black">error</span>
                    <p class="font-black text-sm text-red-800 dark:text-red-200"><?php echo htmlspecialchars($msg); ?></p>
                </div>
            <?php endif; ?>

            <div class="no-print bg-white dark:bg-slate-900 rounded-[20px] border border-slate-200 dark:border-slate-800 p-8 shadow-sm overflow-hidden transition-all hover:shadow-md">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-8 gap-4">
                    <h4 class="flex items-center gap-2 font-black uppercase text-[11px] tracking-wider text-slate-700 dark:text-slate-300"><span class="material-symbols-outlined text-base font-black">tune</span>Filter Records</h4>
                    
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" onclick="setPreset('daily')" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-blue-500 hover:text-white transition-all font-bold text-[9px] uppercase tracking-wide">Today</button>
                        <button type="button" onclick="setPreset('weekly')" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-blue-500 hover:text-white transition-all font-bold text-[9px] uppercase tracking-wide">Week</button>
                        <button type="button" onclick="setPreset('monthly')" class="px-3 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 hover:bg-blue-500 hover:text-white transition-all font-bold text-[9px] uppercase tracking-wide">Month</button>
                        <div class="w-px h-5 bg-slate-300 dark:bg-slate-600 mx-1"></div>
                        <a href="reports.php" class="text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-[9px] uppercase tracking-wide transition-all">Reset</a>
                    </div>
                </div>
                
                <form id="filterForm" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6" onsubmit="return validateFilters()">
                    <div class="space-y-2 font-black uppercase">
                        <label class="text-[9px] text-slate-500 dark:text-slate-400 tracking-wider font-bold transition-colors">Start Date</label>
                        <input type="date" id="from_date" name="from_date" value="<?php echo $fromDate; ?>" class="h-12 w-full rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-800/50 text-sm font-semibold shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all hover:border-slate-300 dark:hover:border-slate-600">
                    </div>
                    <div class="space-y-2 font-black uppercase">
                        <label class="text-[9px] text-slate-500 dark:text-slate-400 tracking-wider font-bold transition-colors">End Date</label>
                        <input type="date" id="to_date" name="to_date" value="<?php echo $toDate; ?>" class="h-12 w-full rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-800/50 text-sm font-semibold shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all hover:border-slate-300 dark:hover:border-slate-600">
                    </div>
                    <div class="space-y-2 font-black uppercase">
                        <label class="text-[9px] text-slate-500 dark:text-slate-400 tracking-wider font-bold transition-colors">Min Amount (PHP)</label>
                        <input type="number" id="min_amt" name="min_amt" value="<?php echo $minAmtInput; ?>" placeholder="0.00" min="0" step="0.01" class="h-12 w-full rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-800/50 text-sm font-semibold shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all hover:border-slate-300 dark:hover:border-slate-600">
                    </div>
                    <div class="space-y-2 font-black uppercase">
                        <label class="text-[9px] text-slate-500 dark:text-slate-400 tracking-wider font-bold transition-colors">Max Amount (PHP)</label>
                        <input type="number" id="max_amt" name="max_amt" value="<?php echo $maxAmtInput; ?>" placeholder="999,999" min="0" step="0.01" class="h-12 w-full rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-800/50 text-sm font-semibold shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all hover:border-slate-300 dark:hover:border-slate-600">
                    </div>
                    <div class="space-y-2 font-black uppercase">
                        <label class="text-[9px] text-slate-500 dark:text-slate-400 tracking-wider font-bold transition-colors">Patient Name</label>
                        <input type="text" name="patient_name" value="<?php echo htmlspecialchars($_GET['patient_name'] ?? ''); ?>" placeholder="Enter name..." class="h-12 w-full rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-800/50 text-sm font-semibold shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all hover:border-slate-300 dark:hover:border-slate-600">
                    </div>
                    <div class="space-y-2 font-black uppercase">
                        <label class="text-[9px] text-slate-500 dark:text-slate-400 tracking-wider font-bold transition-colors">Procedure</label>
                        <select name="procedure_id" class="h-12 w-full rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-800/50 text-sm font-semibold shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all hover:border-slate-300 dark:hover:border-slate-600">
                            <option value="">All Services</option>
                            <?php mysqli_data_seek($procListQ, 0); while($pr = mysqli_fetch_assoc($procListQ)): ?>
                                <option value="<?php echo $pr['id']; ?>" <?php echo ($procFilter == $pr['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pr['procedure_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="space-y-2 font-black uppercase">
                        <label class="text-[9px] text-slate-500 dark:text-slate-400 tracking-wider font-bold transition-colors">Payment Method</label>
                        <select name="payment_method_id" class="h-12 w-full rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-800/50 text-sm font-semibold shadow-sm focus:ring-2 focus:ring-primary focus:border-primary transition-all hover:border-slate-300 dark:hover:border-slate-600">
                            <option value="">All Methods</option>
                            <?php mysqli_data_seek($methodListQ, 0); while($pm = mysqli_fetch_assoc($methodListQ)): ?>
                                <option value="<?php echo $pm['id']; ?>" <?php echo ($methodFilter == $pm['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pm['method_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="flex flex-col justify-end">
                        <button type="submit" class="h-12 w-full bg-gradient-to-r from-primary to-blue-600 text-white rounded-lg font-black uppercase text-[10px] hover:from-primary hover:to-blue-700 shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-2 group">
                            <span class="material-symbols-outlined text-sm font-black group-hover:scale-110 transition-transform">analytics</span> Update Analysis
                        </button>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="stat-card p-8 rounded-[20px] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-md hover:shadow-xl hover:-translate-y-1 transition-all duration-300 relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-500/3 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="flex justify-between items-start mb-6 relative z-10">
                        <div class="p-3 rounded-[12px] bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-900/20 group-hover:from-blue-200 dark:group-hover:from-blue-900/40 transition-colors duration-300">
                            <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 font-black text-2xl">payments</span>
                        </div>
                        <span class="text-xs font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider bg-blue-50 dark:bg-blue-900/30 px-3 py-1.5 rounded-lg">REVENUE</span>
                    </div>
                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 relative z-10">Gross Revenue Collected</p>
                    <h3 class="text-3xl font-black tracking-tight relative z-10 text-slate-900 dark:text-white group-hover:text-blue-600 transition-colors duration-300">₱<?php echo number_format($totalRevenue, 2); ?></h3>
                    <div class="absolute -right-12 -bottom-12 size-40 bg-blue-100/30 dark:bg-blue-500/10 rounded-full blur-3xl group-hover:bg-blue-100/50 dark:group-hover:bg-blue-500/15 transition-all duration-300"></div>
                </div>
                
                <div class="stat-card p-8 rounded-[20px] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-md hover:shadow-xl hover:-translate-y-1 transition-all duration-300 relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-cyan-500/3 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="flex justify-between items-start mb-6 relative z-10">
                        <div class="p-3 rounded-[12px] bg-gradient-to-br from-cyan-100 to-cyan-50 dark:from-cyan-900/30 dark:to-cyan-900/20 group-hover:from-cyan-200 dark:group-hover:from-cyan-900/40 transition-colors duration-300">
                            <span class="material-symbols-outlined text-cyan-600 dark:text-cyan-400 font-black text-2xl">calendar_month</span>
                        </div>
                        <span class="text-xs font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider bg-cyan-50 dark:bg-cyan-900/30 px-3 py-1.5 rounded-lg">QUEUE</span>
                    </div>
                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 relative z-10">Total Appointments</p>
                    <h3 class="text-3xl font-black tracking-tight text-slate-900 dark:text-white group-hover:text-cyan-600 transition-colors duration-300 relative z-10"><?php echo number_format($totalAppts); ?> <span class="text-xs text-slate-500 dark:text-slate-400 uppercase font-bold tracking-wider"></span></h3>
                    <div class="absolute -right-12 -bottom-12 size-40 bg-cyan-100/30 dark:bg-cyan-500/10 rounded-full blur-3xl group-hover:bg-cyan-100/50 dark:group-hover:bg-cyan-500/15 transition-all duration-300"></div>
                </div>
                
                <div class="stat-card p-8 rounded-[20px] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-md hover:shadow-xl hover:-translate-y-1 transition-all duration-300 relative overflow-hidden group">
                    <div class="absolute inset-0 bg-gradient-to-br from-emerald-500/3 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                    <div class="flex justify-between items-start mb-6 relative z-10">
                        <div class="p-3 rounded-[12px] bg-gradient-to-br from-emerald-100 to-emerald-50 dark:from-emerald-900/30 dark:to-emerald-900/20 group-hover:from-emerald-200 dark:group-hover:from-emerald-900/40 transition-colors duration-300">
                            <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 font-black text-2xl">group_add</span>
                        </div>
                        <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider bg-emerald-50 dark:bg-emerald-900/30 px-3 py-1.5 rounded-lg">NEW</span>
                    </div>
                    <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2 relative z-10">Patient Acquisitions</p>
                    <h3 class="text-3xl font-black tracking-tight text-slate-900 dark:text-white group-hover:text-emerald-600 transition-colors duration-300 relative z-10"><?php echo number_format($newPatients); ?></h3>
                    <div class="absolute -right-12 -bottom-12 size-40 bg-emerald-100/30 dark:bg-emerald-500/10 rounded-full blur-3xl group-hover:bg-emerald-100/50 dark:group-hover:bg-emerald-500/15 transition-all duration-300"></div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="content-section bg-white dark:bg-slate-900 rounded-[20px] border border-slate-200 dark:border-slate-800 overflow-hidden shadow-md hover:shadow-xl flex flex-col transition-all duration-300 group">
                    <div class="px-8 py-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-gradient-to-r from-slate-50 to-white dark:from-slate-800/50 dark:to-slate-900 group-hover:from-blue-50/30 dark:group-hover:from-slate-800/70 transition-colors duration-300">
                        <h4 class="font-black uppercase tracking-tight flex items-center gap-3 text-slate-900 dark:text-white group-hover:text-primary transition-colors">
                            <div class="p-2 rounded-lg bg-primary/10 group-hover:bg-primary/20 transition-colors duration-300">
                                <span class="material-symbols-outlined text-primary font-black text-lg">receipt_long</span>
                            </div>
                            Financial Transactions
                        </h4>
                    </div>
                    <div class="max-h-[500px] overflow-y-auto custom-scrollbar transition-all">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-400 font-black uppercase text-[10px] tracking-widest sticky top-0 z-10 backdrop-blur-sm transition-colors">
                                <tr class="border-b border-slate-100 dark:border-slate-700">
                                    <th class="px-8 py-4">Date</th>
                                    <th class="px-8 py-4">Patient</th>
                                    <th class="px-8 py-4 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-bold transition-colors">
                                <?php if(mysqli_num_rows($paymentsResult) > 0): ?>
                                    <?php $idx = 0; while($row = mysqli_fetch_assoc($paymentsResult)): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-all duration-200 group/row border-b border-slate-50 dark:border-slate-800/50" style="animation: slideInUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; animation-delay: <?php echo ($idx * 0.03); ?>s;">
                                        <td class="px-8 py-4 text-slate-500 font-mono transition-colors group-hover/row:text-slate-700 dark:group-hover/row:text-slate-300">
                                            <?php 
                                                $dt = new DateTime($row['payment_date'], new DateTimeZone('UTC'));
                                                $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                                                echo $dt->format('M d');
                                            ?>
                                        </td>
                                        <td class="px-8 py-4 text-slate-900 dark:text-white uppercase text-xs font-black transition-colors group-hover/row:text-primary"><?php echo htmlspecialchars(substr($row['first_name'] . ' ' . $row['last_name'], 0, 20)); ?></td>
                                        <td class="px-8 py-4 text-right text-primary font-black transition-colors group-hover/row:text-primary group-hover/row:scale-110 origin-right">₱<?php echo number_format($row['amount'], 2); ?></td>
                                    </tr>
                                    <?php $idx++; endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="p-12 text-center text-slate-400 font-black uppercase text-[10px] italic transition-colors">No transaction records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="content-section bg-white dark:bg-slate-900 rounded-[20px] border border-slate-200 dark:border-slate-800 overflow-hidden shadow-md hover:shadow-xl flex flex-col transition-all duration-300 group">
                    <div class="px-8 py-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-gradient-to-r from-slate-50 to-white dark:from-slate-800/50 dark:to-slate-900 group-hover:from-green-50/30 dark:group-hover:from-slate-800/70 transition-colors duration-300">
                        <h4 class="font-black uppercase tracking-tight flex items-center gap-3 text-slate-900 dark:text-white group-hover:text-green-600 transition-colors">
                            <div class="p-2 rounded-lg bg-green-100/50 dark:bg-green-900/30 group-hover:bg-green-200/50 dark:group-hover:bg-green-900/50 transition-colors duration-300">
                                <span class="material-symbols-outlined text-green-600 font-black text-lg">bar_chart</span>
                            </div>
                            Service Contribution
                        </h4>
                    </div>
                    <div class="max-h-[500px] overflow-y-auto custom-scrollbar transition-all">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-400 font-black uppercase text-[10px] tracking-widest sticky top-0 z-10 backdrop-blur-sm transition-colors">
                                <tr class="border-b border-slate-100 dark:border-slate-700">
                                    <th class="px-8 py-4">Service</th>
                                    <th class="px-8 py-4 text-center">Cases</th>
                                    <th class="px-8 py-4 text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-bold transition-colors">
                                <?php if(mysqli_num_rows($analyticsResult) > 0): ?>
                                    <?php $idx = 0; while($row = mysqli_fetch_assoc($analyticsResult)): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-all duration-200 group/row border-b border-slate-50 dark:border-slate-800/50" style="animation: slideInUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; animation-delay: <?php echo ($idx * 0.03); ?>s;">
                                        <td class="px-8 py-4 text-slate-900 dark:text-white uppercase text-xs font-black transition-colors group-hover/row:text-green-600"><?php echo htmlspecialchars(substr($row['procedure_name'], 0, 25)); ?></td>
                                        <td class="px-8 py-4 text-center text-slate-500 font-mono transition-colors group-hover/row:text-slate-900 dark:group-hover/row:text-white"><span class="inline-block px-3 py-1 bg-slate-100 dark:bg-slate-800 rounded-lg group-hover/row:bg-green-100 dark:group-hover/row:bg-green-900/30 transition-colors"><?php echo $row['bookings']; ?></span></td>
                                        <td class="px-8 py-4 text-right text-green-600 font-black transition-colors group-hover/row:text-green-700 dark:group-hover/row:text-green-400 group-hover/row:scale-110 origin-right">₱<?php echo number_format($row['revenue'], 2); ?></td>
                                    </tr>
                                    <?php $idx++; endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="p-12 text-center text-slate-400 font-black uppercase text-[10px] italic transition-colors">No active clinical services logged.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <footer class="mt-12 pt-8 border-t border-slate-200 dark:border-slate-800 text-center py-8 no-print">
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest transition-colors">San Nicolas Dental Clinic • Advanced Reporting System • <?php echo date('Y'); ?></p>
            </footer>
        </div>
    </main>
</div>

<div id="validationModal" class="fixed inset-0 z-[110] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 font-black uppercase">
    <div class="bg-white dark:bg-slate-800 rounded-[20px] shadow-2xl p-10 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 font-black transition-all" id="validationModalContent">
        <div class="text-center font-black">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-50 dark:bg-red-900/20 mb-6 shadow-sm"><span class="material-symbols-outlined text-3xl text-red-600 font-black transition-colors">report</span></div>
            <h3 class="text-xl font-black text-slate-900 dark:text-white mb-2 uppercase tracking-tight transition-colors">Invalid Selection</h3>
            <p id="validationMsg" class="text-sm text-slate-500 dark:text-slate-400 mb-8 px-4 font-bold tracking-tight transition-colors">-</p>
            <div class="flex gap-3 justify-center font-black">
                <button onclick="closeM('validationModal')" class="flex-1 py-4 rounded-xl bg-primary text-white font-bold flex items-center justify-center uppercase text-xs shadow-xl transition-all hover:bg-blue-600">Got it</button>
            </div>
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
    // NEW: JS HANDLER FOR DATE PRESETS
   function setPreset(type) {
    const fromInput = document.getElementById('from_date');
    const toInput = document.getElementById('to_date');
    const now = new Date();
    
    // Helper to format date as YYYY-MM-DD using LOCAL time
    const formatDate = (date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };

    if (type === 'daily') {
        const today = formatDate(now);
        fromInput.value = today;
        toInput.value = today;
    } else if (type === 'weekly') {
        // Calculate Sunday as the start of the current week
        const first = new Date(now);
        first.setDate(now.getDate() - now.getDay());
        
        // Calculate Saturday as the end of the current week
        const last = new Date(first);
        last.setDate(first.getDate() + 6);
        
        fromInput.value = formatDate(first);
        toInput.value = formatDate(last);
    } else if (type === 'monthly') {
        // First day of the current month
        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        // Last day of the current month
        const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        
        fromInput.value = formatDate(firstDay);
        toInput.value = formatDate(lastDay);
    }

    // Submit form to trigger the PHP SQL queries
    document.getElementById('filterForm').submit();
}

    function validateFilters() {
        const fromDate = document.getElementById('from_date').value;
        const toDate = document.getElementById('to_date').value;
        const minAmtInput = document.getElementById('min_amt').value;
        const maxAmtInput = document.getElementById('max_amt').value;
        const minAmt = parseFloat(minAmtInput);
        const maxAmt = parseFloat(maxAmtInput);
        const modal = document.getElementById('validationModal');
        const msgEl = document.getElementById('validationMsg');
        if (fromDate && toDate && fromDate > toDate) {
            msgEl.innerText = "The start date cannot be after the end date. Please adjust your range.";
            showM('validationModal', 'validationModalContent');
            return false;
        }
        if ((minAmtInput !== '' && minAmt < 0) || (maxAmtInput !== '' && maxAmt < 0)) {
            msgEl.innerText = "Amounts cannot be negative. Please enter a value of 0 or greater.";
            showM('validationModal', 'validationModalContent');
            return false;
        }
        if (minAmtInput !== '' && maxAmtInput !== '' && minAmt > maxAmt) {
            msgEl.innerText = "The minimum amount cannot exceed the maximum amount.";
            showM('validationModal', 'validationModalContent');
            return false;
        }
        return true;
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
</script>
</body>
</html>
