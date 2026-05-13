<?php
session_start();
include 'includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login_user.php");
    exit();
}

$user_id = $_SESSION['user_id']; // Add user_id to orders table if tracking per user is needed
$stmt = $conn->prepare("SELECT o.id, p.item, o.quantity, o.status, o.order_date FROM orders o JOIN products p ON o.product_id = p.id WHERE o.id IN (SELECT id FROM orders WHERE customer_name = :username) ORDER BY o.order_date DESC");
$stmt->execute(['username' => $_SESSION['username']]);
$orders = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History - Sesy Queen</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 dark:text-gray-200 transition-colors duration-300" id="body">
    <header class="bg-gradient-to-r from-red-600 to-pink-500 text-white py-6 shadow-lg">
        <div class="container mx-auto text-center">
            <h1 class="text-3xl font-extrabold">Order History</h1>
        </div>
    </header>
    <main class="container mx-auto p-4 py-12">
        <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-6 text-center">Your Orders</h2>
        <table class="w-full bg-white/90 shadow-lg rounded-lg overflow-hidden">
            <thead class="bg-gray-200">
                <tr>
                    <th class="p-4 text-left">Order ID</th>
                    <th class="p-4 text-left">Product</th>
                    <th class="p-4 text-left">Quantity</th>
                    <th class="p-4 text-left">Status</th>
                    <th class="p-4 text-left">Date</th>
                </tr>
            </thead>
            <tbody class="table-hover">
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td class="p-4"><?php echo htmlspecialchars($order['id']); ?></td>
                        <td class="p-4"><?php echo htmlspecialchars($order['item']); ?></td>
                        <td class="p-4"><?php echo htmlspecialchars($order['quantity']); ?></td>
                        <td class="p-4"><?php echo htmlspecialchars($order['status']); ?></td>
                        <td class="p-4"><?php echo htmlspecialchars($order['order_date']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <a href="index.php" class="mt-6 inline-block bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-all duration-300">Back to Home</a>
    </main>
</body>
</html>