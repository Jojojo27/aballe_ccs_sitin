<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$pcUsageTableExists = false;
try {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pc_usage'");
    $stmt->execute();
    $pcUsageTableExists = $stmt->rowCount() > 0;
} catch (Throwable $e) {
    $pcUsageTableExists = false;
}

$manualToggleFilter = $pcUsageTableExists
    ? " AND NOT (s.purpose = 'Admin Manual PC Toggle' AND NOT EXISTS (SELECT 1 FROM pc_usage p WHERE p.sitin_id = s.id)) "
    : " ";

function isInvalidRecordTime($value) {
    if ($value === null) {
        return true;
    }
    $value = trim((string) $value);
    return $value === '' || stripos($value, '0000-00-00') !== false;
}

function formatRecordTime($value) {
    if (isInvalidRecordTime($value)) {
        return 'N/A';
    }
    $ts = strtotime((string) $value);
    if ($ts === false) {
        return 'N/A';
    }
    return date('h:i A', $ts);
}

function formatRecordDuration($timeIn, $timeOut) {
    if (isInvalidRecordTime($timeIn) || isInvalidRecordTime($timeOut)) {
        return 'N/A';
    }
    $inTs = strtotime((string) $timeIn);
    $outTs = strtotime((string) $timeOut);
    if ($inTs === false || $outTs === false) {
        return 'N/A';
    }

    $diff = $outTs - $inTs;
    // Reject malformed legacy values that yield unrealistic durations.
    if ($diff < 0 || $diff > 86400) {
        return 'N/A';
    }

    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    return $hours . 'h ' . $minutes . 'm';
}

// Get ALL completed sit-ins (with time_out NOT NULL)
$stmt = $pdo->query(" 
    SELECT s.*, u.id_number, u.first_name, u.last_name, u.profile_pic 
    FROM sit_in_history s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.time_out IS NOT NULL
    {$manualToggleFilter}
    ORDER BY s.date DESC, s.time_in DESC
");
$completed_sitins = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) FROM sit_in_history s WHERE s.time_out IS NOT NULL {$manualToggleFilter}");
$total_completed = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(DISTINCT s.user_id) FROM sit_in_history s WHERE s.time_out IS NOT NULL {$manualToggleFilter}");
$total_students = $stmt->fetchColumn();

