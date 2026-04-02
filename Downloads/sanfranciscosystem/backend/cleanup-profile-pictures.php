<?php
// This script cleans up any invalid profile picture paths in the database
// Run it once to fix existing data, then delete it

require_once 'config.php';

// Check if profile_pictures column exists
$checkColumnSQL = "SHOW COLUMNS FROM patient_profiles LIKE 'profile_picture'";
$columnCheckResult = @mysqli_query($conn, $checkColumnSQL);

if (!$columnCheckResult || mysqli_num_rows($columnCheckResult) === 0) {
    die("profile_picture column does not exist in patient_profiles table");
}

// Get all profile pictures with paths
$query = "SELECT user_id, profile_picture FROM patient_profiles WHERE profile_picture IS NOT NULL AND profile_picture != ''";
$result = mysqli_query($conn, $query);

if (!$result) {
    die("Error: " . mysqli_error($conn));
}

$cleanedCount = 0;
$totalProcessed = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $totalProcessed++;
    $oldPath = $row['profile_picture'];
    
    // Clean the path
    $newPath = trim($oldPath);
    $newPath = str_replace('\\', '/', $newPath);
    // Remove /htdocs/ and similar prefixes
    $newPath = preg_replace('|^.*?/?(assets/images/profiles/)|', '$1', $newPath);
    // Remove leading slashes
    $newPath = ltrim($newPath, '/');
    
    // If path was modified, update it
    if ($newPath !== $oldPath) {
        $newPathEscaped = mysqli_real_escape_string($conn, $newPath);
        $updateQuery = "UPDATE patient_profiles SET profile_picture = '$newPathEscaped' WHERE user_id = '" . $row['user_id'] . "'";
        
        if (mysqli_query($conn, $updateQuery)) {
            $cleanedCount++;
            echo "✓ Cleaned path for user " . $row['user_id'] . ": <br>";
            echo "&nbsp;&nbsp;Old: " . htmlspecialchars($oldPath) . "<br>";
            echo "&nbsp;&nbsp;New: " . htmlspecialchars($newPath) . "<br><br>";
        } else {
            echo "✗ Error updating user " . $row['user_id'] . ": " . mysqli_error($conn) . "<br>";
        }
    }
}

echo "<hr>";
echo "<strong>Cleanup Complete!</strong><br>";
echo "Total records processed: $totalProcessed<br>";
echo "Records cleaned: $cleanedCount<br>";
echo "<br>";
echo "<p style='color: red; font-weight: bold;'>NOTE: You can now delete this file (cleanup-profile-pictures.php) as it has served its purpose.</p>";

mysqli_close($conn);
?>
