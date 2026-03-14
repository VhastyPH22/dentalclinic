<?php
/**
 * Backend script to set up a super admin user
 * This script creates/updates a super admin account
 */

require_once 'config.php';

// Check if super_admin already exists
$checkSql = "SELECT id FROM users WHERE role = 'super_admin' LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);

if (mysqli_num_rows($checkResult) > 0) {
    echo "✅ Super admin account already exists<br>";
} else {
    // Create default super admin user
    // Credentials: username=superadmin, password=SuperAdmin@2024
    $username = "superadmin";
    $email = "admin@sannicolasclinic.com";
    $password = password_hash("SuperAdmin@2024", PASSWORD_BCRYPT);
    $first_name = "Super";
    $last_name = "Admin";
    
    $insertSql = "INSERT INTO users 
        (first_name, last_name, email, username, password, role, tenant_id, is_archived, created_at)
        VALUES 
        ('$first_name', '$last_name', '$email', '$username', '$password', 'super_admin', 1, 0, NOW())";
    
    if (mysqli_query($conn, $insertSql)) {
        echo "✅ Super admin account created successfully<br>";
        echo "Username: $username<br>";
        echo "Password: SuperAdmin@2024<br>";
        echo "⚠️ Please change the default password after first login<br>";
    } else {
        echo "❌ Error creating super admin: " . mysqli_error($conn) . "<br>";
    }
}

// Verify tenants table has status and approved columns
$checkStatusSql = "SHOW COLUMNS FROM tenants LIKE 'status'";
$statusResult = mysqli_query($conn, $checkStatusSql);

if (mysqli_num_rows($statusResult) > 0) {
    echo "✅ Tenants table has status column<br>";
} else {
    echo "❌ Tenants table missing status column<br>";
}

echo "<br>Setup verification complete!";
?>
