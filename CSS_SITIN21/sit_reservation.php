<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get unread notifications count
require_once 'config.php';

$user_id = $_SESSION['user_id'];

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

$student = [
    'id_number' => $_SESSION['id_number'],
    'first_name' => $_SESSION['first_name'],
    'last_name' => $_SESSION['last_name'],
    'middle_name' => $_SESSION['middle_name'],
    'session' => $_SESSION['session']
];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $purpose = $_POST['purpose'];
    $lab = $_POST['lab'];
    $time_in = $_POST['time_in'];
    $time_out = $_POST['time_out'];
    $date = $_POST['date'];

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
?>

<!DOCTYPE html>
<html>
<head>
<title>Sit-in Reservation - CCS Sit-in Monitoring</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
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

.logo-container {
    display: flex;
    align-items: center;
    gap: 15px;
}

/* Avatar Circle with Glow */
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

.nav-avatar:hover {
    transform: scale(1.05);
    box-shadow: 0 0 0 2px rgba(255,255,255,0.4), 0 0 0 8px #f39c12, 0 0 25px rgba(243,156,18,0.7);
}

.nav-avatar img {
    width: 32px;
    height: 32px;
    object-fit: contain;
}

.logo-text {
    color: white;
    font-weight: 600;
    line-height: 1.2;
}

.logo-text strong {
    font-size: 1.1rem;
    display: block;
}

.logo-text small {
    font-size: 0.7rem;
    font-weight: 400;
    opacity: 0.8;
}

.nav-links {
    display: flex;
    gap: 1.5rem;
    align-items: center;
    flex-wrap: wrap;
}

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

.nav-links a:hover {
    color: #f39c12;
}

.nav-links a.active {
    color: #f39c12;
}

/* NOTIFICATION */
.notification-icon {
    position: relative;
    cursor: pointer;
    margin-left: 0.5rem;
}

.notification-icon i {
    font-size: 1.2rem;
    color: white;
}

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

.notification-dropdown.show {
    display: block;
}

.notification-header {
    padding: 12px 15px;
    background: linear-gradient(145deg, #2c3e50, #1a2634);
    color: white;
    font-weight: 600;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.notification-item {
    padding: 12px 15px;
    border-bottom: 1px solid #e0e7ff;
    cursor: pointer;
    transition: 0.2s;
    text-decoration: none;
    display: block;
    color: #2c3e50;
}

.notification-item:hover {
    background: #f8faff;
}

.notification-item.unread {
    background: #fff8e7;
}

.notification-item.unread:hover {
    background: #fff3cd;
}

.notification-title {
    font-weight: 600;
    font-size: 0.85rem;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.notification-title i {
    font-size: 0.8rem;
}

.notification-message {
    font-size: 0.75rem;
    color: #666;
    margin-bottom: 4px;
}

.notification-time {
    font-size: 0.65rem;
    color: #999;
}

.notification-empty {
    text-align: center;
    padding: 30px;
    color: #999;
}

.notification-footer {
    padding: 10px;
    text-align: center;
    background: #f8f9fa;
    border-top: 1px solid #e0e7ff;
}

.notification-footer a {
    color: #3498db;
    text-decoration: none;
    font-size: 0.75rem;
}

/* MAIN CONTAINER */
.container {
    max-width: 1000px;
    margin: 100px auto 40px;
    padding: 0 20px;
}

/* CARD */
.card {
    background: white;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    margin-bottom: 5px;
    border: 1px solid #e0e7ff;
}

.card h2 {
    margin-bottom: 25px;
    color: #2c3e50;
    font-size: 1.6rem;
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
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    border-left: 4px solid #3498db;
}

.student-info p {
    margin: 8px 0;
    font-size: 14px;
    color: #555;
}

.student-info b {
    color: #2c3e50;
}

/* GRID */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
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

/* BUTTON */
.actions {
    margin-top: 30px;
    display: flex;
    justify-content: center;
    gap: 15px;
    padding-top: 20px;
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
        gap: 15px;
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
            <a href="student_dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="profile_edit.php">
                <i class="fas fa-user-edit"></i> Edit Profile
            </a>
            <a href="sit_history.php">
                <i class="fas fa-history"></i> History
            </a>
            <a href="sit_reservation.php" class="active">
                <i class="fas fa-calendar-alt"></i> Reservation
            </a>
            <a href="student_feedback.php">
                <i class="fas fa-star"></i> Feedback
            </a>
            <a href="logout.php" onclick="return confirm('Are you sure you want to logout?')">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
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
    <div class="card">
        <h2><i class="fas fa-calendar-alt"></i> Sit-in Reservation</h2>

        <!-- Student Info Summary -->
        <div class="student-info">
            <p><b>ID Number:</b> <?php echo htmlspecialchars($student['id_number']); ?></p>
            <p><b>Full Name:</b> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']); ?></p>
            <p><b>Remaining Sessions:</b> <?php echo htmlspecialchars($student['session']); ?></p>
        </div>

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
</div>

<script>
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