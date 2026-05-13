<?php
session_start();
include 'includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login_user.php");
    exit();
}

$total = 0;
$stmt = $conn->prepare("SELECT p.price, c.quantity FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$cart_items = $stmt->fetchAll();
foreach ($cart_items as $item) {
    $total += $item['price'] * $item['quantity'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Placeholder for payment processing
    $stmt = $conn->prepare("INSERT INTO orders (product_id, quantity, customer_name, contact, address, status) SELECT product_id, quantity, :cname, :contact, :addr, 'Pending' FROM cart WHERE user_id = :user_id");
    $stmt->execute(['cname' => $_SESSION['username'], 'contact' => '0794416767', 'addr' => 'Krugersdorp', 'user_id' => $_SESSION['user_id']]);
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    header("Location: thank_you.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Sesy Queen</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 dark:text-gray-200 transition-colors duration-300" id="body">
    <header class="bg-gradient-to-r from-red-600 to-pink-500 text-white py-6 shadow-lg">
        <div class="container mx-auto text-center">
            <h1 class="text-3xl font-extrabold">Checkout</h1>
            <a href="index.php" class="mt-4 inline-block bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-all duration-300">Continue Shopping</a>
        </div>
    </header>
    <main class="container mx-auto p-4 py-12">
        <p class="text-center text-gray-700 dark:text-gray-300">Total: R<?php echo htmlspecialchars($total); ?> (including R140 delivery)</p>
        <form method="post" class="max-w-md mx-auto mt-6 bg-white/90 p-6 rounded-xl shadow-lg dark:bg-gray-800">
            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 w-full transition-all duration-300">Confirm Order</button>
        </form>
    </main>
</body>
</html>