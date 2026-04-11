<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login first";
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle "Mark all as read" functionality
if (isset($_POST['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header("Location: student_dashboard.php");
    exit();
}

// Get unread notifications count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();

// Get all notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

// Mark notification as read when clicked
if (isset($_GET['read_notification'])) {
    $notif_id = $_GET['read_notification'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notif_id, $user_id]);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_photo'])) {
    $target_dir = "uploads/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($_FILES["profile_photo"]["name"], PATHINFO_EXTENSION));
    $new_filename = "profile_" . $user_id . "_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (in_array($file_extension, $allowed_types)) {
        if (move_uploaded_file($_FILES["profile_photo"]["tmp_name"], $target_file)) {
            $stmt = $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
            $stmt->execute([$target_file, $user_id]);
            $_SESSION['profile_pic'] = $target_file;
            $upload_success = "Profile photo updated successfully!";
        }
    }
}

// Get latest student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$student = $stmt->fetch();

if (!$student) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Update session
$_SESSION['id_number'] = $student['id_number'];
$_SESSION['first_name'] = $student['first_name'];
$_SESSION['last_name'] = $student['last_name'];
$_SESSION['middle_name'] = $student['middle_name'];
$_SESSION['course'] = $student['course'];
$_SESSION['year'] = $student['year_level'];
$_SESSION['email'] = $student['email'];
$_SESSION['address'] = $student['address'];
$_SESSION['session'] = $student['remaining_sessions'];
$_SESSION['profile_pic'] = $student['profile_pic'];

// Get announcements from database
$stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC");
$announcements = $stmt->fetchAll();

