<?php 
session_start();

/**
 * 1. DATABASE CONNECTION & CONFIG
 */
require_once __DIR__ . '/backend/config.php'; 

// Set Timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Error Reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * 2. SECURITY & ROLE ACCESS
 */
$middleware = file_exists(__DIR__ . '/backend/middleware.php') ? __DIR__ . '/backend/middleware.php' : (file_exists(__DIR__ . '/middleware.php') ? __DIR__ . '/middleware.php' : null);
if ($middleware) { require_once $middleware; }

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['assistant', 'patient', 'dentist'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$fullName = $_SESSION['full_name'] ?? 'User';
$currentUserID = $_SESSION['user_id'] ?? 0;

$view = $_GET['view'] ?? 'list';
$editID = $_GET['id'] ?? 0;

if(!isset($_GET['view']) && ($role === 'dentist' || $role === 'assistant')) {
    $view = 'list'; 
}

if (($role === 'patient' || $role === 'assistant') && ($view === 'add' || $view === 'edit')) {
    $view = 'list';
}

$dashboardLink = 'patient-dashboard.php';
if ($role === 'dentist') $dashboardLink = 'dentist-dashboard.php';
elseif ($role === 'assistant') $dashboardLink = 'assistant-dashboard.php';

$patientsList = [];
if ($role === 'dentist') {
    // Robust status detection and FIFO balance calculation per appointment
    $pQuery = "SELECT 
                a.id as appointment_id, 
                a.patient_name,
                u.id as account_id, 
                u.username, 
                u.first_name,
                u.last_name,
                IFNULL(a.patient_name, CONCAT(u.first_name, ' ', u.last_name)) as booked_name, 
                pr.procedure_name, 
                pr.standard_cost, 
                a.appointment_date,
                (
                    (SELECT IFNULL(SUM(pr2.standard_cost), 0) 
                     FROM appointments a2 
                     JOIN procedures pr2 ON a2.procedure_id = pr2.id 
                     JOIN lookup_statuses s2 ON a2.status_id = s2.id
                     WHERE a2.patient_id = u.id 
                     AND (s2.status_name = 'Completed' OR s2.status_name = 'Complete' OR s2.status_name = 'Paid')
                     AND (a2.appointment_date < a.appointment_date OR (a2.appointment_date = a.appointment_date AND a2.id <= a.id))
                    ) - 
                    (SELECT IFNULL(SUM(p.amount), 0) 
                     FROM payments p 
                     JOIN lookup_statuses s ON p.status_id = s.id 
                     WHERE p.patient_id = u.id AND (s.status_name = 'Completed' OR s.status_name = 'Complete'))
                ) as remaining_balance_for_this_appt
               FROM users u 
               JOIN appointments a ON a.patient_id = u.id
               LEFT JOIN procedures pr ON a.procedure_id = pr.id
               JOIN lookup_statuses s ON a.status_id = s.id
               WHERE u.role = 'patient'
               AND (s.status_name = 'Completed' OR s.status_name = 'Complete')
               AND s.status_name != 'Paid'
               HAVING remaining_balance_for_this_appt > 0.01
               ORDER BY a.appointment_date ASC, a.id ASC";
               
    $pRes = mysqli_query($conn, $pQuery);
    if ($pRes) {
        while($pRow = mysqli_fetch_assoc($pRes)) {
            $patientsList[] = $pRow;
        }
    }
}

/**
 * 4. HANDLE FORM SUBMISSIONS (CRUD)
 */
$msg = "";
$msgType = "";

if (isset($_POST['delete_payment']) && $role === 'dentist') {
    $delID = mysqli_real_escape_string($conn, $_POST['payment_id']);
    if (mysqli_query($conn, "DELETE FROM payments WHERE id = '$delID'")) {
        header("Location: record-payment.php?notif=deleted");
        exit();
    } else {
        $msg = "Error deleting record: " . mysqli_error($conn); $msgType = "error";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_payment'])) {
    if ($role !== 'dentist') {
        $msg = "Error: Unauthorized action."; $msgType = "error";
    } else {
        if (empty($_POST['billing_info'])) {
            $msg = "Error: Please select a patient from the treated list."; $msgType = "error";
        } elseif (empty($_POST['payment_type'])) {
            $msg = "Error: Please select a payment classification."; $msgType = "error";
        } elseif (empty($_POST['payment_method'])) {
            $msg = "Error: Please select a payment method."; $msgType = "error";
        } elseif (empty($_POST['amount']) || !is_numeric($_POST['amount']) || $_POST['amount'] <= 0) {
            $msg = "Error: Please enter a valid positive payment amount."; $msgType = "error";
        } elseif (empty($_POST['invoice_ref'])) {
            $msg = "Error: Invoice/Reference number is required."; $msgType = "error";
        } else {
            $pID = mysqli_real_escape_string($conn, $_POST['billing_info']);
            $amount = filter_var($_POST['amount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $date = mysqli_real_escape_string($conn, $_POST['date']);
            $ref = mysqli_real_escape_string($conn, strtoupper(trim($_POST['invoice_ref'])));
            
            $pTypeLabel = mysqli_real_escape_string($conn, $_POST['payment_type']);
            $pMethodLabel = mysqli_real_escape_string($conn, $_POST['payment_method']);
            
            $typeQ = mysqli_query($conn, "SELECT id FROM lookup_payment_types WHERE type_label = '$pTypeLabel' LIMIT 1");
            $typeID = mysqli_fetch_assoc($typeQ)['id'] ?? null;
            
            $methodQ = mysqli_query($conn, "SELECT id FROM lookup_payment_methods WHERE method_name = '$pMethodLabel' LIMIT 1");
            $methodID = mysqli_fetch_assoc($methodQ)['id'] ?? null;
            
            // Status ID for 'Completed' payment status
            $statusRes = mysqli_query($conn, "SELECT id FROM lookup_statuses WHERE status_name IN ('Completed', 'Complete') LIMIT 1");
            $statusID = mysqli_fetch_assoc($statusRes)['id'] ?? 4; 

            // Validate date is not in the future (using Manila timezone)
            $manilaDate = new DateTime('now', new DateTimeZone('Asia/Manila'));
            $manilaDateStr = $manilaDate->format('Y-m-d');
            if ($date > $manilaDateStr) {
                $msg = "Error: Payment date cannot be in the future."; $msgType = "error";
            } else {
                $success = false;
                if (isset($_POST['is_update']) && $_POST['is_update'] == '1') {
                    $updateID = mysqli_real_escape_string($conn, $_POST['record_id']);
                    $sql = "UPDATE payments SET amount='$amount', payment_date='$date', payment_type_id='$typeID', payment_method_id='$methodID', reference_no='$ref', status_id='$statusID' WHERE id='$updateID'";
                    if (mysqli_query($conn, $sql)) { $success = true; $redirect = "updated"; }
                } else {
                    $check = mysqli_query($conn, "SELECT id FROM payments WHERE reference_no = '$ref'");
                    if (mysqli_num_rows($check) > 0) {
                        $msg = "Error: Reference Number already exists."; $msgType = "error";
                    } else {
                        $sqlInsert = "INSERT INTO payments (patient_id, amount, payment_date, payment_type_id, payment_method_id, reference_no, status_id) 
                                VALUES ('$pID', '$amount', '$date', '$typeID', '$methodID', '$ref', '$statusID')";
                        if (mysqli_query($conn, $sqlInsert)) { $success = true; $redirect = "added"; }
                    }
                }

                if ($success) {
                    if (!empty($pID)) {
                        $resPaid = mysqli_query($conn, "SELECT id FROM lookup_statuses WHERE status_name = 'Paid' LIMIT 1");
                        $paidStatusID = mysqli_fetch_assoc($resPaid)['id'] ?? 4;

                        $resComp = mysqli_query($conn, "SELECT id FROM lookup_statuses WHERE status_name = 'Completed' LIMIT 1");
                        $compStatusID = mysqli_fetch_assoc($resComp)['id'] ?? 3;

                        // Total paid by the patient
                        $paidRes = mysqli_query($conn, "SELECT SUM(p.amount) as total_paid FROM payments p JOIN lookup_statuses s ON p.status_id = s.id WHERE p.patient_id = '$pID' AND (s.status_name = 'Completed' OR s.status_name = 'Complete')");
                        $totalPaid = (float)(mysqli_fetch_assoc($paidRes)['total_paid'] ?? 0);

                        // FIFO logic for marking appointments as Paid
                        $allApptsQuery = "SELECT a.id, pr.standard_cost 
                                          FROM appointments a 
                                          JOIN procedures pr ON a.procedure_id = pr.id 
                                          JOIN lookup_statuses s ON a.status_id = s.id
                                          WHERE a.patient_id = '$pID' 
                                          AND (s.status_name = 'Completed' OR s.status_name = 'Complete' OR s.status_name = 'Paid')
                                          ORDER BY a.appointment_date ASC, a.id ASC";
                        $allRes = mysqli_query($conn, $allApptsQuery);
                        $runningTotalCost = 0;
                        while($row = mysqli_fetch_assoc($allRes)) {
                            $runningTotalCost += (float)$row['standard_cost'];
                            $targetID = $row['id'];
                            
                            if (($totalPaid + 0.01) >= $runningTotalCost) {
                                mysqli_query($conn, "UPDATE appointments SET status_id = '$paidStatusID' WHERE id = '$targetID'");
                            } else {
                                mysqli_query($conn, "UPDATE appointments SET status_id = '$compStatusID' WHERE id = '$targetID'");
                            }
                        }
                    }
                    header("Location: record-payment.php?notif=$redirect");
                    exit();
                }
            }
        }
    }
}

/**
 * 5. FETCH DATA FOR LIST VIEW (3NF JOIN)
 */
if ($view === 'list') {
    $search = $_GET['search'] ?? '';
    $fromDate = $_GET['from_date'] ?? '';
    $toDate = $_GET['to_date'] ?? '';
    $minAmount = $_GET['min_amount'] ?? '';
    $maxAmount = $_GET['max_amount'] ?? '';
    $filterType = $_GET['filter_type'] ?? '';
    $filterMethod = $_GET['filter_method'] ?? '';

    $sqlList = "SELECT DISTINCT p.id, p.patient_id, p.amount, p.payment_date, p.payment_type_id, p.payment_method_id, p.reference_no, p.status_id,
                u.username, u.first_name, u.last_name, 
                pm.method_name as payment_method, pt.type_label as payment_type
                FROM payments p 
                JOIN users u ON p.patient_id = u.id 
                LEFT JOIN lookup_payment_methods pm ON p.payment_method_id = pm.id
                LEFT JOIN lookup_payment_types pt ON p.payment_type_id = pt.id
                WHERE 1=1";
    if ($role === 'patient') $sqlList .= " AND p.patient_id = '$currentUserID'";
    if (!empty($search)) {
        $safeSearch = mysqli_real_escape_string($conn, $search);
        // Expanded search to include reference number, patient name, and procedure/service name
        $sqlList .= " AND (p.reference_no LIKE '%$safeSearch%' 
                      OR u.first_name LIKE '%$safeSearch%' 
                      OR u.last_name LIKE '%$safeSearch%'
                      OR EXISTS (SELECT 1 FROM appointments a2 JOIN procedures pr2 ON a2.procedure_id = pr2.id WHERE a2.patient_id = p.patient_id AND pr2.procedure_name LIKE '%$safeSearch%'))";
    }
    
    // Date Range Filter
    if (!empty($fromDate)) {
        $safeFromDate = mysqli_real_escape_string($conn, $fromDate);
        $sqlList .= " AND DATE(p.payment_date) >= '$safeFromDate'";
    }
    if (!empty($toDate)) {
        $safeToDate = mysqli_real_escape_string($conn, $toDate);
        $sqlList .= " AND DATE(p.payment_date) <= '$safeToDate'";
    }

    // Amount Range Filter
    if (!empty($minAmount)) {
        $safeMinAmount = floatval($minAmount);
        $sqlList .= " AND p.amount >= $safeMinAmount";
    }
    if (!empty($maxAmount)) {
        $safeMaxAmount = floatval($maxAmount);
        $sqlList .= " AND p.amount <= $safeMaxAmount";
    }

    // Payment Type Filter
    if (!empty($filterType)) {
        $safeFilterType = mysqli_real_escape_string($conn, $filterType);
        $sqlList .= " AND pt.type_label = '$safeFilterType'";
    }

    // Payment Method Filter
    if (!empty($filterMethod)) {
        $safeFilterMethod = mysqli_real_escape_string($conn, $filterMethod);
        $sqlList .= " AND pm.method_name = '$safeFilterMethod'";
    }

    $sqlList .= " ORDER BY p.payment_date DESC, p.id DESC";
    $listResult = mysqli_query($conn, $sqlList);
}

/**
 * 6. FETCH DATA FOR EDIT VIEW (3NF JOIN)
 */
$editData = [];
if ($view === 'edit' && $editID > 0) {
    $res = mysqli_query($conn, "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) as account_name, pm.method_name, pt.type_label
                                FROM payments p 
                                JOIN users u ON p.patient_id = u.id 
                                LEFT JOIN lookup_payment_methods pm ON p.payment_method_id = pm.id
                                LEFT JOIN lookup_payment_types pt ON p.payment_type_id = pt.id
                                WHERE p.id = '$editID'");
    $editData = mysqli_fetch_assoc($res);
}

/**
 * 7. NOTIFICATION HANDLING
 */
$notificationMessage = '';
$notificationType = '';
if (isset($_GET['notif'])) {
    $notif = $_GET['notif'];
    if ($notif === 'added') {
        $notificationMessage = 'Payment successfully recorded!';
        $notificationType = 'success';
    } elseif ($notif === 'updated') {
        $notificationMessage = 'Payment record successfully updated!';
        $notificationType = 'success';
    } elseif ($notif === 'deleted') {
        $notificationMessage = 'Payment record successfully deleted!';
        $notificationType = 'success';
    }
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>Billing & Payments - San Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#1e3a5f",
                        "primary-hover": "#152a45",
                        "accent": "#d4a84b",
                        "background-light": "#f6f7f8",
                        "background-dark": "#101922"
                    },
                    fontFamily: {
                        "display": ["Manrope", "sans-serif"]
                    }
                }
            }
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

        @media print {
            body * { visibility: hidden; }
            #receiptModalContent, #receiptModalContent * { visibility: visible; }
            #receiptModalContent {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: clamp(15px, 5vw, 40px);
                background: white !important;
                border: none !important;
                box-shadow: none !important;
                box-sizing: border-box;
            }
            
            @media (max-width: 767px) {
                #receiptModalContent {
                    padding: clamp(12px, 4vw, 25px);
                }
            }
            
            @media (max-width: 479px) {
                #receiptModalContent {
                    padding: 15px;
                }
            }
            .no-print { display: none !important; }
        }
    </style>
    <link rel="stylesheet" href="css/responsive-enhancements.css">
