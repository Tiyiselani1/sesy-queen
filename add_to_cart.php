<?php
session_start();

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        header("Location: login_user.php");
        exit;
    }

    // Include database connection
    $conn = include 'includes/db_connect.php';
    if (!is_object($conn)) {
        throw new Exception('Database connection failed');
    }

    // Validate POST data
    if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
        throw new Exception('Invalid product ID');
    }

    $user_id = $_SESSION['user_id'];
    $product_id = (int)$_POST['product_id'];
    $quantity = 1; // Default quantity

    // Verify product exists
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = :product_id");
    $stmt->execute([':product_id' => $product_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Product not found');
    }

    // Check if product is already in cart
    $stmt = $conn->prepare("SELECT quantity FROM cart WHERE user_id = :user_id AND product_id = :product_id");
    $stmt->execute([':user_id' => $user_id, ':product_id' => $product_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update quantity
        $new_quantity = $existing['quantity'] + $quantity;
        $stmt = $conn->prepare("UPDATE cart SET quantity = :quantity WHERE user_id = :user_id AND product_id = :product_id");
        $stmt->execute([':quantity' => $new_quantity, ':user_id' => $user_id, ':product_id' => $product_id]);
    } else {
        // Insert new cart entry
        $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (:user_id, :product_id, :quantity)");
        $stmt->execute([':user_id' => $user_id, ':product_id' => $product_id, ':quantity' => $quantity]);
    }

    // Redirect with success message
    header("Location: index.php?message=success");
    exit;

} catch (Exception $e) {
    error_log("Add to cart error: " . $e->getMessage());
    // Redirect with error message
    header("Location: index.php?message=error");
    exit;
}
?>