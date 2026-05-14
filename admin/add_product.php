<?php
include '../includes/db_connect.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: ../login.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category = trim($_POST['category']);
    $item = trim($_POST['item']);
    $quantity = (int)$_POST['quantity'];
    $price = (float)$_POST['price'];
    $image = 'default.jpg'; // Default image
    $image2 = 'default.jpg'; // Default image2
    $image3 = 'default.jpg'; // Default image3

    $allowed_types = ['image/jpeg', 'image/png'];
    $max_size = 5000000; // 5MB

    // Handle image 1
    if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
        $check = getimagesize($_FILES['image']['tmp_name']);
        if ($check !== false && in_array($check['mime'], $allowed_types) && $_FILES['image']['size'] <= $max_size) {
            $filename = uniqid() . '_' . basename($_FILES['image']['name']);
            $target_dir = "../images/";
            $target_file = $target_dir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image = $filename;
            } else {
                $error .= "<p class='text-red-500 text-center mt-4'>Failed to upload Image 1. Please try again.</p>";
                error_log("Failed to upload image 1 for product: $item");
            }
        } else {
            $error .= "<p class='text-red-500 text-center mt-4'>Image 1: Only JPG/PNG files under 5MB are allowed.</p>";
            error_log("Invalid image 1 for product: $item. Type: {$check['mime']}, Size: {$_FILES['image']['size']}");
        }
    }

    // Handle image 2
    if (isset($_FILES['image2']) && $_FILES['image2']['error'] == UPLOAD_ERR_OK) {
        $check = getimagesize($_FILES['image2']['tmp_name']);
        if ($check !== false && in_array($check['mime'], $allowed_types) && $_FILES['image2']['size'] <= $max_size) {
            $filename = uniqid() . '_' . basename($_FILES['image2']['name']);
            $target_dir = "../images/";
            $target_file = $target_dir . $filename;
            if (move_uploaded_file($_FILES['image2']['tmp_name'], $target_file)) {
                $image2 = $filename;
            } else {
                $error .= "<p class='text-red-500 text-center mt-4'>Failed to upload Image 2. Please try again.</p>";
                error_log("Failed to upload image 2 for product: $item");
            }
        } else {
            $error .= "<p class='text-red-500 text-center mt-4'>Image 2: Only JPG/PNG files under 5MB are allowed.</p>";
            error_log("Invalid image 2 for product: $item. Type: {$check['mime']}, Size: {$_FILES['image2']['size']}");
        }
    }

    // Handle image 3
    if (isset($_FILES['image3']) && $_FILES['image3']['error'] == UPLOAD_ERR_OK) {
        $check = getimagesize($_FILES['image3']['tmp_name']);
        if ($check !== false && in_array($check['mime'], $allowed_types) && $_FILES['image3']['size'] <= $max_size) {
            $filename = uniqid() . '_' . basename($_FILES['image3']['name']);
            $target_dir = "../images/";
            $target_file = $target_dir . $filename;
            if (move_uploaded_file($_FILES['image3']['tmp_name'], $target_file)) {
                $image3 = $filename;
            } else {
                $error .= "<p class='text-red-500 text-center mt-4'>Failed to upload Image 3. Please try again.</p>";
                error_log("Failed to upload image 3 for product: $item");
            }
        } else {
            $error .= "<p class='text-red-500 text-center mt-4'>Image 3: Only JPG/PNG files under 5MB are allowed.</p>";
            error_log("Invalid image 3 for product: $item. Type: {$check['mime']}, Size: {$_FILES['image3']['size']}");
        }
    }

    if (!$error) {
        try {
            $stmt = $conn->prepare("INSERT INTO products (category, item, quantity, price, image, image2, image3) VALUES (:cat, :item, :qty, :price, :image, :image2, :image3)");
            $stmt->execute(['cat' => $category, 'item' => $item, 'qty' => $quantity, 'price' => $price, 'image' => $image, 'image2' => $image2, 'image3' => $image3]);
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $error = "<p class='text-red-500 text-center mt-4'>Failed to add product: {$e->getMessage()}</p>";
            error_log("Product insert failed: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="apple-touch-icon" sizes="180x180" href="../favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../favicon_io/favicon-16x16.png">
    <link rel="manifest" href="../favicon_io/site.webmanifest">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product - Sesy Queen</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .animate-fadeIn { animation: fadeIn 0.6s ease-out; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 dark:text-gray-200 transition-colors duration-300" id="body">
    <header class="bg-gradient-to-r from-red-600 to-pink-500 text-white py-6 shadow-lg">
        <div class="container mx-auto text-center">
            <h1 class="text-3xl font-extrabold animate-fadeIn">Add Product</h1>
        </div>
    </header>
    <main class="container mx-auto p-4 py-12">
        <?php if ($error): ?>
            <div class="mb-4"><?php echo $error; ?></div>
        <?php endif; ?>
        <form action="" method="post" enctype="multipart/form-data" class="max-w-md mx-auto bg-white/90 p-6 rounded-xl shadow-lg dark:bg-gray-800 animate-fadeIn">
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 font-medium">Category</label>
                <input type="text" name="category" class="border-gray-300 rounded-lg p-2 w-full focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white transition-all" required>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 font-medium">Item</label>
                <input type="text" name="item" class="border-gray-300 rounded-lg p-2 w-full focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white transition-all" required>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 font-medium">Quantity</label>
                <input type="number" name="quantity" class="border-gray-300 rounded-lg p-2 w-full focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white transition-all" required min="0">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 font-medium">Price (R)</label>
                <input type="number" step="0.01" name="price" class="border-gray-300 rounded-lg p-2 w-full focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white transition-all" required min="0">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 font-medium">Upload Image 1</label>
                <input type="file" name="image" class="border-gray-300 rounded-lg p-2 w-full focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white transition-all" accept="image/jpeg,image/png">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 font-medium">Upload Image 2 (Optional)</label>
                <input type="file" name="image2" class="border-gray-300 rounded-lg p-2 w-full focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white transition-all" accept="image/jpeg,image/png">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 font-medium">Upload Image 3 (Optional)</label>
                <input type="file" name="image3" class="border-gray-300 rounded-lg p-2 w-full focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white transition-all" accept="image/jpeg,image/png">
            </div>
            <button type="submit" class="bg-gradient-to-r from-green-600 to-emerald-500 text-white px-4 py-2 rounded-lg hover:from-green-700 hover:to-emerald-600 w-full transition-all duration-300">Add Product</button>
        </form>
    </main>
</body>
</html>