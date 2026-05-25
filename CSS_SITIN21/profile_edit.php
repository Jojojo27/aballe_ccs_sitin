<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle "Mark all as read" functionality
if (isset($_POST['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header("Location: profile_edit.php");
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

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $middle_name = trim($_POST['middle_name']);
    $course = $_POST['course'];
    $year_level = $_POST['year_level'];
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);

    $errors = [];

    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email";
    if (empty($address)) $errors[] = "Address is required";

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetch()) $errors[] = "Email already exists";

    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE users SET first_name=?, last_name=?, middle_name=?, course=?, year_level=?, email=?, address=? WHERE id=?");
        $stmt->execute([$first_name,$last_name,$middle_name,$course,$year_level,$email,$address,$user_id]);

        $success = "Profile updated successfully!";
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['middle_name'] = $middle_name;
        $_SESSION['course'] = $course;
        $_SESSION['year'] = $year_level;
        $_SESSION['email'] = $email;
        $_SESSION['address'] = $address;
        
        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
    } else {
        $error = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Edit Profile - CCS Sit-in Monitoring</title>
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
.notification-header { padding: 12px 15px; background: linear-gradient(145deg, #2c3e50, #1a2634); color: white; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center; }
.notification-header a { color: #f39c12; text-decoration: none; font-size: 0.75rem; }
.notification-header a:hover { text-decoration: underline; }
.notification-item { padding: 12px 15px; border-bottom: 1px solid #e0e7ff; cursor: pointer; transition: 0.2s; text-decoration: none; display: block; color: #2c3e50; }
.notification-item:hover { background: #f8faff; }
.notification-item.unread { background: #fff8e7; }
.notification-item.unread:hover { background: #fff3cd; }
.notification-title { font-weight: 600; font-size: 0.85rem; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
.notification-title i { font-size: 0.8rem; }
.notification-message { font-size: 0.75rem; color: #666; margin-bottom: 4px; }
.notification-time { font-size: 0.65rem; color: #999; }
.notification-empty { text-align: center; padding: 30px; color: #999; }

/* MAIN CONTAINER */
.container {
    max-width: 900px;
    margin: 150px auto 40px;
    padding: 0 20px;
}

/* CARD */
.card {
    background: white;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    border: 1px solid #e0e7ff;
}

.card h2 {
    margin-bottom: 25px;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.6rem;
    font-weight: 600;
}

.card h2 i {
    color: #3498db;
}

/* GRID */
.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.full {
    grid-column: span 2;
}

/* FORM GROUP */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #2c3e50;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* INPUT BOX */
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
    padding: 12px 12px 12px 42px;
    border: 2px solid #e0e7ff;
    border-radius: 12px;
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    background: #f8faff;
    transition: all 0.3s ease;
    box-sizing: border-box;
}

input:focus, select:focus {
    border-color: #3498db;
    background: white;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    outline: none;
}

input:disabled {
    background: #ecf0f1;
    color: #7f8c8d;
    cursor: not-allowed;
    border-color: #e0e7ff;
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

/* BUTTONS */
.actions {
    margin-top: 30px;
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    padding-top: 20px;
    border-top: 1px solid #e0e7ff;
}

.btn {
    padding: 12px 28px;
    border-radius: 50px;
    border: none;
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

.btn-save {
    background: linear-gradient(145deg, #27ae60, #219a52);
    color: white;
    box-shadow: 0 4px 12px rgba(39,174,96,0.3);
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(39,174,96,0.4);
}

.btn-cancel {
    background: #e74c3c;
    color: white;
    box-shadow: 0 4px 12px rgba(231,76,60,0.3);
}

.btn-cancel:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(231,76,60,0.4);
}

/* Responsive */
@media (max-width: 768px) {
    .nav-container {
        flex-direction: column;
        padding: 0.8rem 1rem;
        text-align: center;
    }
    
    .logo-container {
        justify-content: center;
    }
    
    .nav-links {
        justify-content: center;
        gap: 1rem;
    }
    
    .container {
        margin-top: 140px;
        padding: 0 15px;
    }
    
    .grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .full {
        grid-column: span 1;
    }
    
    .actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .card {
        padding: 20px;
    }
    
    .card h2 {
        font-size: 1.3rem;
    }
    
    .notification-dropdown {
        width: 300px;
        right: -50px;
    }
}

@media (max-width: 480px) {
    .container {
        margin-top: 160px;
    }
    
    .nav-links a {
        font-size: 0.8rem;
        padding: 0.3rem 0.6rem;
    }
    
    .nav-links a i {
        font-size: 0.8rem;
    }
    
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
            <a href="profile_edit.php" class="active"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <div class="nav-dropdown">
                <a href="#"><i class="fas fa-clock"></i> Sit-in <i class="fas fa-caret-down"></i></a>
                <div class="nav-dropdown-content">
                    <a href="sit_reservation.php"><i class="fas fa-desktop"></i> Current Sit-in</a>
                    <a href="sit_history.php"><i class="fas fa-file-alt"></i> Sit-in Records</a>
                </div>
            </div>
            <a href="student_feedback.php"><i class="fas fa-star"></i> Feedback</a>
            <a href="logout.php" onclick="return confirm('Are you sure you want to logout?')"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
            </div>
        </div>
    </div>
</nav>

<div class="container">
    <div class="card">
        <h2>
            <i class="fas fa-user-edit"></i> 
            Edit Profile
        </h2>

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

        <form method="POST">
            <div class="grid">
                <div class="form-group full">
                    <label for="id_number">ID Number</label>
                    <div class="input-box">
                        <i class="fas fa-id-card"></i>
                        <input id="id_number" value="<?php echo htmlspecialchars($user['id_number']); ?>" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <div class="input-box">
                        <i class="fas fa-user"></i>
                        <input id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <div class="input-box">
                        <i class="fas fa-user"></i>
                        <input id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="middle_name">Middle Name</label>
                    <div class="input-box">
                        <i class="fas fa-user"></i>
                        <input id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="course">Course</label>
                    <div class="input-box">
                        <i class="fas fa-book"></i>
                        <select id="course" name="course" required>
                            <option value="">Select Course</option>
                            <option value="BSIT" <?php echo ($user['course'] == 'BSIT') ? 'selected' : ''; ?>>BSIT</option>
                            <option value="BSCS" <?php echo ($user['course'] == 'BSCS') ? 'selected' : ''; ?>>BSCS</option>
                            <option value="BSIS" <?php echo ($user['course'] == 'BSIS') ? 'selected' : ''; ?>>BSIS</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="year_level">Year Level</label>
                    <div class="input-box">
                        <i class="fas fa-layer-group"></i>
                        <select id="year_level" name="year_level" required>
                            <option value="">Select Year</option>
                            <option value="1" <?php echo ($user['year_level'] == '1') ? 'selected' : ''; ?>>1st Year</option>
                            <option value="2" <?php echo ($user['year_level'] == '2') ? 'selected' : ''; ?>>2nd Year</option>
                            <option value="3" <?php echo ($user['year_level'] == '3') ? 'selected' : ''; ?>>3rd Year</option>
                            <option value="4" <?php echo ($user['year_level'] == '4') ? 'selected' : ''; ?>>4th Year</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <div class="input-box">
                        <i class="fas fa-envelope"></i>
                        <input id="email" type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <div class="input-box">
                        <i class="fas fa-map-marker-alt"></i>
                        <input id="address" name="address" value="<?php echo htmlspecialchars($user['address']); ?>" required>
                    </div>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-save">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="student_dashboard.php" class="btn btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

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
</script>

</body>
</html>