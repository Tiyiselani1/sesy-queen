<?php
include 'includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['type']) && isset($_POST['value'])) {
    $type = $_POST['type'];
    $value = $_POST['value'];

    // Validate the type
    $validTypes = ['username', 'email'];
    if (!in_array($type, $validTypes)) {
        echo "Invalid check type!";
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE $type = :value");
        $stmt->execute(['value' => $value]);
        $count = $stmt->fetchColumn();

        echo $count == 0 ? "$type is available!" : "$type '$value' already exists!";
    } catch (PDOException $e) {
        echo "An error occurred. Please try again later.";
    }
}
?>