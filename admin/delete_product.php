<?php
include '../includes/db_connect.php';
$id = $_GET['id'];

$stmt = $conn->prepare("DELETE FROM products WHERE id = :id");
$stmt->execute(['id' => $id]);
header("Location: index.php");
exit();
?>