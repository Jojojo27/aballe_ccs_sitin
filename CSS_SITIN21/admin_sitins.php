<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

function normalizeSitInDate($value) {
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function normalizeSitInTime($value) {
    $value = trim((string) $value);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value, $matches)) {
        return null;
    }
    $hour = $matches[1];
    $minute = $matches[2];
    $second = isset($matches[3]) && $matches[3] !== '' ? ltrim($matches[3], ':') : '00';
    return sprintf('%s:%s:%s', $hour, $minute, $second);
}

function combineSitInDateTime($date, $time) {
    if ($date === null || $time === null) {
        return null;
    }
    return $date . ' ' . $time;
}

function normalizePcNumber($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (!preg_match('/^\d{1,3}$/', (string) $value)) {
        return null;
    }
    $pcNo = (int) $value;
    if ($pcNo < 1 || $pcNo > 100) {
        return null;
    }
    return $pcNo;
}

function ensurePcUsageTable($pdo) {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pc_usage'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE pc_usage (
            id INT PRIMARY KEY AUTO_INCREMENT,
            laboratory VARCHAR(10) NOT NULL,
            pc_no INT NOT NULL,
            date DATE NOT NULL,
            user_id INT NOT NULL,
            sitin_id INT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_pc_usage (laboratory, pc_no, date)
        )");
    }
}

function formatSitInTimeForDisplay($value) {
    $value = trim((string) $value);
    if ($value === '' || stripos($value, '0000-00-00') !== false) {
        return 'N/A';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return 'N/A';
    }
    return date('h:i A', $ts);
}

// Cleanup old manual-toggle sit-ins that are no longer linked to active PC usage.
try {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pc_usage'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        $pdo->exec("UPDATE sit_in_history s
                    LEFT JOIN pc_usage p ON p.sitin_id = s.id AND p.is_active = 1
                                        SET s.time_out = NOW(), s.status = 'Completed'
                    WHERE s.time_out IS NULL
                      AND s.status = 'Active'
                      AND s.purpose = 'Admin Manual PC Toggle'
                      AND p.id IS NULL");
    }
} catch (Throwable $e) {
    // Ignore cleanup failure to avoid blocking page load
}

// Handle logout with feedback (time out)
if (isset($_POST['logout_with_feedback'])) {
    $id = (int)$_POST['logout_with_feedback'];
    $admin_feedback = trim($_POST['admin_feedback'] ?? '');
    $admin_feedback_type = $_POST['admin_feedback_type'] ?? '';
    $admin_rating = (int)($_POST['admin_rating'] ?? 0);

    $stmt = $pdo->prepare("UPDATE sit_in_history SET time_out = NOW(), status = 'Completed', admin_feedback = ?, admin_feedback_type = ?, admin_rating = ? WHERE id = ?");
    $stmt->execute([$admin_feedback, $admin_feedback_type, $admin_rating, $id]);

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pc_usage'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE pc_usage SET is_active = 0 WHERE sitin_id = ?");
            $stmt->execute([$id]);
        }
    } catch (Throwable $e) {
        // Ignore pc_usage cleanup failure
    }

    $_SESSION['success_msg'] = "Student logged out and feedback recorded!";
    header("Location: admin_sitins.php?msg=logout");
    exit();
}

