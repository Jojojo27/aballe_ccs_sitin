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

// Function to create notification for all users
function createNotificationForAllUsers($pdo, $title, $message, $type, $link) {
    $stmt = $pdo->query("SELECT id FROM users");
    $users = $stmt->fetchAll();
    
    $success_count = 0;
    foreach ($users as $user) {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$user['id'], $title, $message, $type, $link])) {
            $success_count++;
        }
    }
    return $success_count;
}

// Get statistics from database
$total_students = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_sitins = $pdo->query("SELECT COUNT(*) FROM sit_in_history")->fetchColumn();
$current_sitin = $pdo->query("SELECT COUNT(*) FROM sit_in_history WHERE time_out IS NULL AND date = CURDATE()")->fetchColumn();

// Get programming language stats from sit-ins - ALL LANGUAGES from dropdown
$all_languages = [
    'C Programming' => 0,
    'Java Programming' => 0,
    'Python Programming' => 0,
    'Web Development' => 0,
    'Database Design' => 0,
    'Network Security' => 0,
    'Research' => 0,
    'Other' => 0
];

$stmt = $pdo->query("SELECT purpose FROM sit_in_history");
while ($row = $stmt->fetch()) {
    $purpose = $row['purpose'];
    if (isset($all_languages[$purpose])) {
        $all_languages[$purpose]++;
    }
}

// Remove languages with zero count for display
$display_languages = array_filter($all_languages);
$total_lang_uses = array_sum($display_languages);

// Colors for each language
$lang_colors = [
    'C Programming' => '#3498db',
    'Java Programming' => '#e74c3c',
    'Python Programming' => '#2ecc71',
    'Web Development' => '#f39c12',
    'Database Design' => '#9b59b6',
    'Network Security' => '#1abc9c',
    'Research' => '#e67e22',
    'Other' => '#95a5a6'
];

// Get announcements
$announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll();

// Handle new announcement
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['announcement'])) {
    $msg = trim($_POST['announcement']);
    if (!empty($msg)) {
        $stmt = $pdo->prepare("INSERT INTO announcements (message, created_by) VALUES (?, ?)");
        $stmt->execute([$msg, $_SESSION['admin_username']]);
        
        $title = "New Announcement";
        $notif_count = createNotificationForAllUsers($pdo, $title, $msg, 'announcement', 'student_dashboard.php');
        
        $_SESSION['success_msg'] = "Announcement posted! Notification sent to $notif_count students.";
        
        header("Location: admin_dashboard.php");
        exit();
    }
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
        header("Location: admin_dashboard.php");
        exit();
    }
    
    // Check remaining sessions
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
                    header("Location: admin_dashboard.php");
                    exit();
                }
            } catch (Throwable $e) {
                // Continue if pc_usage validation is unavailable
            }
        }

        // Insert sit-in record
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
                    // Continue even if pc_usage persistence fails
                }
            }

            // Deduct 1 session
            $stmt = $pdo->prepare("UPDATE users SET remaining_sessions = remaining_sessions - 1 WHERE id = ?");
            $stmt->execute([$student_id]);
            
            $_SESSION['success_msg'] = "Sit-in recorded successfully! 1 session deducted.";
        } else {
            $_SESSION['error_msg'] = "Failed to record sit-in.";
        }
    }
    
    header("Location: admin_dashboard.php");
    exit();
}

