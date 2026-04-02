<?php
// backend/config.php

$host = "sql100.infinityfree.com"; // Change this if moving from local to InfinityFree
$user = "if0_40636983";      // Change this to your hosting username
$pass = "dhRV05MgRCBw";          // Change this to your hosting password
$db   = "if0_40636983_setup";

// Create connection
$conn = mysqli_connect($host, $user, $pass, $db);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Ensure character set is set correctly for password hashes
mysqli_set_charset($conn, "utf8mb4");