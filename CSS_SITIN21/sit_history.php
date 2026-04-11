<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login first";
    header("Location: login.php");
    exit();
}

$current_page = 'sit_history';
$user_id = $_SESSION['user_id'];

// Handle "Mark all as read" functionality
if (isset($_POST['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header("Location: sit_history.php");
    exit();
}

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback'])) {
    $sit_in_id = $_POST['sit_in_id'];
    $feedback_rating = $_POST['feedback_rating'];
    $feedback_message = $_POST['feedback_message'];
    
    // Check if feedback already exists
    $stmt = $pdo->prepare("SELECT id FROM feedback WHERE sit_in_id = ? AND user_id = ?");
    $stmt->execute([$sit_in_id, $user_id]);
    
    if ($stmt->rowCount() > 0) {
        $_SESSION['error'] = "Feedback already submitted for this sit-in session.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO feedback (user_id, sit_in_id, rating, message, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt->execute([$user_id, $sit_in_id, $feedback_rating, $feedback_message])) {
            $_SESSION['success'] = "Thank you for your feedback!";
        } else {
            $_SESSION['error'] = "Failed to submit feedback. Please try again.";
        }
    }
    
    header("Location: sit_history.php");
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

// Get user information
$stmt = $pdo->prepare("SELECT first_name, last_name, middle_name, id_number, profile_pic FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get actual history data from database
$stmt = $pdo->prepare("SELECT * FROM sit_in_history WHERE user_id = ? ORDER BY date DESC, time_in DESC");
$stmt->execute([$user_id]);
$history_data = $stmt->fetchAll();

// Function to format total duration from seconds
function formatTotalDuration($totalSeconds) {
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ' . ($hours == 1 ? 'hour' : 'hours');
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' ' . ($minutes == 1 ? 'min' : 'mins');
    }
    if ($seconds > 0 || empty($parts)) {
        $parts[] = $seconds . ' ' . ($seconds == 1 ? 'sec' : 'secs');
    }
    
    return implode(', ', $parts);
}

// Calculate statistics if there is history data
$total_seconds = 0;
$lab_counts = [];
$total_visits = count($history_data);

if (!empty($history_data)) {
    foreach ($history_data as $record) {
        // Calculate duration in seconds
        if ($record['time_in'] && $record['time_out']) {
            $time_in = strtotime($record['time_in']);
            $time_out = strtotime($record['time_out']);
            $duration_seconds = $time_out - $time_in;
            $total_seconds += $duration_seconds;
        }
        
        // Count lab usage
        $lab = $record['laboratory'];
        if (isset($lab_counts[$lab])) {
            $lab_counts[$lab]++;
        } else {
            $lab_counts[$lab] = 1;
        }
    }
    
    // Find most used lab
    $most_used_lab = array_search(max($lab_counts), $lab_counts);
} else {
    $most_used_lab = 'N/A';
    $total_seconds = 0;
}

// Format the total duration
$formatted_duration = formatTotalDuration($total_seconds);

// Check which sit-ins already have feedback
$feedback_exists = [];
if (!empty($history_data)) {
    $sit_in_ids = array_column($history_data, 'id');
    $placeholders = implode(',', array_fill(0, count($sit_in_ids), '?'));
    $stmt = $pdo->prepare("SELECT sit_in_id FROM feedback WHERE sit_in_id IN ($placeholders) AND user_id = ?");
    $stmt->execute(array_merge($sit_in_ids, [$user_id]));
    $feedback_exists = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sit-in History - CCS Sit-in Monitoring</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header a {
            color: #f39c12;
            text-decoration: none;
            font-size: 0.75rem;
        }

        .notification-header a:hover {
            text-decoration: underline;
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

        /* MAIN CONTAINER */
        .main-content {
            max-width: 1000px;
            margin: 150px auto 40px;
            padding: 0 20px;
        }

        /* CARD */
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            margin-bottom: 20px;
            border: 1px solid #e0e7ff;
        }

        .card h2 {
            margin-bottom: 20px;
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

        /* Table Controls */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .entries-per-page {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #666;
        }

        .entries-per-page select {
            padding: 8px 12px;
            border: 2px solid #e0e7ff;
            border-radius: 8px;
            font-size: 13px;
            background: white;
            font-family: 'Poppins', sans-serif;
        }

        .table-search {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-search label {
            font-size: 14px;
            color: #666;
        }

        .table-search input {
            padding: 8px 15px;
            border: 2px solid #e0e7ff;
            border-radius: 8px;
            font-size: 13px;
            width: 250px;
            font-family: 'Poppins', sans-serif;
        }

        .table-search input:focus {
            border-color: #3498db;
            outline: none;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .history-table th {
            background: #34495e;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 500;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .history-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e7ff;
            font-size: 0.8rem;
        }

        .history-table tr:hover {
            background: #f8faff;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            display: inline-block;
        }
        
        .status-badge.completed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.active {
            background: #fff3cd;
            color: #856404;
        }

        /* Action Buttons */
        .action-btn {
            padding: 6px 12px;
            font-size: 11px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .action-btn.view {
            background: #3498db;
            color: white;
        }

        .action-btn.view:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .action-btn.feedback {
            background: #f39c12;
            color: white;
        }
        
        .action-btn.feedback:hover {
            background: #e67e22;
            transform: translateY(-2px);
        }
        
        .action-btn.feedback-submitted {
            background: #27ae60;
            color: white;
            cursor: default;
        }
        
        .action-btn.feedback-submitted:hover {
            transform: none;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Table Footer */
        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e7ff;
            flex-wrap: wrap;
            gap: 15px;
        }

        .table-info {
            font-size: 0.85rem;
            color: #666;
        }

        .table-pagination {
            display: flex;
            gap: 5px;
        }

        .page-btn {
            padding: 6px 12px;
            font-size: 13px;
            border: 1px solid #e0e7ff;
            background: white;
            color: #666;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .page-btn:hover:not(:disabled) {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .page-btn.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(145deg, #3498db, #2980b9);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .summary-details h3 {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-details p {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            margin: 0;
        }

        /* Empty State */
        .empty-history {
            text-align: center;
            padding: 50px 20px;
            background: #f8f9fa;
            border-radius: 15px;
        }

        .empty-history i {
            font-size: 70px;
            color: #cbd5e0;
            margin-bottom: 15px;
        }

        .empty-history h3 {
            font-size: 22px;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .empty-history p {
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .empty-history .btn {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(145deg, #3498db, #2980b9);
            color: white;
            text-decoration: none;
            border-radius: 30px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.3s;
        }

        .empty-history .btn i {
            font-size: 12px;
            margin-right: 5px;
            color: white;
        }

        .empty-history .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(52,152,219,0.3);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
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
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 2px solid #f0f0f0;
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close {
            font-size: 28px;
            cursor: pointer;
            transition: 0.3s;
            color: white;
        }

        .close:hover {
            color: #f39c12;
        }

        .modal-body {
            padding: 20px;
        }

        .details-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e7ff;
        }

        .detail-item label {
            font-weight: 600;
            color: #2c3e50;
        }

        .detail-item span {
            color: #666;
        }

        /* Feedback Form */
        .rating-stars {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            justify-content: center;
        }
        
        .rating-stars i {
            font-size: 30px;
            cursor: pointer;
            color: #ddd;
            transition: all 0.2s;
        }
        
        .rating-stars i:hover,
        .rating-stars i.active {
            color: #f39c12;
        }
        
        .feedback-textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            resize: vertical;
            margin-bottom: 20px;
        }
        
        .feedback-textarea:focus {
            border-color: #3498db;
            outline: none;
        }
        
        .submit-feedback {
            background: linear-gradient(145deg, #27ae60, #219a52);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            width: 100%;
            transition: all 0.3s;
        }
        
        .submit-feedback:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
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

        /* Responsive */
        @media (max-width: 768px) {
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
            
            .main-content {
                margin-top: 180px;
                padding: 0 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card h2 {
                font-size: 1.3rem;
            }
            
            .table-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-search input {
                width: 100%;
            }
            
            .table-footer {
                flex-direction: column;
                text-align: center;
            }
            
            .table-pagination {
                justify-content: center;
            }
            
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                margin: 20% auto;
                width: 95%;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
            
            .action-buttons {
                flex-direction: column;
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
            <a href="sit_history.php" class="active">
                <i class="fas fa-history"></i> History
            </a>
            <a href="sit_reservation.php">
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

<!-- MAIN CONTENT -->
<main class="main-content">
    <div class="card">
        <h2><i class="fas fa-history"></i> Sit-in History</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($history_data)): ?>
            <div class="empty-history">
                <i class="fas fa-calendar-times"></i>
                <h3>No Sit-in History Yet</h3>
                <p>You haven't had any sit-in sessions yet.</p>
                <a href="sit_reservation.php" class="btn">
                    <i class="fas fa-calendar-plus"></i> Make a Reservation
                </a>
            </div>
        <?php else: ?>
            <!-- Table Controls -->
            <div class="table-controls">
                <div class="entries-per-page">
                    <label>Show</label>
                    <select id="entriesPerPage">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span>entries per page</span>
                </div>
                <div class="table-search">
                    <label>Search:</label>
                    <input type="text" id="searchInput" placeholder="Search records...">
                </div>
            </div>

            <!-- History Table -->
            <div class="table-responsive">
                <table class="history-table" id="historyTable">
                    <thead>
                        <tr>
                            <th>ID Number</th>
                            <th>Name</th>
                            <th>Sit Purpose</th>
                            <th>Laboratory</th>
                            <th>Login</th>
                            <th>Logout</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history_data as $index => $record): ?>
                            <?php
                            $has_feedback = in_array($record['id'], $feedback_exists);
                            $status_class = $record['status'] == 'Completed' ? 'completed' : 'active';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['id_number']); ?></td>
                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['middle_name'] . ' ' . $user['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['purpose']); ?></td>
                                <td>Lab <?php echo htmlspecialchars($record['laboratory']); ?></td>
                                <td><?php echo date('h:i A', strtotime($record['time_in'])); ?></td>
                                <td><?php echo $record['time_out'] ? date('h:i A', strtotime($record['time_out'])) : '--'; ?></td>
                                <td><?php echo date('M d, Y', strtotime($record['date'])); ?></td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($record['status']); ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="action-btn view" onclick="viewDetails(<?php echo $index; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($record['status'] == 'Completed'): ?>
                                            <?php if ($has_feedback): ?>
                                                <button class="action-btn feedback-submitted" disabled>
                                                    <i class="fas fa-check-circle"></i> Feedback Sent
                                                </button>
                                            <?php else: ?>
                                                <button class="action-btn feedback" onclick="openFeedbackModal(<?php echo $record['id']; ?>, <?php echo $index; ?>)">
                                                    <i class="fas fa-star"></i> Feedback
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                 </div>
                             </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer -->
            <div class="table-footer">
                <div class="table-info" id="tableInfo">
                    Showing 1 to <?php echo count($history_data); ?> of <?php echo count($history_data); ?> entries
                </div>
                <div class="table-pagination" id="pagination"></div>
            </div>

            <!-- Summary Cards with FORMATTED DURATION -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="summary-icon"><i class="fas fa-clock"></i></div>
                    <div class="summary-details">
                        <h3>TOTAL HOURS</h3>
                        <p><?php echo $formatted_duration; ?></p>
                    </div>
                </div>
                
                <div class="summary-card">
                    <div class="summary-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="summary-details">
                        <h3>TOTAL VISITS</h3>
                        <p><?php echo $total_visits; ?> session<?php echo $total_visits != 1 ? 's' : ''; ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-info-circle"></i> Sit-in Details</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<!-- Feedback Modal -->
<div id="feedbackModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-star"></i> Submit Feedback</h2>
            <span class="close" onclick="closeFeedbackModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <input type="hidden" name="sit_in_id" id="feedback_sit_in_id">
                <input type="hidden" name="submit_feedback" value="1">
                
                <div class="detail-item" style="margin-bottom: 15px;">
                    <label>Session Date:</label>
                    <span id="feedback_date"></span>
                </div>
                <div class="detail-item" style="margin-bottom: 15px;">
                    <label>Laboratory:</label>
                    <span id="feedback_lab"></span>
                </div>
                <div class="detail-item" style="margin-bottom: 15px;">
                    <label>Purpose:</label>
                    <span id="feedback_purpose"></span>
                </div>
                
                <label style="font-weight: 600; margin-bottom: 10px; display: block;">Rating:</label>
                <div class="rating-stars" id="ratingStars">
                    <i class="far fa-star" data-rating="1"></i>
                    <i class="far fa-star" data-rating="2"></i>
                    <i class="far fa-star" data-rating="3"></i>
                    <i class="far fa-star" data-rating="4"></i>
                    <i class="far fa-star" data-rating="5"></i>
                </div>
                <input type="hidden" name="feedback_rating" id="feedback_rating" required>
                
                <label style="font-weight: 600; margin-bottom: 10px; display: block;">Your Feedback:</label>
                <textarea name="feedback_message" id="feedback_message" class="feedback-textarea" rows="4" placeholder="Share your experience..."></textarea>
                
                <button type="submit" class="submit-feedback">
                    <i class="fas fa-paper-plane"></i> Submit Feedback
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    const historyData = <?php echo json_encode($history_data); ?>;
    const user = <?php echo json_encode($user); ?>;

    // Pagination and Search
    let currentPage = 1;
    let rowsPerPage = 10;
    let filteredData = [...historyData];

    function renderTable() {
        const tbody = document.querySelector('#historyTable tbody');
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        const pageData = filteredData.slice(start, end);
        
        tbody.innerHTML = '';
        
        if (pageData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align: center;">No records found</td></td>';
            document.getElementById('tableInfo').innerHTML = 'Showing 0 of 0 entries';
            return;
        }
        
        pageData.forEach((record, idx) => {
            const originalIndex = historyData.findIndex(r => r.id === record.id);
            const hasFeedback = <?php echo json_encode($feedback_exists); ?>;
            const hasFeedbackForRecord = hasFeedback.includes(record.id);
            
            const row = tbody.insertRow();
            row.innerHTML = `
                <td>${escapeHtml(user.id_number)}</td>
                <td>${escapeHtml(user.first_name + ' ' + (user.middle_name || '') + ' ' + user.last_name)}</td>
                <td>${escapeHtml(record.purpose)}</td>
                <td>Lab ${escapeHtml(record.laboratory)}</td>
                <td>${new Date('1970-01-01T' + record.time_in).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</td>
                <td>${record.time_out ? new Date('1970-01-01T' + record.time_out).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '--'}</td>
                <td>${new Date(record.date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</td>
                <td><span class="status-badge ${record.status === 'Completed' ? 'completed' : 'active'}">${escapeHtml(record.status)}</span></td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn view" onclick="viewDetails(${historyData.findIndex(r => r.id === record.id)})">
                            <i class="fas fa-eye"></i> View
                        </button>
                        ${record.status === 'Completed' ? 
                            (hasFeedbackForRecord ? 
                                '<button class="action-btn feedback-submitted" disabled><i class="fas fa-check-circle"></i> Feedback Sent</button>' :
                                `<button class="action-btn feedback" onclick="openFeedbackModal(${record.id}, ${historyData.findIndex(r => r.id === record.id)})">
                                    <i class="fas fa-star"></i> Feedback
                                </button>`
                            ) : ''
                        }
                    </div>
                 </td>
            `;
        });
        
        document.getElementById('tableInfo').innerHTML = `Showing ${start + 1} to ${Math.min(end, filteredData.length)} of ${filteredData.length} entries`;
        renderPagination();
    }

    function renderPagination() {
        const totalPages = Math.ceil(filteredData.length / rowsPerPage);
        const paginationDiv = document.getElementById('pagination');
        paginationDiv.innerHTML = '';
        
        const prevBtn = document.createElement('button');
        prevBtn.className = 'page-btn';
        prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Previous';
        prevBtn.disabled = currentPage === 1;
        prevBtn.onclick = () => { if (currentPage > 1) { currentPage--; renderTable(); } };
        paginationDiv.appendChild(prevBtn);
        
        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const pageBtn = document.createElement('button');
            pageBtn.className = 'page-btn' + (i === currentPage ? ' active' : '');
            pageBtn.textContent = i;
            pageBtn.onclick = () => { currentPage = i; renderTable(); };
            paginationDiv.appendChild(pageBtn);
        }
        
        const nextBtn = document.createElement('button');
        nextBtn.className = 'page-btn';
        nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
        nextBtn.disabled = currentPage === totalPages;
        nextBtn.onclick = () => { if (currentPage < totalPages) { currentPage++; renderTable(); } };
        paginationDiv.appendChild(nextBtn);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    // Search functionality
    document.getElementById('searchInput')?.addEventListener('keyup', function() {
        const searchValue = this.value.toLowerCase();
        filteredData = historyData.filter(record => {
            return record.purpose.toLowerCase().includes(searchValue) ||
                   record.laboratory.toString().includes(searchValue) ||
                   record.status.toLowerCase().includes(searchValue);
        });
        currentPage = 1;
        renderTable();
    });

    // Entries per page
    document.getElementById('entriesPerPage')?.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        renderTable();
    });

    function viewDetails(index) {
        const record = historyData[index];
        const modalBody = document.getElementById('modalBody');
        
        modalBody.innerHTML = `
            <div class="details-grid">
                <div class="detail-item"><label>ID Number:</label><span>${escapeHtml(user.id_number)}</span></div>
                <div class="detail-item"><label>Name:</label><span>${escapeHtml(user.first_name + ' ' + (user.middle_name || '') + ' ' + user.last_name)}</span></div>
                <div class="detail-item"><label>Purpose:</label><span>${escapeHtml(record.purpose)}</span></div>
                <div class="detail-item"><label>Laboratory:</label><span>Lab ${escapeHtml(record.laboratory)}</span></div>
                <div class="detail-item"><label>Login Time:</label><span>${new Date('1970-01-01T' + record.time_in).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span></div>
                <div class="detail-item"><label>Logout Time:</label><span>${record.time_out ? new Date('1970-01-01T' + record.time_out).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '--'}</span></div>
                <div class="detail-item"><label>Date:</label><span>${new Date(record.date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</span></div>
                <div class="detail-item"><label>Status:</label><span class="status-badge ${record.status === 'Completed' ? 'completed' : 'active'}">${escapeHtml(record.status)}</span></div>
            </div>
        `;
        
        document.getElementById('detailsModal').style.display = 'block';
    }

    function openFeedbackModal(sitInId, index) {
        const record = historyData[index];
        document.getElementById('feedback_sit_in_id').value = sitInId;
        document.getElementById('feedback_date').innerText = new Date(record.date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        document.getElementById('feedback_lab').innerText = 'Lab ' + record.laboratory;
        document.getElementById('feedback_purpose').innerText = record.purpose;
        document.getElementById('feedback_rating').value = '';
        document.getElementById('feedback_message').value = '';
        
        // Reset stars
        document.querySelectorAll('.rating-stars i').forEach(star => {
            star.className = 'far fa-star';
        });
        
        document.getElementById('feedbackModal').style.display = 'block';
    }

    // Rating stars functionality
    document.querySelectorAll('.rating-stars i').forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.getAttribute('data-rating');
            document.getElementById('feedback_rating').value = rating;
            
            document.querySelectorAll('.rating-stars i').forEach(s => {
                const starRating = parseInt(s.getAttribute('data-rating'));
                if (starRating <= rating) {
                    s.className = 'fas fa-star';
                } else {
                    s.className = 'far fa-star';
                }
            });
        });
    });

    function closeModal() {
        document.getElementById('detailsModal').style.display = 'none';
    }

    function closeFeedbackModal() {
        document.getElementById('feedbackModal').style.display = 'none';
    }

    window.onclick = function(event) {
        const modal = document.getElementById('detailsModal');
        const feedbackModal = document.getElementById('feedbackModal');
        if (event.target == modal) modal.style.display = 'none';
        if (event.target == feedbackModal) feedbackModal.style.display = 'none';
    }

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

    // Initialize table
    if (historyData.length > 0) {
        filteredData = [...historyData];
        renderTable();
    }
</script>

</body>
</html>