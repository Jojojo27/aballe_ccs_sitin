<?php
session_start();
$_SESSION['user_id'] = 11;
$_GET['action'] = 'get_posts';
ob_start();
include __DIR__ . '/CSS_SITIN21/student_dashboard.php';
$out = ob_get_clean();
$preview = $out; if (strlen($preview) > 400) { $preview = substr($preview, 0, 400); }
$preview = str_replace(["`r", "`n"], ' ', $preview);
echo 'OUTPUT_PREVIEW=' . $preview . PHP_EOL;
json_decode($out, true);
echo 'JSON_VALID=' . (json_last_error() === JSON_ERROR_NONE ? '1' : '0') . PHP_EOL;
if (json_last_error() !== JSON_ERROR_NONE) { echo 'JSON_ERROR=' . json_last_error_msg() . PHP_EOL; }
?>
