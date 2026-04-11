<?php
// get_student.php - Get student details for AJAX
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$id_number = isset($_GET['id_number']) ? trim($_GET['id_number']) : '';

if (empty($id_number)) {
    echo json_encode(['error' => 'Please enter ID number']);
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id_number = ?");
$stmt->execute([$id_number]);
$student = $stmt->fetch();

if ($student) {
    echo json_encode([
        'success' => true,
        'id' => $student['id'],
        'id_number' => $student['id_number'],
        'name' => $student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'],
        'first_name' => $student['first_name'],
        'last_name' => $student['last_name'],
        'remaining_sessions' => $student['remaining_sessions']
    ]);
} else {
    echo json_encode(['error' => 'No student found with ID: ' . $id_number]);
}
?>
