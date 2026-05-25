<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login first";
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Create community tables if they don't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS community_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS post_reactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    type ENUM('like','heart') NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reaction (post_id, user_id, type),
    FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS post_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE
)");

// Backfill legacy feedback into the community feed if there are no posts yet
$community_count = (int)$pdo->query("SELECT COUNT(*) FROM community_posts")->fetchColumn();
if ($community_count === 0) {
    $feedback_columns = $pdo->query("SHOW COLUMNS FROM feedback")->fetchAll(PDO::FETCH_COLUMN);
    $feedback_text_column = in_array('message', $feedback_columns, true)
        ? 'message'
        : (in_array('comment', $feedback_columns, true) ? 'comment' : null);

    if ($feedback_text_column !== null) {
        $has_sit_in_id = in_array('sit_in_id', $feedback_columns, true);
        if ($has_sit_in_id) {
            $stmt = $pdo->prepare("\n                SELECT f.user_id, f.rating, f.created_at, f.{$feedback_text_column} AS feedback_text,\n                       s.purpose, s.laboratory, s.date\n                FROM feedback f\n                LEFT JOIN sit_in_history s ON s.id = f.sit_in_id\n                ORDER BY f.created_at ASC\n            ");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("\n                SELECT f.user_id, f.rating, f.created_at, f.{$feedback_text_column} AS feedback_text,\n                       NULL AS purpose, NULL AS laboratory, NULL AS date\n                FROM feedback f\n                ORDER BY f.created_at ASC\n            ");
            $stmt->execute();
        }

        $legacy_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($legacy_feedback)) {
            $insert_post = $pdo->prepare("INSERT INTO community_posts (user_id, content, created_at) VALUES (?, ?, ?)");
            foreach ($legacy_feedback as $row) {
                $prefix = "Feedback";
                if (!empty($row['laboratory']) && !empty($row['purpose']) && !empty($row['date'])) {
                    $prefix = "Feedback for Lab " . $row['laboratory']
                        . " (" . $row['purpose'] . ") on " . date('M d, Y', strtotime($row['date']));
                }
                $content = $prefix . " | Rating: " . ((int)$row['rating']) . "/5\n" . (string)$row['feedback_text'];
                $insert_post->execute([(int)$row['user_id'], $content, $row['created_at']]);
            }
        }
    }
}

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return $diff . ' seconds ago';
    if ($diff < 3600)   return floor($diff/60)   . ' minutes ago';
    if ($diff < 86400)  return floor($diff/3600)  . ' hours ago';
    if ($diff < 604800) return floor($diff/86400) . ' days ago';
    return date('M d, Y', strtotime($datetime));
}

