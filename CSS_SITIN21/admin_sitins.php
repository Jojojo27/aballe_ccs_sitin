<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Handle logout (time out)
if (isset($_GET['logout'])) {
    $id = $_GET['logout'];
    
    $stmt = $pdo->prepare("UPDATE sit_in_history SET time_out = NOW(), status = 'Completed' WHERE id = ?");
    $stmt->execute([$id]);
    
    header("Location: admin_sitins.php?msg=logout");
    exit();
}

// Handle sit-in submission from modal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sit_in_from_modal'])) {
    $student_id = $_POST['student_id'];
    $purpose = $_POST['purpose'];
    $laboratory = $_POST['laboratory'];
    $time_in = $_POST['time_in'];
    $date = $_POST['date'];
    
    $stmt = $pdo->prepare("SELECT remaining_sessions FROM users WHERE id = ?");
    $stmt->execute([$student_id]);
    $remaining = $stmt->fetchColumn();
    
    if ($remaining <= 0) {
        $_SESSION['error_msg'] = "Student has no remaining sessions left!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO sit_in_history (user_id, purpose, laboratory, time_in, date, status) VALUES (?, ?, ?, ?, ?, 'Active')");
        
        if ($stmt->execute([$student_id, $purpose, $laboratory, $time_in, $date])) {
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
    SELECT s.*, u.id_number, u.first_name, u.last_name, u.profile_pic 
    FROM sit_in_history s 
    JOIN users u ON s.user_id = u.id 
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
    <nav class="navbar">
        <div class="navbar-logo">
            <i class="fas fa-laptop-code"></i> College of Computer Studies Admin
        </div>
        <div class="navbar-links">
            <a href="admin_dashboard.php"><i class="fas fa-home"></i> Home</a>
            <a href="javascript:void(0)" onclick="openSearchModal()"><i class="fas fa-search"></i> Search</a>
            <a href="admin_students.php"><i class="fas fa-users"></i> Students</a>
            <a href="admin_sitins.php" class="active"><i class="fas fa-clock"></i> Sit-in</a>
            <a href="admin_records.php"><i class="fas fa-list"></i> View Sit-in Records</a>
            <a href="admin_reports.php"><i class="fas fa-chart-line"></i> Sit-in Reports</a>
            <a href="admin_feedback.php"><i class="fas fa-star"></i> Feedback Reports</a>
            <a href="admin_reservations.php"><i class="fas fa-calendar-alt"></i> Reservation</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </nav>

    <main class="main-content">
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
                    <tr><th>Sit ID</th><th>ID Number</th><th>Student</th><th>Purpose</th><th>Lab</th><th>Date</th><th>Time In</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($active_sitins)): ?>
                        <tr><td colspan="9" class="empty-state"><i class="fas fa-check-circle" style="font-size: 2rem; color: #27ae60;"></i><p>No active sit-ins at the moment.</p></td></tr>
                    <?php else: ?>
                        <?php foreach($active_sitins as $sit): ?>
                          <tr>
                             <td>#<?php echo $sit['id']; ?></td>
                             <td><?php echo htmlspecialchars($sit['id_number']); ?></td>
                             <td><div class="student-info"><img src="<?php echo htmlspecialchars($sit['profile_pic']); ?>" class="student-avatar" onerror="this.src='default-avatar.png'"><?php echo htmlspecialchars($sit['first_name'] . ' ' . $sit['last_name']); ?></div></td>
                             <td><?php echo htmlspecialchars($sit['purpose']); ?></td>
                             <td>Lab <?php echo htmlspecialchars($sit['laboratory']); ?></td>
                             <td><?php echo date('M d, Y', strtotime($sit['date'])); ?></td>
                             <td><?php echo date('h:i A', strtotime($sit['time_in'])); ?></td>
                             <td><span class="status-badge status-active">Logged In</span></td>
                             <td><a href="?logout=<?php echo $sit['id']; ?>" class="btn-logout" onclick="return confirm('Log out this student?')"><i class="fas fa-sign-out-alt"></i> Log out</a></td>
                          </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

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
                            <div class="form-group"><label>Laboratory:</label><select name="laboratory" required><option value="">Select Laboratory</option><option value="523">Lab 523</option><option value="524">Lab 524</option><option value="525">Lab 525</option><option value="526">Lab 526</option><option value="527">Lab 527</option></select></div>
                            <div class="form-row"><div class="form-group"><label>Time In:</label><input type="time" name="time_in" required></div><div class="form-group"><label>Date:</label><input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required></div></div>
                            <div class="form-group"><label>Remaining Sessions:</label><input type="text" id="formRemainingSessions" class="readonly-field session-field" readonly></div>
                            <div class="form-actions"><button type="button" onclick="closeModal()" class="btn-close">Close</button><button type="submit" class="btn-sit-in"><i class="fas fa-chair"></i> Sit In</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function filterActiveTable() {
            let search = document.getElementById('searchInput').value.toLowerCase();
            let lab = document.getElementById('labFilter').value.toLowerCase();
            let rows = document.querySelectorAll('#activeTable tbody tr');
            rows.forEach(row => { if (!row.querySelector('.empty-state')) { let text = row.textContent.toLowerCase(); let labMatch = lab === '' || text.includes(lab); let searchMatch = search === '' || text.includes(search); row.style.display = (labMatch && searchMatch) ? '' : 'none'; } });
        }
        document.getElementById('searchInput').addEventListener('keyup', filterActiveTable);
        document.getElementById('labFilter').addEventListener('change', filterActiveTable);
        
        function openSearchModal() { document.getElementById('searchModal').style.display = 'block'; document.getElementById('searchIdNumber').focus(); document.getElementById('sitInFormContainer').style.display = 'none'; }
        function closeModal() { document.getElementById('searchModal').style.display = 'none'; }
        
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
</body>
</html>