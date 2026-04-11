<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// DELETE STUDENT
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: admin_students.php?msg=deleted");
    exit();
}

// RESET ALL SESSIONS
if (isset($_GET['reset_sessions'])) {
    $stmt = $pdo->prepare("UPDATE users SET remaining_sessions = 30");
    $stmt->execute();
    header("Location: admin_students.php?msg=reset");
    exit();
}

// Handle Add Student via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_student_ajax'])) {
    $id_number = trim($_POST['id_number']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $middle_name = trim($_POST['middle_name']);
    $course = $_POST['course'];
    $year_level = $_POST['year_level'];
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $password = $_POST['password'];
    
    $errors = [];
    
    if (empty($id_number)) $errors[] = "ID Number is required";
    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (empty($password)) $errors[] = "Password is required";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters";
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id_number = ?");
    $stmt->execute([$id_number]);
    if ($stmt->fetch()) $errors[] = "ID Number already exists";
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) $errors[] = "Email already exists";
    
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (id_number, first_name, last_name, middle_name, course, year_level, email, address, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$id_number, $first_name, $last_name, $middle_name, $course, $year_level, $email, $address, $hashed_password])) {
            echo json_encode(['success' => true, 'message' => 'Student added successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add student']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
    }
    exit();
}

// Handle Edit Student via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_student_ajax'])) {
    $id = $_POST['student_id'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $middle_name = trim($_POST['middle_name']);
    $course = $_POST['course'];
    $year_level = $_POST['year_level'];
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $remaining_sessions = $_POST['remaining_sessions'];
    
    $errors = [];
    
    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) $errors[] = "Email already exists";
    
    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, middle_name=?, course=?, year_level=?, email=?, address=?, remaining_sessions=? WHERE id=?");
        
        if ($stmt->execute([$first_name, $last_name, $middle_name, $course, $year_level, $email, $address, $remaining_sessions, $id])) {
            echo json_encode(['success' => true, 'message' => 'Student updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update student']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
    }
    exit();
}

