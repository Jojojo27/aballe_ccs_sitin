<?php
session_start();

// Clear all admin session data
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_name']);
unset($_SESSION['admin_role']);
unset($_SESSION['admin_email']);

// Destroy the session
session_destroy();

// Redirect to admin login
header("Location: admin_login.php");
exit();
?>