<?php
// Start session
session_start();

// Include database connection for logging (optional)
$conn = include 'includes/db_connect.php';
if (!is_object($conn)) {
    error_log("Database connection failed during logout: " . date('Y-m-d H:i:s'));
    $_SESSION['flash_message'] = "<div class='alert alert-danger text-center animate__animated animate__fadeIn'>Error connecting to database. Logged out.</div>";
    // Proceed with logout even if DB fails
}

// CSRF protection for POST requests (optional, remove if using GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("Invalid CSRF token during logout: " . date('Y-m-d H:i:s') . ", User ID: " . ($_SESSION['user_id'] ?? 'unknown') . ", IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

// Log logout details
$user_id = $_SESSION['user_id'] ?? 'unknown';
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
error_log("Logout at " . date('Y-m-d H:i:s') . ": User ID: $user_id, IP: $ip_address");

// Set flash message for index.php
$_SESSION['flash_message'] = "<div class='alert alert-success text-center animate__animated animate__fadeIn'>Logged out successfully!</div>";

// Clear session data
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
session_destroy();

// Redirect to index.php
header("Location: index.php");
exit;
?>