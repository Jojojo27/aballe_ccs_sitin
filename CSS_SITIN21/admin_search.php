<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$student = null;
$error = '';
$search_id = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['student_id'])) {
    $search_id = trim($_POST['student_id']);
    if (empty($search_id)) {
        $error = "Please enter a student ID";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id_number = ?");
        $stmt->execute([$search_id]);
        $student = $stmt->fetch();
        if (!$student) {
            $error = "No student found with ID: " . htmlspecialchars($search_id);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Student - Admin</title>
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
        
        .main-content { margin-top: 80px; padding: 2rem; max-width: 900px; margin-left: auto; margin-right: auto; }
        
        /* Search Section */
        .search-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .search-section h1 {
            font-size: 1.8rem;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
        }
        
        .search-section h1 i {
            color: #3498db;
        }
        
        .search-section p {
            color: #666;
            margin-bottom: 1.5rem;
        }
        
        .search-box {
            display: flex;
            gap: 1rem;
            max-width: 500px;
            margin: 0 auto;
            flex-wrap: wrap;
        }
        
        .search-box input {
            flex: 1;
            padding: 0.9rem 1rem;
            border: 2px solid #e0e7ff;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        
        .search-box input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }
        
        .search-box button {
            padding: 0.9rem 2rem;
            background: linear-gradient(145deg, #3498db, #2980b9);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .search-box button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52,152,219,0.3);
        }
        
        /* Alert Message */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            text-align: center;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .profile-header {
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid #f39c12;
            object-fit: cover;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .profile-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.3rem;
        }
        
        .profile-header p {
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.2rem;
            padding: 1.8rem;
        }
        
        .info-card {
            background: #f8faff;
            border-radius: 12px;
            padding: 1rem 1.2rem;
            border: 1px solid #e0e7ff;
            transition: all 0.3s;
        }
        
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border-color: #3498db;
        }
        
        .info-label {
            font-size: 0.7rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }
        
        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .session-badge {
            background: #27ae60;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            display: inline-block;
            font-size: 0.9rem;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            padding: 1.2rem 1.8rem 1.8rem;
            flex-wrap: wrap;
            border-top: 1px solid #e0e7ff;
            background: #f8faff;
        }
        
        .btn-action {
            padding: 0.7rem 1.3rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            font-size: 0.85rem;
        }
        
        .btn-edit {
            background: #f39c12;
            color: white;
            box-shadow: 0 2px 8px rgba(243,156,18,0.3);
        }
        
        .btn-history {
            background: #3498db;
            color: white;
            box-shadow: 0 2px 8px rgba(52,152,219,0.3);
        }
        
        .btn-reservation {
            background: #2ecc71;
            color: white;
            box-shadow: 0 2px 8px rgba(46,204,113,0.3);
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }
        
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                text-align: center;
            }
            .navbar-links {
                justify-content: center;
            }
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            .info-grid {
                grid-template-columns: 1fr;
                padding: 1.2rem;
            }
            .action-buttons {
                flex-direction: column;
            }
            .btn-action {
                width: 100%;
                justify-content: center;
            }
            .search-box {
                flex-direction: column;
            }
            .search-box button {
                width: 100%;
                justify-content: center;
            }
            .main-content {
                padding: 1rem;
                margin-top: 100px;
            }
        }
        
        @media (max-width: 480px) {
            .search-section {
                padding: 1.5rem;
            }
            
            .search-section h1 {
                font-size: 1.4rem;
            }
            
            .profile-header {
                padding: 1.5rem;
            }
            
            .profile-avatar {
                width: 80px;
                height: 80px;
            }
            
            .profile-header h2 {
                font-size: 1.2rem;
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
            <a href="admin_dashboard.php"><i class="fas fa-home"></i> Home</a>
            <a href="admin_search.php" class="active"><i class="fas fa-search"></i> Search</a>
            <a href="admin_students.php"><i class="fas fa-users"></i> Students</a>
            <a href="admin_sitins.php"><i class="fas fa-clock"></i> Sit-in</a>
            <a href="admin_records.php"><i class="fas fa-list"></i> View Sit-in Records</a>
            <a href="admin_reports.php"><i class="fas fa-chart-line"></i> Sit-in Reports</a>
            <a href="admin_feedback.php"><i class="fas fa-star"></i> Feedback Reports</a>
            <a href="admin_reservations.php"><i class="fas fa-calendar-alt"></i> Reservation</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </nav>

    <main class="main-content">
        <div class="search-section">
            <h1>
                <i class="fas fa-search"></i> 
                Search Student
            </h1>
            <p>Enter the student ID number to view their complete profile</p>
            <form method="POST" class="search-box">
                <input type="text" name="student_id" placeholder="Enter Student ID Number" value="<?php echo htmlspecialchars($search_id); ?>" required>
                <button type="submit">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
        
        <?php if ($error): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($student): ?>
        <div class="profile-card">
            <div class="profile-header">
                <img src="<?php echo htmlspecialchars($student['profile_pic']); ?>" class="profile-avatar" onerror="this.src='default-avatar.png'">
                <div>
                    <h2><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                    <p>
                        <i class="fas fa-id-card"></i> 
                        ID: <?php echo htmlspecialchars($student['id_number']); ?>
                    </p>
                </div>
            </div>
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-user"></i> Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-book"></i> Course</div>
                    <div class="info-value"><?php echo htmlspecialchars($student['course']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-layer-group"></i> Year Level</div>
                    <div class="info-value"><?php 
                        $year = $student['year_level'];
                        if($year == 1) echo "1st Year";
                        elseif($year == 2) echo "2nd Year";
                        elseif($year == 3) echo "3rd Year";
                        elseif($year == 4) echo "4th Year";
                        else echo $year;
                    ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($student['email']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                    <div class="info-value"><?php echo htmlspecialchars($student['address']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-hourglass-half"></i> Remaining Sessions</div>
                    <div class="info-value">
                        <span class="session-badge">
                            <i class="fas fa-clock"></i> <?php echo $student['remaining_sessions']; ?> Sessions Left
                        </span>
                    </div>
                </div>
            </div>
            <div class="action-buttons">
                <a href="admin_edit_student.php?id=<?php echo $student['id']; ?>" class="btn-action btn-edit">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <a href="admin_student_history.php?id=<?php echo $student['id']; ?>" class="btn-action btn-history">
                    <i class="fas fa-history"></i> View Sit-in History
                </a>
                <a href="admin_student_reservations.php?id=<?php echo $student['id']; ?>" class="btn-action btn-reservation">
                    <i class="fas fa-calendar-alt"></i> View Reservations
                </a>
            </div>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>