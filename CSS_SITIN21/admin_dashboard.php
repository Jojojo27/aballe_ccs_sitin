<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
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
    $student_id = $_POST['student_id'];
    $purpose = $_POST['purpose'];
    $laboratory = $_POST['laboratory'];
    $time_in = $_POST['time_in'];
    $date = $_POST['date'];
    
    // Check remaining sessions
    $stmt = $pdo->prepare("SELECT remaining_sessions FROM users WHERE id = ?");
    $stmt->execute([$student_id]);
    $remaining = $stmt->fetchColumn();
    
    if ($remaining <= 0) {
        $_SESSION['error_msg'] = "Student has no remaining sessions left!";
    } else {
        // Insert sit-in record
        $stmt = $pdo->prepare("INSERT INTO sit_in_history (user_id, purpose, laboratory, time_in, date, status) VALUES (?, ?, ?, ?, ?, 'Active')");
        
        if ($stmt->execute([$student_id, $purpose, $laboratory, $time_in, $date])) {
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; }
        
        .navbar {
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .navbar-logo { font-size: 1.3rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
        .navbar-links { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .navbar-links a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: 0.3s;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .navbar-links a:hover { background: #34495e; }
        .navbar-links a.active { background: #3498db; }
        .logout-btn { background: #e74c3c; }
        .logout-btn:hover { background: #c0392b !important; }
        
        .main-content { margin-top: 80px; padding: 2rem; max-width: 1400px; margin-left: auto; margin-right: auto; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
        
        @media (max-width: 768px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .navbar { flex-direction: column; text-align: center; }
            .navbar-links { justify-content: center; }
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
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card-header h2 { font-size: 1.2rem; font-weight: 600; margin: 0; }
        .card-body { padding: 1.5rem; }
        
        .stats-list { margin-bottom: 1.5rem; }
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid #e0e7ff;
        }
        .stat-label { font-weight: 500; color: #2c3e50; }
        .stat-value { font-weight: 700; color: #2c3e50; font-size: 1.2rem; }
        
        /* Donut Chart Container */
        .donut-chart-container {
            position: relative;
            max-width: 300px;
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
    <nav class="navbar">
        <div class="navbar-logo">
            <i class="fas fa-laptop-code"></i> College of Computer Studies Admin
        </div>
        <div class="navbar-links">
            <a href="admin_dashboard.php" class="active"><i class="fas fa-home"></i> Home</a>
            <a href="javascript:void(0)" onclick="openModal()"><i class="fas fa-search"></i> Search</a>
            <a href="admin_students.php"><i class="fas fa-users"></i> Students</a>
            <a href="admin_sitins.php"><i class="fas fa-clock"></i> Sit-in</a>
            <a href="admin_records.php"><i class="fas fa-list"></i> View Sit-in Records</a>
            <a href="admin_reports.php"><i class="fas fa-chart-line"></i> Sit-in Reports</a>
            <a href="admin_feedback.php"><i class="fas fa-comment-dots"></i> Feedback Reports</a>
            <a href="admin_reservations.php"><i class="fas fa-calendar-alt"></i> Reservation</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </nav>

    <main class="main-content">
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
                                <select name="laboratory" required>
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
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Time In:</label>
                                    <input type="time" name="time_in" required>
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
        }
        
        function closeModal() {
            document.getElementById('searchModal').style.display = 'none';
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
</body>
</html>