// Get today's completed
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sit_in_history s WHERE s.date = ? AND s.time_out IS NOT NULL {$manualToggleFilter}");
$stmt->execute([$today]);
$today_completed = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Sit-in Records - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html { font-size: 13px; zoom: 1; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; }
        
        /* Navbar Styles - Your Exact Style */
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
        
        .main-content { margin-left: 220px; padding: 2rem; min-height: 100vh; }
        
        .page-header { margin-bottom: 2rem; }
        .page-header h1 { font-size: 1.8rem; color: #2c3e50; display: flex; align-items: center; gap: 0.8rem; }
        
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
            background: linear-gradient(145deg, #27ae60, #219a52);
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
        .badge-completed {
            background: #27ae60;
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
            min-width: 900px;
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
        .status-completed { background: #d4edda; color: #155724; }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .btn-back {
            background: #2c3e50;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
        }
        
        .btn-back:hover { background: #1a2634; color: white; }
        
        /* Mobile Responsive */
        @media (max-width: 992px) {
            .navbar {
                flex-direction: column;
                text-align: center;
                padding: 0.8rem;
            }
            .navbar-links {
                justify-content: center;
            }
            .main-content { margin-top: 130px; }
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .filters { flex-direction: column; }
            .filters select, .filters input { width: 100%; }
            .section-header { flex-direction: column; gap: 0.5rem; align-items: flex-start; }
        }
    </style>
</head>
<body>
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
                        <a href="admin_records.php" class="active" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #fff; font-weight: 500; background: #3b82f6; transition: all 0.2s;"><i class="fas fa-list"></i> View Records</a>
                        <a href="admin_reports.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-chart-line"></i> Report & Analytics</a>
                        <a href="admin_feedback.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-comment-dots"></i> Feedback</a>
                        <a href="admin_reservations.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-calendar-alt"></i> Reservation</a>
                    </nav>
                </div>
                <div style="padding-bottom: 2rem;">
                    <a href="logout.php" style="display: flex; align-items: center; gap: 12px; background: #dc2626; color: #fff; text-decoration: none; padding: 0.75rem 1rem; border-radius: 14px; font-weight: 600; justify-content: center;"><i class="fas fa-sign-out-alt"></i> Log out</a>
                </div>
            </aside>

            <main class="main-content" style="margin-left: 250px;">
        <div class="page-header">
            <h1><i class="fas fa-list"></i> View Sit-in Records</h1>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h3>Total Completed</h3>
                    <p><?php echo $total_completed; ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3>Students Served</h3>
                    <p><?php echo $total_students; ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-info">
                    <h3>Completed Today</h3>
                    <p><?php echo $today_completed; ?></p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <select id="labFilter">
                <option value="">All Laboratories</option>
                <option value="523">Lab 523</option>
                <option value="524">Lab 524</option>
                <option value="525">Lab 525</option>
                <option value="526">Lab 526</option>
                <option value="527">Lab 527</option>
                <option value="528">Lab 528</option>
                <option value="529">Lab 529</option>
                <option value="530">Lab 530</option>
            </select>
            <input type="text" id="searchInput" placeholder="Search by student name or ID...">
            <a href="admin_sitins.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Active Sit-ins</a>
        </div>
        
        <!-- Completed Sit-ins Table -->
        <div class="section-header">
            <h2><i class="fas fa-history"></i> Completed Sit-in Records <span class="badge-completed"><?php echo count($completed_sitins); ?> records</span></h2>
        </div>
        
        <div class="table-container">
            <table id="recordsTable">
                <thead>
                    <tr>
                        <th>PC Number</th>
                        <th>ID Number</th>
                        <th>Student</th>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Duration</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($completed_sitins)): ?>
                        <tr>
                            <td colspan="10" class="empty-state">
                                <i class="fas fa-database" style="font-size: 2rem; color: #27ae60;"></i>
                                <p>No completed sit-in records yet.</p>
                                <p style="font-size: 0.8rem; margin-top: 0.5rem;">When students log out, their records will appear here.</p>
                              </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($completed_sitins as $sit): 
                            $duration = formatRecordDuration($sit['time_in'], $sit['time_out']);
                        ?>
                        <tr>
                            <td>#<?php echo $sit['id']; ?></td>
                            <td><?php echo htmlspecialchars($sit['id_number']); ?></td>
                            <td>
                                <div class="student-info">
                                    <img src="<?php echo htmlspecialchars($sit['profile_pic']); ?>" class="student-avatar" onerror="this.src='default-avatar.png'">
                                    <?php echo htmlspecialchars($sit['first_name'] . ' ' . $sit['last_name']); ?>
                                </div>
                              </td>
                            <td><?php echo htmlspecialchars($sit['purpose']); ?></td>
                            <td>Lab <?php echo htmlspecialchars($sit['laboratory']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($sit['date'])); ?></td>
                            <td><?php echo formatRecordTime($sit['time_in']); ?></td>
                            <td><?php echo formatRecordTime($sit['time_out']); ?></td>
                            <td><?php echo $duration; ?></td>
                            <td><span class="status-badge status-completed">Completed</span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        // Search modal function (if needed)
        function openSearchModal() {
            // Add your search modal logic here or redirect
            alert('Search functionality - Add your search modal here');
        }
        
        // Filter function
        function filterTable() {
            let search = document.getElementById('searchInput').value.toLowerCase();
            let lab = document.getElementById('labFilter').value.toLowerCase();
            let rows = document.querySelectorAll('#recordsTable tbody tr');
            
            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                let text = row.textContent.toLowerCase();
                let labMatch = lab === '' || text.includes(lab);
                let searchMatch = search === '' || text.includes(search);
                row.style.display = (labMatch && searchMatch) ? '' : 'none';
            });
        }
        
        document.getElementById('searchInput').addEventListener('keyup', filterTable);
        document.getElementById('labFilter').addEventListener('change', filterTable);
    </script>
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