// Check for messages
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CCS</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .main-content { margin-left: 220px; padding: 1.5rem; min-height: 100vh; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        
        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .navbar { width: 100%; height: auto; flex-direction: row; position: relative; flex-wrap: wrap; }
            .navbar-links { flex-direction: row; flex-wrap: wrap; }
            .main-content { margin-left: 0; }
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(145deg, #2c3e50, #2c3e50);
            color: white;
            padding: 0.8rem 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-header h2 { font-size: 1rem; font-weight: 600; margin: 0; }
        .card-body { padding: 1.2rem; }
        
        .stats-list { margin-bottom: 1.5rem; }
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 0.6rem 0;
            border-bottom: 1px solid #e0e7ff;
        }
        .stat-label { font-weight: 500; color: #2c3e50; font-size: 0.9rem; }
        .stat-value { font-weight: 700; color: #2c3e50; font-size: 1rem; }
        
        /* Donut Chart Container */
        .donut-chart-container {
            position: relative;
            max-width: 240px;
            margin: 0 auto;
        }
        
        .donut-chart-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        
        .donut-chart-center h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .donut-chart-center p {
            font-size: 0.7rem;
            color: #666;
        }
        
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-top: 1.5rem;
            justify-content: center;
            max-height: 150px;
            overflow-y: auto;
        }
        .legend-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.75rem; }
        .legend-color { width: 12px; height: 12px; border-radius: 3px; }
        
        .announcement-form textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            resize: vertical;
            margin-bottom: 1rem;
        }
        .btn-submit {
            background: #27ae60;
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            width: 100%;
        }
        .btn-submit:hover { background: #219a52; }
        
        .announcement-list { margin-top: 1.5rem; }
        .announcement-item {
            padding: 1rem;
            border-bottom: 1px solid #e0e7ff;
            margin-bottom: 0.5rem;
            background: #f8faff;
            border-radius: 8px;
        }
        .announcement-date { font-size: 0.7rem; color: #2c3e50; margin-bottom: 0.3rem; }
        .announcement-message { color: #2c3e50; line-height: 1.5; }
        .no-data { text-align: center; padding: 2rem; color: #999; }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .empty-chart {
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 15px;
        }
        
        .empty-chart i {
            font-size: 3rem;
            color: #cbd5e0;
            margin-bottom: 10px;
        }
        
        .empty-chart p {
            color: #666;
            font-size: 0.85rem;
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
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 550px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
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
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-header .close {
            font-size: 1.8rem;
            cursor: pointer;
            transition: 0.3s;
        }
        
        .modal-header .close:hover {
            color: #f39c12;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .search-section {
            margin-bottom: 1.5rem;
        }
        
        .search-section label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .input-group {
            display: flex;
            gap: 0.8rem;
        }
        
        .input-group input {
            flex: 1;
            padding: 0.8rem;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
        }
        
        .input-group input:focus {
            border-color: #3498db;
            outline: none;
        }
        
        .btn-search {
            background: #3498db;
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-search:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .sit-in-form-section {
            margin-top: 1.5rem;
            border-top: 1px solid #e0e7ff;
            padding-top: 1.5rem;
        }
        
        .sit-in-form-section h3 {
            margin-bottom: 1.2rem;
            color: #2c3e50;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 500;
            color: #2c3e50;
            font-size: 0.8rem;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            background: #f8faff;
        }
        
        .form-group input:focus, .form-group select:focus {
            border-color: #3498db;
            outline: none;
            background: white;
        }
        
        .readonly-field {
            background: #ecf0f1 !important;
            cursor: not-allowed;
            color: #2c3e50;
        }
        
        .session-field {
            background: #e8f8ef !important;
            font-weight: 600;
            color: #27ae60;
        }
        
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
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-close:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
        }
        
        .btn-sit-in {
            background: linear-gradient(145deg, #27ae60, #219a52);
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }
        
        .btn-sit-in:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39,174,96,0.3);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        @media (max-width: 600px) {
            .modal-content {
                margin: 10% auto;
                width: 95%;
            }
            
            .input-group {
                flex-direction: column;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn-close, .btn-sit-in {
                width: 100%;
                justify-content: center;
            }
            
            .donut-chart-container {
                max-width: 220px;
            }
            
            .legend {
                max-height: 120px;
            }
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
                        <a href="admin_dashboard.php" class="active" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #fff; font-weight: 500; background: #3b82f6; transition: all 0.2s;"><i class="fas fa-home"></i> Home</a>
                        <a href="admin_search.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-search"></i> Search</a>
                        <a href="admin_students.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-users"></i> Students</a>
                        <a href="admin_sitins.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-clock"></i> Sit-in</a>
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
        <?php echo $success_msg; ?>
        <?php echo $error_msg; ?>
        
        <div class="dashboard-grid">


            <!-- Statistics Card with Donut Chart for Programming Languages -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i>
                    <h2>Statistics</h2>
                </div>
                <div class="card-body">
                    <div class="stats-list">
                        <div class="stat-item"><span class="stat-label"><i class="fas fa-users"></i> Students Registered:</span><span class="stat-value"><?php echo $total_students; ?></span></div>
                        <div class="stat-item"><span class="stat-label"><i class="fas fa-clock"></i> Currently Sit-in:</span><span class="stat-value"><?php echo $current_sitin; ?></span></div>
                        <div class="stat-item"><span class="stat-label"><i class="fas fa-history"></i> Total Sit-ins:</span><span class="stat-value"><?php echo $total_sitins; ?></span></div>
                    </div>
                    
                    <!-- Circular Donut Chart for Programming Languages -->
                    <div style="margin-top: 20px;">
                        <h4 style="text-align: center; margin-bottom: 15px; color: #2c3e50;">
                            <i class="fas fa-chart-pie"></i> Programming Languages Distribution
                        </h4>
                        
                        <?php if ($total_lang_uses > 0): ?>
                            <div class="donut-chart-container">
                                <canvas id="languageDonutChart"></canvas>
                                <div class="donut-chart-center">
                                    <h3><?php echo $total_lang_uses; ?></h3>
                                    <p>Total Uses</p>
                                </div>
                            </div>
                            <div class="legend" id="chartLegend"></div>
                        <?php else: ?>
                            <div class="empty-chart">
                                <i class="fas fa-chart-pie"></i>
                                <p>No sit-in records yet.</p>
                                <p style="font-size: 0.7rem;">When students complete sit-in sessions, chart will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Announcement Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bullhorn"></i>
                    <h2>Announcement</h2>
                </div>
                <div class="card-body">
                    <form method="POST" class="announcement-form">
                        <textarea name="announcement" rows="3" placeholder="New Announcement" required></textarea>
                        <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Submit</button>
                    </form>
                    <div class="announcement-list">
                        <h3><i class="fas fa-list"></i> Posted Announcement</h3>
                        <?php if (empty($announcements)): ?>
                            <div class="no-data"><i class="fas fa-comment-slash"></i><p>No announcements yet.</p></div>
                        <?php else: ?>
                            <?php foreach ($announcements as $a): ?>
                                <div class="announcement-item">
                                    <div class="announcement-date"><i class="fas fa-user-tie"></i> CCS Admin | <?php echo date('Y-M-d', strtotime($a['created_at'])); ?></div>
                                    <div class="announcement-message"><?php echo htmlspecialchars($a['message']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Search Modal -->
    <div id="searchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-search"></i> Search Student</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="search-section">
                    <label>Enter Student ID Number:</label>
                    <div class="input-group">
                        <input type="text" id="searchIdNumber" placeholder="Enter Student Id Number" autocomplete="off">
                        <button onclick="searchStudent()" class="btn-search">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
                
                <div id="sitInFormContainer" style="display: none;">
                    <div class="sit-in-form-section">
                        <h3><i class="fas fa-chair"></i> Sit In Form</h3>
                        <form method="POST" id="sitInForm">
                            <input type="hidden" name="sit_in_from_modal" value="1">
                            <input type="hidden" name="student_id" id="formStudentId">
                            
                            <div class="form-group">
                                <label>ID Number:</label>
                                <input type="text" id="formIdNumber" class="readonly-field" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Student Name:</label>
                                <input type="text" id="formStudentName" class="readonly-field" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Purpose:</label>
                                <select name="purpose" required>
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
                            
                            <div class="form-group">
                                <label>Laboratory:</label>
                                <select name="laboratory" id="sitInLaboratory" onchange="loadPCNumbers()" required>
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

                            <div class="form-group" id="pcNumberGroup" style="display: none;">
                                <label>PC Number:</label>
                                <select name="pc_number" id="pcNumberSelect" required>
                                    <option value="">Select PC...</option>
                                </select>
                                <small id="pcStatusInfo" style="color:#666; margin-top:0.3rem; display:block;"></small>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Time In:</label>
                                    <input type="time" name="time_in" value="<?php echo date('H:i'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Date:</label>
                                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Remaining Sessions:</label>
                                <input type="text" id="formRemainingSessions" class="readonly-field session-field" readonly>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" onclick="closeModal()" class="btn-close">Close</button>
                                <button type="submit" class="btn-sit-in" id="submitBtn">
                                    <i class="fas fa-chair"></i> Sit In
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Search student function
        function searchStudent() {
            const idNumber = document.getElementById('searchIdNumber').value.trim();
            
            if (!idNumber) {
                alert('Please enter a student ID number');
                return;
            }
            
            const searchBtn = document.querySelector('.btn-search');
            const originalText = searchBtn.innerHTML;
            searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
            searchBtn.disabled = true;
            
            fetch(`get_student.php?id_number=${encodeURIComponent(idNumber)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('formStudentId').value = data.id;
                        document.getElementById('formIdNumber').value = data.id_number;
                        document.getElementById('formStudentName').value = data.name;
                        document.getElementById('formRemainingSessions').value = data.remaining_sessions + ' sessions left';
                        
                        if (data.remaining_sessions <= 0) {
                            alert('Warning: This student has no remaining sessions!');
                            document.getElementById('submitBtn').disabled = true;
                        } else {
                            document.getElementById('submitBtn').disabled = false;
                        }
                        
                        document.getElementById('sitInFormContainer').style.display = 'block';
                        document.getElementById('searchIdNumber').value = '';
                    } else {
                        alert(data.error);
                        document.getElementById('sitInFormContainer').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error searching for student. Please try again.');
                })
                .finally(() => {
                    searchBtn.innerHTML = originalText;
                    searchBtn.disabled = false;
                });
        }
        
        function openModal() {
            document.getElementById('searchModal').style.display = 'block';
            document.getElementById('searchIdNumber').focus();
            document.getElementById('sitInFormContainer').style.display = 'none';
            document.getElementById('searchIdNumber').value = '';
            document.getElementById('sitInForm').reset();

            const pcGroup = document.getElementById('pcNumberGroup');
            const pcSelect = document.getElementById('pcNumberSelect');
            const pcStatusInfo = document.getElementById('pcStatusInfo');
            if (pcGroup) pcGroup.style.display = 'none';
            if (pcSelect) pcSelect.innerHTML = '<option value="">Select PC...</option>';
            if (pcStatusInfo) pcStatusInfo.textContent = '';
        }
        
        function closeModal() {
            document.getElementById('searchModal').style.display = 'none';
        }

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
        
        document.getElementById('searchIdNumber').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchStudent();
            }
        });
        
        window.onclick = function(event) {
            const modal = document.getElementById('searchModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        <?php if ($total_lang_uses > 0): ?>
        // Donut Chart for Programming Languages
        const langCtx = document.getElementById('languageDonutChart').getContext('2d');
        
        // Data from PHP
        const langData = <?php echo json_encode(array_values($display_languages)); ?>;
        const langLabels = <?php echo json_encode(array_keys($display_languages)); ?>;
        const langColors = <?php 
            $colors = [];
            foreach (array_keys($display_languages) as $lang) {
                $colors[] = $lang_colors[$lang];
            }
            echo json_encode($colors);
        ?>;
        
        const donutChart = new Chart(langCtx, {
            type: 'doughnut',
            data: {
                labels: langLabels,
                datasets: [{
                    data: langData,
                    backgroundColor: langColors,
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverOffset: 10,
                    cutout: '60%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = <?php echo $total_lang_uses; ?>;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        
        // Generate custom legend
        const legendContainer = document.getElementById('chartLegend');
        for (let i = 0; i < langLabels.length; i++) {
            const legendItem = document.createElement('div');
            legendItem.className = 'legend-item';
            legendItem.innerHTML = `
                <div class="legend-color" style="background: ${langColors[i]}"></div>
                <span>${langLabels[i]} (${langData[i]})</span>
            `;
            legendContainer.appendChild(legendItem);
        }
        <?php endif; ?>
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