// Handle sit-in submission from modal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sit_in_from_modal'])) {
    $student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
    $purpose = isset($_POST['purpose']) ? trim($_POST['purpose']) : '';
    $laboratory = isset($_POST['laboratory']) ? trim($_POST['laboratory']) : '';
    $time_in = normalizeSitInTime($_POST['time_in'] ?? '');
    $date = normalizeSitInDate($_POST['date'] ?? '');
    $pcNumber = normalizePcNumber($_POST['pc_number'] ?? null);
    $timeInDateTime = combineSitInDateTime($date, $time_in);

    if ($student_id <= 0 || $purpose === '' || $laboratory === '' || $time_in === null || $date === null || $timeInDateTime === null) {
        $_SESSION['error_msg'] = "Invalid sit-in input. Please use a valid date and time.";
        header("Location: admin_sitins.php");
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT remaining_sessions FROM users WHERE id = ?");
    $stmt->execute([$student_id]);
    $remaining = $stmt->fetchColumn();
    
    if ($remaining <= 0) {
        $_SESSION['error_msg'] = "Student has no remaining sessions left!";
    } else {
        if ($pcNumber !== null) {
            try {
                ensurePcUsageTable($pdo);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM pc_usage WHERE laboratory = ? AND pc_no = ? AND date = ? AND is_active = 1");
                $stmt->execute([$laboratory, $pcNumber, $date]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $_SESSION['error_msg'] = "Selected PC is already in use for the selected date.";
                    header("Location: admin_sitins.php");
                    exit();
                }
            } catch (Throwable $e) {
                // If usage validation fails, continue with sit-in creation
            }
        }

        $stmt = $pdo->prepare("INSERT INTO sit_in_history (user_id, purpose, laboratory, time_in, date, status) VALUES (?, ?, ?, ?, ?, 'Active')");
        
        if ($stmt->execute([$student_id, $purpose, $laboratory, $timeInDateTime, $date])) {
            $sitinId = (int) $pdo->lastInsertId();

            if ($pcNumber !== null) {
                try {
                    ensurePcUsageTable($pdo);
                    $stmt = $pdo->prepare("INSERT INTO pc_usage (laboratory, pc_no, date, user_id, sitin_id, is_active)
                                          VALUES (?, ?, ?, ?, ?, 1)
                                          ON DUPLICATE KEY UPDATE
                                            user_id = VALUES(user_id),
                                            sitin_id = VALUES(sitin_id),
                                            is_active = 1,
                                            updated_at = CURRENT_TIMESTAMP");
                    $stmt->execute([$laboratory, $pcNumber, $date, $student_id, $sitinId]);
                } catch (Throwable $e) {
                    // Ignore pc_usage persistence failure and keep sit-in record
                }
            }

            $stmt = $pdo->prepare("UPDATE users SET remaining_sessions = remaining_sessions - 1 WHERE id = ?");
            $stmt->execute([$student_id]);
            
            $_SESSION['success_msg'] = "Sit-in recorded successfully! 1 session deducted.";
        } else {
            $_SESSION['error_msg'] = "Failed to record sit-in.";
        }
    }
    
    header("Location: admin_sitins.php");
    exit();
}

// Get messages
$success_msg = '';
$error_msg = '';
if (isset($_SESSION['success_msg'])) {
    $success_msg = '<div class="alert success">' . $_SESSION['success_msg'] . '</div>';
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error_msg = '<div class="alert error">' . $_SESSION['error_msg'] . '</div>';
    unset($_SESSION['error_msg']);
}

// Get ONLY active sit-ins (not logged out) - NO COMPLETED HERE
$stmt = $pdo->query("
    SELECT s.*, TIME(s.time_in) AS time_in_only, u.id_number, u.first_name, u.last_name, u.profile_pic, p.pc_no AS assigned_pc_no
    FROM sit_in_history s 
    JOIN users u ON s.user_id = u.id 
    LEFT JOIN pc_usage p ON p.sitin_id = s.id AND p.is_active = 1
    WHERE s.time_out IS NULL
    ORDER BY s.date DESC, s.time_in DESC
");
$active_sitins = $stmt->fetchAll();

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'logout') $message = '<div class="alert success">Student logged out successfully!</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sit-in Records - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html { font-size: 13px; zoom: 1; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; }
        
        .navbar {
            background: linear-gradient(135deg, #2c3e50, #1a2634);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 220px;
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1.2rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .navbar-links {
            display: flex;
            flex-direction: column;
            flex: 1;
            justify-content: space-evenly;
        }
        .navbar-links a {
            color: rgba(255,255,255,0.78);
            text-decoration: none;
            padding: 1.2rem 1rem;
            transition: 0.2s;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border-left: 3px solid transparent;
            white-space: nowrap;
        }
        .navbar-links a i { font-size: 0.9rem; width: 16px; text-align: center; }
        .navbar-links a:hover { background: rgba(255,255,255,0.08); border-left-color: #3498db; color: white; }
        .navbar-links a.active { background: rgba(52,152,219,0.2); border-left-color: #3498db; color: white; }
        .logout-btn {
            display: flex !important;
            align-items: center !important;
            gap: 0.6rem !important;
            background: #e74c3c !important;
            color: white !important;
            text-decoration: none;
            padding: 0.75rem 1rem !important;
            font-size: 0.8rem !important;
            border-radius: 0 !important;
            margin: 0 !important;
            border-left: 3px solid transparent !important;
            white-space: nowrap;
        }
        .logout-btn i { font-size: 0.9rem; width: 16px; text-align: center; }
        .logout-btn:hover { background: #c0392b !important; }
        .dark-mode-toggle {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            z-index: 10001;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.5rem 1.1rem;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            transition: 0.2s;
        }
        .dark-mode-toggle:hover { background: #2980b9; }
        .dark-mode-toggle i { font-size: 0.9rem; }
        body.dark-mode { background: #1a2332 !important; }
        body.dark-mode .main-content { background: #1e2a38; color: #dde3ea; }
        body.dark-mode table { background: #253040 !important; color: #dde3ea !important; }
        body.dark-mode th { background: #1a2634 !important; color: #dde3ea !important; }
        body.dark-mode td { border-color: #2c3e50 !important; color: #dde3ea !important; }
        
        .main-content { margin-left: 220px; padding: 2rem; min-height: 100vh; }
        
        .page-header { margin-bottom: 2rem; }
        .page-header h1 { font-size: 1.8rem; color: #2c3e50; display: flex; align-items: center; gap: 0.8rem; }
        
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .stat-icon {
            width: 55px;
            height: 55px;
            background: linear-gradient(145deg, #3498db, #2980b9);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        .stat-info h3 { font-size: 0.8rem; color: #666; margin-bottom: 0.3rem; }
        .stat-info p { font-size: 1.8rem; font-weight: 700; color: #2c3e50; }
        
        /* Filters */
        .filters {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .filters select, .filters input {
            padding: 0.6rem;
            border: 2px solid #e0e7ff;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            flex: 1;
            min-width: 150px;
        }
        
        .section-header {
            margin: 1.5rem 0 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header h2 {
            font-size: 1.3rem;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .badge-active {
            background: #f39c12;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 50px;
            font-size: 0.7rem;
        }
        
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow-x: auto;
            margin-bottom: 2rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        th {
            background: #34495e;
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 500;
            font-size: 0.85rem;
        }
        td {
            padding: 1rem;
            border-bottom: 1px solid #e0e7ff;
            font-size: 0.85rem;
        }
        tr:hover { background: #f8faff; }
        
        .student-info {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        .student-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.7rem;
            display: inline-block;
        }
        .status-active { background: #fff3cd; color: #856404; }
        
        .btn-logout {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            transition: all 0.3s;
        }
        .btn-logout:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        .modal-content {
            background-color: white;
            margin: 3% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 550px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            padding: 1.2rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 20px 20px 0 0;
        }
        .modal-header .close {
            font-size: 1.8rem;
            cursor: pointer;
        }
        .modal-body { padding: 1.5rem; }
        
        .input-group {
            display: flex;
            gap: 0.8rem;
        }
        .input-group input {
            flex: 1;
            padding: 0.8rem;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
        }
        .btn-search {
            background: #3498db;
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
        }
        .readonly-field { background: #ecf0f1; cursor: not-allowed; }
        .session-field { background: #e8f8ef; font-weight: 600; color: #27ae60; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
        }
        .btn-close {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
        }
        .btn-sit-in {
            background: linear-gradient(145deg, #27ae60, #219a52);
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideIn { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
            .navbar-links { justify-content: center; }
            .stats-grid { grid-template-columns: 1fr; }
            .filters { flex-direction: column; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn-close, .btn-sit-in { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
        <div class="app-wrapper">
            <!-- Sidebar Navigation -->
            <aside class="sidebar" style="width: 250px; background: #111827; color: #e5e7eb; min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; position: fixed; left: 0; top: 0; bottom: 0; z-index: 100;">
                <div>
                    <div class="sidebar-logo" style="display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 1.2rem; margin-bottom: 2.5rem; padding-left: 0.5rem; padding-top: 1.5rem;">
                        <i class="fas fa-laptop-code"></i> <span>CCS Admin</span>
                    </div>
                    <nav class="sidebar-nav" style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <a href="admin_dashboard.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-home"></i> Home</a>
                        <a href="admin_search.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-search"></i> Search</a>
                        <a href="admin_students.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-users"></i> Students</a>
                        <a href="admin_sitins.php" class="active" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #fff; font-weight: 500; background: #3b82f6; transition: all 0.2s;"><i class="fas fa-clock"></i> Sit-in</a>
                        <a href="admin_records.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-list"></i> View Records</a>
                        <a href="admin_reports.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-chart-line"></i> Report & Analytics</a>
                        <a href="admin_feedback.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-comment-dots"></i> Feedback</a>
                        <a href="admin_reservations.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-calendar-alt"></i> Reservation</a>
                    </nav>
                </div>
                <div style="padding-bottom: 2rem;">
                    <a href="logout.php" style="display: flex; align-items: center; gap: 12px; background: #dc2626; color: #fff; text-decoration: none; padding: 0.75rem 1rem; border-radius: 14px; font-weight: 600; justify-content: center;"><i class="fas fa-sign-out-alt"></i> Log out</a>
                </div>
            </aside>

            <main class="main-content" style="margin-left: 250px;">
        <div class="page-header">
            <h1><i class="fas fa-clock"></i> Sit-in Records</h1>
        </div>
        
        <?php echo $message; echo $success_msg; echo $error_msg; ?>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3>Currently Sitting In</h3>
                    <p><?php echo count($active_sitins); ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-history"></i></div>
                <div class="stat-info">
                    <h3>Completed Today</h3>
                    <p><?php 
                        $today = date('Y-m-d');
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sit_in_history WHERE date = ? AND time_out IS NOT NULL");
                        $stmt->execute([$today]);
                        echo $stmt->fetchColumn();
                    ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-info">
                    <h3>Total Completed</h3>
                    <p><?php 
                        $stmt = $pdo->query("SELECT COUNT(*) FROM sit_in_history WHERE time_out IS NOT NULL");
                        echo $stmt->fetchColumn();
                    ?></p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <select id="labFilter">
                <option value="">All Laboratories</option>
                <option value="523">Lab 523</option><option value="524">Lab 524</option>
                <option value="525">Lab 525</option><option value="526">Lab 526</option>
                <option value="527">Lab 527</option><option value="528">Lab 528</option>
                <option value="529">Lab 529</option><option value="530">Lab 530</option>
            </select>
            <input type="text" id="searchInput" placeholder="Search by student name or ID...">
        </div>
        
        <!-- ONLY ACTIVE SIT-INS SECTION - NO COMPLETED HERE -->
        <div class="section-header">
            <h2><i class="fas fa-play-circle"></i> Currently Active Sit-ins <span class="badge-active"><?php echo count($active_sitins); ?> active</span></h2>
        </div>
        
        <div class="table-container">
            <table id="activeTable">
                <thead>
                    <tr><th>PC Number</th><th>ID Number</th><th>Student</th><th>Purpose</th><th>Lab</th><th>Date</th><th>Time In</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($active_sitins)): ?>
                        <tr><td colspan="9" class="empty-state"><i class="fas fa-check-circle" style="font-size: 2rem; color: #27ae60;"></i><p>No active sit-ins at the moment.</p></td></tr>
                    <?php else: ?>
                        <?php foreach($active_sitins as $sit): ?>
                                  <tr>
                                      <td>#<?php echo isset($sit['assigned_pc_no']) && $sit['assigned_pc_no'] ? str_pad((string) $sit['assigned_pc_no'], 2, '0', STR_PAD_LEFT) : $sit['id']; ?></td>
                                      <td><?php echo htmlspecialchars($sit['id_number']); ?></td>
                                      <td><div class="student-info"><img src="<?php echo htmlspecialchars($sit['profile_pic']); ?>" class="student-avatar" onerror="this.src='default-avatar.png'"><?php echo htmlspecialchars($sit['first_name'] . ' ' . $sit['last_name']); ?></div></td>
                                      <td><?php echo htmlspecialchars($sit['purpose']); ?></td>
                                      <td>Lab <?php echo htmlspecialchars($sit['laboratory']); ?></td>
                                      <td><?php echo date('M d, Y', strtotime($sit['date'])); ?></td>
                                      <td><?php echo formatSitInTimeForDisplay($sit['time_in_only'] ?? $sit['time_in']); ?></td>
                                      <td><span class="status-badge status-active">Logged In</span></td>
                                      <td><button type="button" class="btn-logout" onclick="openLogoutModal(<?php echo $sit['id']; ?>, '<?php echo htmlspecialchars($sit['first_name'] . ' ' . $sit['last_name'], ENT_QUOTES); ?>')"><i class="fas fa-sign-out-alt"></i> Log out</button></td>
                                  </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Logout Feedback Modal (single instance) -->
    <div id="logoutModal" class="modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-sign-out-alt"></i> Log Out & Feedback</h2>
                <span class="close" onclick="closeLogoutModal()">&times;</span>
            </div>
            <form method="POST" id="logoutFeedbackForm">
                <input type="hidden" name="logout_with_feedback" id="logoutSitInId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Student Name:</label>
                        <input type="text" id="logoutStudentName" class="readonly-field" readonly>
                    </div>
                    <div class="form-group">
                        <label>Feedback Type:</label>
                        <select name="admin_feedback_type" required>
                            <option value="">Select Type</option>
                            <option value="Positive">Positive</option>
                            <option value="Negative">Negative</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Feedback:</label>
                        <textarea name="admin_feedback" rows="3" placeholder="Enter feedback here..." required style="width:100%;padding:0.8rem;border-radius:10px;border:2px solid #e0e7ff;"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Rating (1-10):</label>
                        <select name="admin_rating" required>
                            <option value="">Select Rating</option>
                            <?php for($i=1;$i<=10;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-close" onclick="closeLogoutModal()">Cancel</button>
                    <button type="submit" class="btn-sit-in"><i class="fas fa-sign-out-alt"></i> Log Out & Save Feedback</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Search Modal -->
    <div id="searchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h2><i class="fas fa-search"></i> Search Student</h2><span class="close" onclick="closeModal()">&times;</span></div>
            <div class="modal-body">
                <div class="search-section">
                    <label>Enter Student ID Number:</label>
                    <div class="input-group">
                        <input type="text" id="searchIdNumber" placeholder="Enter Student ID Number" autocomplete="off">
                        <button onclick="searchStudent()" class="btn-search"><i class="fas fa-search"></i> Search</button>
                    </div>
                </div>
                <div id="sitInFormContainer" style="display: none;">
                    <div class="sit-in-form-section">
                        <h3><i class="fas fa-chair"></i> Sit In Form</h3>
                        <form method="POST" id="sitInForm">
                            <input type="hidden" name="sit_in_from_modal" value="1">
                            <input type="hidden" name="student_id" id="formStudentId">
                            <div class="form-group"><label>ID Number:</label><input type="text" id="formIdNumber" class="readonly-field" readonly></div>
                            <div class="form-group"><label>Student Name:</label><input type="text" id="formStudentName" class="readonly-field" readonly></div>
                            <div class="form-group"><label>Purpose:</label><select name="purpose" required><option value="">Select Purpose</option><option value="C Programming">C Programming</option><option value="Java Programming">Java Programming</option><option value="Python Programming">Python Programming</option><option value="Web Development">Web Development</option><option value="Database Design">Database Design</option><option value="Network Security">Network Security</option><option value="Research">Research</option><option value="Other">Other</option></select></div>
                            <div class="form-group"><label>Laboratory:</label><select name="laboratory" id="sitInLaboratory" onchange="loadPCNumbers()" required><option value="">Select Laboratory</option><option value="523">Lab 523</option><option value="524">Lab 524</option><option value="525">Lab 525</option><option value="526">Lab 526</option><option value="527">Lab 527</option><option value="528">Lab 528</option><option value="529">Lab 529</option><option value="530">Lab 530</option></select></div>
                            <div class="form-group" id="pcNumberGroup" style="display:none;"><label>PC Number:</label><select name="pc_number" id="pcNumberSelect"><option value="">Select PC...</option></select><small id="pcStatusInfo" style="color:#666; margin-top:0.3rem; display:block;"></small></div>
                            <div class="form-row"><div class="form-group"><label>Time In:</label><input type="time" name="time_in" value="<?php echo date('H:i'); ?>" required></div><div class="form-group"><label>Date:</label><input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required></div></div>
                            <div class="form-group"><label>Remaining Sessions:</label><input type="text" id="formRemainingSessions" class="readonly-field session-field" readonly></div>
                            <div class="form-actions"><button type="button" onclick="closeModal()" class="btn-close">Close</button><button type="submit" class="btn-sit-in" id="submitBtn"><i class="fas fa-chair"></i> Sit In</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
                        // Logout Feedback Modal logic
                        function openLogoutModal(sitInId, studentName) {
                            document.getElementById('logoutSitInId').value = sitInId;
                            document.getElementById('logoutStudentName').value = studentName;
                            document.getElementById('logoutModal').style.display = 'block';
                        }
                        function closeLogoutModal() {
                            document.getElementById('logoutModal').style.display = 'none';
                        }
                        // Close modal on outside click
                        window.addEventListener('click', function(event) {
                            var modal = document.getElementById('logoutModal');
                            if (event.target == modal) { modal.style.display = 'none'; }
                        });
                // Logout Feedback Modal logic
                function openLogoutModal(sitInId, studentName) {
                    document.getElementById('logoutSitInId').value = sitInId;
                    document.getElementById('logoutStudentName').value = studentName;
                    document.getElementById('logoutModal').style.display = 'block';
                }
                function closeLogoutModal() {
                    document.getElementById('logoutModal').style.display = 'none';
                }
                // Close modal on outside click
                window.addEventListener('click', function(event) {
                    var modal = document.getElementById('logoutModal');
                    if (event.target == modal) { modal.style.display = 'none'; }
                });
        function filterActiveTable() {
            let search = document.getElementById('searchInput').value.toLowerCase();
            let lab = document.getElementById('labFilter').value.toLowerCase();
            let rows = document.querySelectorAll('#activeTable tbody tr');
            rows.forEach(row => { if (!row.querySelector('.empty-state')) { let text = row.textContent.toLowerCase(); let labMatch = lab === '' || text.includes(lab); let searchMatch = search === '' || text.includes(search); row.style.display = (labMatch && searchMatch) ? '' : 'none'; } });
        }
        document.getElementById('searchInput').addEventListener('keyup', filterActiveTable);
        document.getElementById('labFilter').addEventListener('change', filterActiveTable);
        
        function openSearchModal() { document.getElementById('searchModal').style.display = 'block'; document.getElementById('searchIdNumber').focus(); document.getElementById('sitInFormContainer').style.display = 'none'; const pcGroup = document.getElementById('pcNumberGroup'); const pcSelect = document.getElementById('pcNumberSelect'); const pcStatusInfo = document.getElementById('pcStatusInfo'); if (pcGroup) pcGroup.style.display = 'none'; if (pcSelect) pcSelect.innerHTML = '<option value="">Select PC...</option>'; if (pcStatusInfo) pcStatusInfo.textContent = ''; }
        function closeModal() { document.getElementById('searchModal').style.display = 'none'; }

        function loadPCNumbers() {
            const lab = document.getElementById('sitInLaboratory').value;
            const pcGroup = document.getElementById('pcNumberGroup');
            const pcSelect = document.getElementById('pcNumberSelect');
            const pcStatusInfo = document.getElementById('pcStatusInfo');

            if (!lab) {
                pcGroup.style.display = 'none';
                pcSelect.innerHTML = '<option value="">Select PC...</option>';
                pcStatusInfo.textContent = '';
                return;
            }

            const dateInput = document.querySelector('#sitInForm input[name="date"]');
            const selectedDate = dateInput && dateInput.value ? dateInput.value : new Date().toISOString().split('T')[0];

            fetch(`admin_students.php?action=get_pc_status&lab=${encodeURIComponent(lab)}&date=${encodeURIComponent(selectedDate)}`)
                .then(response => response.json())
                .then(data => {
                    pcSelect.innerHTML = '<option value="">Select PC...</option>';
                    if (!Array.isArray(data) || data.length === 0) {
                        pcGroup.style.display = 'block';
                        pcStatusInfo.textContent = 'No PC data available for this laboratory/date.';
                        return;
                    }

                    let vacant = 0;
                    let inUse = 0;
                    let reserved = 0;
                    let pending = 0;
                    let maintenance = 0;

                    data.forEach(pc => {
                        const option = document.createElement('option');
                        option.value = pc.pcNo;
                        option.textContent = `PC ${pc.pcNo} - ${pc.status}`;

                        if (pc.status === 'Vacant') {
                            option.style.color = '#27ae60';
                            vacant++;
                        } else if (pc.status === 'In-Use') {
                            option.style.color = '#e67e22';
                            inUse++;
                        } else if (pc.status === 'Reserved') {
                            option.style.color = '#3498db';
                            reserved++;
                        } else if (pc.status === 'Maintenance') {
                            option.style.color = '#e74c3c';
                            maintenance++;
                        } else {
                            option.style.color = '#95a5a6';
                            pending++;
                        }

                        pcSelect.appendChild(option);
                    });

                    pcStatusInfo.textContent = `Vacant: ${vacant} | In-Use: ${inUse} | Reserved: ${reserved} | Pending: ${pending} | Maintenance: ${maintenance}`;
                    pcGroup.style.display = 'block';
                })
                .catch(error => {
                    console.error('Error loading PC numbers:', error);
                    pcSelect.innerHTML = '<option value="">Select PC...</option>';
                    pcStatusInfo.textContent = 'Unable to load PC availability.';
                    pcGroup.style.display = 'block';
                });
        }

        const sitInDateInput = document.querySelector('#sitInForm input[name="date"]');
        if (sitInDateInput) {
            sitInDateInput.addEventListener('change', function() {
                const labSelect = document.getElementById('sitInLaboratory');
                if (labSelect && labSelect.value) {
                    loadPCNumbers();
                }
            });
        }
        
        function searchStudent() {
            const idNumber = document.getElementById('searchIdNumber').value.trim();
            if (!idNumber) { alert('Please enter a student ID number'); return; }
            fetch(`get_student.php?id_number=${encodeURIComponent(idNumber)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('formStudentId').value = data.id;
                        document.getElementById('formIdNumber').value = data.id_number;
                        document.getElementById('formStudentName').value = data.name;
                        document.getElementById('formRemainingSessions').value = data.remaining_sessions + ' sessions left';
                        document.getElementById('sitInFormContainer').style.display = 'block';
                        document.getElementById('searchIdNumber').value = '';
                        if (data.remaining_sessions <= 0) { alert('Warning: This student has no remaining sessions!'); document.getElementById('submitBtn') && (document.getElementById('submitBtn').disabled = true); }
                        else { document.getElementById('submitBtn') && (document.getElementById('submitBtn').disabled = false); }
                    } else { alert(data.error); document.getElementById('sitInFormContainer').style.display = 'none'; }
                }).catch(error => { alert('Error searching for student.'); });
        }
        document.getElementById('searchIdNumber').addEventListener('keypress', function(e) { if (e.key === 'Enter') searchStudent(); });
        window.onclick = function(event) { if (event.target == document.getElementById('searchModal')) closeModal(); }
    </script>
    <button class="dark-mode-toggle" onclick="toggleTheme()"><i class="fas fa-moon" id="theme-icon"></i> <span id="theme-label">Dark</span></button>
    <script>
    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-label').textContent = isDark ? 'Light' : 'Dark';
        document.getElementById('theme-icon').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
    if (localStorage.getItem('theme') === 'dark') {
        document.body.classList.add('dark-mode');
        document.getElementById('theme-label').textContent = 'Light';
        document.getElementById('theme-icon').className = 'fas fa-sun';
    }
    </script>
</body>
</html>