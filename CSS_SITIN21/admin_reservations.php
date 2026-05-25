<?php
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

function createReservationNotification($pdo, $userId, $title, $message) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, 'reservation', 'sit_reservation.php')");
    $stmt->execute([$userId, $title, $message]);
}

function ensurePcMaintenanceTable($pdo) {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pc_maintenance'");
    $stmt->execute();
    $tableExists = $stmt->rowCount() > 0;

    if (!$tableExists) {
        $pdo->exec("CREATE TABLE pc_maintenance (
            id INT PRIMARY KEY AUTO_INCREMENT,
            laboratory VARCHAR(10) NOT NULL,
            pc_no INT NOT NULL,
            date DATE NOT NULL,
            previous_status VARCHAR(20) DEFAULT 'Vacant',
            is_active TINYINT(1) DEFAULT 1,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_pc_maintenance (laboratory, pc_no, date)
        )");
    }
}

function ensurePcUsageTable($pdo) {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pc_usage'");
    $stmt->execute();
    $tableExists = $stmt->rowCount() > 0;

    if (!$tableExists) {
        $pdo->exec("CREATE TABLE pc_usage (
            id INT PRIMARY KEY AUTO_INCREMENT,
            laboratory VARCHAR(10) NOT NULL,
            pc_no INT NOT NULL,
            date DATE NOT NULL,
            user_id INT NOT NULL,
            sitin_id INT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_pc_usage (laboratory, pc_no, date)
        )");
    }
}

function ensureLabClassTable($pdo) {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_class'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE lab_class (
            id INT PRIMARY KEY AUTO_INCREMENT,
            laboratory VARCHAR(10) NOT NULL,
            date DATE NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_lab_class (laboratory, date)
        )");
    }

    // Backward compatibility: add instructor_id for older deployments.
    $colStmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_class' AND COLUMN_NAME = 'instructor_id'");
    $colStmt->execute();
    if ($colStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE lab_class ADD COLUMN instructor_id INT NULL AFTER is_active");
    }
}

