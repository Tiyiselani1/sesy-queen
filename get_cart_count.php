<?php
session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'count' => 0];

try {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode($response);
        exit;
    }

    $conn = include 'includes/db_connect.php';
    if (!is_object($conn)) {
        throw new Exception('Database connection failed');
    }

    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    $result = $stmt->fetch();

    $response['success'] = true;
    $response['count'] = (int)($result['total'] ?? 0);

} catch (Exception $e) {
    error_log("Get cart count error: " . $e->getMessage());
}

echo json_encode($response);
?>