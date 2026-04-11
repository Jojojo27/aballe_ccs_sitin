<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Get ALL completed sit-ins (with time_out NOT NULL)
$stmt = $pdo->query("
    SELECT s.*, u.id_number, u.first_name, u.last_name, u.profile_pic 
    FROM sit_in_history s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.time_out IS NOT NULL
    ORDER BY s.date DESC, s.time_in DESC
");
$completed_sitins = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) FROM sit_in_history WHERE time_out IS NOT NULL");
$total_completed = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM sit_in_history WHERE time_out IS NOT NULL");
$total_students = $stmt->fetchColumn();

// Get today's completed
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sit_in_history WHERE date = ? AND time_out IS NOT NULL");
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; }
        
        /* Navbar Styles - Your Exact Style */
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
        
        .main-content { margin-top: 90px; padding: 2rem; max-width: 1400px; margin-left: auto; margin-right: auto; }
        
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
    <nav class="navbar">
        <div class="navbar-logo">
            <i class="fas fa-laptop-code"></i> College of Computer Studies Admin
        </div>
        <div class="navbar-links">
            <a href="admin_dashboard.php"><i class="fas fa-home"></i> Home</a>
            <a href="javascript:void(0)" onclick="openSearchModal()"><i class="fas fa-search"></i> Search</a>
            <a href="admin_students.php"><i class="fas fa-users"></i> Students</a>
            <a href="admin_sitins.php"><i class="fas fa-clock"></i> Sit-in</a>
            <a href="admin_records.php" class="active"><i class="fas fa-list"></i> View Sit-in Records</a>
            <a href="admin_reports.php"><i class="fas fa-chart-line"></i> Sit-in Reports</a>
            <a href="admin_feedback.php"><i class="fas fa-star"></i> Feedback Reports</a>
            <a href="admin_reservations.php"><i class="fas fa-calendar-alt"></i> Reservation</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </nav>

    <main class="main-content">
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
                        <th>Sit ID</th>
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
                            $duration = '';
                            if ($sit['time_in'] && $sit['time_out']) {
                                $diff = strtotime($sit['time_out']) - strtotime($sit['time_in']);
                                $hours = floor($diff / 3600);
                                $minutes = floor(($diff % 3600) / 60);
                                $duration = $hours . 'h ' . $minutes . 'm';
                            }
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
                            <td><?php echo date('h:i A', strtotime($sit['time_in'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($sit['time_out'])); ?></td>
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
</body>
</html>