<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get unread notifications count

// Support feedback table schema variations across local DB copies
$feedback_columns = $pdo->query("SHOW COLUMNS FROM feedback")->fetchAll(PDO::FETCH_COLUMN);
$feedback_has_sit_in_id = in_array('sit_in_id', $feedback_columns, true);
$feedback_text_column = in_array('message', $feedback_columns, true)
    ? 'message'
    : (in_array('comment', $feedback_columns, true) ? 'comment' : null);

// Ensure community feed table exists so feedback can be mirrored there
$pdo->exec("CREATE TABLE IF NOT EXISTS community_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
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

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get user's completed sit-ins that don't have feedback yet
if ($feedback_has_sit_in_id) {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END as has_feedback
        FROM sit_in_history s 
        LEFT JOIN feedback f ON s.id = f.sit_in_id AND f.user_id = ?
        WHERE s.user_id = ? AND s.status = 'Completed'
        ORDER BY s.date DESC, s.time_in DESC
    ");
    $stmt->execute([$user_id, $user_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, 0 as has_feedback
        FROM sit_in_history s
        WHERE s.user_id = ? AND s.status = 'Completed'
        ORDER BY s.date DESC, s.time_in DESC
    ");
    $stmt->execute([$user_id]);
}
$completed_sitins = $stmt->fetchAll();

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback'])) {
    $sit_in_id = (int)$_POST['sit_in_id'];
    $rating = (int)$_POST['rating'];
    $message_text = trim($_POST['message']);
    
    $stmt = $pdo->prepare("SELECT id, purpose, laboratory, date FROM sit_in_history WHERE id = ? AND user_id = ? AND status = 'Completed'");
    $stmt->execute([$sit_in_id, $user_id]);
    $sit_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sit_details) {
        $error = "Invalid sit-in record selected.";
    } elseif ($feedback_text_column === null) {
        $error = "Feedback schema is missing the message/comment column.";
    } elseif ($rating < 1 || $rating > 5) {
        $error = "Please select a rating (1-5 stars)";
    } elseif (empty($message_text)) {
        $error = "Please enter your feedback message";
    } else {
        try {
            $result = false;
            if ($feedback_has_sit_in_id) {
                $stmt = $pdo->prepare("SELECT id FROM feedback WHERE sit_in_id = ? AND user_id = ?");
                $stmt->execute([$sit_in_id, $user_id]);

                if ($stmt->rowCount() > 0) {
                    $error = "You have already submitted feedback for this sit-in session.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO feedback (user_id, sit_in_id, rating, {$feedback_text_column}, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $result = $stmt->execute([$user_id, $sit_in_id, $rating, $message_text]);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO feedback (user_id, rating, {$feedback_text_column}, created_at) VALUES (?, ?, ?, NOW())");
                $result = $stmt->execute([$user_id, $rating, $message_text]);
            }

            if (!$error && $result) {
                $community_content = "Feedback for Lab " . $sit_details['laboratory']
                    . " (" . $sit_details['purpose'] . ") on " . date('M d, Y', strtotime($sit_details['date']))
                    . " | Rating: " . $rating . "/5\n" . $message_text;
                $stmt = $pdo->prepare("INSERT INTO community_posts (user_id, content) VALUES (?, ?)");
                $stmt->execute([$user_id, $community_content]);

                $message = "Thank you for your feedback!";
                header("Location: student_feedback.php?success=1");
                exit();
            } elseif (!$error) {
                $error = "Failed to submit feedback. Please try again.";
            }
        } catch(PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get user's feedback with sit-in details
if ($feedback_text_column === null) {
    $user_feedback = [];
} elseif ($feedback_has_sit_in_id) {
    $stmt = $pdo->prepare("
        SELECT f.*, f.{$feedback_text_column} AS feedback_text, s.purpose, s.laboratory, s.date, s.time_in, s.time_out
        FROM feedback f
        JOIN sit_in_history s ON f.sit_in_id = s.id
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $user_feedback = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT f.*, f.{$feedback_text_column} AS feedback_text, NULL AS purpose, NULL AS laboratory, NULL AS date, NULL AS time_in, NULL AS time_out
        FROM feedback f
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $user_feedback = $stmt->fetchAll();
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Thank you for your feedback!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback - CCS Sit-in Monitoring</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html { font-size: 13px; zoom: 1; }
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
        .nav-container { max-width: 1400px; margin: 0 auto; padding: 0.8rem 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
        .logo-container { display: flex; align-items: center; gap: 15px; }
        .nav-avatar { width: 45px; height: 45px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px rgba(255,255,255,0.3), 0 0 0 5px #f39c12, 0 0 15px rgba(243,156,18,0.5); transition: all 0.3s ease; }
        .nav-avatar:hover { transform: scale(1.05); box-shadow: 0 0 0 2px rgba(255,255,255,0.4), 0 0 0 8px #f39c12, 0 0 25px rgba(243,156,18,0.7); }
        .nav-avatar img { width: 32px; height: 32px; object-fit: contain; }
        .logo-text { color: white; font-weight: 600; line-height: 1.2; }
        .logo-text strong { font-size: 1.1rem; display: block; }
        .logo-text small { font-size: 0.7rem; font-weight: 400; opacity: 0.8; }
        .nav-links { display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; }
        .nav-links a { text-decoration: none; color: white; font-weight: 500; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; padding: 0.5rem 0.8rem; border-radius: 8px; }
        .nav-links a:hover { color: #f39c12; background: rgba(255,255,255,0.1); }
        .nav-links a.active { color: #f39c12; background: rgba(255,255,255,0.1); }
        .notification-icon { position: relative; cursor: pointer; margin-left: 0.5rem; display: flex; align-items: center; }
        .notification-icon i { font-size: 1.2rem; color: white; }
        .notification-badge { position: absolute; top: -8px; right: -8px; background: #e74c3c; color: white; font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 50px; min-width: 18px; text-align: center; }
        .notification-dropdown { position: absolute; top: 130%; right: 0; width: 350px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); z-index: 1100; display: none; overflow: hidden; max-height: 500px; overflow-y: auto; }
        .notification-dropdown.show { display: block; }
        .notification-header { padding: 12px 15px; background: linear-gradient(145deg, #2c3e50, #1a2634); color: white; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .notification-item { padding: 12px 15px; border-bottom: 1px solid #e0e7ff; cursor: pointer; transition: 0.2s; text-decoration: none; display: block; color: #2c3e50; }
        .notification-item:hover { background: #f8faff; }
        .notification-item.unread { background: #fff8e7; }
        .notification-title { font-weight: 600; font-size: 0.85rem; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .notification-message { font-size: 0.75rem; color: #666; margin-bottom: 4px; }
        .notification-time { font-size: 0.65rem; color: #999; }
        .notification-empty { text-align: center; padding: 30px; color: #999; }
        .notification-footer { padding: 10px; text-align: center; background: #f8f9fa; border-top: 1px solid #e0e7ff; }
        .notification-footer a { color: #3498db; text-decoration: none; font-size: 0.75rem; }
        
        .main-content {
            margin-top: 120px;
            padding: 2rem;
        }
        
        .feedback-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        /* User Profile Card */
        .user-profile-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .user-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(145deg, #3498db, #2980b9);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 5px 15px rgba(52,152,219,0.3);
        }
        
        .user-avatar-large img {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
        }
        
        .user-details h2 {
            color: #2c3e50;
            font-size: 1.5rem;
            margin-bottom: 0.3rem;
        }
        
        .user-details p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .user-details i {
            color: #3498db;
            width: 20px;
        }
        
        .feedback-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .feedback-header {
            background: linear-gradient(145deg, #3498db, #2980b9);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .feedback-header i { font-size: 3rem; margin-bottom: 1rem; }
        .feedback-header h1 { font-size: 1.8rem; margin-bottom: 0.5rem; font-weight: 600; }
        .feedback-body { padding: 2rem; }
        
        .sit-in-selection {
            margin-bottom: 1.5rem;
        }
        
        .sit-in-selection label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .sit-in-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e7ff;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            background: white;
        }
        
        .sit-in-select:focus {
            border-color: #3498db;
            outline: none;
        }
        
        .selected-sit-info {
            background: #e8f4fd;
            padding: 12px;
            border-radius: 10px;
            margin-top: 10px;
            font-size: 0.85rem;
            display: none;
        }
        
        .selected-sit-info.show {
            display: block;
        }
        
        .rating-section { text-align: center; margin-bottom: 2rem; }
        .stars {
            display: flex;
            justify-content: center;
            gap: 0.8rem;
            font-size: 2.2rem;
            cursor: pointer;
        }
        .stars i { color: #ddd; transition: all 0.2s; cursor: pointer; }
        .stars i:hover, .stars i.active { color: #f39c12; }
        
        .comment-section textarea {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e0e7ff;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            resize: vertical;
            min-height: 120px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(145deg, #27ae60, #219a52);
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1rem;
        }
        
        .alert { padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.8rem; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .previous-feedback {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .previous-header {
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            padding: 1rem 1.5rem;
        }
        
        .feedback-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0e7ff;
        }
        
        .feedback-session {
            font-size: 0.75rem;
            color: #3498db;
            margin-bottom: 0.5rem;
        }
        
        .feedback-rating { color: #f39c12; margin-bottom: 0.3rem; }
        .feedback-message { color: #2c3e50; font-size: 0.85rem; line-height: 1.5; }
        .feedback-date { font-size: 0.7rem; color: #999; margin-top: 0.3rem; }
        .empty-state { text-align: center; padding: 2rem; color: #999; }
        
        @media (max-width: 850px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            .logo-container { justify-content: center; }
            .nav-links { justify-content: center; gap: 0.8rem; }
            .nav-links a { padding: 0.4rem 0.6rem; font-size: 0.85rem; }
            .main-content { margin-top: 120px; padding: 1rem; }
            .stars { font-size: 1.8rem; gap: 0.5rem; }
            .notification-dropdown { width: 300px; right: -50px; }
            .user-profile-card { flex-direction: column; text-align: center; }
            .user-details { text-align: center; }
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
                <a href="student_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="profile_edit.php"><i class="fas fa-user-edit"></i> Edit Profile</a>
                <div class="nav-dropdown">
                    <a href="#"><i class="fas fa-clock"></i> Sit-in <i class="fas fa-caret-down"></i></a>
                    <div class="nav-dropdown-content">
                        <a href="sit_reservation.php"><i class="fas fa-desktop"></i> Current Sit-in</a>
                        <a href="sit_history.php"><i class="fas fa-file-alt"></i> Sit-in Records</a>
                    </div>
                </div>
                <a href="student_feedback.php" class="active"><i class="fas fa-star"></i> Feedback</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
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

    <main class="main-content">
        <div class="feedback-container">
            <!-- User Profile Card -->
            <div class="user-profile-card">
                <div class="user-avatar-large">
                    <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Profile" onerror="this.src='default-avatar.png'">
                </div>
                <div class="user-details">
                    <h2><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                    <p><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($user['id_number']); ?></p>
                    <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($user['course']); ?> - Year <?php echo $user['year_level']; ?></p>
                </div>
            </div>
            
            <div class="feedback-card">
                <div class="feedback-header">
                    <i class="fas fa-star"></i>
                    <h1>Share Your Experience</h1>
                    <p>Your feedback helps us improve our laboratory services</p>
                </div>
                
                <div class="feedback-body">
                    <?php if ($message): ?>
                        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" id="feedbackForm">
                        <div class="sit-in-selection">
                            <label><i class="fas fa-clock"></i> Select Sit-in Session to Review</label>
                            <select name="sit_in_id" id="sitInSelect" class="sit-in-select" required>
                                <option value="">-- Select a completed sit-in session --</option>
                                <?php foreach ($completed_sitins as $sit): ?>
                                    <?php if ($sit['has_feedback'] == 0): ?>
                                        <option value="<?php echo $sit['id']; ?>" 
                                                data-purpose="<?php echo htmlspecialchars($sit['purpose']); ?>"
                                                data-lab="Lab <?php echo $sit['laboratory']; ?>"
                                                data-date="<?php echo date('M d, Y', strtotime($sit['date'])); ?>">
                                            <?php echo date('M d, Y', strtotime($sit['date'])); ?> - Lab <?php echo $sit['laboratory']; ?> - <?php echo htmlspecialchars($sit['purpose']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            
                            <div id="selectedSitInfo" class="selected-sit-info">
                                <i class="fas fa-info-circle"></i> 
                                <span id="selectedPurpose"></span> | <span id="selectedLab"></span> | <span id="selectedDate"></span>
                            </div>
                        </div>
                        
                        <div class="rating-section">
                            <label><i class="fas fa-star"></i> Rate your experience (1-5 stars)</label>
                            <div class="stars" id="starRating">
                                <i class="far fa-star" data-value="1"></i>
                                <i class="far fa-star" data-value="2"></i>
                                <i class="far fa-star" data-value="3"></i>
                                <i class="far fa-star" data-value="4"></i>
                                <i class="far fa-star" data-value="5"></i>
                            </div>
                            <input type="hidden" name="rating" id="ratingValue" required>
                        </div>
                        
                        <div class="comment-section">
                            <textarea name="message" id="message" placeholder="Tell us about your experience... What did you like? What can we improve?" required></textarea>
                        </div>
                        
                        <button type="submit" name="submit_feedback" class="btn-submit">
                            <i class="fas fa-paper-plane"></i> Submit Feedback
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="previous-feedback">
                <div class="previous-header">
                    <h2><i class="fas fa-history"></i> Your Feedback Records</h2>
                </div>
                <div class="feedback-list">
                    <?php if (empty($user_feedback)): ?>
                        <div class="empty-state">
                            <i class="fas fa-comment-slash"></i>
                            <p>You haven't submitted any feedback yet.</p>
                            <p style="font-size: 0.8rem; margin-top: 0.5rem;">Complete a sit-in session and leave feedback!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_feedback as $fb): ?>
                            <div class="feedback-item">
                                <div class="feedback-session">
                                    <i class="fas fa-flask"></i>
                                    <?php if (!empty($fb['purpose']) && !empty($fb['laboratory']) && !empty($fb['date'])): ?>
                                        <?php echo htmlspecialchars($fb['purpose']); ?> | Lab <?php echo htmlspecialchars($fb['laboratory']); ?> | <?php echo date('M d, Y', strtotime($fb['date'])); ?>
                                    <?php else: ?>
                                        General Feedback Record
                                    <?php endif; ?>
                                </div>
                                <div class="feedback-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= $fb['rating']): ?>
                                            <i class="fas fa-star"></i>
                                        <?php else: ?>
                                            <i class="far fa-star"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                <div class="feedback-message"><?php echo nl2br(htmlspecialchars($fb['feedback_text'] ?? '')); ?></div>
                                <div class="feedback-date"><i class="fas fa-calendar-alt"></i> Submitted on <?php echo date('M d, Y g:i A', strtotime($fb['created_at'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        const sitInSelect = document.getElementById('sitInSelect');
        const selectedSitInfo = document.getElementById('selectedSitInfo');
        const selectedPurpose = document.getElementById('selectedPurpose');
        const selectedLab = document.getElementById('selectedLab');
        const selectedDate = document.getElementById('selectedDate');
        
        if (sitInSelect) {
            sitInSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (this.value) {
                    selectedPurpose.textContent = selectedOption.dataset.purpose;
                    selectedLab.textContent = selectedOption.dataset.lab;
                    selectedDate.textContent = selectedOption.dataset.date;
                    selectedSitInfo.classList.add('show');
                } else {
                    selectedSitInfo.classList.remove('show');
                }
            });
        }
        
        const stars = document.querySelectorAll('#starRating i');
        const ratingInput = document.getElementById('ratingValue');
        
        stars.forEach(star => {
            star.addEventListener('click', function() {
                const value = parseInt(this.dataset.value);
                ratingInput.value = value;
                stars.forEach((s, index) => {
                    if (index < value) {
                        s.classList.remove('far');
                        s.classList.add('fas');
                        s.classList.add('active');
                    } else {
                        s.classList.remove('fas');
                        s.classList.add('far');
                        s.classList.remove('active');
                    }
                });
            });
        });
        
        document.getElementById('feedbackForm').addEventListener('submit', function(e) {
            const sitInSelect = document.getElementById('sitInSelect');
            if (!sitInSelect.value) {
                e.preventDefault();
                alert('Please select a sit-in session to review');
                return false;
            }
            if (!ratingInput.value) {
                e.preventDefault();
                alert('Please select a rating (1-5 stars)');
                return false;
            }
            if (!document.getElementById('message').value.trim()) {
                e.preventDefault();
                alert('Please enter your feedback message');
                return false;
            }
        });
        
        const notificationIcon = document.getElementById('notificationIcon');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
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