// ---- AJAX endpoints ----
if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'];
    header('Content-Type: application/json');

    if ($action === 'get_posts') {
        $offset = intval($_GET['offset'] ?? 0);
        $limit  = 10;
        $limit_sql = (int)$limit;
        $offset_sql = (int)$offset;
        $stmt = $pdo->prepare("
            SELECT cp.id, cp.content, cp.created_at, cp.user_id,
                   u.first_name, u.last_name, u.profile_pic,
                   (SELECT COUNT(*) FROM post_reactions WHERE post_id=cp.id AND type='like')  AS like_count,
                   (SELECT COUNT(*) FROM post_reactions WHERE post_id=cp.id AND type='heart') AS heart_count,
                   (SELECT COUNT(*) FROM post_comments  WHERE post_id=cp.id)                  AS comment_count,
                   (SELECT COUNT(*) FROM post_reactions WHERE post_id=cp.id AND user_id=? AND type='like')  AS user_liked,
                   (SELECT COUNT(*) FROM post_reactions WHERE post_id=cp.id AND user_id=? AND type='heart') AS user_hearted,
                   CASE WHEN cp.user_id = ? THEN 1 ELSE 0 END AS is_owner
            FROM community_posts cp
            JOIN users u ON u.id = cp.user_id
            ORDER BY cp.created_at DESC
            LIMIT {$limit_sql} OFFSET {$offset_sql}
        ");
        $stmt->execute([$user_id, $user_id, $user_id]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($posts as &$p) {
            $p['time_ago']     = timeAgo($p['created_at']);
            $p['user_liked']   = (bool)$p['user_liked'];
            $p['user_hearted'] = (bool)$p['user_hearted'];
            $p['is_owner']     = (bool)$p['is_owner'];
        }
        echo json_encode(['posts' => $posts]);
        exit();
    }

    if ($action === 'add_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $content = trim($_POST['content'] ?? '');
        if ($content === '') { echo json_encode(['error' => 'Empty post']); exit(); }
        $stmt = $pdo->prepare("INSERT INTO community_posts (user_id, content) VALUES (?,?)");
        $stmt->execute([$user_id, $content]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit();
    }

    if ($action === 'edit_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_id = intval($_POST['post_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if ($post_id <= 0 || $content === '') {
            echo json_encode(['error' => 'Invalid input']);
            exit();
        }

        // Allow editing only on the student's own post
        $stmt = $pdo->prepare("SELECT id FROM community_posts WHERE id = ? AND user_id = ?");
        $stmt->execute([$post_id, $user_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'Not allowed']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE community_posts SET content = ? WHERE id = ?");
        $stmt->execute([$content, $post_id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($action === 'react' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_id = intval($_POST['post_id'] ?? 0);
        $type    = ($_POST['type'] ?? 'like') === 'heart' ? 'heart' : 'like';
        $chk = $pdo->prepare("SELECT id FROM post_reactions WHERE post_id=? AND user_id=? AND type=?");
        $chk->execute([$post_id, $user_id, $type]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM post_reactions WHERE post_id=? AND user_id=? AND type=?")->execute([$post_id, $user_id, $type]);
            $reacted = false;
        } else {
            $pdo->prepare("INSERT INTO post_reactions (post_id, user_id, type) VALUES (?,?,?)")->execute([$post_id, $user_id, $type]);
            $reacted = true;
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM post_reactions WHERE post_id=? AND type=?");
        $cnt->execute([$post_id, $type]);
        echo json_encode(['success' => true, 'reacted' => $reacted, 'count' => (int)$cnt->fetchColumn()]);
        exit();
    }

    if ($action === 'add_comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_id = intval($_POST['post_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (!$content) { echo json_encode(['error' => 'Empty']); exit(); }
        $pdo->prepare("INSERT INTO post_comments (post_id, user_id, content) VALUES (?,?,?)")->execute([$post_id, $user_id, $content]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($action === 'get_comments') {
        $post_id = intval($_GET['post_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT pc.content, pc.created_at, u.first_name, u.last_name, u.profile_pic
            FROM post_comments pc
            JOIN users u ON u.id = pc.user_id
            WHERE pc.post_id = ? ORDER BY pc.created_at ASC
        ");
        $stmt->execute([$post_id]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($comments as &$c) { $c['time_ago'] = timeAgo($c['created_at']); }
        echo json_encode(['comments' => $comments]);
        exit();
    }

    echo json_encode(['error' => 'Unknown action']);
    exit();
}

// Handle "Mark all as read"
if (isset($_POST['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user_id]);
    header("Location: student_dashboard.php");
    exit();
}

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    $target_dir = "uploads/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
    $ext = strtolower(pathinfo($_FILES["profile_photo"]["name"], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif'])) {
        $new_file = $target_dir . "profile_{$user_id}_" . time() . ".$ext";
        if (move_uploaded_file($_FILES["profile_photo"]["tmp_name"], $new_file)) {
            $pdo->prepare("UPDATE users SET profile_pic=? WHERE id=?")->execute([$new_file, $user_id]);
            $_SESSION['profile_pic'] = $new_file;
            $upload_success = "Profile photo updated!";
        }
    }
}

// Notifications
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

if (isset($_GET['read_notification'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$_GET['read_notification'], $user_id]);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

// Student data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$user_id]);
$student = $stmt->fetch();
if (!$student) { session_destroy(); header("Location: login.php"); exit(); }

$_SESSION['id_number']   = $student['id_number'];
$_SESSION['first_name']  = $student['first_name'];
$_SESSION['last_name']   = $student['last_name'];
$_SESSION['profile_pic'] = $student['profile_pic'];

// Announcements
$announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll();
$current_page  = 'student_dashboard';
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
        html { font-size: 13px; zoom: 1; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(2deg, rgba(203,164,237,1) 0%, rgba(177,204,224,1) 56%, rgba(4,4,59,1) 100%);
            min-height: 100vh;
        }
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
        .notif-badge { position: absolute; top: -8px; right: -12px; background: #e74c3c; color: white; font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 50px; min-width: 18px; text-align: center; }
        .notification-dropdown { position: absolute; top: 130%; right: 0; width: 350px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); z-index: 1100; display: none; overflow: hidden; max-height: 500px; overflow-y: auto; }
        .notification-dropdown.show { display: block; }
        .notification-header { padding: 12px 15px; background: linear-gradient(145deg, #2c3e50, #1a2634); color: white; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .notification-header button { background: none; border: none; color: #f39c12; cursor: pointer; font-size: 0.75rem; font-family: 'Poppins', sans-serif; }
        .notification-item { padding: 12px 15px; border-bottom: 1px solid #e0e7ff; cursor: pointer; transition: 0.2s; text-decoration: none; display: block; color: #2c3e50; }
        .notification-item:hover { background: #f8faff; }
        .notification-item.unread { background: #fff8e7; }
        .notification-title { font-weight: 600; font-size: 0.85rem; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .notification-message { font-size: 0.75rem; color: #666; margin-bottom: 4px; }
        .notification-time { font-size: 0.65rem; color: #999; }
        .notification-empty { text-align: center; padding: 30px; color: #999; }
        .main-content { margin-top: 90px; padding: 1.5rem 2rem; }
        .dashboard-grid {
            max-width: 1300px; margin: 0 auto;
            display: grid; grid-template-columns: 230px 1fr 290px;
            gap: 1.2rem; align-items: start;
        }
        .card { background: white; border-radius: 18px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border: 1px solid #e8eaf6; overflow: hidden; }
        .card-title { font-size: 0.95rem; font-weight: 700; color: #2c3e50; display: flex; align-items: center; gap: 8px; padding: 14px 18px; border-bottom: 1px solid #f0f0f0; }
        .card-title i { color: #3498db; }
        .profile-card { text-align: center; }
        .card-body { padding: 22px 16px; }
        .photo-wrap { position: relative; width: 110px; height: 110px; margin: 0 auto 12px; }
        .profile-photo {
            width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 3px solid white;
            box-shadow: 0 0 0 4px rgba(52,152,219,0.25), 0 0 0 7px #f39c12, 0 0 20px rgba(243,156,18,0.4);
        }
        .change-photo-btn {
            position: absolute; bottom: 4px; right: 4px; width: 30px; height: 30px; border-radius: 50%;
            background: #f39c12; color: white; border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: 12px;
        }
        .student-name { font-size: 1rem; font-weight: 700; color: #2c3e50; margin-bottom: 4px; }
        .student-id-badge { display: inline-block; background: #f0f7ff; padding: 4px 14px; border-radius: 50px; font-size: 0.75rem; color: #3498db; border: 1px solid #c7e0ff; margin-bottom: 16px; }
        .profile-stats { display: flex; flex-direction: column; gap: 8px; text-align: left; }
        .profile-stat-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 10px; background: #f8f9fa; border-radius: 8px; font-size: 0.8rem; }
        .profile-stat-row .lbl { color: #666; display: flex; align-items: center; gap: 6px; }
        .profile-stat-row .val { font-weight: 600; color: #2c3e50; }
        .sessions-box { background: linear-gradient(135deg, #3498db, #2980b9); color: white; border-radius: 10px; padding: 12px; text-align: center; margin-top: 10px; }
        .sessions-box .num { font-size: 2rem; font-weight: 700; display: block; line-height: 1; }
        .sessions-box .lbl-s { font-size: 0.7rem; opacity: 0.9; margin-top: 3px; }
        .quick-links { margin-top: 16px; display: flex; flex-direction: column; gap: 7px; }
        .quick-link { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 10px; background: #f8f9fa; color: #2c3e50; text-decoration: none; font-size: 0.8rem; font-weight: 500; border: 1px solid #eee; transition: all 0.22s; }
        .quick-link i { width: 16px; color: #3498db; text-align: center; }
        .quick-link:hover { background: #3498db; color: white; border-color: #3498db; }
        .quick-link:hover i { color: white; }
        .community-card {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 110px);
        }
        .feed-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #f0f0f0; }
        .feed-header h2 { font-size: 0.92rem; font-weight: 700; color: #2c3e50; display: flex; align-items: center; gap: 8px; }
        .feed-header h2 i { color: #9b59b6; }
        .btn-new-post { background: linear-gradient(135deg, #3498db, #2980b9); color: white; border: none; border-radius: 20px; padding: 7px 15px; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; font-family: 'Poppins', sans-serif; transition: 0.22s; }
        .btn-new-post:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(52,152,219,0.4); }
        .compose-box { padding: 14px 18px; border-bottom: 1px solid #f5f5f5; display: none; }
        .compose-box.open { display: block; }
        .compose-inner { display: flex; gap: 10px; align-items: flex-start; }
        .compose-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid #f39c12; }
        .compose-right { flex: 1; }
        .compose-textarea { width: 100%; border: 1px solid #e0e7ff; border-radius: 12px; padding: 10px 14px; font-family: 'Poppins', sans-serif; font-size: 0.85rem; color: #2c3e50; resize: none; min-height: 80px; }
        .compose-textarea:focus { outline: none; border-color: #9b59b6; }
        .compose-actions { display: flex; justify-content: flex-end; margin-top: 8px; gap: 8px; }
        .btn-cancel { background: #f0f0f0; color: #666; border: none; border-radius: 20px; padding: 7px 16px; font-size: 0.78rem; cursor: pointer; font-family: 'Poppins', sans-serif; }
        .btn-post { background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; border: none; border-radius: 20px; padding: 7px 18px; font-size: 0.78rem; font-weight: 600; cursor: pointer; font-family: 'Poppins', sans-serif; }
        .posts-list {
            min-height: 120px;
            flex: 1;
            overflow-y: auto;
            overscroll-behavior: contain;
        }
        .post-card { padding: 16px 18px; border-bottom: 1px solid #f5f5f5; }
        .post-card:last-child { border-bottom: none; }
        .post-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .post-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid #f39c12; flex-shrink: 0; }
        .post-author { font-weight: 600; font-size: 0.85rem; color: #2c3e50; }
        .post-time { font-size: 0.72rem; color: #999; margin-top: 1px; }
        .post-content { font-size: 0.88rem; color: #333; line-height: 1.6; margin-bottom: 12px; word-break: break-word; }
        .post-actions { display: flex; gap: 7px; flex-wrap: wrap; margin-bottom: 8px; }
        .reaction-btn { display: flex; align-items: center; gap: 5px; padding: 5px 13px; border-radius: 20px; border: 1px solid #e0e7ff; background: #f8f9fa; color: #555; font-size: 0.78rem; font-weight: 500; cursor: pointer; font-family: 'Poppins', sans-serif; transition: all 0.18s; }
        .reaction-btn:hover { border-color: #3498db; color: #3498db; background: #f0f7ff; }
        .reaction-btn.liked { background: #ebf5fb; border-color: #3498db; color: #2980b9; }
        .reaction-btn.hearted { background: #fdeaea; border-color: #e74c3c; color: #c0392b; }
        .reaction-btn.comment-btn:hover { border-color: #27ae60; color: #27ae60; background: #eafaf1; }
        .show-comments-toggle { font-size: 0.78rem; color: #3498db; cursor: pointer; margin-bottom: 8px; display: inline-flex; align-items: center; gap: 5px; font-weight: 500; }
        .show-comments-toggle:hover { text-decoration: underline; }
        .comments-section { display: none; }
        .comments-section.open { display: block; }
        .comment-item { display: flex; gap: 8px; margin-bottom: 8px; }
        .comment-avatar { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 1px solid #f39c12; }
        .comment-bubble { background: #f0f4ff; border-radius: 12px; padding: 8px 12px; flex: 1; font-size: 0.8rem; }
        .comment-author { font-weight: 600; color: #2c3e50; margin-bottom: 2px; font-size: 0.78rem; }
        .comment-text { color: #444; line-height: 1.5; }
        .comment-time { font-size: 0.68rem; color: #aaa; margin-top: 2px; }
        .comment-input-row { display: flex; gap: 8px; margin-top: 10px; align-items: center; }
        .comment-input-row img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid #f39c12; }
        .comment-input { flex: 1; border: 1px solid #e0e7ff; border-radius: 20px; padding: 7px 14px; font-size: 0.8rem; font-family: 'Poppins', sans-serif; color: #2c3e50; outline: none; }
        .comment-input:focus { border-color: #3498db; }
        .comment-send-btn { background: #3498db; color: white; border: none; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; }
        .comment-send-btn:hover { background: #2980b9; }
        .feed-empty { text-align: center; padding: 40px 20px; color: #aaa; }
        .feed-empty i { font-size: 2.5rem; margin-bottom: 10px; display: block; }
        .load-more-btn { display: block; width: calc(100% - 36px); margin: 12px 18px; padding: 10px; background: #f8f9fa; border: 1px solid #e0e7ff; border-radius: 10px; font-size: 0.82rem; color: #3498db; font-weight: 600; cursor: pointer; font-family: 'Poppins', sans-serif; }
        .load-more-btn:hover { background: #f0f7ff; }
        .right-col { display: flex; flex-direction: column; gap: 1.2rem; }
        .rules-intro-text { font-size: 0.78rem; color: #555; line-height: 1.6; padding: 10px 18px; border-bottom: 1px solid #f5f5f5; }
        .rules-list { padding: 10px 18px 16px 34px; }
        .rules-list li { font-size: 0.78rem; color: #444; line-height: 1.6; margin-bottom: 10px; }
        .admin-dates { display: flex; gap: 8px; flex-wrap: wrap; padding: 10px 18px; }
        .admin-date { background: #f0f7ff; padding: 5px 12px; border-radius: 50px; font-size: 0.72rem; color: #3498db; border: 1px solid #c7e0ff; display: inline-flex; align-items: center; gap: 6px; }
        .announcement-list { padding: 0 18px 16px; display: flex; flex-direction: column; gap: 8px; max-height: 260px; overflow-y: auto; }
        .announcement-item { background: #fff8e7; padding: 12px 14px; border-radius: 10px; border-left: 3px solid #f39c12; font-size: 0.78rem; color: #555; line-height: 1.6; }
        .ann-time { font-size: 0.68rem; color: #aaa; margin-top: 6px; }
        .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 500; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        @media (max-width: 1050px) {
            .dashboard-grid { grid-template-columns: 200px 1fr; }
            .right-col { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; }
            .community-card { height: auto; }
            .posts-list { overflow-y: visible; }
        }
        @media (max-width: 700px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .right-col { grid-template-columns: 1fr; }
            .main-content { padding: 1rem; }
            .community-card { height: auto; }
            .posts-list { overflow-y: visible; }
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
            <a href="student_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="profile_edit.php"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <div class="nav-dropdown">
                <a href="#"><i class="fas fa-clock"></i> Sit-in <i class="fas fa-caret-down"></i></a>
                <div class="nav-dropdown-content">
                    <a href="sit_reservation.php"><i class="fas fa-desktop"></i> Current Sit-in</a>
                    <a href="sit_history.php"><i class="fas fa-file-alt"></i> Sit-in Records</a>
                </div>
            </div>
            <a href="student_feedback.php"><i class="fas fa-star"></i> Feedback</a>
            <a href="logout.php" onclick="return confirm('Logout?')"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="notification-icon" id="notificationIcon">
            <i class="fas fa-bell"></i>
            <?php if ($unread_count > 0): ?>
                <span class="notif-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <span><i class="fas fa-bell"></i> Notifications</span>
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="mark_all_read">Mark all as read</button>
                    </form>
                </div>
                <?php if (empty($notifications)): ?>
                    <div class="notification-empty"><i class="fas fa-bell-slash"></i><p>No notifications yet</p></div>
                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                        <a href="?read_notification=<?php echo $n['id']; ?>" class="notification-item <?php echo !$n['is_read'] ? 'unread' : ''; ?>">
                            <div class="notification-title">
                                <?php if ($n['type']==='announcement'): ?><i class="fas fa-bullhorn" style="color:#f39c12;"></i>
                                <?php elseif ($n['type']==='reservation'): ?><i class="fas fa-calendar-check" style="color:#27ae60;"></i>
                                <?php else: ?><i class="fas fa-info-circle" style="color:#3498db;"></i><?php endif; ?>
                                <?php echo htmlspecialchars($n['title']); ?>
                            </div>
                            <div class="notification-message"><?php echo htmlspecialchars($n['message']); ?></div>
                            <div class="notification-time"><i class="fas fa-clock"></i> <?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<main class="main-content">
    <?php if (isset($upload_success)): ?>
        <div style="max-width:1300px;margin:0 auto 1rem;">
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $upload_success; ?></div>
        </div>
    <?php endif; ?>

    <div class="dashboard-grid">

        <!-- LEFT: Profile -->
        <div>
            <div class="card profile-card">
                <div class="card-body">
                    <div class="photo-wrap">
                        <img src="<?php echo htmlspecialchars($student['profile_pic']); ?>" class="profile-photo" id="profilePhoto" alt="Profile">
                        <form method="POST" enctype="multipart/form-data" id="photoUploadForm">
                            <input type="file" name="profile_photo" id="photoUpload" accept="image/*" style="display:none;">
                            <button type="button" class="change-photo-btn" onclick="document.getElementById('photoUpload').click();">
                                <i class="fas fa-camera"></i>
                            </button>
                        </form>
                    </div>
                    <p class="student-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
                    <span class="student-id-badge">ID <?php echo htmlspecialchars($student['id_number']); ?></span>
                    <div class="profile-stats">
                        <div class="profile-stat-row">
                            <span class="lbl"><i class="fas fa-graduation-cap" style="color:#3498db;"></i> Course</span>
                            <span class="val"><?php echo htmlspecialchars($student['course']); ?></span>
                        </div>
                        <div class="profile-stat-row">
                            <span class="lbl"><i class="fas fa-layer-group" style="color:#9b59b6;"></i> Year</span>
                            <span class="val"><?php echo htmlspecialchars($student['year_level']); ?></span>
                        </div>
                        <div class="sessions-box">
                            <span class="num"><?php echo htmlspecialchars($student['remaining_sessions']); ?></span>
                            <div class="lbl-s">Remaining Sessions</div>
                        </div>
                    </div>
                    <div class="quick-links">
                        <a href="profile_edit.php"     class="quick-link"><i class="fas fa-user-edit"></i> Edit Profile</a>
                        <a href="sit_history.php"      class="quick-link"><i class="fas fa-history"></i> Sit-in History</a>
                        <a href="sit_reservation.php"  class="quick-link"><i class="fas fa-calendar-alt"></i> Reservation</a>
                        <a href="student_feedback.php" class="quick-link"><i class="fas fa-star"></i> Feedback</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- CENTER: Feedback/Ratings & Community Feed -->
        <div>
            <!-- Feedback & Ratings Card -->
            <div class="dashboard-card" style="margin-bottom: 2rem;">
                <div class="card-header"><h2><i class="fas fa-star"></i> Your Sit-in Feedback & Ratings</h2></div>
                <div class="card-content">
                <?php
                // Fetch all feedback/rating for this student from sit_in_history
                $stmt = $pdo->prepare("SELECT admin_feedback, admin_feedback_type, admin_rating, laboratory, purpose, date, time_in FROM sit_in_history WHERE user_id = ? AND admin_rating IS NOT NULL ORDER BY date DESC, time_in DESC");
                $stmt->execute([$user_id]);
                $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($feedbacks)) {
                    echo '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No feedback or ratings from admins yet.</div>';
                } else {
                    echo '<table style="width:100%;border-collapse:collapse;font-size:0.97rem;">';
                    echo '<thead><tr style="background:#f8f9fa;"><th>Date</th><th>Lab</th><th>Purpose</th><th>Feedback</th><th>Type</th><th>Rating</th></tr></thead><tbody>';
                    foreach ($feedbacks as $f) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars(date('M d, Y', strtotime($f['date']))) . '</td>';
                        echo '<td>Lab ' . htmlspecialchars($f['laboratory']) . '</td>';
                        echo '<td>' . htmlspecialchars($f['purpose']) . '</td>';
                        echo '<td>' . htmlspecialchars($f['admin_feedback']) . '</td>';
                        echo '<td>' . htmlspecialchars($f['admin_feedback_type']) . '</td>';
                        echo '<td style="font-weight:bold;color:#f39c12;">' . (int)$f['admin_rating'] . ' / 10</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
                ?>
                </div>
            </div>

            <!-- Leaderboard Card -->
            <div class="dashboard-card" style="margin-bottom: 2rem;">
                <div class="card-header"><h2><i class="fas fa-trophy"></i> Top Students by Admin Rating</h2></div>
                <div class="card-content">
                <?php
                // Leaderboard: Top 10 students by total admin_rating
                $stmt = $pdo->query("SELECT u.id_number, u.first_name, u.last_name, u.profile_pic, SUM(s.admin_rating) AS total_rating, COUNT(s.id) AS feedback_count FROM sit_in_history s JOIN users u ON s.user_id = u.id WHERE s.admin_rating IS NOT NULL GROUP BY s.user_id ORDER BY total_rating DESC, feedback_count DESC, u.first_name ASC LIMIT 10");
                $leaders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($leaders)) {
                    echo '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No leaderboard data yet.</div>';
                } else {
                    echo '<table style="width:100%;border-collapse:collapse;font-size:0.97rem;">';
                    echo '<thead><tr style="background:#f8f9fa;"><th>Rank</th><th>Student</th><th>ID</th><th>Total Rating</th><th>Feedbacks</th></tr></thead><tbody>';
                    $rank = 1;
                    foreach ($leaders as $l) {
                        echo '<tr' . ($l['id_number'] == $student['id_number'] ? ' style="background:#eafaf1;font-weight:bold;"' : '') . '>';
                        echo '<td style="text-align:center;">' . $rank . '</td>';
                        echo '<td><img src="' . htmlspecialchars($l['profile_pic']) . '" style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:7px;vertical-align:middle;">' . htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) . '</td>';
                        echo '<td>' . htmlspecialchars($l['id_number']) . '</td>';
                        echo '<td style="color:#27ae60;font-weight:700;">' . (int)$l['total_rating'] . '</td>';
                        echo '<td>' . (int)$l['feedback_count'] . '</td>';
                        echo '</tr>';
                        $rank++;
                    }
                    echo '</tbody></table>';
                }
                ?>
                </div>
            </div>

            <!-- Community Feed Card -->
            <div class="card community-card">
                <div class="feed-header">
                    <h2><i class="fas fa-users"></i> Student Feedback Community <span style="color:#9b59b6;">(For You)</span></h2>
                    <button class="btn-new-post" id="toggleComposeBtn"><i class="fas fa-plus"></i> New Post</button>
                </div>
                <div class="compose-box" id="composeBox">
                    <div class="compose-inner">
                        <img src="<?php echo htmlspecialchars($student['profile_pic']); ?>" class="compose-avatar" alt="You">
                        <div class="compose-right">
                            <textarea class="compose-textarea" id="postContent" placeholder="Add a sit-in Testimony..."></textarea>
                            <div class="compose-actions">
                                <button class="btn-cancel" id="cancelPostBtn">Cancel</button>
                                <button class="btn-post"   id="submitPostBtn"><i class="fas fa-paper-plane"></i> Post</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="posts-list" id="postsList">
                    <div class="feed-empty"><i class="fas fa-spinner fa-spin"></i><p>Loading...</p></div>
                </div>
                <button class="load-more-btn" id="loadMoreBtn" style="display:none;">Load more posts</button>
            </div>
        </div>

        <!-- RIGHT: Rules + Announcement -->
        <div class="right-col">
            <div class="card">
                <div class="card-title"><i class="fas fa-gavel"></i> Rules &amp; Regulations</div>
                <p class="rules-intro-text">To avoid embarrassment and maintain camaraderie with your friends and superiors of our laboratories, please observe the following:</p>
                <ol class="rules-list">
                    <li>Maintain silence, proper decorum, and discipline inside the laboratory. Mobile phones must be switched off.</li>
                    <li>Games are not allowed inside the lab. This includes computer-related games, card games and other games that may disturb the operation of the lab.</li>
                    <li>Surfing the internet is allowed only with the permission of the instructor. Downloading and installing of software are strictly prohibited.</li>
                </ol>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-bullhorn"></i> Announcement</div>
                <div class="admin-dates">
                    <span class="admin-date"><i class="fas fa-user-tie"></i> CCS Admin | <?php echo date('Y-M-d'); ?></span>
                </div>
                <div class="announcement-list">
                    <?php if (empty($announcements)): ?>
                        <div class="announcement-item"><i class="fas fa-star" style="color:#f39c12;"></i> No announcements yet. Check back later!</div>
                    <?php else: ?>
                        <?php foreach ($announcements as $ann): ?>
                            <div class="announcement-item">
                                <i class="fas fa-star" style="color:#f39c12;margin-right:4px;"></i>
                                <?php echo htmlspecialchars($ann['message']); ?>
                                <div class="ann-time"><i class="fas fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($ann['created_at'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
const ME_AVATAR = <?php echo json_encode($student['profile_pic']); ?>;
let postsOffset = 0;
const LIMIT = 10;

const notifIcon = document.getElementById('notificationIcon');
const notifDrop = document.getElementById('notificationDropdown');
notifIcon.addEventListener('click', e => { e.stopPropagation(); notifDrop.classList.toggle('show'); });
document.addEventListener('click', () => notifDrop.classList.remove('show'));
notifDrop.addEventListener('click', e => e.stopPropagation());

document.getElementById('photoUpload').addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const r = new FileReader();
        r.onload = e => document.getElementById('profilePhoto').src = e.target.result;
        r.readAsDataURL(this.files[0]);
        this.form.submit();
    }
});

const composeBox  = document.getElementById('composeBox');
const postContent = document.getElementById('postContent');
document.getElementById('toggleComposeBtn').addEventListener('click', () => {
    composeBox.classList.toggle('open');
    if (composeBox.classList.contains('open')) postContent.focus();
});
document.getElementById('cancelPostBtn').addEventListener('click', () => { composeBox.classList.remove('open'); postContent.value = ''; });

document.getElementById('submitPostBtn').addEventListener('click', async () => {
    const content = postContent.value.trim();
    if (!content) return;
    const fd = new FormData();
    fd.append('action', 'add_post');
    fd.append('content', content);
    const data = await (await fetch('student_dashboard.php', { method: 'POST', body: fd })).json();
    if (data.success) { postContent.value = ''; composeBox.classList.remove('open'); loadPosts(true); }
});

async function loadPosts(reset) {
    if (reset) postsOffset = 0;
    const data = await (await fetch('student_dashboard.php?action=get_posts&offset=' + postsOffset)).json();
    const list = document.getElementById('postsList');
    const more = document.getElementById('loadMoreBtn');
    if (reset) list.innerHTML = '';
    if (!data.posts || (data.posts.length === 0 && postsOffset === 0)) {
        list.innerHTML = '<div class="feed-empty"><i class="fas fa-comments"></i><p>No testimonies yet. Be the first to share!</p></div>';
        more.style.display = 'none'; return;
    }
    data.posts.forEach(p => list.insertAdjacentHTML('beforeend', renderPost(p)));
    postsOffset += data.posts.length;
    more.style.display = data.posts.length < LIMIT ? 'none' : 'block';
}

function renderPost(p) {
    var av = p.profile_pic || 'uploads/default.png';
    var ownerActions = p.is_owner
        ? '<button class="reaction-btn edit-btn" onclick="editPost(' + p.id + ',this)"><i class="fas fa-pen"></i> Edit</button>'
        : '';
    return '<div class="post-card" data-post-id="' + p.id + '">' +
        '<div class="post-header">' +
        '<img src="' + esc(av) + '" class="post-avatar" alt="">' +
        '<div><div class="post-author">@' + esc(p.first_name) + "'s Sit-in Testimony</div>" +
        '<div class="post-time">' + esc(p.time_ago) + '</div></div></div>' +
        '<div class="post-content">' + esc(p.content) + '</div>' +
        '<div class="post-actions">' +
        ownerActions +
        '<button class="reaction-btn' + (p.user_liked ? ' liked' : '') + '" onclick="react(' + p.id + ',\'like\',this)">' +
        '<i class="fas fa-thumbs-up"></i> <span class="rc">' + p.like_count + '</span> Like(s)</button>' +
        '<button class="reaction-btn' + (p.user_hearted ? ' hearted' : '') + '" onclick="react(' + p.id + ',\'heart\',this)">' +
        '<i class="fas fa-heart"></i> <span class="rc">' + p.heart_count + '</span> Heart(s)</button>' +
        '<button class="reaction-btn comment-btn" onclick="toggleComments(' + p.id + ')">' +
        '<i class="fas fa-comment"></i> <span class="cc">' + p.comment_count + '</span> Comment(s)</button>' +
        '</div>' +
        '<div class="show-comments-toggle" onclick="toggleComments(' + p.id + ')">' +
        '<i class="fas fa-chevron-down"></i> Show Comments (' + p.comment_count + ')</div>' +
        '<div class="comments-section" id="cs-' + p.id + '">' +
        '<div id="ci-' + p.id + '"></div>' +
        '<div class="comment-input-row">' +
        '<img src="' + esc(ME_AVATAR) + '" alt="You">' +
        '<input class="comment-input" id="cm-' + p.id + '" placeholder="Write a comment..." onkeydown="if(event.key===\'Enter\')sendComment(' + p.id + ')">' +
        '<button class="comment-send-btn" onclick="sendComment(' + p.id + ')"><i class="fas fa-paper-plane"></i></button>' +
        '</div></div></div>';
}

async function editPost(postId, btn) {
    var postCard = btn.closest('[data-post-id]');
    var contentNode = postCard ? postCard.querySelector('.post-content') : null;
    if (!contentNode) return;

    var currentText = contentNode.textContent || '';
    var updatedText = prompt('Edit your testimony:', currentText);
    if (updatedText === null) return;
    updatedText = updatedText.trim();
    if (!updatedText) {
        alert('Post content cannot be empty.');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'edit_post');
    fd.append('post_id', postId);
    fd.append('content', updatedText);
    var data = await (await fetch('student_dashboard.php', { method: 'POST', body: fd })).json();
    if (data.success) {
        contentNode.textContent = updatedText;
    } else {
        alert(data.error || 'Failed to update post.');
    }
}

async function react(postId, type, btn) {
    var fd = new FormData();
    fd.append('action','react'); fd.append('post_id',postId); fd.append('type',type);
    var data = await (await fetch('student_dashboard.php',{method:'POST',body:fd})).json();
    if (data.success) {
        btn.querySelector('.rc').textContent = data.count;
        if (type === 'like') btn.classList.toggle('liked', data.reacted);
        else btn.classList.toggle('hearted', data.reacted);
    }
}

async function toggleComments(postId) {
    var sec = document.getElementById('cs-' + postId);
    if (sec.classList.contains('open')) { sec.classList.remove('open'); return; }
    await fetchComments(postId);
    sec.classList.add('open');
}

async function fetchComments(postId) {
    var data = await (await fetch('student_dashboard.php?action=get_comments&post_id=' + postId)).json();
    var inner = document.getElementById('ci-' + postId);
    inner.innerHTML = '';
    if (!data.comments || data.comments.length === 0) {
        inner.innerHTML = '<p style="font-size:0.78rem;color:#aaa;padding:4px 0;">No comments yet.</p>'; return;
    }
    data.comments.forEach(function(c) {
        inner.insertAdjacentHTML('beforeend',
            '<div class="comment-item">' +
            '<img src="' + esc(c.profile_pic || 'uploads/default.png') + '" class="comment-avatar" alt="">' +
            '<div class="comment-bubble">' +
            '<div class="comment-author">@' + esc(c.first_name) + "'s Sit-in Testimony</div>" +
            '<div class="comment-text">' + esc(c.content) + '</div>' +
            '<div class="comment-time">' + esc(c.time_ago) + '</div>' +
            '</div></div>');
    });
}

async function sendComment(postId) {
    var inp = document.getElementById('cm-' + postId);
    var content = inp.value.trim(); if (!content) return;
    var fd = new FormData();
    fd.append('action','add_comment'); fd.append('post_id',postId); fd.append('content',content);
    var data = await (await fetch('student_dashboard.php',{method:'POST',body:fd})).json();
    if (data.success) {
        inp.value = '';
        await fetchComments(postId);
        document.querySelectorAll('[data-post-id="' + postId + '"] .cc').forEach(function(el){ el.textContent = parseInt(el.textContent||0)+1; });
    }
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

document.getElementById('loadMoreBtn').addEventListener('click', function(){ loadPosts(false); });
loadPosts(true);
</script>
</body>
</html>