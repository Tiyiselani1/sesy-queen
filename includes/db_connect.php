<?php
$host = 'localhost';
$dbname = 'sesy_queen_db';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, [
        PDO::ATTR_TIMEOUT => 5 // 5-second timeout
    ]);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    error_log("Database connection successful to $dbname at " . date('Y-m-d H:i:s'));
    return $conn;
} catch (PDOException $e) {
    error_log("Connection failed: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}
?>