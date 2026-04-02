    <?php 
    session_start();
    require_once 'backend/config.php'; 
    require_once 'backend/middleware.php'; 

    // Set Timezone to Philippines
    date_default_timezone_set('Asia/Manila');

    // 1. Access Control - ALLOW PATIENT, DENTIST, AND ASSISTANT
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['patient', 'dentist', 'assistant'])) {
        header("Location: login.php");
        exit();
    }

    $role = $_SESSION['role'];
    $userID = $_SESSION['user_id'];
    $fullName = $_SESSION['full_name'] ?? 'User';
    $msg = ""; $msgType = "";

    // A. Handle Clinical Response (Dentist or Assistant)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_response']) && ($role === 'dentist' || $role === 'assistant')) {
        $compID = mysqli_real_escape_string($conn, $_POST['complaint_id']);
        $response = mysqli_real_escape_string($conn, trim($_POST['response_text']));
        
        if (!empty($response)) {
            // Status 6 = Responded in lookup_statuses
            $sql = "UPDATE patient_complaints SET dentist_response = '$response', status_id = 6 WHERE id = '$compID'";
            if (mysqli_query($conn, $sql)) {
                $msg = "Clinical response successfully recorded and sent to patient."; $msgType = "success";
            } else {
                $msg = "Error saving response: " . mysqli_error($conn); $msgType = "error";
            }
        }
    }

    // B. Handle Patient Feedback Submission (3NF SYNC)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_complaint']) && $role === 'patient') {
        $reason = mysqli_real_escape_string($conn, trim($_POST['visit_reason'] ?? ''));
        $categoryLabel = mysqli_real_escape_string($conn, $_POST['category'] ?? '');
        $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));

        // Resolve Category ID
        $catQ = mysqli_query($conn, "SELECT id FROM lookup_categories WHERE category_name = '$categoryLabel' LIMIT 1");
        $catData = mysqli_fetch_assoc($catQ);
        $catID = $catData['id'] ?? 7; // Default to 'other' if not found

        if (empty($categoryLabel) || empty($description)) {
            $msg = "Please fill in all required fields."; $msgType = "error";
        } else {
            // Status 1 = Pending in lookup_statuses
            $sql = "INSERT INTO patient_complaints (patient_id, visit_reason, category_id, description, status_id) 
                    VALUES ('$userID', '$reason', '$catID', '$description', 1)";
            if (mysqli_query($conn, $sql)) {
                $msg = "Thank you. Your feedback has been recorded."; $msgType = "success";
            } else {
                $msg = "Error submitting feedback: " . mysqli_error($conn); $msgType = "error";
            }
        }
    }

    // C. Fetch Records (3NF JOIN Logic)
    $fromDate = $_GET['from_date'] ?? '';
    $toDate = $_GET['to_date'] ?? '';
    $filterCategory = $_GET['filter_category'] ?? '';
    $filterStatus = $_GET['filter_status'] ?? '';
    $searchText = $_GET['search'] ?? '';
    
    if ($role === 'dentist' || $role === 'assistant') {
        // Joining users, categories, and statuses for a comprehensive staff view
        $sql = "SELECT c.*, u.first_name, u.last_name, u.username, 
                cat.category_name as category, s.status_name as status 
                FROM patient_complaints c 
                JOIN users u ON c.patient_id = u.id 
                LEFT JOIN lookup_categories cat ON c.category_id = cat.id
                LEFT JOIN lookup_statuses s ON c.status_id = s.id
                WHERE 1=1";
        
        // Search filter
        if (!empty($searchText)) {
            $safeSearch = mysqli_real_escape_string($conn, $searchText);
            $sql .= " AND (c.description LIKE '%$safeSearch%' OR u.first_name LIKE '%$safeSearch%' OR u.last_name LIKE '%$safeSearch%')";
        }
    } else {
        // Joining lookups for the patient's personal history view
        $sql = "SELECT c.*, cat.category_name as category, s.status_name as status 
                FROM patient_complaints c 
                LEFT JOIN lookup_categories cat ON c.category_id = cat.id
                LEFT JOIN lookup_statuses s ON c.status_id = s.id
                WHERE c.patient_id = '$userID'";
        
        // Search filter
        if (!empty($searchText)) {
            $safeSearch = mysqli_real_escape_string($conn, $searchText);
            $sql .= " AND c.description LIKE '%$safeSearch%'";
        }
    }
    
    // Date range filter
    if (!empty($fromDate)) {
        $safeFromDate = mysqli_real_escape_string($conn, $fromDate);
        $sql .= " AND DATE(c.created_at) >= '$safeFromDate'";
    }
    if (!empty($toDate)) {
        $safeToDate = mysqli_real_escape_string($conn, $toDate);
        $sql .= " AND DATE(c.created_at) <= '$safeToDate'";
    }
    
    // Category filter
    if (!empty($filterCategory)) {
        $safeCategory = mysqli_real_escape_string($conn, $filterCategory);
        $sql .= " AND cat.category_name = '$safeCategory'";
    }
    
    // Status filter
    if (!empty($filterStatus)) {
        $safeStatus = mysqli_real_escape_string($conn, $filterStatus);
        $sql .= " AND s.status_name = '$safeStatus'";
    }
    
    $sql .= " ORDER BY c.created_at DESC";
    $complaints = mysqli_query($conn, $sql);

    // Determine Dashboard Link
    $dashboardLink = 'patient-dashboard.php';
    if ($role === 'dentist') $dashboardLink = 'dentist-dashboard.php';
    elseif ($role === 'assistant') $dashboardLink = 'assistant-dashboard.php';

    ?>
    <!DOCTYPE html>
    <html class="light" lang="en">
    <head>
        <meta charset="utf-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
        <title>Complaints Management - San Nicolas Dental Clinic</title>
        <link href="https://fonts.googleapis.com" rel="preconnect"/>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
        <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
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
            
            textarea, input {
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            textarea:focus, input:focus {
                transform: translateY(-1px);
            }
            
            .rounded-\[32px\], .rounded-3xl, .rounded-2xl {
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            .shadow-2xl, .shadow-sm {
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
        </style>
        <link rel="stylesheet" href="css/responsive-enhancements.css">
    </head>
    <body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 font-display antialiased text-sm">
    <div class="flex flex-col min-h-screen w-full relative">

    <header class="sticky top-0 z-30 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-b-2 border-slate-200 dark:border-slate-800 px-6 py-4">
            <div class="max-w-6xl mx-auto flex justify-between items-center text-slate-900 dark:text-white font-black uppercase tracking-tight">
                <div>
                    <h1 class="text-2xl font-black">📢 Feedback & Complaints</h1>
                    <h1 class="text-2xl font-black">Patient Complaint Form</h1>
                    <p class="text-slate-500 text-[10px] font-black uppercase">San Nicolas Dental Clinic</p>
                </div>
                <button onclick="openBackModal()" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 transition-colors text-sm font-bold shadow-sm font-black">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span> Dashboard
                </button>
            </div>
        </header>
        
        <main class="flex-1 bg-[#f8fafc] dark:bg-background-dark text-slate-900 dark:text-white pb-24">
            <div class="max-w-6xl mx-auto px-4 md:px-10 py-12 flex flex-col gap-10 animate-fade-in">
                
                <header class="flex flex-col gap-3 text-left">
                    <h1 class="text-3xl md:text-4xl font-black tracking-tight uppercase">
                        <?php echo ($role !== 'patient') ? 'Patient Clinical Complaints' : 'Patient Complaint Form'; ?>
                    </h1>
                    <p class="text-base text-slate-500 dark:text-slate-400 max-w-2xl font-bold">
                        <?php echo ($role !== 'patient') ? 'Address patient concerns and maintain clinical standards.' : 'Your feedback helps us maintain high quality standards.'; ?>
                    </p>
                </header>

                <?php if($msg): ?>
                    <div class="p-4 rounded-xl border font-bold flex items-center gap-3 shadow-sm <?php echo ($msgType == 'success') ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'; ?>">
                        <span class="material-symbols-outlined"><?php echo ($msgType == 'success') ? 'check_circle' : 'error'; ?></span> <?php echo $msg; ?>
                    </div>
                <?php endif; ?>

                <?php if ($role === 'patient'): ?>
                <div class="flex flex-col gap-6 rounded-3xl bg-white dark:bg-slate-900 p-8 md:p-10 border border-slate-100 dark:border-slate-800 shadow-md">
                    <form method="POST" action="" class="flex flex-col gap-8">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <label class="flex flex-col gap-2"><span class="text-xs font-bold text-slate-500 uppercase">Reason for visit (Optional)</span>
                            <input name="visit_reason" class="h-12 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold focus:ring-primary px-4 shadow-inner" placeholder="e.g., Routine Cleaning" type="text"/></label>
                            <label class="flex flex-col gap-2"><span class="text-xs font-bold text-slate-500 uppercase">Complaint category</span>
                            <select name="category" required class="h-12 rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 font-bold focus:ring-primary shadow-inner">
                                <option value="" disabled selected>Select category</option>
                                <option value="billing">Billing & Insurance</option>
                                <option value="appointment">Appointment Scheduling</option>
                                <option value="wait-time">Wait Time</option>
                                <option value="treatment">Treatment Quality</option>
                                <option value="staff">Staff Behavior</option>
                                <option value="facility">Facility & Cleanliness</option>
                                <option value="other">Other</option>
                            </select></label>
                        </div>
                        <label class="flex flex-col gap-2"><span class="text-xs font-bold text-slate-500 uppercase">Description of incident</span>
                        <textarea name="description" required class="min-h-[160px] rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 px-4 py-4 font-medium focus:ring-primary shadow-inner" placeholder="Please describe issue in detail..."></textarea></label>
                        <div class="flex justify-end pt-4 border-t border-slate-50 dark:border-slate-800">
                            <button type="submit" name="submit_complaint" class="px-10 py-3.5 rounded-xl bg-primary text-white font-black shadow-xl hover:bg-blue-600 transition-all uppercase text-xs">Submit complaint</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <div class="space-y-6">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="text-xl font-black px-1 flex items-center gap-2 tracking-tight uppercase"><span class="material-symbols-outlined text-primary">forum</span> Clinical History</h3>
                        <button type="button" onclick="toggleAdvancedSearch()" class="h-11 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-black text-xs uppercase hover:bg-slate-200 transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px]">tune</span> Filters
                        </button>
                    </div>

                    <!-- Advanced Search Panel -->
                    <div id="advancedSearchPanel" class="hidden animate-fade-in bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-6 shadow-sm">
                        <div class="flex items-center gap-2 mb-6 font-black uppercase text-slate-900 dark:text-white">
                            <span class="material-symbols-outlined text-primary">filter_alt</span>
                            <h3 class="text-sm font-black">Advanced Filters</h3>
                            <button type="button" onclick="toggleAdvancedSearch()" class="ml-auto p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors">
                                <span class="material-symbols-outlined text-[18px]">close</span>
                            </button>
                        </div>

                        <form method="GET" class="space-y-5">
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

                            <!-- Category Filter -->
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Category</label>
                                <select name="filter_category" class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                    <option value="">-- All Categories --</option>
                                    <option value="billing" <?php echo (($_GET['filter_category'] ?? '') === 'billing') ? 'selected' : ''; ?>>Billing & Insurance</option>
                                    <option value="appointment" <?php echo (($_GET['filter_category'] ?? '') === 'appointment') ? 'selected' : ''; ?>>Appointment Scheduling</option>
                                    <option value="wait-time" <?php echo (($_GET['filter_category'] ?? '') === 'wait-time') ? 'selected' : ''; ?>>Wait Time</option>
                                    <option value="treatment" <?php echo (($_GET['filter_category'] ?? '') === 'treatment') ? 'selected' : ''; ?>>Treatment Quality</option>
                                    <option value="staff" <?php echo (($_GET['filter_category'] ?? '') === 'staff') ? 'selected' : ''; ?>>Staff Behavior</option>
                                    <option value="facility" <?php echo (($_GET['filter_category'] ?? '') === 'facility') ? 'selected' : ''; ?>>Facility & Cleanliness</option>
                                    <option value="other" <?php echo (($_GET['filter_category'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <!-- Status Filter -->
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Status</label>
                                <select name="filter_status" class="w-full h-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                    <option value="">-- All Statuses --</option>
                                    <option value="Pending" <?php echo (($_GET['filter_status'] ?? '') === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Responded" <?php echo (($_GET['filter_status'] ?? '') === 'Responded') ? 'selected' : ''; ?>>Responded</option>
                                </select>
                            </div>

                            <!-- Search Text -->
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 tracking-widest">Search Description</label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-[18px]">search</span>
                                    <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Search keywords..." 
                                        class="w-full h-10 pl-10 rounded-lg border border-slate-200 dark:border-slate-700 px-3 text-sm font-bold bg-white dark:bg-slate-900 focus:ring-primary">
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-3 pt-4 border-t border-slate-100 dark:border-slate-700 font-black uppercase">
                                <button type="submit" class="flex-1 h-10 rounded-lg bg-primary text-white font-black text-xs uppercase shadow-lg hover:bg-blue-600 transition-all flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]">search</span> Apply Filters
                                </button>
                                <a href="complaint.php" class="flex-1 h-10 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-black text-xs uppercase hover:bg-slate-100 dark:hover:bg-slate-700 transition-all flex items-center justify-center gap-2">
                                    <span class="material-symbols-outlined text-[16px]">refresh</span> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-md">
                        <table class="w-full text-left border-collapse">
                            <thead><tr class="bg-slate-50 dark:bg-slate-900/50 text-slate-400 text-[10px] font-black uppercase tracking-widest border-b dark:border-slate-800"><th class="p-6">Submission info</th><th class="p-6">Message Details</th><th class="p-6">Status</th><th class="p-6 text-right">Action</th></tr></thead>
                            <tbody class="divide-y divide-slate-50 dark:divide-slate-800 font-bold">
                                <?php if ($complaints && mysqli_num_rows($complaints) > 0): ?>
                                    <?php while($row = mysqli_fetch_assoc($complaints)): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="p-6 align-top">
                                            <p class="text-slate-900 dark:text-white font-black"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></p>
                                            <p class="text-[10px] text-slate-400 uppercase"><?php echo htmlspecialchars($row['category'] ?? 'General'); ?></p>
                                            <?php if($role !== 'patient'): ?>
                                                <p class="text-[10px] text-primary mt-1 font-black uppercase">
                                                    By: <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?> 
                                                    <span class="text-slate-400">(@<?php echo htmlspecialchars($row['username']); ?>)</span>
                                                </p>
                                                <p class="text-[9px] text-slate-400 font-bold uppercase">Account ID: #<?php echo $row['patient_id']; ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-6 align-top">
                                            <p class="text-sm italic font-medium">"<?php echo htmlspecialchars($row['description']); ?>"</p>
                                            <?php if(!empty($row['dentist_response'])): ?>
                                                <div class="mt-3 p-4 bg-primary/5 dark:bg-primary/10 rounded-2xl border-l-4 border-primary shadow-sm font-black">
                                                    <p class="text-[10px] font-black text-primary uppercase mb-1">Clinic Response</p>
                                                    <p class="text-xs italic text-slate-600 dark:text-slate-300 font-bold">"<?php echo htmlspecialchars($row['dentist_response']); ?>"</p>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-6 align-top"><span class="px-2 py-0.5 rounded-lg text-[10px] uppercase font-black <?php echo ($row['status']=='Pending') ? 'bg-orange-100 text-orange-600' : 'bg-green-100 text-green-600'; ?> shadow-sm"><?php echo $row['status']; ?></span></td>
                                        <td class="p-6 text-right align-top">
                                            <?php if($role !== 'patient'): ?>
                                                <button onclick='openResponseModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>)' class="px-4 py-2 rounded-lg bg-primary/10 text-primary hover:bg-primary hover:text-white transition-all font-black uppercase text-[10px] shadow-sm">Respond</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="p-12 text-center text-slate-400 italic font-bold uppercase text-[10px]">No historical feedback logs.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- RESPONSE MODAL -->
    <div id="responseModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0">
        <div class="bg-white dark:bg-slate-800 rounded-[32px] shadow-2xl p-8 w-full max-sm:mx-4 max-w-md transform scale-95 transition-all duration-300 shadow-2xl font-black" id="resContent">
            <h3 class="text-xl font-black text-slate-900 dark:text-white mb-6 uppercase">Clinical reply</h3>
            <form method="POST" action="" class="flex flex-col gap-6">
                <input type="hidden" name="complaint_id" id="resId">
                <div class="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border shadow-inner">
                    <p class="text-[10px] font-black text-slate-400 mb-1 uppercase tracking-widest">Patient message</p>
                    <p id="resDesc" class="text-sm italic font-medium text-slate-600 dark:text-slate-400"></p>
                </div>
                <textarea name="response_text" required class="h-32 w-full rounded-2xl border bg-slate-50 dark:bg-slate-900 p-4 font-bold focus:ring-primary shadow-inner text-slate-900 dark:text-white" placeholder="Type clinical response..."></textarea>
                <div class="flex gap-3">
                    <button type="button" onclick="closeM('responseModal')" class="flex-1 py-4 rounded-xl border font-bold text-slate-500 uppercase text-[10px]">Cancel</button>
                    <button type="submit" name="submit_response" class="flex-1 py-4 rounded-xl bg-primary text-white font-black shadow-lg uppercase text-[10px]">Save & Send</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EXIT CONFIRMATION -->
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
        function toggleAdvancedSearch() {
            const panel = document.getElementById('advancedSearchPanel');
            panel.classList.toggle('hidden');
            panel.classList.toggle('animate-fade-in');
        }

        function openBackModal() { showM('backModal', 'backModalContent'); }
        
        function openResponseModal(data) { 
            document.getElementById('resId').value = data.id; 
            document.getElementById('resDesc').innerText = data.description; 
            showM('responseModal', 'resContent'); 
        }
        
        function closeM(id) { 
            document.getElementById(id).classList.add('opacity-0'); 
            setTimeout(() => document.getElementById(id).classList.add('hidden'), 300); 
        }
        
        function showM(mId, cId) {
            const m = document.getElementById(mId), c = document.getElementById(cId);
            m.classList.remove('hidden'); m.classList.add('flex');
            setTimeout(() => { m.classList.remove('opacity-0'); c.classList.remove('scale-95'); c.classList.add('scale-100'); }, 10);
        }
    </script>
    </body>
    </html>