function ensureInstructorsTable($pdo) {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructors'");
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE instructors (
            id INT PRIMARY KEY AUTO_INCREMENT,
            full_name VARCHAR(120) NOT NULL,
            last_name VARCHAR(60) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
}

function cleanupOrphanedManualSitins($pdo) {
    // Close any active manual-toggle sit-ins that no longer map to an active PC usage record.
    $sql = "
        UPDATE sit_in_history s
        LEFT JOIN pc_usage p ON p.sitin_id = s.id AND p.is_active = 1
        SET s.time_out = NOW(), s.status = 'Completed'
        WHERE s.time_out IS NULL
          AND s.status = 'Active'
          AND s.purpose = 'Admin Manual PC Toggle'
          AND p.id IS NULL
    ";
    $pdo->exec($sql);
}

function combineSitInDateTimeValue($date, $time) {
    $date = trim((string) $date);
    $time = trim((string) $time);
    if ($date === '' || $time === '') {
        return null;
    }
    return $date . ' ' . $time;
}

$success_msg = '';
$error_msg = '';

try {
    ensurePcMaintenanceTable($pdo);
    ensurePcUsageTable($pdo);
    cleanupOrphanedManualSitins($pdo);
} catch (Throwable $e) {
    // Ignore table init errors here; API handlers will report details if needed
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_action'])) {
    $action = trim($_POST['reservation_action']);

    if (in_array($action, ['approve', 'reject', 'start_sitin'], true) && isset($_POST['reservation_id'])) {
        $reservationId = (int) $_POST['reservation_id'];

        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch();

        if (!$reservation) {
            $error_msg = "Reservation not found.";
        } else {
            try {
                if ($action === 'approve') {
                    if ($reservation['status'] !== 'Pending') {
                        $error_msg = "Only pending reservations can be approved.";
                    } else {
                        $stmt = $pdo->prepare("UPDATE reservations SET status = 'Approved' WHERE id = ?");
                        $stmt->execute([$reservationId]);

                        createReservationNotification(
                            $pdo,
                            (int) $reservation['user_id'],
                            'Reservation Approved',
                            'Your reservation on ' . $reservation['date'] . ' for Lab ' . $reservation['laboratory'] . ' has been approved.'
                        );

                        $success_msg = "Reservation approved successfully.";
                    }
                } elseif ($action === 'reject') {
                    if ($reservation['status'] !== 'Pending') {
                        $error_msg = "Only pending reservations can be rejected.";
                    } else {
                        $stmt = $pdo->prepare("UPDATE reservations SET status = 'Rejected' WHERE id = ?");
                        $stmt->execute([$reservationId]);

                        createReservationNotification(
                            $pdo,
                            (int) $reservation['user_id'],
                            'Reservation Rejected',
                            'Your reservation on ' . $reservation['date'] . ' for Lab ' . $reservation['laboratory'] . ' was rejected.'
                        );

                        $success_msg = "Reservation rejected.";
                    }
                } elseif ($action === 'start_sitin') {
                    if ($reservation['status'] !== 'Approved') {
                        $error_msg = "Only approved reservations can be started.";
                    } elseif ($reservation['date'] !== date('Y-m-d')) {
                        $error_msg = "Sit-in can only be started on the reserved date.";
                    } else {
                        $stmt = $pdo->prepare("SELECT remaining_sessions FROM users WHERE id = ?");
                        $stmt->execute([(int) $reservation['user_id']]);
                        $remaining = (int) $stmt->fetchColumn();

                        if ($remaining <= 0) {
                            $error_msg = "Student has no remaining sessions left.";
                        } else {
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sit_in_history WHERE user_id = ? AND time_out IS NULL");
                            $stmt->execute([(int) $reservation['user_id']]);
                            $activeCount = (int) $stmt->fetchColumn();

                            if ($activeCount > 0) {
                                $error_msg = "Student already has an active sit-in session.";
                            } else {
                                $pdo->beginTransaction();

                                $stmt = $pdo->prepare("INSERT INTO sit_in_history (user_id, purpose, laboratory, time_in, date, status) VALUES (?, ?, ?, ?, ?, 'Active')");
                                $stmt->execute([
                                    (int) $reservation['user_id'],
                                    $reservation['purpose'],
                                    $reservation['laboratory'],
                                    combineSitInDateTimeValue($reservation['date'], $reservation['time_in']),
                                    $reservation['date']
                                ]);

                                $stmt = $pdo->prepare("UPDATE users SET remaining_sessions = remaining_sessions - 1 WHERE id = ?");
                                $stmt->execute([(int) $reservation['user_id']]);

                                $stmt = $pdo->prepare("UPDATE reservations SET status = 'Completed' WHERE id = ?");
                                $stmt->execute([$reservationId]);

                                createReservationNotification(
                                    $pdo,
                                    (int) $reservation['user_id'],
                                    'Sit-in Started',
                                    'Your approved reservation has been converted to an active sit-in session.'
                                );

                                $pdo->commit();
                                $success_msg = "Sit-in started successfully from reservation.";
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "Action failed. Please try again.";
            }
        }
    } elseif ($action === 'set_vacant' && isset($_POST['sitin_id'])) {
        $sitinId = (int) $_POST['sitin_id'];
        $stmt = $pdo->prepare("UPDATE sit_in_history SET time_out = NOW(), status = 'Completed' WHERE id = ? AND time_out IS NULL");
        $stmt->execute([$sitinId]);

        if ($stmt->rowCount() > 0) {
            $success_msg = "PC status updated to Vacant (active sit-in ended).";
        } else {
            $error_msg = "Unable to set PC as vacant.";
        }
    }
}

// Handle AJAX: toggle class-in-session for a lab
if (isset($_GET['action']) && $_GET['action'] === 'toggle_class_status') {
    header('Content-Type: application/json');
    $lab  = isset($_POST['lab'])  ? trim($_POST['lab'])  : '';
    $date = isset($_POST['date']) ? trim($_POST['date']) : date('Y-m-d');
    $instructorId = isset($_POST['instructorId']) ? (int)$_POST['instructorId'] : 0;
    $allowedLabsAjax = ['523','524','525','526','527','528','529','530'];
    if (!in_array($lab, $allowedLabsAjax, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['error' => 'Invalid parameters']);
        exit();
    }
    try {
        ensureLabClassTable($pdo);
        ensureInstructorsTable($pdo);

        if ($instructorId > 0) {
            $validInsStmt = $pdo->prepare("SELECT COUNT(*) FROM instructors WHERE id = ? AND is_active = 1");
            $validInsStmt->execute([$instructorId]);
            if ((int)$validInsStmt->fetchColumn() === 0) {
                echo json_encode(['error' => 'Selected instructor is not available']);
                exit();
            }
        }

        $stmt = $pdo->prepare("SELECT is_active FROM lab_class WHERE laboratory = ? AND date = ?");
        $stmt->execute([$lab, $date]);
        $row = $stmt->fetch();
        if ($row) {
            $newActive = $row['is_active'] ? 0 : 1;
            if ($newActive === 1 && $instructorId <= 0) {
                echo json_encode(['error' => 'Please select an instructor before starting class']);
                exit();
            }
            $pdo->prepare("UPDATE lab_class SET is_active = ?, instructor_id = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE laboratory = ? AND date = ?")
                ->execute([$newActive, $newActive === 1 ? $instructorId : null, (int)$_SESSION['admin_id'], $lab, $date]);
        } else {
            if ($instructorId <= 0) {
                echo json_encode(['error' => 'Please select an instructor before starting class']);
                exit();
            }
            $newActive = 1;
            $pdo->prepare("INSERT INTO lab_class (laboratory, date, is_active, instructor_id, updated_by) VALUES (?, ?, 1, ?, ?)")
                ->execute([$lab, $date, $instructorId, (int)$_SESSION['admin_id']]);
        }

        $insLastName = '';
        if ($newActive === 1) {
            $insStmt = $pdo->prepare("SELECT last_name FROM instructors WHERE id = ? AND is_active = 1 LIMIT 1");
            $insStmt->execute([$instructorId]);
            $insLastName = (string)($insStmt->fetchColumn() ?: '');
        }

        echo json_encode([
            'success' => true,
            'class_in_session' => (bool)$newActive,
            'instructor_last_name' => $insLastName
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX: add instructor
if (isset($_GET['action']) && $_GET['action'] === 'add_instructor') {
    header('Content-Type: application/json');
    $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
    if ($name === '') {
        echo json_encode(['error' => 'Instructor name is required']);
        exit();
    }
    try {
        ensureInstructorsTable($pdo);
        $parts = preg_split('/\s+/', $name);
        $lastName = trim((string)end($parts));
        if ($lastName === '') {
            $lastName = $name;
        }
        $stmt = $pdo->prepare("INSERT INTO instructors (full_name, last_name, is_active) VALUES (?, ?, 1)");
        $stmt->execute([$name, $lastName]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'full_name' => $name, 'last_name' => $lastName]);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX: remove instructor
if (isset($_GET['action']) && $_GET['action'] === 'remove_instructor') {
    header('Content-Type: application/json');
    $instructorId = isset($_POST['instructorId']) ? (int)$_POST['instructorId'] : 0;
    if ($instructorId <= 0) {
        echo json_encode(['error' => 'Invalid instructor']);
        exit();
    }
    try {
        ensureInstructorsTable($pdo);
        ensureLabClassTable($pdo);
        // Prevent deleting an instructor that is currently assigned to an active class.
        $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM lab_class WHERE instructor_id = ? AND is_active = 1");
        $activeStmt->execute([$instructorId]);
        if ((int)$activeStmt->fetchColumn() > 0) {
            echo json_encode(['error' => 'Cannot remove instructor while assigned to an active class']);
            exit();
        }
        $stmt = $pdo->prepare("UPDATE instructors SET is_active = 0 WHERE id = ?");
        $stmt->execute([$instructorId]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX requests to get PC status for a lab
if (isset($_GET['action']) && $_GET['action'] === 'get_pc_status') {
    header('Content-Type: application/json');

    $reqLab = isset($_GET['lab']) ? trim($_GET['lab']) : '';
    $reqDate = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
    $allowedLabsAjax = ['523', '524', '525', '526', '527', '528', '529', '530'];

    if (!in_array($reqLab, $allowedLabsAjax, true)) {
        echo json_encode(['error' => 'Invalid lab']);
        exit();
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) {
        $reqDate = date('Y-m-d');
    }

    try {
        ensurePcUsageTable($pdo);
        ensurePcMaintenanceTable($pdo);
        ensureLabClassTable($pdo);
        ensureInstructorsTable($pdo);

        // Get pc_count for the lab
        $stmtLab = $pdo->prepare("SELECT pc_count FROM laboratories WHERE lab_number = ?");
        $stmtLab->execute([$reqLab]);
        $labRow = $stmtLab->fetch();
        $reqPcCount = $labRow ? (int)$labRow['pc_count'] : 50;

        // Build default slots
        $slots = [];
        for ($i = 1; $i <= $reqPcCount; $i++) {
            $slots[$i] = ['status' => 'Vacant', 'owner' => ''];
        }

        // Apply in-use from pc_usage
        $usageSt = $pdo->prepare("SELECT p.pc_no, u.first_name, u.last_name
                                   FROM pc_usage p
                                   INNER JOIN users u ON p.user_id = u.id
                                   WHERE p.laboratory = ? AND p.date = ? AND p.is_active = 1");
        $usageSt->execute([$reqLab, $reqDate]);
        foreach ($usageSt->fetchAll() as $uRow) {
            $n = (int)$uRow['pc_no'];
            if ($n >= 1 && $n <= $reqPcCount) {
                $slots[$n] = ['status' => 'In-Use', 'owner' => trim($uRow['first_name'] . ' ' . $uRow['last_name'])];
            }
        }

        // Apply maintenance overrides
        $maintSt = $pdo->prepare("SELECT pc_no FROM pc_maintenance WHERE laboratory = ? AND date = ? AND is_active = 1");
        $maintSt->execute([$reqLab, $reqDate]);
        foreach ($maintSt->fetchAll() as $mRow) {
            $n = (int)$mRow['pc_no'];
            if ($n >= 1 && $n <= $reqPcCount) {
                $slots[$n] = ['status' => 'Maintenance', 'owner' => ''];
            }
        }

        // Check if class is in session for this lab
        $classSt = $pdo->prepare("SELECT is_active, instructor_id FROM lab_class WHERE laboratory = ? AND date = ? LIMIT 1");
        $classSt->execute([$reqLab, $reqDate]);
        $classRow = $classSt->fetch();
        $classInSession = ($classRow && (int)$classRow['is_active'] === 1);
        $classInstructorId = $classRow ? (int)($classRow['instructor_id'] ?? 0) : 0;
        $classInstructorLastName = '';
        if ($classInstructorId > 0) {
            $insLastStmt = $pdo->prepare("SELECT last_name FROM instructors WHERE id = ? AND is_active = 1 LIMIT 1");
            $insLastStmt->execute([$classInstructorId]);
            $classInstructorLastName = (string)($insLastStmt->fetchColumn() ?: '');
        }

        $insListStmt = $pdo->prepare("SELECT id, full_name, last_name FROM instructors WHERE is_active = 1 ORDER BY last_name ASC, full_name ASC");
        $insListStmt->execute();
        $instructors = $insListStmt->fetchAll();

        // If class is in session, override all slots
        if ($classInSession) {
            for ($i = 1; $i <= $reqPcCount; $i++) {
                $slots[$i] = ['status' => 'In-Class', 'owner' => '', 'action' => ($classInstructorLastName !== '' ? ('Instructor: ' . $classInstructorLastName) : 'Instructor: N/A')];
            }
        }

        // Build summary counts
        $counts = ['Vacant' => 0, 'In-Use' => 0, 'Reserved' => 0, 'Pending' => 0, 'Maintenance' => 0, 'In-Class' => 0];
        foreach ($slots as $s) {
            $st = $s['status'];
            if (isset($counts[$st])) $counts[$st]++;
        }

        echo json_encode([
            'success' => true,
            'lab' => $reqLab,
            'date' => $reqDate,
            'pcCount' => $reqPcCount,
            'class_in_session' => $classInSession,
            'class_instructor_id' => $classInstructorId,
            'class_instructor_last_name' => $classInstructorLastName,
            'instructors' => $instructors,
            'slots' => $slots,
            'counts' => $counts
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX requests for PC toggle
if (isset($_GET['action']) && $_GET['action'] === 'toggle_pc_status') {
    header('Content-Type: application/json');
    
    $pcNo = isset($_POST['pcNo']) ? (int) $_POST['pcNo'] : null;
    $lab = isset($_POST['lab']) ? trim($_POST['lab']) : null;
    $date = isset($_POST['date']) ? trim($_POST['date']) : null;
    $currentStatus = isset($_POST['currentStatus']) ? trim($_POST['currentStatus']) : null;
    
    if (!$pcNo || !$lab || !$date || !$currentStatus) {
        echo json_encode(['error' => 'Invalid parameters']);
        exit();
    }
    
    $newStatus = '';
    $response = ['success' => false, 'newStatus' => '', 'message' => ''];
    
    try {
        // If Vacant, mark as In-Use by finding/creating sit-in
        if ($currentStatus === 'Vacant') {
            // Get the first student who has a reservation for this lab/date to use their ID
            // or simply close any existing sit-ins to free up the PC
            $stmt = $pdo->prepare("SELECT user_id FROM reservations 
                                  WHERE laboratory = ? AND date = ? AND status IN ('Approved', 'Pending')
                                  LIMIT 1");
            $stmt->execute([$lab, $date]);
            $reservedUser = $stmt->fetch();
            
            if ($reservedUser) {
                // Use the student ID from reservation
                $userId = (int) $reservedUser['user_id'];
            } else {
                // Use first available student from users table
                $stmt = $pdo->prepare("SELECT id FROM users LIMIT 1");
                $stmt->execute();
                $user = $stmt->fetch();
                if ($user) {
                    $userId = (int) $user['id'];
                } else {
                    throw new Exception("No users available in system");
                }
            }
            
            ensurePcUsageTable($pdo);

            // Prevent assigning the same student to multiple active PCs on the same date.
            $stmt = $pdo->prepare("SELECT laboratory, pc_no FROM pc_usage WHERE user_id = ? AND date = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$userId, $date]);
            $existingUserPc = $stmt->fetch();
            if ($existingUserPc && ((string)$existingUserPc['laboratory'] !== (string)$lab || (int)$existingUserPc['pc_no'] !== (int)$pcNo)) {
                $response['error'] = 'This student is already assigned to PC ' . str_pad((string)$existingUserPc['pc_no'], 2, '0', STR_PAD_LEFT) . ' (Lab ' . $existingUserPc['laboratory'] . ').';
                echo json_encode($response);
                exit();
            }

            // Reuse an existing active sit-in row for this user/date/lab if present to avoid duplicates.
            $stmt = $pdo->prepare("SELECT id FROM sit_in_history WHERE user_id = ? AND laboratory = ? AND date = ? AND time_out IS NULL AND status = 'Active' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId, $lab, $date]);
            $existingSitin = $stmt->fetch();

            if ($existingSitin) {
                $sitinId = (int)$existingSitin['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO sit_in_history (user_id, purpose, laboratory, time_in, date, status) 
                                      VALUES (?, 'Admin Manual PC Toggle', ?, ?, ?, 'Active')");
                $stmt->execute([$userId, $lab, combineSitInDateTimeValue($date, date('H:i:s')), $date]);
                $sitinId = (int) $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("INSERT INTO pc_usage (laboratory, pc_no, date, user_id, sitin_id, is_active)
                                  VALUES (?, ?, ?, ?, ?, 1)
                                  ON DUPLICATE KEY UPDATE
                                      user_id = VALUES(user_id),
                                      sitin_id = VALUES(sitin_id),
                                      is_active = 1,
                                      updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$lab, $pcNo, $date, $userId, $sitinId]);
            
            $response['success'] = true;
            $response['newStatus'] = 'In-Use';
            $response['message'] = 'PC marked as In-Use';
            
        } elseif ($currentStatus === 'In-Use') {
            ensurePcUsageTable($pdo);

            $stmt = $pdo->prepare("SELECT sitin_id FROM pc_usage WHERE laboratory = ? AND pc_no = ? AND date = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$lab, $pcNo, $date]);
            $pcUsage = $stmt->fetch();

            $sitinId = $pcUsage ? (int) ($pcUsage['sitin_id'] ?? 0) : 0;

            $stmt = $pdo->prepare("UPDATE pc_usage SET is_active = 0 WHERE laboratory = ? AND pc_no = ? AND date = ?");
            $stmt->execute([$lab, $pcNo, $date]);

            if ($sitinId > 0) {
                $stmt = $pdo->prepare("UPDATE sit_in_history 
                                      SET time_out = NOW(), status = 'Completed' 
                                      WHERE id = ? AND time_out IS NULL");
                $stmt->execute([$sitinId]);
            }

            $response['success'] = true;
            $response['newStatus'] = 'Vacant';
            $response['message'] = 'PC marked as Vacant';
        }
    } catch (Throwable $e) {
        $response['error'] = 'Database error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// Handle AJAX requests for maintenance toggle
if (isset($_GET['action']) && $_GET['action'] === 'toggle_maintenance_status') {
    header('Content-Type: application/json');

    $pcNo = isset($_POST['pcNo']) ? (int) $_POST['pcNo'] : null;
    $lab = isset($_POST['lab']) ? trim($_POST['lab']) : null;
    $date = isset($_POST['date']) ? trim($_POST['date']) : null;
    $currentStatus = isset($_POST['currentStatus']) ? trim($_POST['currentStatus']) : null;

    if (!$pcNo || !$lab || !$date || !$currentStatus) {
        echo json_encode(['error' => 'Invalid parameters']);
        exit();
    }

    try {
        ensurePcMaintenanceTable($pdo);

        if ($currentStatus === 'Maintenance') {
            $stmt = $pdo->prepare("SELECT previous_status FROM pc_maintenance WHERE laboratory = ? AND pc_no = ? AND date = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$lab, $pcNo, $date]);
            $row = $stmt->fetch();

            $fallbackStatus = 'Vacant';
            if ($row && in_array($row['previous_status'], ['Vacant', 'In-Use', 'Reserved', 'Pending'], true)) {
                $fallbackStatus = $row['previous_status'];
            }

            $stmt = $pdo->prepare("UPDATE pc_maintenance SET is_active = 0, updated_by = ? WHERE laboratory = ? AND pc_no = ? AND date = ?");
            $stmt->execute([(int) $_SESSION['admin_id'], $lab, $pcNo, $date]);

            echo json_encode([
                'success' => true,
                'newStatus' => $fallbackStatus,
                'message' => 'PC removed from maintenance'
            ]);
        } else {
            $previousStatus = in_array($currentStatus, ['Vacant', 'In-Use', 'Reserved', 'Pending'], true) ? $currentStatus : 'Vacant';
            $stmt = $pdo->prepare("INSERT INTO pc_maintenance (laboratory, pc_no, date, previous_status, is_active, updated_by)
                                   VALUES (?, ?, ?, ?, 1, ?)
                                   ON DUPLICATE KEY UPDATE
                                       previous_status = VALUES(previous_status),
                                       is_active = 1,
                                       updated_by = VALUES(updated_by),
                                       updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$lab, $pcNo, $date, $previousStatus, (int) $_SESSION['admin_id']]);

            echo json_encode([
                'success' => true,
                'newStatus' => 'Maintenance',
                'message' => 'PC marked as maintenance'
            ]);
        }
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX requests to update PC count
if (isset($_GET['action']) && $_GET['action'] === 'update_pc_count') {
    header('Content-Type: application/json');
    
    $lab = isset($_POST['lab']) ? trim($_POST['lab']) : null;
    $pcCount = isset($_POST['pcCount']) ? (int) $_POST['pcCount'] : null;
    
    if (!$lab || $pcCount < 1 || $pcCount > 100) {
        echo json_encode(['error' => 'Invalid lab or PC count']);
        exit();
    }
    
    try {
        // Check if laboratories table exists
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laboratories'");
        $stmt->execute();
        $tableExists = $stmt->rowCount() > 0;
        
        if (!$tableExists) {
            // Create laboratories table
            $pdo->exec("CREATE TABLE laboratories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                lab_number VARCHAR(10) UNIQUE NOT NULL,
                pc_count INT DEFAULT 50,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
        }
        
        // Insert or update lab configuration
        $stmt = $pdo->prepare("INSERT INTO laboratories (lab_number, pc_count) VALUES (?, ?) 
                              ON DUPLICATE KEY UPDATE pc_count = ?");
        $stmt->execute([$lab, $pcCount, $pcCount]);
        
        echo json_encode(['success' => true, 'message' => "Lab $lab updated to $pcCount PCs"]);
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Initialize laboratories table with default values
try {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'laboratories'");
    $stmt->execute();
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE laboratories (
            id INT PRIMARY KEY AUTO_INCREMENT,
            lab_number VARCHAR(10) UNIQUE NOT NULL,
            pc_count INT DEFAULT 50,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // Insert default labs
        $defaultLabs = ['523', '524', '525', '526', '527', '528', '529', '530'];
        $stmt = $pdo->prepare("INSERT INTO laboratories (lab_number, pc_count) VALUES (?, 50)");
        foreach ($defaultLabs as $labNum) {
            $stmt->execute([$labNum]);
        }
    }
} catch (Throwable $e) {
    // Table might already exist, ignore
}

try {
    ensurePcUsageTable($pdo);
} catch (Throwable $e) {
    // ignore
try {
    ensureLabClassTable($pdo);
} catch (Throwable $e) {
    // ignore
}

}

$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$allowedFilters = ['all', 'Pending', 'Approved', 'Rejected', 'Completed'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

$pcLab = isset($_GET['pc_lab']) ? trim($_GET['pc_lab']) : '530';
$allowedLabs = ['523', '524', '525', '526', '527', '528', '529', '530'];
if (!in_array($pcLab, $allowedLabs, true)) {
    $pcLab = '530';
}

$pcDate = isset($_GET['pc_date']) ? trim($_GET['pc_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pcDate)) {
    $pcDate = date('Y-m-d');
}

$stats = [
    'Pending' => 0,
    'Approved' => 0,
    'Rejected' => 0,
    'Completed' => 0,
    'Total' => 0
];

$statusRows = $pdo->query("SELECT status, COUNT(*) AS total FROM reservations GROUP BY status")->fetchAll();
foreach ($statusRows as $row) {
    if (isset($stats[$row['status']])) {
        $stats[$row['status']] = (int) $row['total'];
    }
    $stats['Total'] += (int) $row['total'];
}

$sql = "
    SELECT
        r.*,
        u.id_number,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.course,
        u.year_level,
        u.email,
        u.remaining_sessions
    FROM reservations r
    INNER JOIN users u ON r.user_id = u.id
";

$params = [];
if ($statusFilter !== 'all') {
    $sql .= " WHERE r.status = :status ";
    $params['status'] = $statusFilter;
}

$sql .= " ORDER BY
    CASE r.status
        WHEN 'Pending' THEN 1
        WHEN 'Approved' THEN 2
        WHEN 'Completed' THEN 3
        WHEN 'Rejected' THEN 4
        ELSE 5
    END,
    r.date ASC,
    r.time_in ASC,
    r.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Fetch PC count for selected lab from database
$stmt = $pdo->prepare("SELECT pc_count FROM laboratories WHERE lab_number = ?");
$stmt->execute([$pcLab]);
$labConfig = $stmt->fetch();
$pcCount = $labConfig ? (int)$labConfig['pc_count'] : 50;

$pcSlots = [];
for ($i = 1; $i <= $pcCount; $i++) {
    $pcSlots[$i] = [
        'status' => 'Vacant',
        'chip_class' => 'chip-vacant',
        'owner' => '',
        'action' => '',
        'sitin_id' => null
    ];
}

// Only explicit PC assignments should appear as In-Use
try {
    ensurePcUsageTable($pdo);
    $usageStmt = $pdo->prepare("SELECT p.pc_no, p.sitin_id, u.first_name, u.last_name
                               FROM pc_usage p
                               INNER JOIN users u ON p.user_id = u.id
                               WHERE p.laboratory = ? AND p.date = ? AND p.is_active = 1
                               ORDER BY p.pc_no ASC");
    $usageStmt->execute([$pcLab, $pcDate]);
    $pcUsages = $usageStmt->fetchAll();

    foreach ($pcUsages as $usage) {
        $usagePcNo = (int) $usage['pc_no'];
        if ($usagePcNo >= 1 && $usagePcNo <= $pcCount) {
            $pcSlots[$usagePcNo] = [
                'status' => 'In-Use',
                'chip_class' => 'chip-inuse',
                'owner' => trim($usage['first_name'] . ' ' . $usage['last_name']),
                'action' => 'Click to set Vacant',
                'sitin_id' => isset($usage['sitin_id']) ? (int) $usage['sitin_id'] : null
            ];
        }
    }
} catch (Throwable $e) {
    // Keep default Vacant slots if usage table is unavailable
}

// Apply maintenance overrides by exact PC number
try {
    $maintStmt = $pdo->prepare("SELECT pc_no FROM pc_maintenance WHERE laboratory = ? AND date = ? AND is_active = 1");
    $maintStmt->execute([$pcLab, $pcDate]);
    $maintenanceRows = $maintStmt->fetchAll();

    foreach ($maintenanceRows as $mRow) {
        $maintenancePcNo = (int) $mRow['pc_no'];
        if ($maintenancePcNo >= 1 && $maintenancePcNo <= $pcCount) {
            $pcSlots[$maintenancePcNo] = [
                'status' => 'Maintenance',
                'chip_class' => 'chip-maintenance',
                'owner' => '',
                'action' => 'Under Maintenance',
                'sitin_id' => null
            ];
        }
    }
} catch (Throwable $e) {
    // If maintenance table is unavailable, continue without overrides
}

// Check if class is in session for initial render
$pcClassInSession = false;
$pcClassInstructorId = 0;
$pcClassInstructorLastName = '';
$instructors = [];
try {
    $insInitStmt = $pdo->prepare("SELECT id, full_name, last_name FROM instructors WHERE is_active = 1 ORDER BY last_name ASC, full_name ASC");
    $insInitStmt->execute();
    $instructors = $insInitStmt->fetchAll();
} catch (Throwable $e) {
    $instructors = [];
}

try {
    $classCheck = $pdo->prepare("SELECT is_active, instructor_id FROM lab_class WHERE laboratory = ? AND date = ? LIMIT 1");
    $classCheck->execute([$pcLab, $pcDate]);
    $classRow2 = $classCheck->fetch();
    $pcClassInSession = ($classRow2 && (int)$classRow2['is_active'] === 1);
    $pcClassInstructorId = $classRow2 ? (int)($classRow2['instructor_id'] ?? 0) : 0;
    if ($pcClassInstructorId > 0) {
        $insActiveStmt = $pdo->prepare("SELECT last_name FROM instructors WHERE id = ? AND is_active = 1 LIMIT 1");
        $insActiveStmt->execute([$pcClassInstructorId]);
        $pcClassInstructorLastName = (string)($insActiveStmt->fetchColumn() ?: '');
    }
    if ($pcClassInSession) {
        for ($i = 1; $i <= $pcCount; $i++) {
            $pcSlots[$i] = ['status' => 'In-Class', 'chip_class' => 'chip-inclass', 'owner' => '', 'action' => ($pcClassInstructorLastName !== '' ? ('Instructor: ' . $pcClassInstructorLastName) : 'Instructor: N/A'), 'sitin_id' => null];
        }
    }
} catch (Throwable $e) {
    // ignore
}

$vacantCount = 0;
$inUseCount = 0;
$reservedCount = 0;
$pendingCount = 0;
$maintenanceCount = 0;
$inClassCount = 0;

foreach ($pcSlots as $slot) {
    if ($slot['status'] === 'Vacant') {
        $vacantCount++;
    } elseif ($slot['status'] === 'In-Use') {
        $inUseCount++;
    } elseif ($slot['status'] === 'Reserved') {
        $reservedCount++;
    } elseif ($slot['status'] === 'Pending') {
        $pendingCount++;
    } elseif ($slot['status'] === 'Maintenance') {
        $maintenanceCount++;
    } elseif ($slot['status'] === 'In-Class') {
        $inClassCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Management - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html { font-size: 13px; zoom: 1; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: #f0f2f5; }

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

        .main-content {
            margin-left: 220px;
            padding: 1.6rem;
            min-height: 100vh;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.8rem;
        }

        .page-header h1 {
            font-size: 2rem;
            color: #1f2d3d;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
        }

        .alert {
            padding: 0.8rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(130px, 1fr));
            gap: 0.7rem;
            margin-bottom: 0.8rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 0.8rem 0.9rem;
            box-shadow: 0 2px 9px rgba(0,0,0,0.08);
        }

        .stat-label { color: #6b6f76; font-size: 0.76rem; margin-bottom: 0.2rem; }
        .stat-value { color: #111; font-size: 1.4rem; font-weight: 700; }

        .layout-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 330px;
            gap: 0.9rem;
            align-items: start;
        }

        .left-card,
        .right-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .toolbar {
            padding: 0.8rem;
            border-bottom: 1px solid #ecf0f5;
            display: flex;
            gap: 0.6rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .toolbar .group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            min-width: 170px;
        }

        .toolbar label { font-size: 0.76rem; color: #444; font-weight: 500; }

        .toolbar select,
        .toolbar input {
            border: 2px solid #e0e7ff;
            border-radius: 8px;
            padding: 0.48rem 0.58rem;
            font-family: 'Poppins', sans-serif;
            font-size: 0.82rem;
        }

        .btn {
            border: none;
            border-radius: 7px;
            padding: 0.53rem 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            font-weight: 600;
            font-size: 0.78rem;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-danger { background: #e74c3c; color: white; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        th {
            background: #2f445d;
            color: white;
            padding: 0.65rem;
            text-align: left;

        try {
            ensureInstructorsTable($pdo);
        } catch (Throwable $e) {
            // ignore
        }
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        td {
            padding: 0.62rem;
            border-bottom: 1px solid #e9eef6;
            font-size: 0.76rem;
            color: #1f2937;
            vertical-align: top;
        }

        tr:hover td { background: #f7fbff; }

        .badge {
            border-radius: 999px;
            padding: 0.16rem 0.5rem;
            font-size: 0.66rem;
            font-weight: 700;
            display: inline-block;
            text-transform: uppercase;
        }

        .badge-pending { background: #ffe9b8; color: #8a5d00; }
        .badge-approved { background: #daf5df; color: #0f7333; }
        .badge-rejected { background: #fddede; color: #8b1d1d; }
        .badge-completed { background: #d8ecff; color: #1b5f9c; }

        .actions {
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        .empty {
            text-align: center;
            color: #666;
            padding: 2rem;
        }

        .right-header {
            padding: 0.85rem 0.9rem;
            border-bottom: 1px solid #ecf0f5;
        }

        .right-header h2 {
            font-size: 1rem;
            color: #1f2d3d;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .right-body {
            padding: 0.8rem;
        }

        .pc-list {
            max-height: 530px;
            overflow-y: auto;
            margin-top: 0.7rem;
            padding-right: 0.2rem;
        }

        .pc-item {
            border: 1px solid #e7edf6;
            border-radius: 10px;
            padding: 0.5rem 0.58rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.55rem;
            background: #fbfdff;
            transition: all 0.2s ease;
        }

        .pc-item.clickable {
            cursor: pointer;
        }

        .pc-item.clickable:hover {
            border-color: #3498db;
            background: #e8f4ff;
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.15);
            transform: translateY(-1px);
        }

        .pc-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .pc-name {
            font-size: 0.8rem;
            color: #1f2d3d;
            font-weight: 500;
        }

        .pc-owner {
            font-size: 0.65rem;
            color: #5f6b76;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 140px;
        }

        .chip {
            border-radius: 999px;
            font-size: 0.66rem;
            font-weight: 700;
            padding: 0.16rem 0.44rem;
            color: white;
            white-space: nowrap;
        }

        .chip-vacant { background: #2eaf5d; }
        .chip-inuse { background: #cf9800; }
        .chip-reserved { background: #2f6fc6; }
        .chip-pending { background: #7a8594; }

        .pc-action {
            margin-top: 0.2rem;
            font-size: 0.6rem;
            color: #4c5a68;
            background: #eaf0f7;
            border-radius: 5px;
            padding: 0.13rem 0.35rem;
            border: none;
            cursor: pointer;
        }

        .pc-summary {
            margin-top: 0.7rem;
            padding-top: 0.6rem;
            border-top: 1px solid #e7edf6;
            font-size: 0.73rem;
            color: #354354;
            line-height: 1.5;
        }

        .pc-note {
            margin-top: 0.6rem;
            font-size: 0.65rem;
            color: #5b6470;
            background: #f5f8fc;
            border-radius: 8px;
            padding: 0.45rem 0.55rem;
            border: 1px solid #e7edf6;
        }

        @media (max-width: 1250px) {
            .layout-grid { grid-template-columns: 1fr; }
            .pc-list { max-height: 320px; }
        }

        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
            .navbar-links { justify-content: center; }
            .main-content { margin-top: 120px; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, minmax(100px, 1fr)); }
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
                        <a href="admin_reports.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-chart-line"></i> Report & Analytics</a>
                        <a href="admin_feedback.php" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #cbd5e1; font-weight: 500; transition: all 0.2s;"><i class="fas fa-comment-dots"></i> Feedback</a>
                        <a href="admin_reservations.php" class="active" style="display: flex; align-items: center; gap: 12px; padding: 0.75rem 1rem; border-radius: 14px; text-decoration: none; color: #fff; font-weight: 500; background: #3b82f6; transition: all 0.2s;"><i class="fas fa-calendar-alt"></i> Reservation</a>
                    </nav>
                </div>
                <div style="padding-bottom: 2rem;">
                    <a href="admin_logout.php" style="display: flex; align-items: center; gap: 12px; background: #dc2626; color: #fff; text-decoration: none; padding: 0.75rem 1rem; border-radius: 14px; font-weight: 600; justify-content: center;"><i class="fas fa-sign-out-alt"></i> Log out</a>
                </div>
            </aside>

            <div class="main-content" style="margin-left: 250px;">

    <main class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-calendar-check"></i> Reservation Management</h1>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card"><div class="stat-label">Total</div><div class="stat-value"><?php echo $stats['Total']; ?></div></div>
            <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value"><?php echo $stats['Pending']; ?></div></div>
            <div class="stat-card"><div class="stat-label">Approved</div><div class="stat-value"><?php echo $stats['Approved']; ?></div></div>
            <div class="stat-card"><div class="stat-label">Completed</div><div class="stat-value"><?php echo $stats['Completed']; ?></div></div>
            <div class="stat-card"><div class="stat-label">Rejected</div><div class="stat-value"><?php echo $stats['Rejected']; ?></div></div>
        </section>

        <section class="layout-grid">
            <div class="left-card">
                <form class="toolbar" method="GET" action="admin_reservations.php">
                    <div class="group">
                        <label for="status">Filter by status</label>
                        <select name="status" id="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Approved" <?php echo $statusFilter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Rejected" <?php echo $statusFilter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="group">
                        <label for="pc_lab">Laboratory Select</label>
                        <select name="pc_lab" id="pc_lab">
                            <?php foreach ($allowedLabs as $lab): ?>
                                <option value="<?php echo $lab; ?>" <?php echo $pcLab === $lab ? 'selected' : ''; ?>>Laboratory <?php echo $lab; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="group">
                        <label for="pc_date">Date</label>
                        <input type="date" name="pc_date" id="pc_date" value="<?php echo htmlspecialchars($pcDate); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filter</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Course/Year</th>
                                <th>Purpose</th>
                                <th>Laboratory</th>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Sessions Left</th>
                                <th>Status</th>
                                <th>Requested At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reservations)): ?>
                                <tr>
                                    <td colspan="11" class="empty">No reservations found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reservations as $reservation): ?>
                                    <?php
                                        $studentName = trim($reservation['first_name'] . ' ' . ($reservation['middle_name'] ? $reservation['middle_name'] . ' ' : '') . $reservation['last_name']);
                                        $status = $reservation['status'];
                                        $badgeClass = 'badge-pending';
                                        if ($status === 'Approved') {
                                            $badgeClass = 'badge-approved';
                                        } elseif ($status === 'Rejected') {
                                            $badgeClass = 'badge-rejected';
                                        } elseif ($status === 'Completed') {
                                            $badgeClass = 'badge-completed';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($studentName); ?></strong><br>
                                            <small><?php echo htmlspecialchars($reservation['id_number']); ?></small><br>
                                            <small><?php echo htmlspecialchars($reservation['email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($reservation['course'] . ' - ' . $reservation['year_level']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['purpose']); ?></td>
                                        <td>Lab <?php echo htmlspecialchars($reservation['laboratory']); ?></td>
                                        <td><?php echo htmlspecialchars($reservation['date']); ?></td>
                                        <td><?php echo htmlspecialchars(date('g:i A', strtotime($reservation['time_in']))); ?></td>
                                        <td><?php echo htmlspecialchars(date('g:i A', strtotime($reservation['time_out']))); ?></td>
                                        <td><?php echo (int) $reservation['remaining_sessions']; ?></td>
                                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                        <td><?php echo htmlspecialchars('Requested at ' . date('g:i A', strtotime($reservation['created_at']))); ?></td>
                                        <td>
                                            <div class="actions">
                                                <?php if ($status === 'Pending'): ?>
                                                    <form method="POST" onsubmit="return confirm('Approve this reservation?');">
                                                        <input type="hidden" name="reservation_id" value="<?php echo (int) $reservation['id']; ?>">
                                                        <input type="hidden" name="reservation_action" value="approve">
                                                        <button type="submit" class="btn btn-success">Approve</button>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Reject this reservation?');">
                                                        <input type="hidden" name="reservation_id" value="<?php echo (int) $reservation['id']; ?>">
                                                        <input type="hidden" name="reservation_action" value="reject">
                                                        <button type="submit" class="btn btn-danger">Reject</button>
                                                    </form>
                                                <?php elseif ($status === 'Approved'): ?>
                                                    <form method="POST" onsubmit="return confirm('Start sit-in from this approved reservation?');">
                                                        <input type="hidden" name="reservation_id" value="<?php echo (int) $reservation['id']; ?>">
                                                        <input type="hidden" name="reservation_action" value="start_sitin">
                                                        <button type="submit" class="btn btn-warning">Start Sit-in</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="color:#666;font-size:0.72rem;">No actions</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PC Control Modal Button -->
            <div style="text-align:center;margin-top:1.5rem;">
                <button onclick="openPCControlModal()" style="padding:0.8rem 1.5rem;background:#27ae60;color:white;border:none;border-radius:50px;cursor:pointer;font-size:1rem;font-weight:600;display:inline-flex;align-items:center;gap:0.5rem;box-shadow:0 4px 12px rgba(39,174,96,0.3);transition:all 0.3s;">
                    <i class="fas fa-laptop"></i> Open Real-time PC Control
                </button>
            </div>
        </section>
    </main>

    <!-- PC Control Modal -->
    <div id="pcControlModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:2000;overflow-y:auto;padding:1rem;">
        <div style="background:white;border-radius:15px;margin:1rem auto;max-width:1400px;box-shadow:0 10px 50px rgba(0,0,0,0.3);">
            <!-- Modal Header -->
            <div style="display:flex;justify-content:space-between;align-items:center;padding:1.5rem;border-bottom:1px solid #e7edf6;background:linear-gradient(145deg, #2c3e50, #1a2634);color:white;border-radius:15px 15px 0 0;">
                <h2 style="margin:0;display:flex;align-items:center;gap:0.8rem;font-size:1.3rem;">
                    <i class="fas fa-laptop"></i> Real-time PC Control
                </h2>
                <button onclick="closePCControlModal()" style="background:none;border:none;color:white;font-size:1.5rem;cursor:pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div style="padding:1.5rem;">
                <!-- Lab Selector and PC Count Editor -->
                <div style="background:#f5f8fc;padding:1rem;border-radius:8px;margin-bottom:1.2rem;border:1px solid #e7edf6;display:flex;gap:1rem;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:0.75rem;color:#5f6b76;margin-bottom:0.4rem;font-weight:600;text-transform:uppercase;">LABORATORY</label>
                        <select id="pcModalLab" onchange="updatePCControlModal()" style="padding:0.6rem 1rem;border:1px solid #c5d3e0;border-radius:6px;font-size:0.95rem;background:white;cursor:pointer;min-width:120px;">
                            <?php 
                            $allowedLabs = ['523', '524', '525', '526', '527', '528', '529', '530'];
                            foreach ($allowedLabs as $lab): 
                            ?>
                                <option value="<?php echo $lab; ?>" <?php echo $pcLab === $lab ? 'selected' : ''; ?>>
                                    Lab <?php echo $lab; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.75rem;color:#5f6b76;margin-bottom:0.4rem;font-weight:600;text-transform:uppercase;">PC Count</label>
                        <div style="display:flex;gap:0.4rem;">
                            <input type="number" id="pcCountInputModal" value="<?php echo $pcCount; ?>" min="1" max="100" style="width:80px;padding:0.6rem;border:1px solid #c5d3e0;border-radius:6px;font-size:0.95rem;">
                            <button type="button" onclick="updatePCCount()" style="padding:0.6rem 1rem;background:#3498db;color:white;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;font-weight:600;white-space:nowrap;">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.75rem;color:#5f6b76;margin-bottom:0.4rem;font-weight:600;text-transform:uppercase;">Lab Status</label>
                        <div style="display:flex;flex-direction:column;gap:0.45rem;min-width:260px;">
                            <select id="classInstructorSelect" style="padding:0.55rem 0.7rem;border:1px solid #c5d3e0;border-radius:6px;font-size:0.85rem;background:white;">
                                <option value="">Select instructor...</option>
                                <?php foreach ($instructors as $ins): ?>
                                    <option value="<?php echo (int)$ins['id']; ?>" <?php echo $pcClassInstructorId === (int)$ins['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ins['full_name']); ?> (<?php echo htmlspecialchars($ins['last_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="display:flex;gap:0.4rem;align-items:center;">
                                <button type="button" id="classToggleBtn" onclick="toggleClassStatus()" data-class-active="<?php echo $pcClassInSession ? '1' : '0'; ?>"
                                    style="padding:0.6rem 1.1rem;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;font-weight:700;display:flex;align-items:center;gap:0.5rem;
                                    background:<?php echo $pcClassInSession ? '#8e44ad' : '#ecf0f1'; ?>;
                                    color:<?php echo $pcClassInSession ? 'white' : '#5f6b76'; ?>;">
                                    <i class="fas <?php echo $pcClassInSession ? 'fa-chalkboard-teacher' : 'fa-chalkboard'; ?>"></i>
                                    <span id="classToggleLabel"><?php echo $pcClassInSession ? 'End Class' : 'Class In Session'; ?></span>
                                </button>
                                <button type="button" onclick="removeSelectedInstructor()" style="padding:0.6rem 0.8rem;border:1px solid #e74c3c;background:#fff5f5;color:#c0392b;border-radius:6px;cursor:pointer;font-size:0.8rem;font-weight:700;">
                                    Remove
                                </button>
                            </div>
                            <div style="display:flex;gap:0.4rem;">
                                <input type="text" id="newInstructorName" placeholder="Add instructor name" style="flex:1;padding:0.55rem 0.7rem;border:1px solid #c5d3e0;border-radius:6px;font-size:0.82rem;">
                                <button type="button" onclick="addInstructor()" style="padding:0.55rem 0.75rem;border:none;background:#27ae60;color:white;border-radius:6px;cursor:pointer;font-size:0.8rem;font-weight:700;">
                                    Add
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="text-align:right;">
                        <div style="font-size:0.75rem;color:#5f6b76;margin-bottom:0.4rem;font-weight:600;">Date</div>
                        <div style="font-size:0.95rem;color:#1f2d3d;font-weight:600;">
                            <?php echo date('M d, Y', strtotime($pcDate)); ?>
                        </div>
                    </div>
                </div>

                <!-- Class In Session Banner -->
                <div id="classInSessionBanner" style="display:<?php echo $pcClassInSession ? 'flex' : 'none'; ?>;align-items:center;gap:0.8rem;background:linear-gradient(135deg,#8e44ad,#6c3483);color:white;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.2rem;font-weight:600;font-size:0.9rem;">
                    <i class="fas fa-chalkboard-teacher" style="font-size:1.2rem;"></i>
                    <span id="classInSessionText">CLASS IN SESSION<?php echo $pcClassInstructorLastName !== '' ? ' — Instructor: ' . htmlspecialchars($pcClassInstructorLastName) : ''; ?> &mdash; Individual PC toggling is disabled while a class is using this lab.</span>
                </div>

                <!-- PC Grid with Rows -->
                <div id="pcGridContainer" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(100px, 1fr));gap:0.8rem;margin-bottom:1.2rem;">
                    <?php 
                    // Display PCs in a grid
                    $colCount = 0;
                    $currentRow = 1;
                    foreach ($pcSlots as $slotNo => $slot): 
                        $colCount++;
                        $badgeBg = '#27ae60';
                        if ($slot['status'] === 'In-Use') {
                            $badgeBg = '#e67e22';
                        } elseif ($slot['status'] === 'Reserved') {
                            $badgeBg = '#3498db';
                        } elseif ($slot['status'] === 'Pending') {
                            $badgeBg = '#95a5a6';
                        } elseif ($slot['status'] === 'Maintenance') {
                            $badgeBg = '#e74c3c';
                        } elseif ($slot['status'] === 'In-Class') {
                            $badgeBg = '#8e44ad';
                        }
                    ?>
                        <div class="pc-grid-item <?php if ($slot['status'] === 'Vacant' || $slot['status'] === 'In-Use'): ?>clickable<?php endif; ?>" 
                             data-pc-no="<?php echo str_pad((string) $slotNo, 2, '0', STR_PAD_LEFT); ?>"
                             data-pc-lab="<?php echo htmlspecialchars($pcLab); ?>"
                             data-pc-date="<?php echo htmlspecialchars($pcDate); ?>"
                             data-pc-status="<?php echo htmlspecialchars($slot['status']); ?>"
                             data-pc-owner="<?php echo htmlspecialchars($slot['owner']); ?>"
                             style="border:2px solid #e7edf6;border-radius:10px;padding:0.8rem;text-align:center;background:white;transition:all 0.3s;cursor:pointer;position:relative;">
                            
                            <div style="font-size:1.8rem;margin-bottom:0.4rem;">
                                <i class="fas fa-desktop"></i>
                            </div>
                            <div style="font-weight:700;font-size:0.9rem;color:#1f2d3d;margin-bottom:0.4rem;">
                                PC - <?php echo str_pad((string) $slotNo, 2, '0', STR_PAD_LEFT); ?>
                            </div>
                            <div class="pc-status-badge" style="display:inline-block;padding:0.3rem 0.6rem;border-radius:4px;font-size:0.7rem;font-weight:700;margin-bottom:0.4rem;background:<?php echo $badgeBg; ?>;color:white;">
                                <?php echo htmlspecialchars($slot['status']); ?>
                            </div>
                            
                            <?php if ($slot['owner'] !== ''): ?>
                                <div style="font-size:0.65rem;color:#555;margin-bottom:0.4rem;font-style:italic;word-break:break-word;">
                                    <?php echo htmlspecialchars($slot['owner']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="pc-action-text" style="font-size:0.7rem;color:#3498db;font-weight:600;margin-top:0.4rem;">
                                <?php if ($slot['status'] === 'Vacant'): ?>
                                    Click to Use
                                <?php elseif ($slot['status'] === 'In-Use'): ?>
                                    Click to Free
                                <?php else: ?>
                                    <?php echo htmlspecialchars($slot['action'] ?: 'N/A'); ?>
                                <?php endif; ?>
                            </div>

                            <!-- Maintenance Menu -->
                            <div class="maintenance-controls">
                                <button type="button" class="maintenance-menu-toggle" onclick="toggleMaintenanceMenu(this, event)" title="Open maintenance menu" aria-label="Open maintenance menu">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="maintenance-menu">
                                    <button type="button" class="maintenance-btn" onclick="toggleMaintenance(this, event)" title="Mark as maintenance">
                                        <i class="fas <?php echo $slot['status'] === 'Maintenance' ? 'fa-check' : 'fa-tools'; ?>"></i><span class="maintenance-label"><?php echo $slot['status'] === 'Maintenance' ? ' Active' : ' Maintenance'; ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Summary Stats -->
                <div style="background:#f5f8fc;padding:1rem;border-radius:8px;border:1px solid #e7edf6;display:flex;gap:1.5rem;justify-content:center;flex-wrap:wrap;text-align:center;margin-bottom:1rem;">
                    <div>
                        <div id="countVacant" style="font-size:2rem;font-weight:700;color:#27ae60;">
                            <?php echo $vacantCount; ?>
                        </div>
                        <div style="font-size:0.8rem;color:#666;margin-top:0.3rem;">Vacant</div>
                    </div>
                    <div>
                        <div id="countInUse" style="font-size:2rem;font-weight:700;color:#e67e22;">
                            <?php echo $inUseCount; ?>
                        </div>
                        <div style="font-size:0.8rem;color:#666;margin-top:0.3rem;">In-Use</div>
                    </div>
                    <div>
                        <div id="countReserved" style="font-size:2rem;font-weight:700;color:#3498db;">
                            <?php echo $reservedCount; ?>
                        </div>
                        <div style="font-size:0.8rem;color:#666;margin-top:0.3rem;">Reserved</div>
                    </div>
                    <div>
                        <div id="countPending" style="font-size:2rem;font-weight:700;color:#95a5a6;">
                            <?php echo $pendingCount; ?>
                        </div>
                        <div style="font-size:0.8rem;color:#666;margin-top:0.3rem;">Pending</div>
                    </div>
                    <div>
                        <div id="countMaintenance" style="font-size:2rem;font-weight:700;color:#e74c3c;">
                            <?php echo $maintenanceCount; ?>
                        </div>
                        <div style="font-size:0.8rem;color:#666;margin-top:0.3rem;">Maintenance</div>
                    </div>
                    <div>
                        <div id="countInClass" style="font-size:2rem;font-weight:700;color:#8e44ad;">
                            <?php echo $inClassCount; ?>
                        </div>
                        <div style="font-size:0.8rem;color:#666;margin-top:0.3rem;">In-Class</div>
                    </div>
                </div>

                <div style="font-size:0.75rem;color:#666;background:#f9fafb;padding:0.8rem;border-radius:6px;border-left:3px solid #3498db;">
                    <i class="fas fa-info-circle"></i> Click any Vacant or In-Use PC to toggle status. Click the maintenance button to mark PC as not working.
                </div>
            </div>
        </div>
    </div>

    <style>
        .pc-grid-item {
            background: white;
        }
        
        .pc-grid-item.clickable:hover {
            border-color: #3498db !important;
            background: #e8f4ff !important;
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.15);
        }

        .maintenance-controls {
            position: relative;
            margin-top: 0.45rem;
            display: flex;
            justify-content: center;
        }

        .maintenance-menu-toggle {
            width: 26px;
            height: 26px;
            border: 1px solid #d6dee8;
            border-radius: 6px;
            background: #ffffff;
            color: #5d6b7a;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
        }

        .maintenance-menu-toggle:hover {
            border-color: #3498db;
            color: #3498db;
            background: #eef6ff;
        }

        .maintenance-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #ffffff;
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
            padding: 0.4rem;
            z-index: 40;
            display: none;
        }

        .maintenance-menu.open {
            display: block;
        }

        .maintenance-btn {
            white-space: nowrap;
            padding: 0.35rem 0.7rem;
            background: #e74c3c;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 0.68rem;
            font-weight: 600;
            cursor: pointer;
        }

        .maintenance-btn:hover {
            background: #cf3f30;
        }

        .pc-grid-item[data-pc-status="Vacant"] {
            border-color: #27ae60;
            background: #f0fdf4;
        }

        .pc-grid-item[data-pc-status="In-Use"] {
            border-color: #e67e22;
            background: #fffbf0;
        }

        .pc-grid-item[data-pc-status="Reserved"] {
            border-color: #3498db;
            background: #f0f9ff;
        }

        .pc-grid-item[data-pc-status="Pending"] {
            border-color: #95a5a6;
            background: #f3f4f6;
        }

        .pc-grid-item[data-pc-status="Maintenance"] {
            border-color: #e74c3c;
            background: #fef2f2;
        }
        .pc-grid-item[data-pc-status="In-Class"] {
            border-color: #8e44ad;
            background: #f5eefa;
            cursor: default !important;
        }
    </style>

    <script>
        function refreshPcSummaryCounts() {
            const counts = {
                'Vacant': 0,
                'In-Use': 0,
                'Reserved': 0,
                'Pending': 0,
                'Maintenance': 0,
                'In-Class': 0
            };

            document.querySelectorAll('.pc-grid-item').forEach(item => {
                const status = item.dataset.pcStatus || 'Vacant';
                if (Object.prototype.hasOwnProperty.call(counts, status)) {
                    counts[status] += 1;
                }
            });

            const countVacant = document.getElementById('countVacant');
            const countInUse = document.getElementById('countInUse');
            const countReserved = document.getElementById('countReserved');
            const countPending = document.getElementById('countPending');
            const countMaintenance = document.getElementById('countMaintenance');
            const countInClass = document.getElementById('countInClass');

            if (countVacant) countVacant.textContent = counts['Vacant'];
            if (countInUse) countInUse.textContent = counts['In-Use'];
            if (countReserved) countReserved.textContent = counts['Reserved'];
            if (countPending) countPending.textContent = counts['Pending'];
            if (countMaintenance) countMaintenance.textContent = counts['Maintenance'];
            if (countInClass) countInClass.textContent = counts['In-Class'];
        }

        function renderInstructorOptions(instructors, selectedId) {
            const select = document.getElementById('classInstructorSelect');
            if (!select) return;
            const options = ['<option value="">Select instructor...</option>'];
            (instructors || []).forEach(ins => {
                const id = String(ins.id || '');
                const selected = String(selectedId || '') === id ? ' selected' : '';
                options.push(`<option value="${escapeHtml(id)}"${selected}>${escapeHtml(ins.full_name)} (${escapeHtml(ins.last_name)})</option>`);
            });
            select.innerHTML = options.join('');
        }

        async function addInstructor() {
            const input = document.getElementById('newInstructorName');
            if (!input) return;
            const name = (input.value || '').trim();
            if (!name) {
                alert('Please enter instructor name');
                return;
            }
            try {
                const fd = new FormData();
                fd.append('name', name);
                const resp = await fetch('?action=add_instructor', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data.success) {
                    alert('Error: ' + (data.error || 'Could not add instructor'));
                    return;
                }
                input.value = '';
                await updatePCControlModal();
                const select = document.getElementById('classInstructorSelect');
                if (select) select.value = String(data.id);
            } catch (err) {
                console.error(err);
                alert('Failed to add instructor');
            }
        }

        async function removeSelectedInstructor() {
            const select = document.getElementById('classInstructorSelect');
            if (!select || !select.value) {
                alert('Please select an instructor to remove');
                return;
            }
            if (!confirm('Remove this instructor from the dropdown list?')) {
                return;
            }
            try {
                const fd = new FormData();
                fd.append('instructorId', select.value);
                const resp = await fetch('?action=remove_instructor', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data.success) {
                    alert('Error: ' + (data.error || 'Could not remove instructor'));
                    return;
                }
                await updatePCControlModal();
            } catch (err) {
                console.error(err);
                alert('Failed to remove instructor');
            }
        }

        async function toggleClassStatus() {
            const labSelect = document.getElementById('pcModalLab');
            if (!labSelect) return;
            const lab = labSelect.value;
            const date = '<?php echo htmlspecialchars($pcDate); ?>';
            const btn = document.getElementById('classToggleBtn');
            const select = document.getElementById('classInstructorSelect');
            const isCurrentlyActive = btn ? btn.dataset.classActive === '1' : false;
            const instructorId = select ? parseInt(select.value || '0', 10) : 0;

            if (!isCurrentlyActive && instructorId <= 0) {
                alert('Please select an instructor before starting class.');
                return;
            }

            if (btn) btn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('lab', lab);
                fd.append('date', date);
                fd.append('instructorId', String(instructorId));
                const resp = await fetch('?action=toggle_class_status', { method: 'POST', body: fd });
                const data = await resp.json();
                if (!data.success) {
                    alert('Error: ' + (data.error || 'Could not toggle class status'));
                    return;
                }
                // Reload grid for new state
                await updatePCControlModal();
            } catch(e) {
                console.error(e);
                alert('Failed to toggle class status');
            } finally {
                if (btn) btn.disabled = false;
            }
        }
        // Open/Close PC Control Modal
        function openPCControlModal() {
            document.getElementById('pcControlModal').style.display = 'block';
            updatePCControlModal();
            refreshPcSummaryCounts();
        }

        function closePCControlModal() {
            document.getElementById('pcControlModal').style.display = 'none';
        }

        async function updatePCControlModal() {
            const labSelect = document.getElementById('pcModalLab');
            if (!labSelect) return;
            const lab = labSelect.value;
            const date = '<?php echo htmlspecialchars($pcDate); ?>';

            const container = document.getElementById('pcGridContainer');
            if (container) {
                container.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:#888;">Loading...</div>';
            }

            try {
                const resp = await fetch(`?action=get_pc_status&lab=${encodeURIComponent(lab)}&date=${encodeURIComponent(date)}`);
                const data = await resp.json();

                if (!data.success) {
                    if (container) container.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:#e74c3c;">Error loading PC data</div>';
                    return;
                }

                // Update PC count input
                const pcCountInput = document.getElementById('pcCountInputModal');
                if (pcCountInput) pcCountInput.value = data.pcCount;

                // Build grid HTML
                const statusColors = {
                    'Vacant': '#27ae60',
                    'In-Use': '#e67e22',
                    'Reserved': '#3498db',
                    'Pending': '#95a5a6',
                    'Maintenance': '#e74c3c',
                    'In-Class': '#8e44ad'
                };

                // Update Class In Session button & banner
                const classInSession = !!data.class_in_session;
                const classBtn = document.getElementById('classToggleBtn');
                const classLabel = document.getElementById('classToggleLabel');
                const classBanner = document.getElementById('classInSessionBanner');
                const classText = document.getElementById('classInSessionText');

                renderInstructorOptions(data.instructors || [], data.class_instructor_id || 0);

                if (classBtn) {
                    classBtn.style.background = classInSession ? '#8e44ad' : '#ecf0f1';
                    classBtn.style.color = classInSession ? 'white' : '#5f6b76';
                    classBtn.dataset.classActive = classInSession ? '1' : '0';
                    const icon = classBtn.querySelector('i');
                    if (icon) icon.className = 'fas ' + (classInSession ? 'fa-chalkboard-teacher' : 'fa-chalkboard');
                }
                if (classLabel) classLabel.textContent = classInSession ? 'End Class' : 'Class In Session';
                if (classBanner) classBanner.style.display = classInSession ? 'flex' : 'none';
                if (classText) {
                    const lastName = (data.class_instructor_last_name || '').trim();
                    classText.textContent = classInSession
                        ? `CLASS IN SESSION${lastName ? ' - Instructor: ' + lastName : ''} - Individual PC toggling is disabled while a class is using this lab.`
                        : '';
                }

                let html = '';
                for (let i = 1; i <= data.pcCount; i++) {
                    const slot = data.slots[i] || {status: 'Vacant', owner: ''};
                    const padded = String(i).padStart(2, '0');
                    const badgeBg = statusColors[slot.status] || '#27ae60';
                    const isClickable = (!classInSession && (slot.status === 'Vacant' || slot.status === 'In-Use')) ? 'clickable' : '';
                    let actionText = 'N/A';
                    if (slot.status === 'Vacant') actionText = 'Click to Use';
                    else if (slot.status === 'In-Use') actionText = 'Click to Free';
                    else if (slot.status === 'Reserved') actionText = 'Approved reservation';
                    else if (slot.status === 'Pending') actionText = 'Pending approval';
                    else if (slot.status === 'Maintenance') actionText = 'Under Maintenance';
                    else if (slot.status === 'In-Class') {
                        const lastName = (data.class_instructor_last_name || '').trim();
                        actionText = lastName ? `Instructor: ${lastName}` : 'Instructor: N/A';
                    }

                    const maintenanceIcon = slot.status === 'Maintenance' ? 'fa-check' : 'fa-tools';
                    const maintenanceLabel = slot.status === 'Maintenance' ? ' Active' : ' Maintenance';
                    const ownerHtml = slot.owner ? `<div style="font-size:0.65rem;color:#555;margin-bottom:0.4rem;font-style:italic;word-break:break-word;">${escapeHtml(slot.owner)}</div>` : '';

                    html += `<div class="pc-grid-item ${isClickable}"
                        data-pc-no="${padded}"
                        data-pc-lab="${escapeHtml(lab)}"
                        data-pc-date="${escapeHtml(date)}"
                        data-pc-status="${escapeHtml(slot.status)}"
                        data-pc-owner="${escapeHtml(slot.owner)}"
                        style="border:2px solid #e7edf6;border-radius:10px;padding:0.8rem;text-align:center;background:white;transition:all 0.3s;cursor:pointer;position:relative;">
                        <div style="font-size:1.8rem;margin-bottom:0.4rem;"><i class="fas fa-desktop"></i></div>
                        <div style="font-weight:700;font-size:0.9rem;color:#1f2d3d;margin-bottom:0.4rem;">PC - ${padded}</div>
                        <div class="pc-status-badge" style="display:inline-block;padding:0.3rem 0.6rem;border-radius:4px;font-size:0.7rem;font-weight:700;margin-bottom:0.4rem;background:${badgeBg};color:white;">${escapeHtml(slot.status)}</div>
                        ${ownerHtml}
                        <div class="pc-action-text" style="font-size:0.7rem;color:#3498db;font-weight:600;margin-top:0.4rem;">${escapeHtml(actionText)}</div>
                        <div class="maintenance-controls">
                            <button type="button" class="maintenance-menu-toggle" onclick="toggleMaintenanceMenu(this, event)" title="Open maintenance menu" aria-label="Open maintenance menu"><i class="fas fa-ellipsis-h"></i></button>
                            <div class="maintenance-menu">
                                <button type="button" class="maintenance-btn" onclick="toggleMaintenance(this, event)" title="Mark as maintenance">
                                    <i class="fas ${maintenanceIcon}"></i><span class="maintenance-label">${maintenanceLabel}</span>
                                </button>
                            </div>
                        </div>
                    </div>`;
                }

                if (container) {
                    container.innerHTML = html;
                    bindPCGridClicks();
                }

                // Update summary counts
                const counts = data.counts || {};
                const cv = document.getElementById('countVacant');
                const ci = document.getElementById('countInUse');
                const cr = document.getElementById('countReserved');
                const cp = document.getElementById('countPending');
                const cm = document.getElementById('countMaintenance');
                const ck = document.getElementById('countInClass');
                if (cv) cv.textContent = counts['Vacant'] || 0;
                if (ci) ci.textContent = counts['In-Use'] || 0;
                if (cr) cr.textContent = counts['Reserved'] || 0;
                if (cp) cp.textContent = counts['Pending'] || 0;
                if (cm) cm.textContent = counts['Maintenance'] || 0;
                if (ck) ck.textContent = counts['In-Class'] || 0;

            } catch (err) {
                console.error('Error loading PC status:', err);
                if (container) container.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:#e74c3c;">Failed to load PC data</div>';
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }

        function closeAllMaintenanceMenus() {
            document.querySelectorAll('.maintenance-menu.open').forEach(menu => {
                menu.classList.remove('open');
            });
        }

        function toggleMaintenanceMenu(toggleBtn, event) {
            event.stopPropagation();
            const menu = toggleBtn.parentElement.querySelector('.maintenance-menu');
            const isOpen = menu.classList.contains('open');

            closeAllMaintenanceMenus();

            if (!isOpen) {
                menu.classList.add('open');
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('pcControlModal');
            if (event.target === modal) {
                closePCControlModal();
                return;
            }

            if (!event.target.closest('.maintenance-controls')) {
                closeAllMaintenanceMenus();
            }
        });

        // Handle PC grid item clicks — called on load and after re-render
        function bindPCGridClicks() {
            document.querySelectorAll('.pc-grid-item.clickable').forEach(item => {
                // Avoid double-binding
                if (item.dataset.clickBound === '1') return;
                item.dataset.clickBound = '1';
                item.addEventListener('click', async function(e) {
                // Ignore maintenance button clicks
                if (e.target.closest('button')) {
                    return;
                }

                const pcNo = this.dataset.pcNo;
                const pcLab = this.dataset.pcLab;
                const pcDate = this.dataset.pcDate;
                const currentStatus = this.dataset.pcStatus;
                
                if (currentStatus !== 'Vacant' && currentStatus !== 'In-Use') {
                    return;
                }
                
                const newStatus = currentStatus === 'Vacant' ? 'In-Use' : 'Vacant';
                const newChipClass = newStatus === 'Vacant' ? 'chip-vacant' : 'chip-inuse';
                
                const statusBadge = this.querySelector('.pc-status-badge');
                const actionText = this.querySelector('.pc-action-text');
                
                if (statusBadge) {
                    statusBadge.textContent = newStatus;
                    statusBadge.style.background = newStatus === 'Vacant' ? '#27ae60' : '#e67e22';
                }
                if (actionText) {
                    actionText.textContent = newStatus === 'Vacant' ? 'Click to Use' : 'Click to Free';
                }
                
                this.dataset.pcStatus = newStatus;
                
                try {
                    const formData = new FormData();
                    formData.append('pcNo', pcNo);
                    formData.append('lab', pcLab);
                    formData.append('date', pcDate);
                    formData.append('currentStatus', currentStatus);
                    
                    const response = await fetch(`?action=toggle_pc_status`, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        if (statusBadge) {
                            statusBadge.textContent = currentStatus;
                            statusBadge.style.background = currentStatus === 'Vacant' ? '#27ae60' : '#e67e22';
                        }
                        if (actionText) {
                            actionText.textContent = currentStatus === 'Vacant' ? 'Click to Use' : 'Click to Free';
                        }
                        this.dataset.pcStatus = currentStatus;
                        
                        alert('Error: ' + (data.error || 'Could not update PC status'));
                    } else {
                        refreshPcSummaryCounts();
                    }
                } catch (error) {
                    console.error('Error:', error);
                    if (statusBadge) {
                        statusBadge.textContent = currentStatus;
                        statusBadge.style.background = currentStatus === 'Vacant' ? '#27ae60' : '#e67e22';
                    }
                    if (actionText) {
                        actionText.textContent = currentStatus === 'Vacant' ? 'Click to Use' : 'Click to Free';
                    }
                    this.dataset.pcStatus = currentStatus;
                    
                    alert('Failed to update PC status');
                }
                }); // end addEventListener
            }); // end forEach
        } // end bindPCGridClicks

        // Bind on initial page load
        bindPCGridClicks();

        // Toggle maintenance status (persisted)
        async function toggleMaintenance(button, event) {
            if (event) {
                event.stopPropagation();
            }

            const pcItem = button.closest('.pc-grid-item');
            const status = pcItem.dataset.pcStatus;
            const pcNo = pcItem.dataset.pcNo;
            const pcLab = pcItem.dataset.pcLab;
            const pcDate = pcItem.dataset.pcDate;
            const statusBadge = pcItem.querySelector('.pc-status-badge');
            const actionText = pcItem.querySelector('.pc-action-text');
            const maintenanceIcon = button.querySelector('i');
            const maintenanceLabel = button.querySelector('.maintenance-label');

            button.disabled = true;

            try {
                const formData = new FormData();
                formData.append('pcNo', pcNo);
                formData.append('lab', pcLab);
                formData.append('date', pcDate);
                formData.append('currentStatus', status);

                const response = await fetch(`?action=toggle_maintenance_status`, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                if (!data.success) {
                    alert('Error: ' + (data.error || 'Could not update maintenance status'));
                    closeAllMaintenanceMenus();
                    return;
                }

                const newStatus = data.newStatus || (status === 'Maintenance' ? 'Vacant' : 'Maintenance');
                pcItem.dataset.pcStatus = newStatus;

                if (statusBadge) {
                    statusBadge.textContent = newStatus;
                    let badgeBg = '#27ae60';
                    if (newStatus === 'In-Use') badgeBg = '#e67e22';
                    else if (newStatus === 'Reserved') badgeBg = '#3498db';
                    else if (newStatus === 'Pending') badgeBg = '#95a5a6';
                    else if (newStatus === 'Maintenance') badgeBg = '#e74c3c';
                    statusBadge.style.background = badgeBg;
                }

                if (actionText) {
                    if (newStatus === 'Vacant') actionText.textContent = 'Click to Use';
                    else if (newStatus === 'In-Use') actionText.textContent = 'Click to Free';
                    else if (newStatus === 'Reserved') actionText.textContent = 'Approved reservation';
                    else if (newStatus === 'Pending') actionText.textContent = 'Pending approval';
                    else if (newStatus === 'Maintenance') actionText.textContent = 'Under Maintenance';
                    else actionText.textContent = 'N/A';
                }

                if (maintenanceIcon) {
                    maintenanceIcon.className = newStatus === 'Maintenance' ? 'fas fa-check' : 'fas fa-tools';
                }
                if (maintenanceLabel) {
                    maintenanceLabel.textContent = newStatus === 'Maintenance' ? ' Active' : ' Maintenance';
                }

                refreshPcSummaryCounts();
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to update maintenance status');
            } finally {
                button.disabled = false;
                closeAllMaintenanceMenus();
            }
        }

        // Update PC count
        async function updatePCCount() {
            const input = document.getElementById('pcCountInputModal');
            const pcCount = parseInt(input.value);
            
            if (isNaN(pcCount) || pcCount < 1 || pcCount > 100) {
                alert('Please enter a valid PC count between 1 and 100');
                return;
            }
            
            const labSelect = document.getElementById('pcModalLab');
            const lab = labSelect ? labSelect.value : '<?php echo $pcLab; ?>';
            
            try {
                const formData = new FormData();
                formData.append('lab', lab);
                formData.append('pcCount', pcCount);
                
                const response = await fetch(`?action=update_pc_count`, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Could not update PC count'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to update PC count');
            }
        }
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
