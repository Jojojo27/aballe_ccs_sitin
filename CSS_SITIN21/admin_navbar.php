<?php
if (!isset($current_page)) $current_page = '';
?>

<nav class="navbar admin-nav">
    <div class="nav-container">

        <!-- LOGO -->
        <div class="logo-container">
            <img src="ccsmainlogo.png" class="logo-img">
            <div class="logo-text">
                <strong>CCS Admin</strong>
                <small>Administration</small>
            </div>
        </div>

        <!-- NAV LINKS -->
        <div class="nav-links">
            <a href="admin_dashboard.php" class="<?php echo $current_page=='dashboard'?'active':''; ?>">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>

            <a href="admin_students.php" class="<?php echo $current_page=='students'?'active':''; ?>">
                <i class="fas fa-users"></i> Students
            </a>

            <div class="nav-dropdown">
                <a href="#" class="<?php echo ($current_page=='sitins' || $current_page=='sit_records')?'active':''; ?>">
                    <i class="fas fa-history"></i> Sit-in <i class="fas fa-caret-down"></i>
                </a>
                <div class="nav-dropdown-content">
                    <a href="admin_sitins.php" class="<?php echo $current_page=='sitins'?'active':''; ?>">
                        <i class="fas fa-desktop"></i> Current Sit-in
                    </a>
                    <a href="admin_records.php" class="<?php echo $current_page=='sit_records'?'active':''; ?>">
                        <i class="fas fa-file-alt"></i> Sit-in Records
                    </a>
                </div>
            </div>

            <a href="admin_reservations.php" class="<?php echo $current_page=='reservations'?'active':''; ?>">
                <i class="fas fa-calendar-alt"></i> Reservations
            </a>

            <a href="admin_reports.php" class="<?php echo $current_page=='reports'?'active':''; ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a>

            <a href="admin_logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>

    </div>
</nav>