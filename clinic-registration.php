<?php
session_start();
require_once 'backend/config.php';
date_default_timezone_set('Asia/Manila');

// Check if user is logged in and has super_admin role
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Normalize role for comparison
$userRole = strtolower(trim($_SESSION['role'] ?? ''));
$userRole = str_replace('-', '_', $userRole);

if ($userRole !== 'super_admin') {
    header('Location: login.php');
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$successMessage = '';
$errorMessage = '';

// Check if coming back from successful registration
if (isset($_SESSION['clinic_registration_success'])) {
    $successMessage = $_SESSION['clinic_registration_success'];
    unset($_SESSION['clinic_registration_success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Register New Clinic | San Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brandBlue: '#2C3E50',
                        brandGold: '#D4AF37',
                        primary: '#2C3E50',
                    },
                    fontFamily: {
                        sans: ['Montserrat', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
        }
        .loading {
            display: none;
        }
        .loading.show {
            display: inline-block;
        }
    </style>
</head>
<body class="bg-slate-50">
<div class="h-screen flex overflow-hidden">

<!-- SIDEBAR -->
<aside class="w-64 bg-slate-900 text-white flex flex-col">
    <div class="p-6 border-b border-slate-700">
        <div class="flex items-center gap-3">
            <div class="size-10 bg-brandGold rounded-lg flex items-center justify-center">
                <span class="material-symbols-outlined text-slate-900 font-bold">dental_tracks</span>
            </div>
            <div>
                <h1 class="font-bold text-base">San Nicolas</h1>
                <p class="text-brandGold text-xs font-bold">Admin Panel</p>
            </div>
        </div>
    </div>

    <nav class="flex-1 p-6 space-y-2">
        <a href="super-admin-dashboard.php" class="nav-link flex items-center gap-3 px-4 py-3 rounded-lg text-slate-300 hover:text-brandGold cursor-pointer transition">
            <span class="material-symbols-outlined">grid_view</span>
            <span class="text-sm font-medium">Dashboard</span>
        </a>
        <a onclick="location.href='clinic-registration.php'" class="nav-link active flex items-center gap-3 px-4 py-3 rounded-lg bg-brandBlue text-white cursor-pointer">
            <span class="material-symbols-outlined">add_circle</span>
            <span class="text-sm font-medium">Register Clinic</span>
        </a>
    </nav>

    <div class="p-6 border-t border-slate-700">
        <a href="?logout=1" class="flex items-center gap-3 px-4 py-3 rounded-lg text-slate-300 hover:text-red-400 transition border border-slate-600">
            <span class="material-symbols-outlined">logout</span>
            <span class="text-sm font-medium">Logout</span>
        </a>
    </div>
</aside>

<!-- MAIN CONTENT -->
<main class="flex-1 overflow-y-auto bg-slate-50">

    <!-- HEADER -->
    <header class="bg-white border-b border-gray-200 px-8 py-4 flex justify-between items-center shadow-sm">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Register New Clinic</h2>
            <p class="text-sm text-slate-500">Add a new dental clinic to the system</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-slate-500">notifications</span>
            <div class="size-10 rounded-full bg-slate-200 flex items-center justify-center">
                <span class="material-symbols-outlined text-slate-500">person</span>
            </div>
        </div>
    </header>

    <!-- CONTENT -->
    <div class="p-8">

        <!-- SUCCESS MESSAGE -->
        <?php if ($successMessage): ?>
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
            <div class="flex gap-3">
                <span class="material-symbols-outlined text-green-600">check_circle</span>
                <div>
                    <h3 class="font-bold text-green-800">Success!</h3>
                    <p class="text-sm text-green-700 mt-1"><?php echo htmlspecialchars($successMessage); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- REGISTRATION FORM -->
        <div class="max-w-2xl">
            <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-8">
                <form id="clinicRegistrationForm" class="space-y-6">

                    <!-- CLINIC INFORMATION SECTION -->
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">business</span>
                            Clinic Information
                        </h3>
                        
                        <div class="space-y-4">
                            <!-- Clinic Name -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Clinic Name *
                                </label>
                                <input 
                                    type="text" 
                                    name="clinic_name" 
                                    required
                                    placeholder="e.g., San Nicolas Dental Clinic"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                            </div>

                            <!-- Clinic Address -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Clinic Address *
                                </label>
                                <input 
                                    type="text" 
                                    name="clinic_address" 
                                    required
                                    placeholder="e.g., 123 Main Street, Medical Building"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                            </div>

                            <!-- Clinic Email -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Clinic Email *
                                </label>
                                <input 
                                    type="email" 
                                    name="clinic_email" 
                                    required
                                    placeholder="e.g., info@clinic.com"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                            </div>

                            <!-- Clinic Phone -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Clinic Phone *
                                </label>
                                <input 
                                    type="tel" 
                                    name="clinic_phone" 
                                    required
                                    placeholder="e.g., +63 2 1234 5678"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- OWNER INFORMATION SECTION -->
                    <div class="border-t border-gray-200 pt-6">
                        <h3 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">person</span>
                            Clinic Owner Information
                        </h3>
                        
                        <div class="space-y-4">
                            <!-- Owner Name -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Owner Full Name *
                                </label>
                                <input 
                                    type="text" 
                                    name="owner_name" 
                                    required
                                    placeholder="e.g., Dr. John Santos"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                            </div>

                            <!-- Owner Email -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Owner Email *
                                </label>
                                <input 
                                    type="email" 
                                    name="owner_email" 
                                    required
                                    placeholder="e.g., owner@clinic.com"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                                <p class="text-xs text-slate-500 mt-1">This email will be used to log into the clinic admin panel</p>
                            </div>

                            <!-- Owner Password -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Owner Password *
                                </label>
                                <input 
                                    type="password" 
                                    name="owner_password" 
                                    required
                                    id="ownerPassword"
                                    placeholder="At least 8 characters"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                                <p class="text-xs text-slate-500 mt-1">Must contain: uppercase, lowercase, and number</p>
                            </div>

                            <!-- Confirm Password -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">
                                    Confirm Password *
                                </label>
                                <input 
                                    type="password" 
                                    name="owner_password_confirm" 
                                    required
                                    id="ownerPasswordConfirm"
                                    placeholder="Re-enter password"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brandBlue"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- ERROR/INFO MESSAGES -->
                    <div id="formMessages" class="space-y-2"></div>

                    <!-- SUBMIT BUTTON -->
                    <div class="flex gap-3 pt-4">
                        <button 
                            type="submit"
                            class="flex-1 bg-brandBlue text-white py-3 rounded-lg font-medium hover:bg-blue-900 transition flex items-center justify-center gap-2"
                        >
                            <span class="material-symbols-outlined">add</span>
                            Register Clinic
                            <span class="loading show" id="submitLoading">
                                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                        </button>
                        <a href="super-admin-dashboard.php" class="px-6 py-3 border border-gray-300 rounded-lg font-medium text-slate-700 hover:bg-gray-50 transition">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>

            <!-- INFO BOX -->
            <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h4 class="font-bold text-blue-900 mb-2">What happens after registration?</h4>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>✓ A unique <strong>Clinic Code</strong> will be automatically generated</li>
                    <li>✓ The owner account will be created and ready to login</li>
                    <li>✓ The clinic code will be displayed for the owner to share with patients</li>
                    <li>✓ Patients will use this code to register in the mobile app</li>
                </ul>
            </div>
        </div>

    </div>

</main>

</div>

<script>
document.getElementById('clinicRegistrationForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // Get form data
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    // Validate passwords match
    if (data.owner_password !== data.owner_password_confirm) {
        showMessage('Passwords do not match', 'error');
        return;
    }

    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;

    try {
        const response = await fetch('backend/register-clinic.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            showMessage(result.message, 'success');
            
            // Reset form
            this.reset();
            
            // Redirect after 2 seconds
            setTimeout(() => {
                window.location.href = 'super-admin-dashboard.php?registration=success';
            }, 2000);
        } else {
            showMessage(result.message, 'error');
        }
    } catch (error) {
        showMessage('An error occurred. Please try again.', 'error');
        console.error('Error:', error);
    } finally {
        submitBtn.disabled = false;
    }
});

function showMessage(message, type) {
    const container = document.getElementById('formMessages');
    const bgColor = type === 'success' ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200';
    const textColor = type === 'success' ? 'text-green-800' : 'text-red-800';
    const icon = type === 'success' ? 'check_circle' : 'error';
    
    container.innerHTML = `
        <div class="p-4 ${bgColor} border rounded-lg flex items-start gap-3">
            <span class="material-symbols-outlined ${textColor}">${icon}</span>
            <p class="${textColor} text-sm">${message}</p>
        </div>
    `;
}
</script>

</body>
</html>