// Get student details for edit
if (isset($_GET['get_student_for_edit'])) {
    $id = $_GET['get_student_for_edit'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    echo json_encode($student);
    exit();
}

$students = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $message = '<div class="alert success">Student deleted successfully!</div>';
    if ($_GET['msg'] == 'reset') $message = '<div class="alert success">All student sessions reset to 30!</div>';
    if ($_GET['msg'] == 'added') $message = '<div class="alert success">Student added successfully!</div>';
    if ($_GET['msg'] == 'updated') $message = '<div class="alert success">Student updated successfully!</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students Management - Admin</title>
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
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .btn-add { background: #27ae60; color: white; padding: 0.8rem 1.5rem; border-radius: 8px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-reset { background: #e74c3c; color: white; padding: 0.8rem 1.5rem; border-radius: 8px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-search-icon {
            background: #3498db;
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .button-group { display: flex; gap: 1rem; flex-wrap: wrap; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert.success { background: #d4edda; color: #155724; }
        
        .table-container { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #34495e; color: white; padding: 1rem; text-align: left; }
        td { padding: 1rem; border-bottom: 1px solid #e0e7ff; }
        tr:hover { background: #f8faff; }
        .clickable-row { cursor: pointer; transition: all 0.2s; }
        .clickable-row:hover { background: #e8f0fe; }
        .session-badge { background: #27ae60; color: white; padding: 0.2rem 0.6rem; border-radius: 50px; font-size: 0.8rem; }
        .action-edit { background: #3498db; color: white; padding: 0.3rem 0.8rem; border-radius: 5px; text-decoration: none; margin-right: 0.5rem; }
        .action-delete { background: #e74c3c; color: white; padding: 0.3rem 0.8rem; border-radius: 5px; text-decoration: none; }
        
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
        
        /* Search Section */
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
        
        /* Sit-in Form Section - Only shows after search */
        .sit-in-form-section {
            margin-top: 1rem;
            border-top: 1px solid #e0e7ff;
            padding-top: 1.5rem;
        }
        
        .sit-in-form-section h3 {
            margin-bottom: 1rem;
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
            font-size: 0.85rem;
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
        
        .alert-modal {
            padding: 0.8rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: #f8d7da;
            color: #721c24;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
        
        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
            .navbar-links { justify-content: center; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .button-group { justify-content: center; }
            .input-group { flex-direction: column; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn-close, .btn-sit-in { width: 100%; justify-content: center; }
            .modal-content { width: 95%; margin: 10% auto; }
        }
        
        @media (max-width: 600px) {
            .modal-content { margin: 10% auto; width: 95%; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-logo"><i class="fas fa-laptop-code"></i> College of Computer Studies Admin</div>
        <div class="navbar-links">
            <a href="admin_dashboard.php"><i class="fas fa-home"></i> Home</a>
            <a href="javascript:void(0)" onclick="openSearchModal()"><i class="fas fa-search"></i> Search</a>
            <a href="admin_students.php" class="active"><i class="fas fa-users"></i> Students</a>
            <a href="admin_sitins.php"><i class="fas fa-clock"></i> Sit-in</a>
            <a href="admin_records.php"><i class="fas fa-list"></i> View Sit-in Records</a>
            <a href="admin_reports.php"><i class="fas fa-chart-line"></i> Sit-in Reports</a>
            <a href="admin_feedback.php"><i class="fas fa-star"></i> Feedback Reports</a>
            <a href="admin_reservations.php"><i class="fas fa-calendar-alt"></i> Reservation</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </nav>

    <main class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-users"></i> Students Information</h1>
            <div class="button-group">
                <button onclick="openAddStudentModal()" class="btn-add"><i class="fas fa-plus"></i> Add Students</button>
                <a href="?reset_sessions=1" class="btn-reset" onclick="return confirm('Reset all students sessions to 30?')"><i class="fas fa-sync-alt"></i> Reset All Session</a>
                <button onclick="openSearchModal()" class="btn-search-icon"><i class="fas fa-search"></i> Search Student</button>
            </div>
        </div>
        
        <?php echo $message; ?>
        
        <div class="top-bar">
            <div class="search-box" style="display: flex; align-items: center; gap: 0.5rem; background: white; padding: 0.5rem 1rem; border-radius: 8px;">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Filter students by name, ID, or course..." style="border: none; outline: none; width: 250px;">
            </div>
        </div>
        
        <div class="table-container">
             <table>
                <thead>
                     <tr><th>ID Number</th><th>Name</th><th>Year Level</th><th>Course</th><th>Remaining Session</th><th>Actions</th></tr>
                </thead>
                <tbody id="studentTable">
                    <?php foreach($students as $s): ?>
                    <tr class="clickable-row" data-student-id="<?php echo $s['id']; ?>" data-student-id-number="<?php echo htmlspecialchars($s['id_number']); ?>" data-student-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>" data-student-course="<?php echo htmlspecialchars($s['course']); ?>" data-student-year="<?php echo $s['year_level']; ?>" data-student-email="<?php echo htmlspecialchars($s['email']); ?>" data-student-address="<?php echo htmlspecialchars($s['address']); ?>" data-student-sessions="<?php echo $s['remaining_sessions']; ?>" data-student-profile="<?php echo htmlspecialchars($s['profile_pic']); ?>">
                         <td><?php echo htmlspecialchars($s['id_number']); ?></td>
                         <td><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ' ' . $s['middle_name']); ?></td>
                         <td><?php echo $s['year_level']; ?></td>
                         <td><?php echo htmlspecialchars($s['course']); ?></td>
                         <td><span class="session-badge"><?php echo $s['remaining_sessions']; ?></span></td>
                         <td>
                            <button onclick="event.stopPropagation(); openEditStudentModal(<?php echo $s['id']; ?>)" class="action-edit">Edit</button>
                            <a href="?delete=<?php echo $s['id']; ?>" class="action-delete" onclick="event.stopPropagation(); return confirm('Delete this student?')">Delete</a>
                         </td>
                     </tr>
                    <?php endforeach; ?>
                </tbody>
             </table>
        </div>
    </main>

    <!-- Add Student Modal -->
    <div id="addStudentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Add New Student</h2>
                <span class="close" onclick="closeAddStudentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="addStudentMessage"></div>
                <form id="addStudentForm">
                    <div class="form-group"><label>ID Number:</label><input type="text" name="id_number" id="add_id_number" required></div>
                    <div class="form-row">
                        <div class="form-group"><label>Last Name:</label><input type="text" name="last_name" id="add_last_name" required></div>
                        <div class="form-group"><label>First Name:</label><input type="text" name="first_name" id="add_first_name" required></div>
                    </div>
                    <div class="form-group"><label>Middle Name (Optional):</label><input type="text" name="middle_name" id="add_middle_name"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Course:</label><select name="course" id="add_course" required><option value="">Select Course</option><option value="BSIT">BSIT</option><option value="BSCS">BSCS</option><option value="BSIS">BSIS</option></select></div>
                        <div class="form-group"><label>Year Level:</label><select name="year_level" id="add_year_level" required><option value="">Select Year</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Email:</label><input type="email" name="email" id="add_email" required></div>
                        <div class="form-group"><label>Address:</label><input type="text" name="address" id="add_address" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Password:</label><input type="password" name="password" id="add_password" required></div>
                        <div class="form-group"><label>Confirm Password:</label><input type="password" name="confirm_password" id="add_confirm_password" required></div>
                    </div>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Add Student</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editStudentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-edit"></i> Edit Student</h2>
                <span class="close" onclick="closeEditStudentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="editStudentMessage"></div>
                <div id="editStudentLoading" style="text-align:center; padding:2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                <form id="editStudentForm" style="display:none;">
                    <input type="hidden" name="student_id" id="edit_student_id">
                    <div class="form-group"><label>ID Number:</label><input type="text" id="edit_id_number" disabled style="background:#ecf0f1;"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Last Name:</label><input type="text" name="last_name" id="edit_last_name" required></div>
                        <div class="form-group"><label>First Name:</label><input type="text" name="first_name" id="edit_first_name" required></div>
                    </div>
                    <div class="form-group"><label>Middle Name:</label><input type="text" name="middle_name" id="edit_middle_name"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Course:</label><select name="course" id="edit_course" required><option value="BSIT">BSIT</option><option value="BSCS">BSCS</option><option value="BSIS">BSIS</option></select></div>
                        <div class="form-group"><label>Year Level:</label><select name="year_level" id="edit_year_level" required><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Email:</label><input type="email" name="email" id="edit_email" required></div>
                        <div class="form-group"><label>Address:</label><input type="text" name="address" id="edit_address" required></div>
                    </div>
                    <div class="form-group"><label>Remaining Sessions:</label><input type="number" name="remaining_sessions" id="edit_remaining_sessions" required min="0"></div>
                    <button type="submit" class="btn-submit btn-update"><i class="fas fa-save"></i> Update Student</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Student Profile Modal -->
    <div id="studentProfileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-graduate"></i> Student Profile</h2>
                <span class="close" onclick="closeProfileModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="profileContent"></div>
            </div>
        </div>
    </div>

    <!-- Search Modal - Only Sit-in Form -->
    <div id="searchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-search"></i> Search Student</h2>
                <span class="close" onclick="closeSearchModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="search-section">
                    <label>Enter Student ID Number:</label>
                    <div class="input-group">
                        <input type="text" id="searchIdNumber" placeholder="Enter Student ID Number" autocomplete="off">
                        <button onclick="searchStudent()" class="btn-search">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
                
                <!-- Sit-in Form (Shows only after search) -->
                <div id="sitInFormContainer" style="display: none;">
                    <div class="sit-in-form-section">
                        <h3><i class="fas fa-chair"></i> Sit In Form</h3>
                        <form method="POST" id="sitInForm" action="admin_sitins.php">
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
                                <button type="button" onclick="closeSearchModal()" class="btn-close">Close</button>
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
        // Filter table function
        document.getElementById('searchInput').addEventListener('keyup', function() {
            let value = this.value.toLowerCase();
            let rows = document.querySelectorAll('#studentTable tr');
            rows.forEach(row => { row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none'; });
        });
        
        // Add Student Modal Functions
        function openAddStudentModal() {
            document.getElementById('addStudentModal').style.display = 'block';
            document.getElementById('addStudentForm').reset();
            document.getElementById('addStudentMessage').innerHTML = '';
        }
        function closeAddStudentModal() { document.getElementById('addStudentModal').style.display = 'none'; }
        
        // Add Student Form Submission
        document.getElementById('addStudentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (document.getElementById('add_password').value !== document.getElementById('add_confirm_password').value) {
                document.getElementById('addStudentMessage').innerHTML = '<div class="alert-modal"><i class="fas fa-exclamation-circle"></i> Passwords do not match!</div>';
                return;
            }
            const submitBtn = this.querySelector('button');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
            submitBtn.disabled = true;
            const formData = new FormData(this);
            formData.append('add_student_ajax', '1');
            fetch('admin_students.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('addStudentMessage').innerHTML = '<div class="alert-modal alert-success-modal"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        document.getElementById('addStudentMessage').innerHTML = '<div class="alert-modal"><i class="fas fa-exclamation-circle"></i> ' + data.message.replace(/\n/g, '<br>') + '</div>';
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => { console.error('Error:', error); submitBtn.innerHTML = originalText; submitBtn.disabled = false; });
        });
        
        // Edit Student Modal Functions
        function openEditStudentModal(studentId) {
            document.getElementById('editStudentModal').style.display = 'block';
            document.getElementById('editStudentLoading').style.display = 'block';
            document.getElementById('editStudentForm').style.display = 'none';
            document.getElementById('editStudentMessage').innerHTML = '';
            
            fetch(`admin_students.php?get_student_for_edit=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_student_id').value = data.id;
                    document.getElementById('edit_id_number').value = data.id_number;
                    document.getElementById('edit_last_name').value = data.last_name;
                    document.getElementById('edit_first_name').value = data.first_name;
                    document.getElementById('edit_middle_name').value = data.middle_name;
                    document.getElementById('edit_course').value = data.course;
                    document.getElementById('edit_year_level').value = data.year_level;
                    document.getElementById('edit_email').value = data.email;
                    document.getElementById('edit_address').value = data.address;
                    document.getElementById('edit_remaining_sessions').value = data.remaining_sessions;
                    document.getElementById('editStudentLoading').style.display = 'none';
                    document.getElementById('editStudentForm').style.display = 'block';
                })
                .catch(error => { console.error('Error:', error); document.getElementById('editStudentLoading').innerHTML = '<div class="alert-modal">Error loading student data</div>'; });
        }
        function closeEditStudentModal() { document.getElementById('editStudentModal').style.display = 'none'; }
        
        // Edit Student Form Submission
        document.getElementById('editStudentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            submitBtn.disabled = true;
            const formData = new FormData(this);
            formData.append('edit_student_ajax', '1');
            fetch('admin_students.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editStudentMessage').innerHTML = '<div class="alert-modal alert-success-modal"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        document.getElementById('editStudentMessage').innerHTML = '<div class="alert-modal"><i class="fas fa-exclamation-circle"></i> ' + data.message.replace(/\n/g, '<br>') + '</div>';
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => { console.error('Error:', error); submitBtn.innerHTML = originalText; submitBtn.disabled = false; });
        });
        
        // Student Profile Modal
        function showStudentProfile(studentId) {
            const row = document.querySelector(`tr[data-student-id="${studentId}"]`);
            if (!row) return;
            const idNumber = row.getAttribute('data-student-id-number');
            const name = row.getAttribute('data-student-name');
            const course = row.getAttribute('data-student-course');
            const year = row.getAttribute('data-student-year');
            const email = row.getAttribute('data-student-email');
            const address = row.getAttribute('data-student-address');
            const sessions = row.getAttribute('data-student-sessions');
            const profilePic = row.getAttribute('data-student-profile');
            let yearText = year == 1 ? '1st Year' : year == 2 ? '2nd Year' : year == 3 ? '3rd Year' : year == 4 ? '4th Year' : year;
            document.getElementById('profileContent').innerHTML = `
                <div class="profile-card">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                        <img src="${profilePic}" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #f39c12;" onerror="this.src='default-avatar.png'">
                        <div><h3 style="margin:0;">${name}</h3><p style="margin:0;">ID: ${idNumber}</p></div>
                    </div>
                    <div class="profile-row"><span class="profile-label">Full Name:</span><span class="profile-value">${name}</span></div>
                    <div class="profile-row"><span class="profile-label">ID Number:</span><span class="profile-value">${idNumber}</span></div>
                    <div class="profile-row"><span class="profile-label">Course:</span><span class="profile-value">${course}</span></div>
                    <div class="profile-row"><span class="profile-label">Year Level:</span><span class="profile-value">${yearText}</span></div>
                    <div class="profile-row"><span class="profile-label">Email:</span><span class="profile-value">${email}</span></div>
                    <div class="profile-row"><span class="profile-label">Address:</span><span class="profile-value">${address}</span></div>
                    <div class="profile-row"><span class="profile-label">Remaining Sessions:</span><span class="profile-value"><span class="profile-badge">${sessions} sessions left</span></span></div>
                    <div class="profile-actions">
                        <button onclick="closeProfileModal(); openEditStudentModal(${studentId});" class="action-edit">Edit Profile</button>
                        <a href="admin_student_history.php?id=${studentId}" class="action-edit" style="background: #2ecc71;">View History</a>
                    </div>
                </div>
            `;
            document.getElementById('studentProfileModal').style.display = 'block';
        }
        function closeProfileModal() { document.getElementById('studentProfileModal').style.display = 'none'; }
        
        // Clickable rows
        document.querySelectorAll('.clickable-row').forEach(row => {
            row.addEventListener('click', function(e) {
                if (e.target.classList.contains('action-edit') || e.target.classList.contains('action-delete')) return;
                showStudentProfile(this.getAttribute('data-student-id'));
            });
        });
        
        // Search Modal functions
        function openSearchModal() {
            document.getElementById('searchModal').style.display = 'block';
            document.getElementById('searchIdNumber').focus();
            document.getElementById('sitInFormContainer').style.display = 'none';
            document.getElementById('searchIdNumber').value = '';
            document.getElementById('sitInForm').reset();
        }
        
        function closeSearchModal() {
            document.getElementById('searchModal').style.display = 'none';
        }
        
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
                        // Fill the form fields
                        document.getElementById('formStudentId').value = data.id;
                        document.getElementById('formIdNumber').value = data.id_number;
                        document.getElementById('formStudentName').value = data.name;
                        document.getElementById('formRemainingSessions').value = data.remaining_sessions + ' sessions left';
                        
                        // Check remaining sessions
                        if (data.remaining_sessions <= 0) {
                            alert('Warning: This student has no remaining sessions!');
                            document.getElementById('submitBtn').disabled = true;
                        } else {
                            document.getElementById('submitBtn').disabled = false;
                        }
                        
                        // Show the form
                        document.getElementById('sitInFormContainer').style.display = 'block';
                        
                        // Clear search input
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
        
        // Enter key to search
        document.getElementById('searchIdNumber').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchStudent();
            }
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('searchModal');
            if (event.target == modal) {
                closeSearchModal();
            }
        }
    </script>
</body>
</html>