$current_date = date('Y-M-d');
$admin_date = "2024-May-08";
$current_page = 'student_dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - CCS Sit-in Monitoring</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(2deg,rgba(203, 164, 237, 1) 0%, rgba(177, 204, 224, 1) 56%, rgba(4, 4, 59, 1) 100%); }

        .navbar {
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0.8rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo-container { display: flex; align-items: center; gap: 15px; }
        .nav-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 2px rgba(255,255,255,0.3), 0 0 0 5px #f39c12, 0 0 15px rgba(243,156,18,0.5);
            transition: all 0.3s ease;
        }
        .nav-avatar:hover { transform: scale(1.05); box-shadow: 0 0 0 2px rgba(255,255,255,0.4), 0 0 0 8px #f39c12, 0 0 25px rgba(243,156,18,0.7); }
        .nav-avatar img { width: 32px; height: 32px; object-fit: contain; }
        .logo-text { color: white; font-weight: 600; line-height: 1.2; }
        .logo-text strong { font-size: 1.1rem; display: block; }
        .logo-text small { font-size: 0.7rem; font-weight: 400; opacity: 0.8; }
        .nav-links { display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; }
        .nav-links a {
            text-decoration: none;
            color: white;
            font-weight: 500;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0.5rem 0;
        }
        .nav-links a:hover, .nav-links a.active { color: #f39c12; }
        
        .notification-icon {
            position: relative;
            cursor: pointer;
            margin-left: 0.5rem;
        }
        .notification-icon i { font-size: 1.2rem; color: white; }
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -12px;
            background: #e74c3c;
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 50px;
            min-width: 18px;
            text-align: center;
        }
        .notification-dropdown {
            position: absolute;
            top: 130%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            z-index: 1100;
            display: none;
            overflow: hidden;
            max-height: 500px;
            overflow-y: auto;
        }
        .notification-dropdown.show { display: block; }
        .notification-header {
            padding: 12px 15px;
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .notification-header a {
            color: #f39c12;
            text-decoration: none;
            font-size: 0.75rem;
        }
        .notification-header a:hover { text-decoration: underline; }
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e7ff;
            cursor: pointer;
            transition: 0.2s;
            text-decoration: none;
            display: block;
            color: #2c3e50;
        }
        .notification-item:hover { background: #f8faff; }
        .notification-item.unread { background: #fff8e7; }
        .notification-item.unread:hover { background: #fff3cd; }
        .notification-title {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .notification-message { font-size: 0.75rem; color: #666; margin-bottom: 4px; }
        .notification-time { font-size: 0.65rem; color: #999; }
        .notification-empty { text-align: center; padding: 30px; color: #999; }
        
        .main-content { margin-top: 80px; padding: 2rem; }
        .dashboard-wrapper { max-width: 1200px; margin: 0 auto; }
        
        .dashboard-header-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 25px 30px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e0e7ff;
        }
        .dashboard-header-card h1 { font-size: 28px; color: #2c3e50; display: flex; align-items: center; gap: 10px; }
        .dashboard-header-card h1 i { color: #3498db; }
        .notification-badge-card {
            background: #fff8e7;
            padding: 10px 20px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #f39c12;
            font-weight: 500;
            border: 1px solid #ffe0b2;
        }
        
        .main-container-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 25px;
            border: 1px solid #e0e7ff;
        }
        .container-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            flex-wrap: wrap;
            gap: 15px;
        }
        .container-header h2 { font-size: 20px; color: #2c3e50; display: flex; align-items: center; gap: 10px; }
        .container-header h2 i { color: #3498db; }
        .edit-profile-link {
            text-decoration: none;
            color: #3498db;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 50px;
            background: #f0f7ff;
            border: 1px solid #3498db;
            transition: all 0.3s;
        }
        .edit-profile-link:hover { background: #3498db; color: white; }
        
        .profile-layout { display: grid; grid-template-columns: 200px 1fr; gap: 30px; }
        @media (max-width: 768px) { .profile-layout { grid-template-columns: 1fr; } }
        
        .profile-photo-section { text-align: center; }
        .photo-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 15px;
        }
        .profile-photo {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 0 0 4px rgba(52,152,219,0.3), 0 0 0 8px #f39c12, 0 0 25px rgba(243,156,18,0.4);
        }
        .change-photo-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #f39c12;
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .student-name { font-size: 18px; color: #2c3e50; margin-bottom: 5px; font-weight: 600; }
        .student-id-badge {
            background: #f0f7ff;
            display: inline-block;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 14px;
            color: #3498db;
            border: 1px solid #c7e0ff;
        }
        
        .details-grid { display: flex; flex-direction: column; gap: 12px; }
        .detail-row {
            display: flex;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e7ff;
        }
        .detail-label { min-width: 120px; font-weight: 500; color: #2c3e50; }
        .detail-colon { margin: 0 15px; color: #999; }
        .detail-value { flex: 1; color: #333; }
        .session-row {
            background: linear-gradient(145deg, #3498db, #2980b9);
            padding: 15px;
            border-radius: 10px;
            border-bottom: none;
            margin-top: 10px;
        }
        .session-row .detail-label, .session-row .detail-colon, .session-row .detail-value { color: white; }
        .session-number { font-size: 24px; font-weight: 700; }
        
        /* SCROLLABLE ANNOUNCEMENT CARD */
        .announcement-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 25px;
            border: 1px solid #e0e7ff;
        }
        .announcement-container {
            max-height: 300px;
            overflow-y: auto;
            padding-right: 10px;
        }
        .announcement-container::-webkit-scrollbar {
            width: 6px;
        }
        .announcement-container::-webkit-scrollbar-track {
            background: #f0f0f0;
            border-radius: 3px;
        }
        .announcement-container::-webkit-scrollbar-thumb {
            background: #3498db;
            border-radius: 3px;
        }
        .admin-dates {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .admin-date {
            background: #f0f7ff;
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 14px;
            color: #3498db;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #c7e0ff;
        }
        .announcement-message {
            background: #fff8e7;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #f39c12;
            margin-bottom: 15px;
        }
        
        .rules-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 25px;
            border: 1px solid #e0e7ff;
        }
        .university-header { text-align: center; margin-bottom: 25px; }
        .university-header h3 { font-size: 22px; color: #2c3e50; margin-bottom: 5px; }
        .university-header h4 { font-size: 16px; color: #3498db; font-weight: 500; }
        .rules-title { font-size: 18px; color: #2c3e50; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; }
        .rules-intro {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 3px solid #3498db;
            font-style: italic;
            color: #666;
        }
        .rules-list { padding-left: 20px; margin-bottom: 25px; }
        .rules-list li { margin-bottom: 12px; line-height: 1.6; color: #2c3e50; }
        
        .quick-actions-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 30px;
            border: 1px solid #e0e7ff;
        }
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .action-item {
            background: #f8faff;
            padding: 20px;
            border-radius: 10px;
            text-decoration: none;
            color: #2c3e50;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            border: 2px solid #e0e7ff;
        }
        .action-item i { font-size: 28px; color: #3498db; }
        .action-item:hover { background: #3498db; color: white; transform: translateY(-3px); }
        .action-item:hover i { color: white; }
        
        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        
        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
            .nav-links { justify-content: center; }
            .dashboard-header-card { flex-direction: column; text-align: center; gap: 15px; }
            .container-header { flex-direction: column; text-align: center; }
            .admin-dates { flex-direction: column; }
            .notification-dropdown { width: 300px; right: -50px; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="nav-container">
        <div class="logo-container">
            <div class="nav-avatar"><img src="ccsmainlogo.png" alt="CCS Logo"></div>
            <div class="logo-text"><strong>CCS</strong><small>Sit-in Monitoring</small></div>
        </div>
        <div class="nav-links">
            <a href="student_dashboard.php" class="<?php echo $current_page == 'student_dashboard' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="profile_edit.php"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <a href="sit_history.php"><i class="fas fa-history"></i> History</a>
            <a href="sit_reservation.php"><i class="fas fa-calendar-alt"></i> Reservation</a>
            <a href="student_feedback.php"><i class="fas fa-star"></i> Feedback</a>
            <a href="logout.php" onclick="return confirm('Logout?')"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="notification-icon" id="notificationIcon">
            <i class="fas fa-bell"></i>
            <?php if ($unread_count > 0): ?>
                <span class="notification-badge" id="notificationBadge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <span><i class="fas fa-bell"></i> Notifications</span>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="mark_all_read" style="background: none; border: none; color: #f39c12; cursor: pointer; font-size: 0.75rem;">
                            Mark all as read
                        </button>
                    </form>
                </div>
                <div class="notification-list">
                    <?php if (empty($notifications)): ?>
                        <div class="notification-empty"><i class="fas fa-bell-slash"></i><p>No notifications yet</p></div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <a href="?read_notification=<?php echo $notif['id']; ?>" class="notification-item <?php echo $notif['is_read'] == 0 ? 'unread' : ''; ?>">
                                <div class="notification-title">
                                    <?php if ($notif['type'] == 'announcement'): ?><i class="fas fa-bullhorn" style="color: #f39c12;"></i>
                                    <?php elseif ($notif['type'] == 'reservation'): ?><i class="fas fa-calendar-check" style="color: #27ae60;"></i>
                                    <?php else: ?><i class="fas fa-info-circle" style="color: #3498db;"></i><?php endif; ?>
                                    <?php echo htmlspecialchars($notif['title']); ?>
                                </div>
                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div class="notification-time"><i class="fas fa-clock"></i> <?php echo date('M d, h:i A', strtotime($notif['created_at'])); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="main-content">
    <div class="dashboard-wrapper">

        <?php if (isset($upload_success)): ?>
            <div class="alert alert-success"><?php echo $upload_success; ?></div>
        <?php endif; ?>

        <!-- Student Information Card -->
        <div class="main-container-card">
            <div class="container-header">
                <h2><i class="fas fa-user-graduate"></i> Student Information</h2>
                <a href="profile_edit.php" class="edit-profile-link"><i class="fas fa-edit"></i> Edit Profile</a>
            </div>
            <div class="profile-layout">
                <div class="profile-photo-section">
                    <div class="photo-container">
                        <img src="<?php echo htmlspecialchars($student['profile_pic']); ?>" class="profile-photo" id="profilePhoto">
                        <form method="POST" enctype="multipart/form-data" id="photoUploadForm">
                            <input type="file" name="profile_photo" id="photoUpload" accept="image/*" style="display: none;" onchange="this.form.submit()">
                            <button type="button" class="change-photo-btn" onclick="document.getElementById('photoUpload').click();"><i class="fas fa-camera"></i></button>
                        </form>
                    </div>
                    <h3 class="student-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h3>
                    <span class="student-id-badge">ID: <?php echo htmlspecialchars($student['id_number']); ?></span>
                </div>
                <div class="details-grid">
                    <div class="detail-row"><span class="detail-label">Full Name</span><span class="detail-colon">:</span><span class="detail-value"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']); ?></span></div>
                    <div class="detail-row"><span class="detail-label">Course</span><span class="detail-colon">:</span><span class="detail-value"><?php echo htmlspecialchars($student['course']); ?></span></div>
                    <div class="detail-row"><span class="detail-label">Year</span><span class="detail-colon">:</span><span class="detail-value"><?php echo htmlspecialchars($student['year_level']); ?></span></div>
                    <div class="detail-row"><span class="detail-label">Email</span><span class="detail-colon">:</span><span class="detail-value"><?php echo htmlspecialchars($student['email']); ?></span></div>
                    <div class="detail-row"><span class="detail-label">Address</span><span class="detail-colon">:</span><span class="detail-value"><?php echo htmlspecialchars($student['address']); ?></span></div>
                    <div class="detail-row session-row"><span class="detail-label">Session</span><span class="detail-colon">:</span><span class="detail-value session-number"><?php echo htmlspecialchars($student['remaining_sessions']); ?></span></div>
                </div>
            </div>
        </div>

        <!-- Announcement Card - SCROLLABLE -->
        <div class="announcement-card">
            <div class="container-header">
                <h2><i class="fas fa-bullhorn"></i> Announcement</h2>
            </div>
            <div class="admin-dates">
                <span class="admin-date"><i class="fas fa-user-tie"></i> CCS Admin | <?php echo $current_date; ?></span>
                <span class="admin-date"><i class="fas fa-calendar-check"></i> CCS Admin | <?php echo $admin_date; ?></span>
            </div>
            
            <div class="announcement-container">
                <?php if (empty($announcements)): ?>
                    <div class="announcement-message">
                        <p><i class="fas fa-star" style="color: #f39c12;"></i> No announcements yet. Check back later!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="announcement-message">
                            <p>
                                <i class="fas fa-star" style="color: #f39c12;"></i>
                                <?php echo htmlspecialchars($announcement['message']); ?>
                            </p>
                            <div style="font-size: 0.7rem; color: #999; margin-top: 8px;">
                                <i class="fas fa-clock"></i> Posted on: <?php echo date('M d, Y h:i A', strtotime($announcement['created_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rules and Regulations Card -->
        <div class="rules-card">
            <div class="container-header">
                <h2><i class="fas fa-gavel"></i> Rules and Regulation</h2>
            </div>
            <div class="university-header">
                <h3>University of Cebu</h3>
                <h4>COLLEGE OF INFORMATION & COMPUTER STUDIES</h4>
            </div>
            <h5 class="rules-title">LABORATORY RULES AND REGULATIONS</h5>
            <p class="rules-intro">To avoid embarrassment and maintain camaraderie with your friends and superiors at our laboratories, please observe the following:</p>
            <ol class="rules-list">
                <li>Maintain silence, proper decorum, and discipline inside the laboratory. Mobile phones must be switched off.</li>
                <li>Games are not allowed inside the lab. This includes computer-related games, card games and other games that may disturb the operation of the lab.</li>
                <li>Surfing the internet is allowed only with the permission of the instructor. Downloading and installing of software are strictly prohibited.</li>
            </ol>
        </div>

        <!-- Quick Actions Card -->
    
    </div>
</main>

<script>
    // Notification dropdown toggle
    const notificationIcon = document.getElementById('notificationIcon');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const notificationBadge = document.getElementById('notificationBadge');
    
    if (notificationIcon) {
        notificationIcon.addEventListener('click', function(event) {
            event.stopPropagation();
            notificationDropdown.classList.toggle('show');
        });
    }
    
    document.addEventListener('click', function() {
        if (notificationDropdown) notificationDropdown.classList.remove('show');
    });
    
    if (notificationDropdown) {
        notificationDropdown.addEventListener('click', function(event) {
            event.stopPropagation();
        });
    }
    
    document.getElementById('photoUpload').onchange = function() {
        if (this.files && this.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('profilePhoto').src = e.target.result;
            }
            reader.readAsDataURL(this.files[0]);
            this.form.submit();
        }
    };
    
    // Function to update notification badge (called after marking as read)
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (count > 0) {
            if (badge) {
                badge.textContent = count;
            } else {
                const newBadge = document.createElement('span');
                newBadge.className = 'notification-badge';
                newBadge.id = 'notificationBadge';
                newBadge.textContent = count;
                notificationIcon.appendChild(newBadge);
            }
        } else {
            if (badge) badge.remove();
        }
    }
</script>

</body>
</html>