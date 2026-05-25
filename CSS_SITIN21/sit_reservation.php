<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get unread notifications count
require_once 'config.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

if (isset($_GET['read_notification'])) {
    $notif_id = $_GET['read_notification'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $user_id]);
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit();
}

// Handle AJAX request for PC availability
if (isset($_GET['action']) && $_GET['action'] === 'get_pc_status') {
    header('Content-Type: application/json');
    
    $lab = isset($_GET['lab']) ? $_GET['lab'] : null;
    $date = isset($_GET['date']) ? $_GET['date'] : null;
    
    if (!$lab || !$date) {
        echo json_encode(['error' => 'Invalid lab or date']);
        exit();
    }
    
    $pcStatus = [];

    $pcCount = 50;
    try {
        $stmt = $pdo->prepare("SELECT pc_count FROM laboratories WHERE lab_number = ?");
        $stmt->execute([$lab]);
        $labConfig = $stmt->fetch();
        if ($labConfig && isset($labConfig['pc_count'])) {
            $pcCount = max(1, min(100, (int) $labConfig['pc_count']));
        }
    } catch (Throwable $e) {
        $pcCount = 50;
    }

    $inUseByPc = [];
    try {
        $stmt = $pdo->prepare("SELECT p.pc_no, CONCAT(u.first_name, ' ', u.last_name) AS occupant
                               FROM pc_usage p
                               INNER JOIN users u ON u.id = p.user_id
                               WHERE p.laboratory = ? AND p.date = ? AND p.is_active = 1");
        $stmt->execute([$lab, $date]);
        foreach ($stmt->fetchAll() as $row) {
            $inUseByPc[(int) $row['pc_no']] = $row['occupant'];
        }
    } catch (Throwable $e) {
        $inUseByPc = [];
    }

    $maintenanceByPc = [];
    try {
        $stmt = $pdo->prepare("SELECT pc_no FROM pc_maintenance WHERE laboratory = ? AND date = ? AND is_active = 1");
        $stmt->execute([$lab, $date]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pcNoValue) {
            $maintenanceByPc[(int) $pcNoValue] = true;
        }
    } catch (Throwable $e) {
        $maintenanceByPc = [];
    }

    $classInSession = false;
    $classInstructorLastName = '';
    try {
        $stmt = $pdo->prepare("SELECT lc.is_active, i.last_name
                               FROM lab_class lc
                               LEFT JOIN instructors i ON i.id = lc.instructor_id AND i.is_active = 1
                               WHERE lc.laboratory = ? AND lc.date = ?
                               LIMIT 1");
        $stmt->execute([$lab, $date]);
        $classRow = $stmt->fetch();
        if ($classRow && (int)($classRow['is_active'] ?? 0) === 1) {
            $classInSession = true;
            $classInstructorLastName = trim((string)($classRow['last_name'] ?? ''));
        }
    } catch (Throwable $e) {
        $classInSession = false;
        $classInstructorLastName = '';
    }

    for ($i = 1; $i <= $pcCount; $i++) {
        $pcNo = str_pad($i, 2, '0', STR_PAD_LEFT);
        $status = 'Vacant';
        $occupant = '';

        if ($classInSession) {
            $status = 'In-Class';
            $occupant = $classInstructorLastName !== '' ? $classInstructorLastName : 'Instructor';
        } elseif (isset($maintenanceByPc[$i])) {
            $status = 'Maintenance';
            $occupant = '(Under Maintenance)';
        } elseif (isset($inUseByPc[$i])) {
            $status = 'In-Use';
            $occupant = $inUseByPc[$i];
        }

        $pcStatus[] = [
            'pcNo' => $pcNo,
            'status' => $status,
            'occupant' => $occupant
        ];
    }

    echo json_encode($pcStatus);
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$student = $stmt->fetch();

if (!$student) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$_SESSION['id_number'] = $student['id_number'];
$_SESSION['first_name'] = $student['first_name'];
$_SESSION['last_name'] = $student['last_name'];
$_SESSION['middle_name'] = $student['middle_name'];
$_SESSION['session'] = $student['remaining_sessions'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['cancel_reservation'])) {
        $reservation_id = isset($_POST['reservation_id']) ? (int) $_POST['reservation_id'] : 0;

        if ($reservation_id <= 0) {
            $error = "Invalid reservation selected.";
        } else {
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'Cancelled' WHERE id = ? AND user_id = ? AND LOWER(status) = 'pending'");
            $stmt->execute([$reservation_id, $user_id]);
            if ($stmt->rowCount() > 0) {
                $success = "Reservation cancelled successfully.";
            } else {
                $error = "Only pending reservations can be cancelled.";
            }
        }
    } else {
        $purpose = $_POST['purpose'] ?? '';
        $lab = $_POST['lab'] ?? '';
        $time_in = $_POST['time_in'] ?? '';
        $time_out = $_POST['time_out'] ?? '';
        $date = $_POST['date'] ?? '';

        if ($purpose && $lab && $time_in && $time_out && $date) {
            // Insert reservation into database
            $stmt = $pdo->prepare("INSERT INTO reservations (user_id, purpose, laboratory, time_in, time_out, date, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            if ($stmt->execute([$user_id, $purpose, $lab, $time_in, $time_out, $date])) {
                $success = "Reservation submitted successfully! Please wait for admin approval.";
            } else {
                $error = "Failed to submit reservation. Please try again.";
            }
        } else {
            $error = "Please fill all fields";
        }
    }
}

// Student reservation list
$stmt = $pdo->prepare("SELECT id, purpose, laboratory, time_in, time_out, date, status, created_at
                       FROM reservations
                       WHERE user_id = ?
                       ORDER BY date DESC, time_in DESC, created_at DESC");
$stmt->execute([$user_id]);
$reservation_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
<title>Sit-in Reservation - CCS Sit-in Monitoring</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    html { font-size: 13px; zoom: 1; }
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    background: linear-gradient(2deg,rgba(203, 164, 237, 1) 0%, rgba(177, 204, 224, 1) 56%, rgba(4, 4, 59, 1) 100%);
    min-height: 100vh;
}

/* NAVBAR */
.navbar {
    background: linear-gradient(145deg, #2c3e50, #1a2634);
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    position: fixed;
    width: 100%;
    top: 0;
    z-index: 1000;
}
.nav-container { max-width: 1400px; margin: 0 auto; padding: 0.8rem 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
.logo-container { display: flex; align-items: center; gap: 15px; }
.nav-avatar { width: 45px; height: 45px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px rgba(255,255,255,0.3), 0 0 0 5px #f39c12, 0 0 15px rgba(243,156,18,0.5); transition: all 0.3s ease; }
.nav-avatar:hover { transform: scale(1.05); box-shadow: 0 0 0 2px rgba(255,255,255,0.4), 0 0 0 8px #f39c12, 0 0 25px rgba(243,156,18,0.7); }
.nav-avatar img { width: 32px; height: 32px; object-fit: contain; }
.logo-text { color: white; font-weight: 600; line-height: 1.2; }
.logo-text strong { font-size: 1.1rem; display: block; }
.logo-text small { font-size: 0.7rem; font-weight: 400; opacity: 0.8; }
.nav-links { display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; }
.nav-links a { text-decoration: none; color: white; font-weight: 500; transition: 0.3s; display: flex; align-items: center; gap: 8px; padding: 0.5rem 0; }
.nav-links a:hover { color: #f39c12; }
.nav-links a.active { color: #f39c12; }
.notification-icon { position: relative; cursor: pointer; margin-left: 0.5rem; }
.notification-icon i { font-size: 1.2rem; color: white; }
.notification-badge { position: absolute; top: -8px; right: -12px; background: #e74c3c; color: white; font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 50px; min-width: 18px; text-align: center; }
.notification-dropdown { position: absolute; top: 130%; right: 0; width: 350px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); z-index: 1100; display: none; overflow: hidden; max-height: 500px; overflow-y: auto; }
.notification-dropdown.show { display: block; }
.notification-header { padding: 12px 15px; background: linear-gradient(145deg, #2c3e50, #1a2634); color: white; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.1); }
.notification-item { padding: 12px 15px; border-bottom: 1px solid #e0e7ff; cursor: pointer; transition: 0.2s; text-decoration: none; display: block; color: #2c3e50; }
.notification-item:hover { background: #f8faff; }
.notification-item.unread { background: #fff8e7; }
.notification-item.unread:hover { background: #fff3cd; }
.notification-title { font-weight: 600; font-size: 0.85rem; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
.notification-title i { font-size: 0.8rem; }
.notification-message { font-size: 0.75rem; color: #666; margin-bottom: 4px; }
.notification-time { font-size: 0.65rem; color: #999; }
.notification-empty { text-align: center; padding: 30px; color: #999; }
.notification-footer {
    padding: 10px; text-align: center;
    background: #f8f9fa; border-top: 1px solid #e0e7ff;
}
.notification-footer a { color: #3498db; text-decoration: none; font-size: 0.75rem; }

/* MAIN CONTAINER */
.container {
    max-width: 1180px;
    margin: 100px auto 40px;
    padding: 0 20px;
}

/* CARD */
.card {
    background: white;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    margin-bottom: 5px;
    border: 1px solid #e0e7ff;
}

.card h2 {
    margin-bottom: 16px;
    color: #2c3e50;
    font-size: 1.45rem;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
}

.card h2 i {
    color: #3498db;
}

/* STUDENT INFO */
.student-info {
    background: #f8faff;
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 16px;
    border-left: 4px solid #3498db;
}

.student-info p {
    margin: 5px 0;
    font-size: 0.95rem;
    color: #555;
}

.student-info b {
    color: #2c3e50;
}

/* GRID */
.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(220px, 1fr));
    gap: 12px 14px;
    margin-bottom: 14px;
}

/* FORM GROUP */
.form-group {
    margin-bottom: 12px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #2c3e50;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* INPUT */
.input-box {
    position: relative;
}

.input-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #95a5a6;
    font-size: 16px;
    z-index: 1;
}

input, select {
    width: 100%;
    padding: 10px 12px 10px 40px;
    border: 2px solid #e0e7ff;
    border-radius: 12px;
    font-size: 13px;
    font-family: 'Poppins', sans-serif;
    background: #f8faff;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

input:focus, select:focus {
    border-color: #3498db;
    background: white;
    box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
    outline: none;
}

input:disabled {
    background: #ecf0f1;
    color: #7f8c8d;
    cursor: not-allowed;
}

input:hover, select:hover {
    border-color: #bdc3c7;
}

/* ALERT */
.alert {
    padding: 14px 18px;
    margin-bottom: 25px;
    border-radius: 12px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
}

.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Two-column reservation layout */
.reservation-main-grid {
    display: grid;
    grid-template-columns: 0.95fr 1.05fr;
    gap: 24px;
    align-items: stretch;
}

.reservation-main-grid .card {
    margin-bottom: 0;
}

.reservation-form-panel {
    min-width: 0;
}

.reservation-list-card {
    min-width: 0;
    height: auto;
    display: flex;
}

/* BUTTON */
.actions {
    margin-top: 10px;
    display: flex;
    justify-content: center;
    gap: 15px;
    padding-top: 12px;
    border-top: 1px solid #e0e7ff;
}

.btn {
    padding: 12px 28px;
    border: none;
    border-radius: 50px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Poppins', sans-serif;
}

.btn-primary {
    background: linear-gradient(145deg, #27ae60, #219a52);
    color: white;
    box-shadow: 0 4px 12px rgba(39,174,96,0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(39,174,96,0.4);
}

.btn-secondary {
    background: #95a5a6;
    color: white;
    box-shadow: 0 4px 12px rgba(127,140,141,0.3);
}

.btn-secondary:hover {
    background: #7f8c8d;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(127,140,141,0.4);
}

/* Reservation List */
.reservation-list-section {
    margin-top: 30px;
    padding-top: 24px;
    border-top: 2px solid #e0e7ff;
}

.reservation-list-side {
    margin-top: 0;
    padding-top: 0;
    border-top: none;
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
}

.reservation-list-section h3 {
    color: #2c3e50;
    font-size: 0.95rem;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}

.reservation-list-section h3 i {
    color: #3498db;
    font-size: 0.95rem;
}

.reservation-table-wrap {
    overflow-y: auto;
    overflow-x: hidden;
    border: 1px solid #e0e7ff;
    border-radius: 12px;
    background: #fdfdff;
    flex: 1;
}

.reservation-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.reservation-table th,
.reservation-table td {
    padding: 8px 6px;
    border-bottom: 1px solid #edf1ff;
    text-align: left;
    font-size: 0.74rem;
    color: #2c3e50;
    overflow-wrap: anywhere;
}

.reservation-table th {
    background: #f4f7ff;
    color: #445;
    font-weight: 600;
    font-size: 0.66rem;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.reservation-table th:nth-child(1),
.reservation-table td:nth-child(1) { width: 13%; }

.reservation-table th:nth-child(2),
.reservation-table td:nth-child(2) { width: 20%; white-space: nowrap; }

.reservation-table th:nth-child(3),
.reservation-table td:nth-child(3) { width: 15%; }

.reservation-table th:nth-child(4),
.reservation-table td:nth-child(4) { width: 19%; }

.reservation-table th:nth-child(5),
.reservation-table td:nth-child(5) { width: 17%; }

.reservation-table th:nth-child(6),
.reservation-table td:nth-child(6) { width: 16%; }

.reservation-table td:nth-child(5),
.reservation-table td:nth-child(6) {
    white-space: nowrap;
    overflow-wrap: normal;
    word-break: keep-all;
}

.reservation-table tbody tr:hover {
    background: #f8fbff;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 600;
    border: 1px solid transparent;
    white-space: nowrap;
}

.status-pending {
    background: #fff8e7;
    color: #9a6a00;
    border-color: #f5dc9e;
}

.status-approved {
    background: #eafaf1;
    color: #1b7d4d;
    border-color: #9ee3bf;
}

.status-disapproved {
    background: #fdeeee;
    color: #a33a3a;
    border-color: #f3b2b2;
}

.status-cancelled {
    background: #f0f2f5;
    color: #5d6775;
    border-color: #ced4df;
}

.btn-cancel-reservation {
    border: none;
    background: #e74c3c;
    color: #fff;
    padding: 5px 7px;
    border-radius: 8px;
    font-size: 0.64rem;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: 0.2s;
    white-space: nowrap;
}

.btn-cancel-reservation:hover {
    background: #c0392b;
}

.reservation-empty {
    border: 1px dashed #d7def3;
    border-radius: 12px;
    padding: 18px;
    text-align: center;
    color: #6b7280;
    background: #f9fbff;
    font-size: 0.85rem;
}

/* PC GRID */
.pc-grid-section {
    margin-top: 30px;
    padding-top: 30px;
    border-top: 2px solid #e0e7ff;
}

.pc-grid-section h3 {
    color: #2c3e50;
    font-size: 1.1rem;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.pc-grid-section h3 i {
    color: #3498db;
}

.pc-grid-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(50px, 1fr));
    gap: 8px;
    margin-bottom: 20px;
    max-height: 400px;
    overflow-y: auto;
    padding: 15px;
    background: #f8faff;
    border-radius: 12px;
    border: 1px solid #e0e7ff;
}

.pc-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    aspect-ratio: 1;
    border-radius: 8px;
    border: 2px solid #ddd;
    font-size: 0.7rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    padding: 4px;
    text-align: center;
    background: white;
}

.pc-item:hover {
    transform: scale(1.05);
}

.pc-item.vacant {
    background: #d4edda;
    border-color: #28a745;
    color: #155724;
}

.pc-item.in-use {
    background: #fff3cd;
    border-color: #ffc107;
    color: #856404;
}

.pc-item.reserved {
    background: #d1ecf1;
    border-color: #17a2b8;
    color: #0c5460;
}

.pc-item.pending {
    background: #e2e3e5;
    border-color: #6c757d;
    color: #383d41;
}

.pc-number {
    font-weight: 700;
    font-size: 0.8rem;
}

.pc-status {
    font-size: 0.6rem;
    margin-top: 2px;
    opacity: 0.8;
}

.pc-grid-stats {
    display: flex;
    justify-content: space-around;
    gap: 10px;
    margin-top: 15px;
    padding: 15px;
    background: #f8faff;
    border-radius: 12px;
    border: 1px solid #e0e7ff;
    flex-wrap: wrap;
}

.pc-stat {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    font-weight: 500;
}

.pc-stat-badge {
    display: inline-block;
    width: 20px;
    height: 20px;
    border-radius: 4px;
}

.pc-stat-badge.vacant {
    background: #28a745;
}

.pc-stat-badge.in-use {
    background: #ffc107;
}

.pc-stat-badge.reserved {
    background: #17a2b8;
}

.pc-stat-badge.pending {
    background: #6c757d;
}

.pc-grid-loading {
    text-align: center;
    padding: 20px;
    color: #666;
}

.pc-grid-loading i {
    font-size: 2rem;
    color: #3498db;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* RESPONSIVE */
@media(max-width: 768px) {
    .nav-container {
        flex-direction: column;
        text-align: center;
    }
    
    .logo-container {
        justify-content: center;
    }
    
    .nav-links {
        justify-content: center;
    }
    
    .container {
        margin-top: 140px;
        padding: 0 15px;
    }
    
    .grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .reservation-main-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .reservation-table-wrap {
        overflow-x: auto;
        overflow-y: visible;
    }

    .reservation-table {
        min-width: 640px;
        table-layout: auto;
    }

    .reservation-list-card {
        height: auto;
        display: block;
    }
    
    .card {
        padding: 20px;
    }
    
    .card h2 {
        font-size: 1.3rem;
    }
    
    .actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .student-info {
        padding: 15px;
    }
    
    .notification-dropdown {
        width: 300px;
        right: -50px;
    }
}

@media (max-width: 1100px) {
    .reservation-main-grid {
        grid-template-columns: 1fr;
    }

    .reservation-list-card {
        height: auto;
        display: block;
    }
}

@media (max-width: 480px) {
    .notification-dropdown {
        width: 280px;
        right: -30px;
    }
}
</style>
</head>

<body>

<!-- NAVBAR -->
<nav class="navbar">
    <div class="nav-container">
        <div class="logo-container">
            <div class="nav-avatar">
                <img src="ccsmainlogo.png" alt="CCS Logo">
            </div>
            <div class="logo-text">
                <strong>CCS</strong>
                <small>Sit-in Monitoring</small>
            </div>
        </div>
        <div class="nav-links">
            <a href="student_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="profile_edit.php"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <div class="nav-dropdown">
                <a href="#"><i class="fas fa-clock"></i> Sit-in <i class="fas fa-caret-down"></i></a>
                <div class="nav-dropdown-content">
                    <a href="sit_reservation.php" class="active"><i class="fas fa-desktop"></i> Current Sit-in</a>
                    <a href="sit_history.php"><i class="fas fa-file-alt"></i> Sit-in Records</a>
                </div>
            </div>
            <a href="student_feedback.php"><i class="fas fa-star"></i> Feedback</a>
            <a href="logout.php" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="notification-icon" id="notificationIcon">
            <i class="fas fa-bell"></i>
            <?php if ($unread_count > 0): ?>
                <span class="notification-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <i class="fas fa-bell"></i> Notifications
                </div>
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="notification-empty">
                            <i class="fas fa-bell-slash"></i>
                            <p>No notifications yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <a href="?read_notification=<?php echo $notif['id']; ?>"
                               class="notification-item <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>">
                                <div class="notification-title">
                                    <?php if ($notif['type'] == 'announcement'): ?>
                                        <i class="fas fa-bullhorn" style="color: #f39c12;"></i>
                                    <?php elseif ($notif['type'] == 'reservation'): ?>
                                        <i class="fas fa-calendar-check" style="color: #27ae60;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-info-circle" style="color: #3498db;"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($notif['title']); ?>
                                </div>
                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div class="notification-time">
                                    <i class="fas fa-clock"></i> <?php echo date('M d, h:i A', strtotime($notif['created_at'])); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="notification-footer">
                    <a href="#">Mark all as read</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="container">
    <?php if($success): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>
    
    <?php if($error): ?>
        <div class="alert error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="reservation-main-grid">
        <div class="card reservation-form-panel">
            <h2><i class="fas fa-calendar-alt"></i> Sit-in Reservation</h2>

            <!-- Student Info Summary -->
            <div class="student-info">
                <p><b>ID Number:</b> <?php echo htmlspecialchars($student['id_number']); ?></p>
                <p><b>Full Name:</b> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']); ?></p>
                <p><b>Remaining Sessions:</b> <?php echo htmlspecialchars($student['remaining_sessions']); ?></p>
            </div>

                <form method="POST">
                    <div class="grid">
                        <div class="form-group">
                            <label for="purpose">
                                <i class="fas fa-tasks"></i> Purpose:
                            </label>
                            <div class="input-box">
                                <i class="fas fa-tasks"></i>
                                <select id="purpose" name="purpose" required>
                                    <option value="">Select Purpose</option>
                                    <option value="C Programming">C Programming</option>
                                            <option value="Java Programming">Java Programming</option>
                                            <option value="Python Programming">Python Programming</option>
                                            <option value="Web Development">Web Development</option>
                                            <option value="Database Design">Database Design</option>
                                            <option value="Network Security">Network Security</option>
                                            <option value="Research">Research</option>
                                            <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="lab">
                                <i class="fas fa-desktop"></i> Laboratory:
                            </label>
                            <div class="input-box">
                                <i class="fas fa-desktop"></i>
                                <select id="lab" name="lab" required>
                                    <option value="">Select Laboratory</option>
                                    <option value="523">Lab 523</option>
                                            <option value="524">Lab 524</option>
                                            <option value="525">Lab 525</option>
                                            <option value="526">Lab 526</option>
                                            <option value="527">Lab 527</option>
                                            <option value="528">Lab 528</option>
                                            <option value="529">Lab 529</option>
                                            <option value="530">Lab 530</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" id="pcNumberGroup" style="display: none;">
                            <label for="pc_number">
                                <i class="fas fa-laptop"></i> PC Number:
                            </label>
                            <div class="input-box">
                                <i class="fas fa-laptop"></i>
                                <select id="pc_number" name="pc_number">
                                    <option value="">Select PC...</option>
                                </select>
                            </div>
                            <small id="pcStatusInfo" style="display:block;margin-top:8px;color:#666;font-size:12px;"></small>
                        </div>

                        <div class="form-group">
                            <label for="time_in">
                                <i class="fas fa-clock"></i> Time In:
                            </label>
                            <div class="input-box">
                                <i class="fas fa-clock"></i>
                                <input type="time" id="time_in" name="time_in" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="time_out">
                                <i class="fas fa-clock"></i> Time Out:
                            </label>
                            <div class="input-box">
                                <i class="fas fa-clock"></i>
                                <input type="time" id="time_out" name="time_out" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="date">
                                <i class="fas fa-calendar"></i> Date:
                            </label>
                            <div class="input-box">
                                <i class="fas fa-calendar"></i>
                                <input type="date" id="date" name="date"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       max="<?php echo date('Y-m-d', strtotime('+14 days')); ?>"
                                       value="<?php echo date('Y-m-d'); ?>"
                                       required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="duration">
                                <i class="fas fa-hourglass-half"></i> Duration:
                            </label>
                            <div class="input-box">
                                <i class="fas fa-hourglass-half"></i>
                                <input type="text" id="duration" value="--:--" readonly disabled>
                            </div>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calendar-check"></i> Reserve Seat
                        </button>
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                    </div>
                </form>
        </div>

        <div class="card reservation-list-card">
            <div class="reservation-list-section reservation-list-side">
                <h3><i class="fas fa-list"></i> My Reservation List</h3>

                <?php if (empty($reservation_list)): ?>
                    <div class="reservation-empty">You have no reservations yet.</div>
                <?php else: ?>
                    <div class="reservation-table-wrap">
                        <table class="reservation-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Laboratory</th>
                                    <th>Purpose</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservation_list as $res): ?>
                                    <?php
                                    $status_raw = trim((string) ($res['status'] ?? 'Pending'));
                                    $status_key = strtolower($status_raw);
                                    if ($status_key === 'approved') {
                                        $status_class = 'status-approved';
                                        $status_icon = 'fa-check-circle';
                                    } elseif ($status_key === 'disapproved') {
                                        $status_class = 'status-disapproved';
                                        $status_icon = 'fa-times-circle';
                                    } elseif ($status_key === 'cancelled' || $status_key === 'canceled') {
                                        $status_class = 'status-cancelled';
                                        $status_icon = 'fa-ban';
                                        $status_raw = 'Cancelled';
                                    } else {
                                        $status_class = 'status-pending';
                                        $status_icon = 'fa-hourglass-half';
                                        $status_raw = 'Pending';
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($res['date'])); ?></td>
                                        <td>
                                            <?php echo date('h:i A', strtotime($res['time_in'])); ?> -
                                            <?php echo date('h:i A', strtotime($res['time_out'])); ?>
                                        </td>
                                        <td>Lab <?php echo htmlspecialchars($res['laboratory']); ?></td>
                                        <td><?php echo htmlspecialchars($res['purpose']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <i class="fas <?php echo $status_icon; ?>"></i> <?php echo htmlspecialchars($status_raw); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (strtolower($status_raw) === 'pending'): ?>
                                                <form method="POST" style="margin:0;" onsubmit="return confirm('Cancel this reservation?');">
                                                    <input type="hidden" name="reservation_id" value="<?php echo (int) $res['id']; ?>">
                                                    <button type="submit" name="cancel_reservation" class="btn-cancel-reservation">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;font-size:0.75rem;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function renderPcDropdown(pcData, statusMessage = '') {
        const pcGroup = document.getElementById('pcNumberGroup');
        const pcSelect = document.getElementById('pc_number');
        const pcStatusInfo = document.getElementById('pcStatusInfo');

        pcSelect.innerHTML = '<option value="">Select PC...</option>';
        pcSelect.disabled = false;

        const stats = {
            vacant: 0,
            'in-use': 0,
            reserved: 0,
            pending: 0,
            maintenance: 0,
            'in-class': 0
        };

        pcData.forEach(pc => {
            const option = document.createElement('option');
            option.value = pc.pcNo;
            option.textContent = `PC ${pc.pcNo} - ${pc.status}${pc.occupant ? ` (${pc.occupant})` : ''}`;

            if (pc.status === 'Vacant') {
                option.style.color = '#27ae60';
                stats.vacant++;
            } else if (pc.status === 'In-Use') {
                option.style.color = '#e67e22';
                option.disabled = true;
                stats['in-use']++;
            } else if (pc.status === 'Reserved') {
                option.style.color = '#17a2b8';
                option.disabled = true;
                stats.reserved++;
            } else if (pc.status === 'Pending') {
                option.style.color = '#6c757d';
                option.disabled = true;
                stats.pending++;
            } else if (pc.status === 'Maintenance') {
                option.style.color = '#e74c3c';
                option.disabled = true;
                stats.maintenance++;
            } else if (pc.status === 'In-Class') {
                option.style.color = '#8e44ad';
                option.disabled = true;
                stats['in-class']++;
            }

            pcSelect.appendChild(option);
        });

        pcStatusInfo.textContent = statusMessage || `Available: ${stats.vacant} | In-Use: ${stats['in-use']} | In-Class: ${stats['in-class']} | Maintenance: ${stats.maintenance}`;
        pcGroup.style.display = 'block';
    }

    function showPcStatusUnavailable(message) {
        const pcGroup = document.getElementById('pcNumberGroup');
        const pcSelect = document.getElementById('pc_number');
        const pcStatusInfo = document.getElementById('pcStatusInfo');

        pcSelect.innerHTML = '<option value="">PC status unavailable</option>';
        pcSelect.disabled = true;
        pcStatusInfo.textContent = message;
        pcGroup.style.display = 'block';
    }

    // Fetch PC availability based on selected lab and date
    async function fetchPCStatus() {
        const lab = document.getElementById('lab').value;
        const date = document.getElementById('date').value;
        
        if (!lab || !date) {
            document.getElementById('pcNumberGroup').style.display = 'none';
            return;
        }

        try {
            const endpoint = `${window.location.pathname}?action=get_pc_status&lab=${encodeURIComponent(lab)}&date=${encodeURIComponent(date)}`;
            const response = await fetch(endpoint, {
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-store'
            });

            const pcData = await response.json();
            
            if (response.ok && Array.isArray(pcData)) {
                renderPcDropdown(pcData);
            } else {
                showPcStatusUnavailable('Live availability could not be loaded from the admin PC status.');
            }
        } catch (error) {
            console.error('Error fetching PC status:', error);
            showPcStatusUnavailable('Live availability could not be loaded from the admin PC status.');
        }
    }

    // Add event listeners for lab and date changes
    document.getElementById('lab').addEventListener('change', fetchPCStatus);
    document.getElementById('date').addEventListener('change', fetchPCStatus);

    // Calculate duration between time in and time out
    const timeInField = document.getElementById('time_in');
    const timeOutField = document.getElementById('time_out');
    const durationField = document.getElementById('duration');

    function calculateDuration() {
        const timeIn = timeInField.value;
        const timeOut = timeOutField.value;

        if (timeIn && timeOut) {
            const [inHours, inMinutes] = timeIn.split(':').map(Number);
            const [outHours, outMinutes] = timeOut.split(':').map(Number);

            const inTotalMinutes = inHours * 60 + inMinutes;
            const outTotalMinutes = outHours * 60 + outMinutes;

            let totalMinutes = outTotalMinutes - inTotalMinutes;

            if (totalMinutes > 0) {
                const hours = Math.floor(totalMinutes / 60);
                const minutes = totalMinutes % 60;
                durationField.value = `${hours}h ${minutes}m`;
            } else {
                durationField.value = 'Invalid time range';
            }
        } else {
            durationField.value = '--:--';
        }
    }

    timeInField.addEventListener('change', calculateDuration);
    timeOutField.addEventListener('change', calculateDuration);

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const timeIn = timeInField.value;
        const timeOut = timeOutField.value;
        const purpose = document.getElementById('purpose').value;
        const lab = document.getElementById('lab').value;
        const date = document.getElementById('date').value;

        // Check if all required fields are filled
        if (!purpose || !lab || !timeIn || !timeOut || !date) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }

        // Validate time range
        if (timeIn >= timeOut) {
            e.preventDefault();
            alert('Time Out must be later than Time In.');
            return false;
        }

        // Check if date is not in the past
        const selectedDate = new Date(date);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        if (selectedDate < today) {
            e.preventDefault();
            alert('Please select a current or future date.');
            return false;
        }

        return confirm('Are you sure you want to submit this reservation?');
    });

    // Set minimum time to current time for today
    document.getElementById('date').addEventListener('change', function() {
        const selectedDate = new Date(this.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        if (selectedDate.getTime() === today.getTime()) {
            // If today, set minimum time to current time + 30 minutes
            const now = new Date();
            now.setMinutes(now.getMinutes() + 30);
            const minTime = now.toTimeString().slice(0, 5);
            timeInField.min = minTime;
        } else {
            // For future dates, allow any time
            timeInField.min = '07:00';
        }
    });

    // Initialize minimum time on page load
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('date').dispatchEvent(new Event('change'));
    });

    // Notification dropdown toggle
    const notificationIcon = document.getElementById('notificationIcon');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    notificationIcon.addEventListener('click', function(event) {
        event.stopPropagation();
        notificationDropdown.classList.toggle('show');
    });
    
    document.addEventListener('click', function() {
        notificationDropdown.classList.remove('show');
    });
    
    notificationDropdown.addEventListener('click', function(event) {
        event.stopPropagation();
    });
</script>
</body>
</html>