</head>
<body class="font-display bg-background-light dark:bg-background-dark text-slate-900 dark:text-white antialiased overflow-hidden text-sm">
<div class="flex min-h-screen w-full flex-col h-screen no-print">
    
    <header class="sticky top-0 z-30 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-b-2 border-slate-200 dark:border-slate-800 px-6 py-4">
        <div class="max-w-6xl mx-auto flex justify-between items-center text-slate-900 dark:text-white font-black uppercase tracking-tight">
            <div>
                <h1 class="text-2xl font-black">💳 Billing Records</h1>
                <p class="text-slate-500 text-[10px] font-black uppercase">San Nicolas Dental Clinic</p>
            </div>
            <button onclick="openBackModal()" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 transition-colors text-sm font-bold shadow-sm font-black">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Dashboard
            </button>
        </div>
    </header>
    
    <main class="flex-1 w-full max-w-6xl mx-auto px-4 lg:px-8 py-8 animate-fade-in overflow-y-auto">
        
        <?php if (!empty($notificationMessage)): ?>
            <div class="mb-6 p-4 rounded-2xl border-2 flex items-center gap-3 <?php echo ($notificationType === 'success') ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'; ?> animate-fade-in">
                <span class="material-symbols-outlined <?php echo ($notificationType === 'success') ? 'text-green-600' : 'text-red-600'; ?> text-2xl font-black">
                    <?php echo ($notificationType === 'success') ? 'check_circle' : 'error'; ?>
                </span>
                <div class="flex-1">
                    <p class="font-black text-sm <?php echo ($notificationType === 'success') ? 'text-green-800 dark:text-green-200' : 'text-red-800 dark:text-red-200'; ?>"><?php echo htmlspecialchars($notificationMessage); ?></p>
                </div>
                <button onclick="this.parentElement.style.display='none'" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>
        <?php endif; ?>
        
        <div class="text-center mb-10">
            <h1 class="text-3xl font-black text-slate-900 dark:text-white uppercase tracking-tight">Financial ledger</h1>
            <p class="text-slate-500 mt-1 font-bold">Manage clinical settlements and transactions</p>
            <div class="flex justify-center mt-6 bg-slate-100 dark:bg-slate-800 p-1.5 rounded-2xl shadow-sm w-fit mx-auto font-black uppercase">
                <a href="record-payment.php?view=list" class="px-8 py-2.5 rounded-xl text-[10px] transition-all <?php echo ($view=='list') ? 'bg-white dark:bg-slate-700 shadow-sm text-primary' : 'text-slate-500 hover:text-slate-700'; ?>">Payment history</a>
                <?php if ($role === 'dentist'): ?>
                <a href="record-payment.php?view=add" class="px-8 py-2.5 rounded-xl text-[10px] transition-all <?php echo ($view=='add' || $view=='edit') ? 'bg-white dark:bg-slate-700 shadow-sm text-primary' : 'text-slate-500 hover:text-slate-700'; ?>">+ New entry</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($view === 'list'): ?>
            <div class="flex flex-col gap-4">
                <!-- Quick Search -->
                <form method="GET" class="relative flex max-w-lg mx-auto w-full mb-4 gap-2 font-black">
                    <input type="hidden" name="view" value="list">
                    <div class="relative flex-1">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400">search</span>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search reference, patient, or service..." 
                            class="w-full h-11 pl-10 rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-bold focus:ring-primary shadow-sm">
                    </div>
                    <button type="submit" class="h-11 px-6 rounded-xl bg-primary text-white font-black text-xs uppercase shadow-lg hover:bg-blue-600 transition-all">Search</button>
                    <button type="button" onclick="toggleAdvancedSearch()" class="h-11 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-black text-xs uppercase hover:bg-slate-200 transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined text-[16px]">tune</span>
                    </button>
                </form>

                <!-- Advanced Search Panel -->
                <div id="advancedSearchPanel" class="hidden animate-fade-in bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-6 mb-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-6 font-black uppercase text-slate-900 dark:text-white">
                        <span class="material-symbols-outlined text-primary">filter_alt</span>
                        <h3 class="text-sm font-black">Advanced Filters</h3>
                        <button type="button" onclick="toggleAdvancedSearch()" class="ml-auto p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors">
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </button>
                    </div>

                    <form method="GET" class="space-y-5">
                        <input type="hidden" name="view" value="list">
                        
                        <!-- Date Range -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">From Date</label>
                                <input type="date" name="from_date" value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>" 
                                    class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">To Date</label>
                                <input type="date" name="to_date" value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>" 
                                    class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                            </div>
                        </div>

                        <!-- Amount Range -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Min Amount (₱)</label>
                                <input type="number" name="min_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($_GET['min_amount'] ?? ''); ?>" placeholder="0.00"
                                    class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Max Amount (₱)</label>
                                <input type="number" name="max_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($_GET['max_amount'] ?? ''); ?>" placeholder="999999.99"
                                    class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                            </div>
                        </div>

                        <!-- Payment Type Filter -->
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Payment Classification</label>
                            <select name="filter_type" class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                <option value="">-- All Classifications --</option>
                                <?php 
                                    $pTypes = mysqli_query($conn, "SELECT type_label FROM lookup_payment_types");
                                    while($pt = mysqli_fetch_assoc($pTypes)):
                                ?>
                                    <option value="<?php echo $pt['type_label']; ?>" <?php echo (($_GET['filter_type'] ?? '') === $pt['type_label']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pt['type_label']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Payment Method Filter -->
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Payment Method</label>
                            <select name="filter_method" class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                <option value="">-- All Methods --</option>
                                <?php 
                                    $pMethods = mysqli_query($conn, "SELECT method_name FROM lookup_payment_methods");
                                    while($pm = mysqli_fetch_assoc($pMethods)):
                                ?>
                                    <option value="<?php echo $pm['method_name']; ?>" <?php echo (($_GET['filter_method'] ?? '') === $pm['method_name']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pm['method_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex gap-3 pt-4 border-t border-slate-100 dark:border-slate-700 font-black uppercase">
                            <button type="submit" class="flex-1 h-10 rounded-lg bg-primary text-white font-black text-xs uppercase shadow-lg hover:bg-blue-600 transition-all flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">search</span> Apply Filters
                            </button>
                            <a href="record-payment.php?view=list" class="flex-1 h-10 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-black text-xs uppercase hover:bg-slate-100 dark:hover:bg-slate-700 transition-all flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">refresh</span> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <div class="bg-white dark:bg-slate-800 rounded-3xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm shadow-md text-slate-900 dark:text-white font-black">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-900/50 text-slate-500 font-black uppercase text-[10px] border-b dark:border-slate-700 tracking-widest">
                            <tr>
                                <th class="px-6 py-4">Date / ref</th>
                                <th class="px-6 py-4">Patient / service</th>
                                <th class="px-6 py-4">Method / class</th>
                                <th class="px-6 py-4 text-right">Amount</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                            <?php if ($listResult && mysqli_num_rows($listResult) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($listResult)): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <p class="text-slate-900 dark:text-white font-black"><?php 
                                            $dt = new DateTime($row['payment_date'], new DateTimeZone('UTC'));
                                            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                                            echo $dt->format('M d, Y');
                                        ?></p>
                                        <p class="text-xs text-slate-400 font-mono uppercase tracking-widest">#<?php echo $row['reference_no']; ?></p>
                                    </td>
                                    <td class="px-6 py-4">
                                         <p class="text-slate-800 dark:text-white font-black"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></p>
                                        <p class="text-[10px] text-primary uppercase font-black italic">General clinical fee</p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col gap-1">
                                            <span class="px-2 py-0.5 rounded-lg border text-[10px] uppercase text-slate-500 w-fit">
                                                <?php echo htmlspecialchars($row['payment_method'] ?? 'Cash'); ?>
                                            </span>
                                            <p class="text-[9px] text-primary font-black uppercase tracking-widest"><?php echo htmlspecialchars($row['payment_type'] ?? 'N/A'); ?></p>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-right font-black text-primary">₱<?php echo number_format($row['amount'], 2); ?></td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-1">
                                             <button type="button" onclick='openReceipt(<?php echo htmlspecialchars(json_encode(array_merge($row, ["patient_name" => ($row["display_patient_name"] ?? ($row["first_name"]." ".$row["last_name"])), "service" => ($row["procedure_name"] ?? "General clinical fee")])), ENT_QUOTES, 'UTF-8'); ?>)' class="p-2 text-primary hover:bg-primary/5 rounded-xl transition-all" title="View Receipt"><span class="material-symbols-outlined text-[20px]">receipt</span></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-slate-400 font-black italic shadow-inner uppercase text-[10px]">No financial records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'add' || $view === 'edit'): 
            $formRef = $editData['reference_no'] ?? 'INV-' . rand(10000, 99999);
            $formDate = $editData['payment_date'] ?? date('Y-m-d');
            $formAmt = $editData['amount'] ?? '';
            $selectedType = $editData['type_label'] ?? '';
            $selectedMethod = $editData['method_name'] ?? 'Cash';
        ?>
            <div class="max-w-2xl mx-auto flex flex-col gap-8 mb-20 text-slate-900 dark:text-white">
                
                <div id="guidelinePanel" class="hidden animate-fade-in bg-white dark:bg-slate-800 rounded-[32px] border-2 border-primary/10 p-8 shadow-sm">
                    <div class="flex items-center gap-3 mb-6 font-black uppercase tracking-tight">
                        <span class="material-symbols-outlined text-primary">analytics</span>
                        <h4 class="font-black text-lg">Clinical Billing Guidance</h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl shadow-inner uppercase font-black">
                            <p class="text-[10px] font-black text-slate-400 mb-1">Detected Procedure</p>
                            <p id="detectedProc" class="font-black text-slate-800 dark:text-white text-sm">-</p>
                        </div>
                        <div class="p-4 bg-primary/5 rounded-2xl border border-primary/10 shadow-inner font-black uppercase">
                            <p class="text-[10px] font-black text-primary mb-1">Clinical Balance</p>
                            <p id="procPrice" class="text-xl font-black text-primary">₱0.00</p>
                        </div>
                    </div>

                    <div id="warningFullOnly" class="mt-6 p-4 bg-red-50 dark:bg-red-900/20 rounded-2xl border border-red-100 hidden">
                        <p class="text-[10px] text-red-600 font-black uppercase tracking-widest flex items-center gap-2">
                            <span class="material-symbols-outlined text-sm">info</span> Settlement Required
                        </p>
                        <p class="text-xs text-red-800 dark:text-red-200 mt-1 font-bold">This treatment is restricted to Full Payment only (Remaining balance settlement).</p>
                    </div>

                    <div id="installmentLogic" class="mt-6 pt-6 border-t border-slate-100 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-2 gap-4 uppercase font-black">
                        <div class="flex flex-col">
                            <span class="text-[10px] font-black text-slate-400 mb-1">Downpayment (Remaining Share)</span>
                            <span id="calcDP" class="text-sm font-black text-slate-900 dark:text-white">-</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-[10px] font-black text-slate-400 mb-1">Installment Split</span>
                            <span id="suggestedInstallment" class="text-sm font-black text-blue-600">-</span>
                        </div>
                    </div>
                </div>

                <form id="paymentForm" method="POST" class="flex flex-col gap-6" onsubmit="handleFormSubmit(event)">
                    <input type="hidden" name="save_payment" value="1">
                    <?php if ($view === 'edit'): ?>
                        <input type="hidden" name="is_update" value="1">
                        <input type="hidden" name="record_id" value="<?php echo $editID; ?>">
                    <?php endif; ?>

                    <div class="rounded-[40px] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-8 md:p-12 shadow-md font-black uppercase">
                        <div class="mb-8">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest">Select Treated Patient <span class="text-red-500">*</span></label>
                            <select name="billing_info" id="billingInfo" required class="w-full h-14 rounded-2xl bg-slate-50 dark:bg-slate-900 border-none px-5 focus:ring-primary font-black shadow-inner" onchange="handlePatientChange(this)">
                                <option value="" data-reason="">-- Choose treatment --</option>
                                <?php if ($view === 'edit' && !empty($editData)) echo '<option value="'.$editData['patient_id'].'". selected>'.htmlspecialchars($editData['account_name'] ?? '').'</option>'; ?>
                                <?php foreach($patientsList as $p): ?>
                                    <option value="<?php echo $p['account_id']; ?>" 
                                            data-patient-name="<?php echo htmlspecialchars($p['booked_name'] ?? ''); ?>"
                                            data-reason="<?php echo htmlspecialchars($p['procedure_name'] ?? ''); ?>"
                                            data-orig-price="<?php echo $p['standard_cost'] ?? 0; ?>"
                                            data-price="<?php echo $p['remaining_balance_for_this_appt'] ?? 0; ?>">
                                        <?php echo htmlspecialchars($p['booked_name'] ?? ''); ?> - <?php echo htmlspecialchars($p['procedure_name'] ?? ''); ?> (<?php echo date('M d, Y', strtotime($p['appointment_date'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-8" id="paymentTypeSection">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest">Payment Classification <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 font-black uppercase" id="paymentTypeContainer">
                                <?php 
                                    $pTypes = mysqli_query($conn, "SELECT type_label FROM lookup_payment_types");
                                    while($pt = mysqli_fetch_assoc($pTypes)):
                                        $val = $pt['type_label'];
                                ?>
                                    <label class="cursor-pointer payment-type-option" data-type-label="<?php echo $val; ?>">
                                        <input type="radio" name="payment_type" value="<?php echo $val; ?>" class="hidden peer" <?php echo ($selectedType == $val) ? 'checked' : ''; ?> required onchange="updateAmountField()">
                                        <div class="h-12 flex items-center justify-center rounded-xl border-2 border-slate-100 peer-checked:border-primary peer-checked:bg-primary/5 peer-checked:text-primary text-[10px] transition-all px-2 text-center shadow-sm">
                                            <?php echo $val; ?>
                                        </div>
                                    </label>
                                <?php endwhile; ?>
                            </div>
                        </div>

                        <div class="mb-8">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest">Payment Method <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 font-black uppercase">
                                <?php 
                                    $pMethods = mysqli_query($conn, "SELECT method_name FROM lookup_payment_methods");
                                    while($pm = mysqli_fetch_assoc($pMethods)):
                                        $val = $pm['method_name'];
                                ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="payment_method" value="<?php echo $val; ?>" class="hidden peer" <?php echo ($selectedMethod == $val) ? 'checked' : ''; ?> required>
                                        <div class="h-12 flex items-center justify-center rounded-xl border-2 border-slate-100 peer-checked:border-primary peer-checked:bg-primary/5 peer-checked:text-primary text-[10px] transition-all px-2 text-center shadow-sm">
                                            <?php echo $val; ?>
                                        </div>
                                    </label>
                                <?php endwhile; ?>
                            </div>
                        </div>

                        <div class="mb-8">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest">Amount Paid (PHP) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-5 top-1/2 -translate-y-1/2 text-2xl font-black text-slate-300">₱</span>
                                <input name="amount" id="amountInput" type="number" step="0.01" min="0.01" value="<?php echo $formAmt; ?>" required placeholder="0.00" class="w-full h-20 pl-12 pr-6 text-4xl font-black rounded-3xl border-slate-200 focus:border-primary focus:ring-4 focus:ring-primary/10 shadow-inner">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 uppercase font-black">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 mb-2">Transaction Date</label>
                                <input name="date" type="date" value="<?php echo $formDate; ?>" required max="<?php $manilaToday = new DateTime('now', new DateTimeZone('Asia/Manila')); echo $manilaToday->format('Y-m-d'); ?>" class="w-full h-12 rounded-xl border border-slate-200 px-4 font-black focus:ring-primary shadow-inner">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 mb-2">Invoice / Ref #</label>
                                <input name="invoice_ref" type="text" value="<?php echo $formRef; ?>" required class="w-full h-12 rounded-xl border border-slate-200 px-4 font-black focus:ring-primary shadow-inner uppercase tracking-widest">
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-8 border-t border-slate-100 dark:border-slate-700">
                            <button type="button" onclick="openDiscardModal()" class="px-8 py-4 rounded-xl border border-slate-200 text-slate-500 font-black hover:bg-slate-50 transition-all uppercase text-xs">Discard</button>
                            <button type="submit" id="submitBtn" class="px-10 py-4 rounded-xl bg-primary text-white font-black shadow-xl hover:bg-blue-600 transition-all flex items-center gap-2 uppercase text-xs">
                                <span class="material-symbols-outlined">verified</span> Post Payment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Receipt Modal -->
<div id="receiptModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md transition-opacity opacity-0 font-black uppercase">
    <div class="bg-white dark:bg-slate-900 rounded-[32px] shadow-2xl p-0 w-full max-w-md transform scale-95 transition-all duration-300 overflow-hidden" id="receiptModalContent">
        <div class="bg-primary p-7 text-white flex justify-between items-start no-print font-black uppercase">
            <div class="flex items-center gap-4">
                <div class="size-10 rounded-xl bg-white/20 flex items-center justify-center shadow-inner"><span class="material-symbols-outlined text-[22px]">receipt_long</span></div>
                  <div><h3 class="text-base tracking-tight">Official Receipt</h3><p class="text-[9px] opacity-80 uppercase tracking-widest">San Nicolas Dental Clinic</p></div>
            </div>
            <button onclick="closeM('receiptModal')" class="p-2 hover:bg-white/10 rounded-full transition-colors"><span class="material-symbols-outlined text-[20px]">close</span></button>
        </div>
        <div class="p-8 space-y-7 bg-white dark:bg-slate-900">
            <div class="hidden print:block text-center border-b pb-5 mb-7 uppercase font-black">
                  <h1 class="text-xl text-slate-900">San Nicolas Dental Clinic</h1>
                <p class="text-[9px] text-slate-500 tracking-widest mt-1">Clinical Transaction Receipt</p>
                <p class="text-[8px] text-slate-400 mt-1">Official Medical Billing</p>
            </div>
            <div class="flex justify-between items-start gap-4">
                <div class="space-y-1">
                    <p class="text-[9px] text-slate-400 tracking-widest uppercase">Billed To</p>
                    <h4 id="rPatient" class="text-base text-slate-900 dark:text-white font-black">-</h4>
                </div>
                <div class="text-right space-y-1 font-black">
                    <p class="text-[9px] text-slate-400 tracking-widest uppercase">Reference #</p>
                    <p id="rRef" class="text-sm text-primary font-mono tracking-tighter">-</p>
                </div>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-800 rounded-2xl border border-slate-100 dark:border-slate-800 uppercase font-black">
                <p class="text-[9px] text-slate-400 mb-1">Service / Procedure</p>
                <p id="rService" class="text-xs text-slate-700 dark:text-slate-200 italic">-</p>
            </div>
            <div class="grid grid-cols-2 gap-6 border-y border-slate-100 dark:border-slate-800 py-5 uppercase font-black">
                <div class="space-y-1">
                    <p class="text-[9px] text-slate-400 uppercase">Payment Date</p>
                    <p id="rDate" class="text-xs text-slate-700 dark:text-slate-300">-</p>
                </div>
                <div class="space-y-1 text-right">
                    <p class="text-[9px] text-slate-400 uppercase">Method</p>
                    <p id="rMethod" class="text-xs text-slate-700 dark:text-slate-300">-</p>
                </div>
            </div>
            <div class="flex justify-between items-center bg-primary/5 p-5 rounded-2xl border-2 border-primary/5 uppercase font-black">
                <div class="space-y-1">
                    <p class="text-[9px] text-slate-400 uppercase">Classification</p>
                    <p id="rType" class="text-[9px] text-primary tracking-widest font-black">-</p>
                </div>
                <div class="text-right">
                    <p class="text-[9px] text-primary mb-1 uppercase tracking-widest">Total Paid</p>
                    <p id="rAmount" class="text-2xl text-slate-900 dark:text-white tracking-tighter">₱0.00</p>
                </div>
            </div>
            <div class="text-center space-y-4 pt-2 uppercase font-black no-print">
                <p class="text-[8px] text-slate-400 font-bold italic tracking-tight">System-generated medical billing record.</p>
                <button onclick="window.print()" class="w-full h-12 rounded-xl bg-slate-900 text-white font-black text-xs uppercase flex items-center justify-center gap-2 hover:bg-slate-800 transition-all shadow-xl"><span class="material-symbols-outlined text-[18px]">print</span> Export / Print Receipt</button>
            </div>
        </div>
    </div>
</div>

<!-- Back Modal -->
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

<!-- Discard Modal -->
<div id="discardModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 uppercase font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl" id="discardContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-orange-100 dark:bg-orange-900/20 mb-6"><span class="material-symbols-outlined text-3xl text-orange-600">warning</span></div>
            <h3 class="text-xl font-black text-slate-900 dark:text-white mb-2 uppercase tracking-tight">Discard entry?</h3>
            <div class="flex gap-3 justify-center mt-8 text-[10px]">
                <button onclick="closeM('discardModal')" class="flex-1 py-3 rounded-xl border border-slate-200 font-black text-slate-500 uppercase">Stay</button>
                <a href="record-payment.php?view=list" class="flex-1 py-3 rounded-xl bg-orange-500 text-white font-black shadow-lg flex items-center justify-center uppercase">Discard</a>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal (Hidden by Default) -->
<div id="deleteModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 uppercase font-black">
    <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl p-8 w-full max-sm:mx-4 max-w-sm transform scale-95 transition-all duration-300 shadow-2xl" id="deleteModalContent">
        <div class="text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 mb-6"><span class="material-symbols-outlined text-3xl text-red-600">delete_forever</span></div>
            <h3 class="text-xl font-black text-slate-900 dark:text-white mb-2 uppercase">Delete record?</h3>
            <form method="POST" class="flex flex-col gap-6 mt-8">
                <input type="hidden" name="delete_payment" value="1">
                <input type="hidden" name="payment_id" id="modalPaymentId">
                <div class="flex gap-3 justify-center text-[10px]">
                    <button type="button" onclick="closeM('deleteModal')" class="flex-1 py-3 rounded-xl border border-slate-200 font-black text-slate-500 uppercase">Cancel</button>
                    <button type="submit" class="flex-1 py-3 rounded-xl bg-red-500 text-white font-black shadow-lg uppercase">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let currentFinancials = { full: 0, dp: 0, installment: 0 };
    let isSubmitting = false;

    function toggleAdvancedSearch() {
        const panel = document.getElementById('advancedSearchPanel');
        panel.classList.toggle('hidden');
        panel.classList.toggle('animate-fade-in');
    }

    function handleFormSubmit(event) {
        if (isSubmitting) {
            event.preventDefault();
            return false;
        }
        isSubmitting = true;
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.style.cursor = 'not-allowed';
        return true;
    }

    /**
     * CLASSIFICATION REGISTRY
     * SIMPLE TREATMENTS (FULL PAY ONLY)
     */
    const FULL_PAYMENT_ONLY_KEYWORDS = [
        "Consultation", 
        "Online Consultation", 
        "Face to Face Consultation",
        "Dental Cleaning", 
        "Oral Prophylaxis", 
        "Fluoride Treatment", 
        "Pits and Fissures", 
        "Perio Probing", 
        "Minor Restorative", 
        "Light Cured Composite", 
        "Temporary Filling", 
        "Simple Radiology", 
        "Periapical X-ray", 
        "Panoramic X-ray", 
        "Cephalometric X-ray", 
        "Minor Orthodontic Adjustments", 
        "Re-bonding of Bracket", 
        "Bracket Replacement",
        "Preventive / Minor Procedures"
    ];

    const INSTALLMENT_KEYWORDS = [
        "Orthodontics", "Braces", "Aligners", "Implant", "TMJ"
    ];

    function handlePatientChange(select) {
        const panel = document.getElementById('guidelinePanel');
        const warning = document.getElementById('warningFullOnly');
        const typeSection = document.getElementById('paymentTypeSection');
        const opt = select.options[select.selectedIndex];
        const reason = opt.getAttribute('data-reason') || "";
        const patientName = opt.getAttribute('data-patient-name') || "";
        const dbPrice = parseFloat(opt.getAttribute('data-price')) || 0;
        const origPrice = parseFloat(opt.getAttribute('data-orig-price')) || dbPrice;

        if (!reason || select.value === "") { 
            panel.classList.add('hidden'); 
            return; 
        }
        
        panel.classList.remove('hidden');
        document.getElementById('detectedProc').innerText = reason;
        document.getElementById('procPrice').innerText = '₱' + dbPrice.toLocaleString('en-US', { minimumFractionDigits: 2 });
        
        const instLogic = document.getElementById('installmentLogic');
        currentFinancials.full = dbPrice;
        currentFinancials.patientName = patientName;

        let isFullOnly = FULL_PAYMENT_ONLY_KEYWORDS.some(k => reason.toLowerCase().includes(k.toLowerCase()));
        let allowsInstallment = INSTALLMENT_KEYWORDS.some(k => reason.toLowerCase().includes(k.toLowerCase()));

        warning.classList.add('hidden');
        instLogic.classList.add('hidden');
        typeSection.classList.remove('hidden'); // Show by default
        
        const options = document.querySelectorAll('.payment-type-option');
        options.forEach(optDiv => {
            const label = optDiv.getAttribute('data-type-label');
            const input = optDiv.querySelector('input');
            
            if (isFullOnly) {
                if (label === 'FULLPAYMENT') {
                    optDiv.style.display = 'block';
                    input.checked = true;
                } else {
                    optDiv.style.display = 'none';
                    input.checked = false;
                }
                warning.classList.remove('hidden');
                typeSection.classList.add('hidden');
            } else if (allowsInstallment) {
                optDiv.style.display = 'block';
                instLogic.classList.remove('hidden');
            } else {
                if (label === 'INSTALLMENT PAYMENT') {
                    optDiv.style.display = 'none';
                    input.checked = false;
                } else {
                    optDiv.style.display = 'block';
                }
            }
        });

        if (!isFullOnly) {
            let dpPercent = 0.50, visits = 2;
            if (origPrice >= 16000 && origPrice <= 40000) { dpPercent = 0.40; visits = 4; }
            else if (origPrice >= 41000) { dpPercent = 0.30; visits = 12; }
            
            const dpAmount = origPrice * dpPercent;
            currentFinancials.dp = Math.min(dbPrice, dpAmount); 
            currentFinancials.installment = Math.min(dbPrice, (origPrice - dpAmount) / visits);

            document.getElementById('calcDP').innerText = '₱' + dpAmount.toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' (' + (dpPercent * 100) + '%)';
            document.getElementById('suggestedInstallment').innerText = '₱' + ((origPrice - dpAmount) / visits).toLocaleString('en-US', { minimumFractionDigits: 2 });
        }

        updateAmountField();
    }

    function updateAmountField() {
        const selectedType = document.querySelector('input[name="payment_type"]:checked');
        const amountInput = document.getElementById('amountInput');
        if (!selectedType || !amountInput) return;
        
        if (selectedType.value === 'FULLPAYMENT') amountInput.value = currentFinancials.full.toFixed(2);
        else if (selectedType.value === 'DOWN PAYMENT') amountInput.value = currentFinancials.dp.toFixed(2);
        else if (selectedType.value === 'INSTALLMENT PAYMENT') amountInput.value = currentFinancials.installment.toFixed(2);
    }

    function openReceipt(data) {
        document.getElementById('rPatient').innerText = data.patient_name;
        document.getElementById('rRef').innerText = '#' + data.reference_no;
        document.getElementById('rService').innerText = data.service;
        
        // Convert UTC payment_date to Manila timezone (UTC+8) for display
        const paymentDate = new Date(data.payment_date);
        const manilaFormatter = new Intl.DateTimeFormat('en-US', {
            month: 'long',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'Asia/Manila'
        });
        document.getElementById('rDate').innerText = manilaFormatter.format(paymentDate);
        
        document.getElementById('rMethod').innerText = data.payment_method || 'Cash';
        document.getElementById('rType').innerText = data.payment_type;
        document.getElementById('rAmount').innerText = '₱' + parseFloat(data.amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
        showM('receiptModal', 'receiptModalContent');
    }

    function showM(mId, cId) {
        const m = document.getElementById(mId), c = document.getElementById(cId);
        m.classList.remove('hidden'); m.classList.add('flex');
        setTimeout(() => { m.classList.remove('opacity-0'); c.classList.remove('scale-95'); c.classList.add('scale-100'); }, 10);
    }
    function closeM(id) { document.getElementById(id).classList.add('opacity-0'); setTimeout(() => document.getElementById(id).classList.add('hidden'), 300); }
    function openBackModal() { showM('backModal', 'backModalContent'); }
    function openDiscardModal() { showM('discardModal', 'discardContent'); }
    function openDeleteModal(id) { document.getElementById('modalPaymentId').value = id; showM('deleteModal', 'deleteModalContent'); }
</script>
</body>
</html>


