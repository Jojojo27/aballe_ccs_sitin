<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Check if pc_usage table exists
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

function escapeCsvValue($value) {
    $value = (string) $value;
    $value = str_replace('"', '""', $value);
    return '"' . $value . '"';
}

function formatDuration($timeIn, $timeOut) {
    if (empty($timeIn) || empty($timeOut)) {
        return 'N/A';
    }

    $timeIn = trim((string) $timeIn);
    $timeOut = trim((string) $timeOut);

    if ($timeIn === '' || $timeOut === '' || stripos($timeIn, '0000-00-00') !== false || stripos($timeOut, '0000-00-00') !== false) {
        return 'N/A';
    }

    $in = strtotime($timeIn);
    $out = strtotime($timeOut);

    if ($in === false || $out === false || $out < $in) {
        return 'N/A';
    }

    $seconds = $out - $in;
    if ($seconds > 86400) {
        return 'N/A';
    }
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . 'm';
}

function formatTimeDisplay($value) {
    $value = trim((string) $value);
    if ($value === '' || stripos($value, '0000-00-00') !== false) {
        return 'N/A';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return 'N/A';
    }
    return date('h:i A', $ts);
}

$period = isset($_GET['period']) ? trim($_GET['period']) : 'this_month';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$format = isset($_GET['format']) ? trim($_GET['format']) : '';

$today = date('Y-m-d');
$resolvedStart = null;
$resolvedEnd = null;
$label = 'This Month';

$dataRangeStart = null;
$dataRangeEnd = null;
try {
    $rangeStmt = $pdo->query("SELECT MIN(date) AS min_date, MAX(date) AS max_date FROM sit_in_history");
    $rangeRow = $rangeStmt->fetch();
    if (!empty($rangeRow['min_date']) && !empty($rangeRow['max_date'])) {
        $dataRangeStart = $rangeRow['min_date'];
        $dataRangeEnd = $rangeRow['max_date'];
    }
} catch (Throwable $e) {
    $dataRangeStart = null;
    $dataRangeEnd = null;
}

if ($period === 'all_time') {
    $resolvedStart = $dataRangeStart ?: date('Y-m-01');
    $resolvedEnd = $dataRangeEnd ?: date('Y-m-t');
    $label = 'All Time';
} elseif ($period === 'this_week') {
    $resolvedStart = date('Y-m-d', strtotime('monday this week'));
    $resolvedEnd = date('Y-m-d', strtotime('sunday this week'));
    $label = 'This Week';
} elseif ($period === 'custom') {
    if ($start_date !== '' && $end_date !== '' && $start_date <= $end_date) {
        $resolvedStart = $start_date;
        $resolvedEnd = $end_date;
        $label = 'Custom Range';
    } else {
        $resolvedStart = date('Y-m-01');
        $resolvedEnd = date('Y-m-t');
        $label = 'This Month';
        $period = 'this_month';
    }
} else {
    $resolvedStart = date('Y-m-01');
    $resolvedEnd = date('Y-m-t');
    $period = 'this_month';
}

// Main query to fetch sit-in records
$query = "
    SELECT
        s.id,
        s.date,
        s.time_in,
        s.time_out,
        s.status,
        s.purpose,
        s.laboratory,
        s.admin_rating,
        u.id_number,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.course,
        u.year_level,
        u.email
    FROM sit_in_history s
    INNER JOIN users u ON s.user_id = u.id
    WHERE s.date BETWEEN :start_date AND :end_date
    {$manualToggleFilter}
    ORDER BY s.date DESC, s.time_in DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([
    'start_date' => $resolvedStart,
    'end_date' => $resolvedEnd
]);
$rows = $stmt->fetchAll();

$totalRecords = count($rows);
$completedCount = 0;
$activeCount = 0;
$uniqueStudents = [];

foreach ($rows as $row) {
    $uniqueStudents[$row['id_number']] = true;
    if (strtolower((string) $row['status']) === 'active' || empty($row['time_out'])) {
        $activeCount++;
    } else {
        $completedCount++;
    }
}

$uniqueStudentCount = count($uniqueStudents);

