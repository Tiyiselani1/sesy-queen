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

    // Verify product exists
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = :product_id");
    $stmt->execute([':product_id' => $product_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Product not found');
    }

    // Check if product is already in wishlist
    $stmt = $conn->prepare("SELECT id FROM wishlist WHERE user_id = :user_id AND product_id = :product_id");
    $stmt->execute([':user_id' => $user_id, ':product_id' => $product_id]);
    if ($stmt->fetch()) {
        // Item already in wishlist
        header("Location: index.php?message=wishlist_success");
        exit;
    }

    // Insert into wishlist
    $stmt = $conn->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (:user_id, :product_id)");
    $stmt->execute([':user_id' => $user_id, ':product_id' => $product_id]);

    // Redirect with success message
    header("Location: index.php?message=wishlist_success");
    exit;

} catch (Exception $e) {
    error_log("Add to wishlist error: " . $e->getMessage());
    // Redirect with error message
    header("Location: index.php?message=wishlist_error");
    exit;
}
?>