<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo "Session not set - user not logged in<br>";
} else {
    echo "Session is set - user ID: " . $_SESSION['user_id'] . "<br>";
}
?>