// Calculate analytics data
$dailySessions = [];
$labUtilization = [];
$purposeBreakdown = [];
$satisfaction = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$heatmap = [];
$topStudents = [];
$totalMinutes = 0;

foreach ($rows as $row) {
    // Daily sessions
    $date = $row['date'];
    if (!isset($dailySessions[$date])) $dailySessions[$date] = 0;
    $dailySessions[$date]++;
    
    // Lab utilization
    $lab = $row['laboratory'];
    if (!isset($labUtilization[$lab])) $labUtilization[$lab] = 0;
    $labUtilization[$lab]++;
    
    // Purpose breakdown
    $purpose = $row['purpose'];
    if (!isset($purposeBreakdown[$purpose])) $purposeBreakdown[$purpose] = 0;
    $purposeBreakdown[$purpose]++;
    
    // Satisfaction (admin_rating)
    if (isset($row['admin_rating']) && $row['admin_rating'] > 0 && $row['admin_rating'] <= 5) {
        $satisfaction[(int)$row['admin_rating']]++;
    }
    
    // Heatmap (day of week x hour)
    if (!empty($row['time_in'])) {
        $day = date('N', strtotime($row['date'])); // 1=Mon, 7=Sun
        $hour = (int)substr($row['time_in'], 0, 2);
        if (!isset($heatmap[$day])) $heatmap[$day] = [];
        if (!isset($heatmap[$day][$hour])) $heatmap[$day][$hour] = 0;
        $heatmap[$day][$hour]++;
    }
    
    // Top students - accumulate sessions per student
    $id = $row['id_number'];
    $name = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
    if (!isset($topStudents[$id])) {
        $topStudents[$id] = ['name' => $name, 'count' => 0, 'id_number' => $id];
    }
    $topStudents[$id]['count']++;
    
    // Calculate total minutes for duration stats
    if (!empty($row['time_in']) && !empty($row['time_out'])) {
        $in = strtotime($row['date'] . ' ' . $row['time_in']);
        $out = strtotime($row['date'] . ' ' . $row['time_out']);
        if ($out > $in) $totalMinutes += ($out - $in) / 60;
    }
}

// Sort top students by session count (descending)
uasort($topStudents, function($a, $b) {
    return $b['count'] <=> $a['count'];
});
$topStudents = array_slice($topStudents, 0, 10, true);

// Get top lab
$topLab = '-';
$maxLab = 0;
foreach ($labUtilization as $lab => $count) {
    if ($count > $maxLab) {
        $maxLab = $count;
        $topLab = $lab;
    }
}

// Calculate average rating
$ratingSum = 0;
$ratingCount = 0;
foreach ($rows as $row) {
    if (isset($row['admin_rating']) && $row['admin_rating'] > 0) {
        $ratingSum += $row['admin_rating'];
        $ratingCount++;
    }
}
$avgRating = $ratingCount ? round($ratingSum / $ratingCount, 2) : 0;

// Calculate total hours and average duration
$totalHours = round($totalMinutes / 60, 1);
$avgMinutes = $totalRecords ? round($totalMinutes / $totalRecords) : 0;

// CSV Export
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sit_in_reports_' . $resolvedStart . '_to_' . $resolvedEnd . '.csv"');

    echo "Sit-in Reports\n";
    echo "Period," . escapeCsvValue($label) . "\n";
    echo "Date Range," . escapeCsvValue($resolvedStart . ' to ' . $resolvedEnd) . "\n";
    echo "Total Records," . $totalRecords . "\n";
    echo "Unique Students," . $uniqueStudentCount . "\n\n";

    echo "Date,Student ID,Student Name,Course,Year,Purpose,Laboratory,Time In,Time Out,Duration,Status,Rating,Email\n";
    foreach ($rows as $row) {
        $fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
        $duration = formatDuration($row['time_in'], $row['time_out']);
        $rating = isset($row['admin_rating']) && $row['admin_rating'] > 0 ? $row['admin_rating'] : 'N/A';

        echo escapeCsvValue($row['date']) . ',';
        echo escapeCsvValue($row['id_number']) . ',';
        echo escapeCsvValue($fullName) . ',';
        echo escapeCsvValue($row['course']) . ',';
        echo escapeCsvValue($row['year_level']) . ',';
        echo escapeCsvValue($row['purpose']) . ',';
        echo escapeCsvValue($row['laboratory']) . ',';
        echo escapeCsvValue(formatTimeDisplay($row['time_in'])) . ',';
        echo escapeCsvValue($row['time_out'] ? formatTimeDisplay($row['time_out']) : 'Active') . ',';
        echo escapeCsvValue($duration) . ',';
        echo escapeCsvValue($row['status']) . ',';
        echo escapeCsvValue($rating) . ',';
        echo escapeCsvValue($row['email']) . "\n";
    }
    exit();
}

