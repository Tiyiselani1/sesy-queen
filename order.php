<?php
header('Content-Type: application/json');
include 'includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $customer_name = $_POST['customer_name'] ?? 'Sesy Queen Customer';
    $contact = $_POST['contact'] ?? '0794416767';
    $address = $_POST['address'] ?? 'Krugersdorp';

    $stmt = $conn->prepare("INSERT INTO orders (product_id, quantity, customer_name, contact, address) VALUES (:pid, :qty, :cname, :contact, :addr)");
    $stmt->execute(['pid' => $product_id, 'qty' => $quantity, 'cname' => $customer_name, 'contact' => $contact, 'addr' => $address]);

    echo json_encode(['success' => true]);
    exit();
}
?>