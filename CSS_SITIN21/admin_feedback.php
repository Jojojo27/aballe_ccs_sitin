<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Schema-safe detection for users table
$users_columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$users_has_profile_image = in_array('profile_image', $users_columns, true);

// Schema-safe detection for feedback table
$feedback_columns = $pdo->query("SHOW COLUMNS FROM feedback")->fetchAll(PDO::FETCH_COLUMN);
$feedback_has_sit_in_id = in_array('sit_in_id', $feedback_columns, true);
$feedback_has_rating    = in_array('rating', $feedback_columns, true);
$feedback_text_column   = in_array('message', $feedback_columns, true)
    ? 'message'
    : (in_array('comment', $feedback_columns, true) ? 'comment' : null);

// Build feedback query dynamically
$text_sel   = $feedback_text_column ? "f.{$feedback_text_column} AS feedback_text" : "NULL AS feedback_text";
$rating_sel = $feedback_has_rating  ? "f.rating" : "NULL AS rating";
$sitin_sel  = $feedback_has_sit_in_id
    ? "s.laboratory, s.purpose, s.date AS sitin_date"
    : "NULL AS laboratory, NULL AS purpose, NULL AS sitin_date";
$join_clause = $feedback_has_sit_in_id
    ? "LEFT JOIN sit_in_history s ON s.id = f.sit_in_id"
    : "";
$profile_sel = $users_has_profile_image ? "u.profile_image" : "NULL AS profile_image";

// Search / filter
$search  = isset($_GET['search'])  ? trim($_GET['search'])  : '';
$rating_filter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;

$where_parts = [];
$params = [];