// PDF Export
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Sit-in Reports PDF</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #2c3e50; }
            .meta { margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #34495e; color: white; }
            .leaderboard-item { padding: 10px; border-bottom: 1px solid #eee; }
            .rank { font-weight: bold; color: #f39c12; }
        </style>
    </head>
    <body>
        <h1>CCS Sit-in Reports</h1>
        <div class="meta">
            <strong>Period:</strong> <?php echo htmlspecialchars($label); ?><br>
            <strong>Date Range:</strong> <?php echo htmlspecialchars($resolvedStart . ' to ' . $resolvedEnd); ?><br>
            <strong>Total Records:</strong> <?php echo $totalRecords; ?><br>
            <strong>Unique Students:</strong> <?php echo $uniqueStudentCount; ?><br>
            <strong>Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?>
        </div>
        
        <h2>Student Leaderboard</h2>
        <?php 
        $rank = 1;
        foreach ($topStudents as $student): 
        ?>
        <div class="leaderboard-item">
            <span class="rank">#<?php echo $rank++; ?></span>
            <strong><?php echo htmlspecialchars($student['name']); ?></strong>
            <span><?php echo $student['count']; ?> sessions</span>
            <span><?php echo $student['id_number']; ?></span>
        </div>
        <?php endforeach; ?>
        
        <h2>Sit-in Records</h2>
        <table>
            <thead>
                <tr><th>Date</th><th>Student ID</th><th>Name</th><th>Course</th><th>Purpose</th><th>Lab</th><th>Time In</th><th>Time Out</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($rows, 0, 100) as $row): 
                    $fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
                    $isActive = strtolower($row['status']) === 'active' || empty($row['time_out']);
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['date']); ?></td>
                        <td><?php echo htmlspecialchars($row['id_number']); ?></td>
                        <td><?php echo htmlspecialchars($fullName); ?></td>
                        <td><?php echo htmlspecialchars($row['course'] . ' - ' . $row['year_level']); ?></td>
                        <td><?php echo htmlspecialchars($row['purpose']); ?></td>
                        <td><?php echo htmlspecialchars($row['laboratory']); ?></td>
                        <td><?php echo htmlspecialchars(formatTimeDisplay($row['time_in'])); ?></td>
                        <td><?php echo htmlspecialchars($row['time_out'] ? formatTimeDisplay($row['time_out']) : 'Active'); ?></td>
                        <td><?php echo $isActive ? 'Active' : 'Completed'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports - CCS Sit-in Monitoring</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            color: #1e293b;
            transition: all 0.2s ease;
        }
        
        /* Dark Mode */
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
        body.dark-mode .card, body.dark-mode .filters-card, body.dark-mode .table-container { background: #1e293b; border-color: #334155; }
        body.dark-mode th { background: #0f172a; color: #e2e8f0; }
        body.dark-mode td { border-bottom-color: #334155; color: #cbd5e1; }
        body.dark-mode .leaderboard-card { background: #1e293b; }
        body.dark-mode .leaderboard-item { border-bottom-color: #334155; }
        body.dark-mode .leaderboard-rank { background: #334155; }
        
        /* Layout */
        .navbar {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 250px;
            padding: 1.5rem 1rem;
            overflow-y: auto;
        }
        
        .navbar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 1.5rem;
        }
        
        .navbar-links { display: flex; flex-direction: column; gap: 0.5rem; }
        .navbar-links a {
            color: #cbd5e1;
            text-decoration: none;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: 0.2s;
        }
        .navbar-links a:hover, .navbar-links a.active { background: #3b82f6; color: white; }
        .logout-btn {
            margin-top: 2rem;
            background: #dc2626;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.75rem 1rem;
            border-radius: 12px;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 1.5rem 2rem;
        }
        
        /* Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stat-card .stat-label { font-size: 0.75rem; text-transform: uppercase; color: #64748b; margin-bottom: 0.5rem; }
        .stat-card .stat-value { font-size: 1.8rem; font-weight: 800; color: #1e293b; }
        .stat-card .stat-desc { font-size: 0.7rem; color: #94a3b8; margin-top: 0.3rem; }
        
        body.dark-mode .stat-card { background: #1e293b; }
        body.dark-mode .stat-card .stat-value { color: #f1f5f9; }
        
        .filters-card {
            background: white;
            border-radius: 20px;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }
        
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-group label { font-size: 0.75rem; font-weight: 600; color: #475569; }
        select, input {
            padding: 0.5rem 1rem;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
            font-family: inherit;
            font-size: 0.85rem;
        }
        
        .btn {
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.2rem;
            margin-bottom: 1.5rem;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 1rem;
            font-weight: 600;
            border-left: 4px solid #3b82f6;
            padding-left: 12px;
        }
        
        canvas { max-height: 200px; width: 100%; }
        
        .heatmap-container {
            background: white;
            border-radius: 20px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* LEADERBOARD STYLES - matching the image */
        .leaderboard-wrapper {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .leaderboard-card {
            background: white;
            border-radius: 24px;
            padding: 1.5rem;
            flex: 1;
            min-width: 300px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .leaderboard-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #1e293b;
            border-left: 4px solid #f59e0b;
            padding-left: 15px;
        }
        
        body.dark-mode .leaderboard-title {
            color: #f1f5f9;
        }
        
        .leaderboard-list {
            display: flex;
            flex-direction: column;
        }
        
        .leaderboard-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid #e2e8f0;
            transition: 0.2s;
        }
        
        .leaderboard-item:last-child {
            border-bottom: none;
        }
        
        .leaderboard-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .leaderboard-rank {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.2rem;
            color: white;
            box-shadow: 0 4px 10px rgba(245, 158, 11, 0.3);
        }
        
        /* Different colors for top ranks */
        .leaderboard-item:nth-child(1) .leaderboard-rank {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            box-shadow: 0 4px 15px rgba(251, 191, 36, 0.4);
        }
        
        .leaderboard-item:nth-child(2) .leaderboard-rank {
            background: linear-gradient(135deg, #94a3b8, #64748b);
        }
        
        .leaderboard-item:nth-child(3) .leaderboard-rank {
            background: linear-gradient(135deg, #cd7f32, #b87333);
        }
        
        .leaderboard-name {
            font-weight: 700;
            font-size: 1rem;
            color: #1e293b;
        }
        
        body.dark-mode .leaderboard-name {
            color: #f1f5f9;
        }
        
        .leaderboard-id {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 2px;
        }
        
        body.dark-mode .leaderboard-id {
            color: #94a3b8;
        }
        
        .leaderboard-score {
            font-weight: 800;
            font-size: 1.3rem;
            color: #f59e0b;
            background: #fef3c7;
            padding: 0.3rem 1rem;
            border-radius: 40px;
        }
        
        body.dark-mode .leaderboard-score {
            background: #422800;
            color: #fbbf24;
        }
        
        /* Table styles */
        .table-container {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            padding: 0.2rem;
        }
        
        table { width: 100%; border-collapse: collapse; font-size: 0.75rem; }
        th { text-align: left; padding: 0.8rem; background: #f8fafc; font-weight: 600; }
        td { padding: 0.7rem 0.8rem; border-bottom: 1px solid #e2e8f0; }
        
        .tag {
            padding: 0.2rem 0.6rem;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .tag-active { background: #fef3c7; color: #d97706; }
        .tag-complete { background: #d1fae5; color: #059669; }
        
        .theme-toggle {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            background: #1e293b;
            color: white;
            border: none;
            border-radius: 40px;
            padding: 0.6rem 1rem;
            cursor: pointer;
            z-index: 100;
        }
        
        @media (max-width: 768px) {
            .navbar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .leaderboard-item { flex-wrap: wrap; gap: 10px; }
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
                        <a href="admin_records.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-list"></i> View Records</a>
                        <a href="admin_reports.php" class="active" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #fff; font-weight: 500; background: #3b82f6; transition: all 0.2s;"><i class="fas fa-chart-line"></i> Report & Analytics</a>
                        <a href="admin_feedback.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-comment-dots"></i> Feedback</a>
                        <a href="admin_reservations.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-calendar-alt"></i> Reservation</a>
                    </nav>
                </div>
                <div style="padding-bottom: 2rem;">
                    <a href="admin_logout.php" style="display: flex; align-items: center; gap: 12px; background: #dc2626; color: #fff; text-decoration: none; padding: 0.75rem 1rem; border-radius: 14px; font-weight: 600; justify-content: center;"><i class="fas fa-sign-out-alt"></i> Log out</a>
                </div>
            </aside>

            <div class="main-content" style="margin-left: 250px;">
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 2rem; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-chart-line" style="color: #3b82f6;"></i> Report and Analytics
            </h1>
            <p style="color: #64748b;">Real-time insights from <?php echo $uniqueStudentCount; ?> students across <?php echo $totalRecords; ?> sit-in sessions</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-label">Total Sessions</div><div class="stat-value"><?php echo $totalRecords; ?></div><div class="stat-desc">All time</div></div>
            <div class="stat-card"><div class="stat-label">Total Hours</div><div class="stat-value"><?php echo $totalHours; ?>h</div><div class="stat-desc">Completed sessions</div></div>
            <div class="stat-card"><div class="stat-label">Avg Duration</div><div class="stat-value"><?php echo $avgMinutes; ?>m</div><div class="stat-desc">Per session</div></div>
            <div class="stat-card"><div class="stat-label">Top Lab</div><div class="stat-value"><?php echo htmlspecialchars($topLab); ?></div><div class="stat-desc">Most used</div></div>
            <div class="stat-card"><div class="stat-label">Unique Students</div><div class="stat-value"><?php echo $uniqueStudentCount; ?></div><div class="stat-desc">Total visitors</div></div>
            <div class="stat-card"><div class="stat-label">Avg Rating</div><div class="stat-value"><?php echo $avgRating; ?></div><div class="stat-desc">/5 · Satisfaction</div></div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Report Range</label>
                    <select name="period" onchange="this.form.submit()">
                        <option value="all_time" <?php echo $period === 'all_time' ? 'selected' : ''; ?>>All Time</option>
                        <option value="this_week" <?php echo $period === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="this_month" <?php echo $period === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Duration</option>
                    </select>
                </div>
                <?php if ($period === 'custom'): ?>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date ?: $resolvedStart); ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date ?: $resolvedEnd); ?>">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Generate</button>
                </div>
                <div class="form-group">
                    <a href="?period=<?php echo urlencode($period); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&format=csv" class="btn btn-success"><i class="fas fa-file-csv"></i> CSV</a>
                </div>
                <div class="form-group">
                    <a href="?period=<?php echo urlencode($period); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&format=pdf" class="btn btn-secondary" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
                </div>
            </form>
            <div style="margin-top: 12px; font-size: 0.75rem; color: #64748b;">
                <i class="far fa-calendar"></i> <?php echo htmlspecialchars($label); ?>: <?php echo htmlspecialchars($resolvedStart . ' to ' . $resolvedEnd); ?>
            </div>
        </div>

        <!-- LEADERBOARD SECTION - Like the image -->
        <div class="leaderboard-wrapper">
            <div class="leaderboard-card">
                <div class="leaderboard-title">
                    <i class="fas fa-trophy" style="color: #f59e0b;"></i> 
                    Student Leaderboard
                    <span style="font-size: 0.7rem; background: #e2e8f0; padding: 2px 8px; border-radius: 20px; margin-left: auto;">Top 10</span>
                </div>
                <div class="leaderboard-list">
                    <?php 
                    $rank = 1;
                    foreach ($topStudents as $student): 
                    ?>
                    <div class="leaderboard-item">
                        <div class="leaderboard-info">
                            <div class="leaderboard-rank"><?php echo $rank++; ?></div>
                            <div>
                                <div class="leaderboard-name"><?php echo htmlspecialchars($student['name']); ?></div>
                                <div class="leaderboard-id"><?php echo htmlspecialchars($student['id_number']); ?></div>
                            </div>
                        </div>
                        <div class="leaderboard-score"><?php echo $student['count']; ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($topStudents)): ?>
                        <div style="text-align: center; padding: 2rem; color: #64748b;">No data available for selected period</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Stats Card -->
            <div class="leaderboard-card">
                <div class="leaderboard-title">
                    <i class="fas fa-chart-simple" style="color: #3b82f6;"></i> 
                    Session Summary
                </div>
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px dashed #e2e8f0;">
                        <span><i class="fas fa-calendar-alt"></i> Period</span>
                        <span style="font-weight: 700;"><?php echo htmlspecialchars($label); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px dashed #e2e8f0;">
                        <span><i class="fas fa-users"></i> Active Students</span>
                        <span style="font-weight: 700; font-size: 1.2rem;"><?php echo $uniqueStudentCount; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px dashed #e2e8f0;">
                        <span><i class="fas fa-clock"></i> Total Sit-ins</span>
                        <span style="font-weight: 700; font-size: 1.2rem;"><?php echo $totalRecords; ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px dashed #e2e8f0;">
                        <span><i class="fas fa-hourglass-half"></i> Avg Duration</span>
                        <span style="font-weight: 700;"><?php echo $avgMinutes; ?> minutes</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;">
                        <span><i class="fas fa-star" style="color: #f59e0b;"></i> Avg Rating</span>
                        <span style="font-weight: 700; color: #f59e0b;"><?php echo $avgRating; ?> / 5</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Dashboard -->
        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header"><i class="fas fa-calendar-day"></i> Daily Sit-in Sessions</div>
                <canvas id="dailyChart"></canvas>
            </div>
            <div class="card">
                <div class="card-header"><i class="fas fa-flask"></i> Lab Utilization</div>
                <canvas id="labChart"></canvas>
            </div>
            <div class="card">
                <div class="card-header"><i class="fas fa-bullseye"></i> Purpose Breakdown</div>
                <canvas id="purposeChart"></canvas>
            </div>
            <div class="card">
                <div class="card-header"><i class="fas fa-star"></i> Satisfaction Ratings</div>
                <canvas id="ratingChart"></canvas>
            </div>
        </div>

        <!-- Heatmap -->
        <div class="heatmap-container">
            <div class="card-header"><i class="fas fa-th"></i> Activity Heatmap (Hour × Day)</div>
            <canvas id="heatmapCanvas" width="800" height="200" style="width:100%; height:auto;"></canvas>
        </div>

        <!-- Records Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Date</th><th>Student ID</th><th>Name</th><th>Course/Year</th><th>Purpose</th><th>Lab</th><th>Time In</th><th>Time Out</th><th>Duration</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($rows, 0, 50) as $row): 
                        $fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
                        $isActive = strtolower($row['status']) === 'active' || empty($row['time_out']);
                        $duration = formatDuration($row['time_in'], $row['time_out']);
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['date']); ?></td>
                            <td><?php echo htmlspecialchars($row['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($fullName); ?></td>
                            <td><?php echo htmlspecialchars($row['course'] . ' - ' . $row['year_level']); ?></td>
                            <td><?php echo htmlspecialchars($row['purpose']); ?></td>
                            <td><?php echo htmlspecialchars($row['laboratory']); ?></td>
                            <td><?php echo htmlspecialchars(formatTimeDisplay($row['time_in'])); ?></td>
                            <td><?php echo htmlspecialchars($row['time_out'] ? formatTimeDisplay($row['time_out']) : 'Active'); ?></td>
                            <td><?php echo htmlspecialchars($duration); ?></td>
                            <td><span class="tag <?php echo $isActive ? 'tag-active' : 'tag-complete'; ?>"><?php echo $isActive ? 'Active' : 'Completed'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="10" style="text-align: center;">No sit-in records found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-moon"></i> Dark</button>

    <script>
        // PHP Data to JavaScript
        const dailyLabels = <?php echo json_encode(array_keys($dailySessions)); ?>;
        const dailyData = <?php echo json_encode(array_values($dailySessions)); ?>;
        const labLabels = <?php echo json_encode(array_keys($labUtilization)); ?>;
        const labData = <?php echo json_encode(array_values($labUtilization)); ?>;
        const purposeLabels = <?php echo json_encode(array_keys($purposeBreakdown)); ?>;
        const purposeData = <?php echo json_encode(array_values($purposeBreakdown)); ?>;
        const ratingData = <?php echo json_encode([$satisfaction[1], $satisfaction[2], $satisfaction[3], $satisfaction[4], $satisfaction[5]]); ?>;
        const heatmapData = <?php echo json_encode($heatmap); ?>;

        // Initialize Charts
        if(document.getElementById('dailyChart')) {
            new Chart(document.getElementById('dailyChart'), {
                type: 'line',
                data: { labels: dailyLabels, datasets: [{ label: 'Sessions', data: dailyData, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', fill: true, tension: 0.3 }] },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }

        if(document.getElementById('labChart')) {
            new Chart(document.getElementById('labChart'), {
                type: 'bar',
                data: { labels: labLabels, datasets: [{ label: 'Utilization', data: labData, backgroundColor: '#10b981', borderRadius: 8 }] },
                options: { responsive: true }
            });
        }

        if(document.getElementById('purposeChart')) {
            new Chart(document.getElementById('purposeChart'), {
                type: 'pie',
                data: { labels: purposeLabels, datasets: [{ data: purposeData, backgroundColor: ['#3b82f6', '#f59e0b', '#ef4444', '#10b981', '#8b5cf6', '#ec4899'] }] },
                options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
            });
        }

        if(document.getElementById('ratingChart')) {
            new Chart(document.getElementById('ratingChart'), {
                type: 'bar',
                data: { labels: ['1 Star', '2 Star', '3 Star', '4 Star', '5 Star'], datasets: [{ label: 'Ratings', data: ratingData, backgroundColor: '#f59e0b', borderRadius: 8 }] },
                options: { responsive: true }
            });
        }

        // Draw Heatmap
        const canvas = document.getElementById('heatmapCanvas');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            const hours = Array.from({length: 13}, (_, i) => i + 7);
            const cellW = 38, cellH = 24;
            canvas.width = 60 + hours.length * cellW;
            canvas.height = 40 + days.length * cellH;
            
            ctx.font = '10px Inter';
            ctx.fillStyle = '#64748b';
            hours.forEach((h, i) => ctx.fillText(h + ':00', 60 + i * cellW, 18));
            days.forEach((d, i) => ctx.fillText(d, 10, 40 + i * cellH));
            
            let maxCount = 0;
            for (let d = 1; d <= 7; d++) {
                for (let h of hours) {
                    maxCount = Math.max(maxCount, (heatmapData[d] && heatmapData[d][h]) || 0);
                }
            }
            
            for (let d = 1; d <= 7; d++) {
                for (let hi = 0; hi < hours.length; hi++) {
                    let h = hours[hi];
                    let count = (heatmapData[d] && heatmapData[d][h]) || 0;
                    let intensity = maxCount ? Math.min(0.8, count / maxCount) : 0;
                    ctx.fillStyle = `rgba(59, 130, 246, ${0.15 + intensity * 0.7})`;
                    ctx.fillRect(60 + hi * cellW, 22 + (d - 1) * cellH, cellW - 2, cellH - 2);
                    if (count > 0) {
                        ctx.fillStyle = '#1e293b';
                        ctx.font = 'bold 9px Inter';
                        ctx.fillText(count, 60 + hi * cellW + cellW / 2 - 5, 22 + (d - 1) * cellH + cellH / 2 + 4);
                    }
                }
            }
            ctx.strokeStyle = '#cbd5e1';
            ctx.strokeRect(60, 22, cellW * hours.length, cellH * days.length);
        }

        // Dark Mode Toggle
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            const btn = document.querySelector('.theme-toggle');
            btn.innerHTML = isDark ? '<i class="fas fa-sun"></i> Light' : '<i class="fas fa-moon"></i> Dark';
        }
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
            document.querySelector('.theme-toggle').innerHTML = '<i class="fas fa-sun"></i> Light';
        }
    </script>
</body>
</html>