if ($search !== '') {
    $where_parts[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.id_number LIKE :search OR f.{$feedback_text_column} LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($feedback_has_rating && $rating_filter >= 1 && $rating_filter <= 5) {
    $where_parts[] = "f.rating = :rating";
    $params['rating'] = $rating_filter;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$sql = "
    SELECT
        f.id,
        f.user_id,
        f.created_at,
        {$text_sel},
        {$rating_sel},
        {$sitin_sel},
        u.first_name,
        u.last_name,
        u.id_number,
        u.course,
        u.year_level,
        {$profile_sel}
    FROM feedback f
    INNER JOIN users u ON u.id = f.user_id
    {$join_clause}
    {$where_sql}
    ORDER BY f.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll();

// Summary stats
$total_feedback  = count($feedbacks);
$avg_rating      = 0;
$five_star_count = 0;
$unique_students = [];

foreach ($feedbacks as $fb) {
    $unique_students[$fb['user_id']] = true;
    if ($feedback_has_rating && $fb['rating']) {
        $avg_rating      += (int)$fb['rating'];
        if ((int)$fb['rating'] === 5) $five_star_count++;
    }
}
$avg_rating = $total_feedback > 0 && $feedback_has_rating
    ? round($avg_rating / max(1, array_sum(array_map(fn($f) => $f['rating'] ? 1 : 0, $feedbacks))), 1)
    : 0;
$unique_student_count = count($unique_students);

function renderStars(int $rating): string {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $rating
            ? '<i class="fas fa-star" style="color:#f39c12;font-size:0.8rem;"></i>'
            : '<i class="far fa-star" style="color:#ddd;font-size:0.8rem;"></i>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback Reports - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html { font-size: 13px; zoom: 1; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; }

        /* ── Navbar ── */
        .navbar {
            background: linear-gradient(135deg, #2c3e50, #1a2634);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 220px;
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1.2rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .navbar-links {
            display: flex;
            flex-direction: column;
            flex: 1;
            justify-content: space-evenly;
        }
        .navbar-links a {
            color: rgba(255,255,255,0.78);
            text-decoration: none;
            padding: 1.2rem 1rem;
            transition: 0.2s;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border-left: 3px solid transparent;
            white-space: nowrap;
        }
        .navbar-links a i { font-size: 0.9rem; width: 16px; text-align: center; }
        .navbar-links a:hover { background: rgba(255,255,255,0.08); border-left-color: #3498db; color: white; }
        .navbar-links a.active { background: rgba(52,152,219,0.2); border-left-color: #3498db; color: white; }
        .logout-btn {
            display: flex !important;
            align-items: center !important;
            gap: 0.6rem !important;
            background: #e74c3c !important;
            color: white !important;
            text-decoration: none;
            padding: 0.75rem 1rem !important;
            font-size: 0.8rem !important;
            border-radius: 0 !important;
            margin: 0 !important;
            border-left: 3px solid transparent !important;
            white-space: nowrap;
        }
        .logout-btn i { font-size: 0.9rem; width: 16px; text-align: center; }
        .logout-btn:hover { background: #c0392b !important; }
        .dark-mode-toggle {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            z-index: 10001;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.5rem 1.1rem;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            transition: 0.2s;
        }
        .dark-mode-toggle:hover { background: #2980b9; }
        .dark-mode-toggle i { font-size: 0.9rem; }
        body.dark-mode { background: #1a2332 !important; }
        body.dark-mode .main-content { background: #1e2a38; color: #dde3ea; }
        body.dark-mode table { background: #253040 !important; color: #dde3ea !important; }
        body.dark-mode th { background: #1a2634 !important; color: #dde3ea !important; }
        body.dark-mode td { border-color: #2c3e50 !important; color: #dde3ea !important; }

        /* ── Layout ── */
        .main-content { margin-left: 220px; padding: 2rem; min-height: 100vh; }

        .page-header { margin-bottom: 1.8rem; }
        .page-header h1 { font-size: 1.8rem; color: #2c3e50; display: flex; align-items: center; gap: 0.8rem; }
        .page-header p  { color: #666; font-size: 0.9rem; margin-top: 0.3rem; }

        /* ── Stats ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white; border-radius: 15px; padding: 1.5rem;
            display: flex; align-items: center; gap: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .stat-icon {
            width: 55px; height: 55px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.4rem; flex-shrink: 0;
        }
        .stat-icon.blue   { background: linear-gradient(145deg,#3498db,#2980b9); }
        .stat-icon.green  { background: linear-gradient(145deg,#2ecc71,#27ae60); }
        .stat-icon.yellow { background: linear-gradient(145deg,#f39c12,#e67e22); }
        .stat-icon.purple { background: linear-gradient(145deg,#9b59b6,#8e44ad); }
        .stat-info h3 { font-size: 0.78rem; color: #888; margin-bottom: 0.2rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-info p  { font-size: 1.9rem; font-weight: 700; color: #2c3e50; line-height: 1; }

        /* ── Filters ── */
        .filters {
            background: white; padding: 1rem 1.2rem; border-radius: 12px;
            margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .filters input, .filters select {
            padding: 0.6rem 0.9rem; border: 2px solid #e0e7ff; border-radius: 8px;
            font-family: 'Poppins', sans-serif; font-size: 0.85rem; outline: none; transition: 0.2s;
        }
        .filters input:focus, .filters select:focus { border-color: #3498db; }
        .filters input[type="search"] { flex: 1; min-width: 220px; }
        .btn-filter {
            background: #3498db; color: white; border: none; padding: 0.6rem 1.2rem;
            border-radius: 8px; cursor: pointer; font-family: 'Poppins', sans-serif;
            font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem; transition: 0.2s;
        }
        .btn-filter:hover { background: #2980b9; }
        .btn-reset {
            background: #ecf0f1; color: #555; border: none; padding: 0.6rem 1rem;
            border-radius: 8px; cursor: pointer; font-family: 'Poppins', sans-serif;
            font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .btn-reset:hover { background: #dfe6e9; }

        /* ── Table ── */
        .table-container {
            background: white; border-radius: 15px; overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08); overflow-x: auto;
        }
        .table-header {
            padding: 1.2rem 1.5rem; border-bottom: 1px solid #f0f2f5;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;
        }
        .table-header h2 { font-size: 1.1rem; color: #2c3e50; display: flex; align-items: center; gap: 0.5rem; }
        .record-count { background: #e0e7ff; color: #3949ab; padding: 0.25rem 0.7rem; border-radius: 50px; font-size: 0.75rem; font-weight: 600; }

        table { width: 100%; border-collapse: collapse; min-width: 750px; }
        th {
            background: #34495e; color: white; padding: 0.9rem 1rem;
            text-align: left; font-weight: 500; font-size: 0.82rem;
        }
        td { padding: 1rem; border-bottom: 1px solid #f0f2f5; font-size: 0.84rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8faff; }

        /* ── Student cell ── */
        .student-cell { display: flex; align-items: center; gap: 0.75rem; }
        .student-avatar {
            width: 38px; height: 38px; border-radius: 50%; object-fit: cover;
            border: 2px solid #e0e7ff; flex-shrink: 0;
        }
        .avatar-placeholder {
            width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(145deg,#3498db,#2980b9);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 0.9rem; flex-shrink: 0;
        }
        .student-name { font-weight: 600; color: #2c3e50; font-size: 0.85rem; }
        .student-meta { color: #888; font-size: 0.75rem; }

        /* ── Feedback text ── */
        .feedback-text {
            max-width: 320px; word-break: break-word; color: #444; font-size: 0.83rem;
            line-height: 1.5;
        }
        .feedback-text blockquote {
            border-left: 3px solid #3498db; padding-left: 0.75rem;
            margin: 0; font-style: italic; color: #555;
        }

        /* ── Stars ── */
        .stars-cell { white-space: nowrap; }

        /* ── Lab badge ── */
        .lab-badge {
            display: inline-block; background: #e8f4fd; color: #2980b9;
            padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600;
        }

        /* ── Date ── */
        .date-cell { white-space: nowrap; color: #555; font-size: 0.8rem; }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 4rem 2rem; color: #aaa; }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; display: block; color: #d0d7e8; }
        .empty-state p { font-size: 0.95rem; }

        /* ── Responsive ── */
        @media(max-width: 768px) {
            .main-content { margin-top: 140px; padding: 1rem; }
            .feedback-text { max-width: 200px; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
        <div class="app-wrapper">
            <!-- Sidebar Navigation -->
            <aside class="sidebar" style="width: 250px; background: #111827; color: #e5e7eb; min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; position: fixed; left: 0; top: 0; bottom: 0; z-index: 100;">
                <div>
                    <div class="sidebar-logo" style="display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 1.2rem; margin-bottom: 2.5rem; padding-left: 0.5rem; padding-top: 1.5rem;">
                        <i class="fas fa-laptop-code"></i> <span>CCS Admin</span>
                    </div>
                    <nav class="sidebar-nav" style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <a href="admin_dashboard.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-home"></i> Home</a>
                        <a href="admin_search.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-search"></i> Search</a>
                        <a href="admin_students.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-users"></i> Students</a>
                        <a href="admin_sitins.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-clock"></i> Sit-in</a>
                        <a href="admin_records.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-list"></i> View Records</a>
                        <a href="admin_reports.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-chart-line"></i> Report & Analytics</a>
                        <a href="admin_feedback.php" class="active" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #fff; font-weight: 500; background: #3b82f6; transition: all 0.2s;"><i class="fas fa-comment-dots"></i> Feedback</a>
                        <a href="admin_reservations.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-calendar-alt"></i> Reservation</a>
                    </nav>
                </div>
                <div style="padding-bottom: 2rem;">
                    <a href="logout.php" style="display: flex; align-items: center; gap: 12px; background: #dc2626; color: #fff; text-decoration: none; padding: 0.75rem 1rem; border-radius: 14px; font-weight: 600; justify-content: center;"><i class="fas fa-sign-out-alt"></i> Log out</a>
                </div>
            </aside>

            <div class="main-content" style="margin-left: 250px;">

    <!-- Page Header -->
    <div class="page-header">
        <h1><i class="fas fa-comment-dots" style="color:#3498db;"></i> Student Feedback Reports</h1>
        <p>All testimonials and feedback submitted by students after their sit-in sessions.</p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-comments"></i></div>
            <div class="stat-info">
                <h3>Total Feedback</h3>
                <p><?= $total_feedback ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-user-graduate"></i></div>
            <div class="stat-info">
                <h3>Unique Students</h3>
                <p><?= $unique_student_count ?></p>
            </div>
        </div>
        <?php if ($feedback_has_rating): ?>
        <div class="stat-card">
            <div class="stat-icon yellow"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <h3>Average Rating</h3>
                <p><?= $avg_rating > 0 ? $avg_rating . ' / 5' : '—' ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-trophy"></i></div>
            <div class="stat-info">
                <h3>5-Star Reviews</h3>
                <p><?= $five_star_count ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters">
        <input
            type="search"
            name="search"
            placeholder="Search by name, ID, or feedback..."
            value="<?= htmlspecialchars($search) ?>"
        >
        <?php if ($feedback_has_rating): ?>
        <select name="rating">
            <option value="0">All Ratings</option>
            <?php for ($r = 5; $r >= 1; $r--): ?>
            <option value="<?= $r ?>" <?= $rating_filter === $r ? 'selected' : '' ?>>
                <?= $r ?> Star<?= $r > 1 ? 's' : '' ?>
            </option>
            <?php endfor; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filter</button>
        <a href="admin_feedback.php" class="btn-reset"><i class="fas fa-times"></i> Reset</a>
    </form>

    <!-- Table -->
    <div class="table-container">
        <div class="table-header">
            <h2><i class="fas fa-list"></i> Feedback List</h2>
            <span class="record-count"><?= $total_feedback ?> record<?= $total_feedback !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($feedbacks)): ?>
        <div class="empty-state">
            <i class="fas fa-comment-slash"></i>
            <p>No feedback found<?= ($search || $rating_filter) ? ' matching your filters' : ' yet' ?>.</p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Feedback</th>
                    <?php if ($feedback_has_rating): ?><th>Rating</th><?php endif; ?>
                    <?php if ($feedback_has_sit_in_id): ?><th>Lab / Purpose</th><?php endif; ?>
                    <th>Date Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($feedbacks as $i => $fb): ?>
                <?php
                $fullName  = trim($fb['first_name'] . ' ' . $fb['last_name']);
                $initials  = strtoupper(substr($fb['first_name'] ?? 'S', 0, 1) . substr($fb['last_name'] ?? 'T', 0, 1));
                $avatarSrc = !empty($fb['profile_image']) ? 'uploads/' . htmlspecialchars($fb['profile_image']) : null;
                $submittedAt = !empty($fb['created_at']) ? date('M d, Y g:i A', strtotime($fb['created_at'])) : '—';
                $sitinDate   = !empty($fb['sitin_date'])  ? date('M d, Y', strtotime($fb['sitin_date']))       : null;
                ?>
                <tr>
                    <td style="color:#aaa;font-size:0.78rem;"><?= $i + 1 ?></td>

                    <td>
                        <div class="student-cell">
                            <?php if ($avatarSrc): ?>
                                <img src="<?= $avatarSrc ?>" class="student-avatar" alt="">
                            <?php else: ?>
                                <div class="avatar-placeholder"><?= htmlspecialchars($initials) ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="student-name"><?= htmlspecialchars($fullName) ?></div>
                                <div class="student-meta">
                                    <?= htmlspecialchars($fb['id_number'] ?? '') ?>
                                    <?php if (!empty($fb['course'])): ?>
                                        &middot; <?= htmlspecialchars($fb['course']) ?>
                                        <?= !empty($fb['year_level']) ? htmlspecialchars($fb['year_level']) : '' ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>

                    <td>
                        <div class="feedback-text">
                            <blockquote><?= nl2br(htmlspecialchars($fb['feedback_text'] ?? '—')) ?></blockquote>
                        </div>
                    </td>

                    <?php if ($feedback_has_rating): ?>
                    <td class="stars-cell">
                        <?php if ($fb['rating']): ?>
                            <?= renderStars((int)$fb['rating']) ?>
                            <div style="font-size:0.7rem;color:#888;margin-top:2px;"><?= (int)$fb['rating'] ?>/5</div>
                        <?php else: ?>
                            <span style="color:#ccc;font-size:0.8rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <?php if ($feedback_has_sit_in_id): ?>
                    <td>
                        <?php if (!empty($fb['laboratory'])): ?>
                            <span class="lab-badge"><i class="fas fa-desktop"></i> <?= htmlspecialchars($fb['laboratory']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($fb['purpose'])): ?>
                            <div style="font-size:0.75rem;color:#666;margin-top:4px;"><?= htmlspecialchars($fb['purpose']) ?></div>
                        <?php endif; ?>
                        <?php if ($sitinDate): ?>
                            <div style="font-size:0.73rem;color:#aaa;"><?= $sitinDate ?></div>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <td class="date-cell">
                        <i class="far fa-clock" style="color:#bbb;margin-right:4px;"></i><?= $submittedAt ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
    <button class="dark-mode-toggle" onclick="toggleTheme()"><i class="fas fa-moon" id="theme-icon"></i> <span id="theme-label">Dark</span></button>
    <script>
    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-label').textContent = isDark ? 'Light' : 'Dark';
        document.getElementById('theme-icon').className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
    if (localStorage.getItem('theme') === 'dark') {
        document.body.classList.add('dark-mode');
        document.getElementById('theme-label').textContent = 'Light';
        document.getElementById('theme-icon').className = 'fas fa-sun';
    }
    </script>
</body>
</html>
