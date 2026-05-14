<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="apple-touch-icon" sizes="180x180" href="favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png">
    <link rel="manifest" href="favicon_io/site.webmanifest">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Thank you for your order with Sesy Queen!">
    <meta name="robots" content="noindex">
    <title>Thank You - Sesy Queen</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="js/script.js" defer></script>
</head>
<body class="bg-gray-50 font-sans text-gray-800 dark:bg-gray-900 dark:text-gray-200 transition-colors duration-300" id="body">
    <header class="bg-red-600 text-white py-4 shadow-md">
        <div class="container mx-auto text-center">
            <h1 class="text-2xl font-bold">Sesy Queen</h1>
        </div>
    </header>
    <main class="container mx-auto p-4 py-8 text-center">
        <h2 class="text-3xl font-bold mb-4 text-gray-900 dark:text-gray-100">Thank You for Your Order!</h2>
        <p class="text-lg text-gray-600 mb-6 dark:text-gray-400">We will contact you at 0794416767 to confirm your delivery on <?php echo date('l, F j, Y', strtotime('10:37 AM SAST, June 23, 2025')); ?>.</p>
        <a href="index.php" class="bg-red-600 text-white px-6 py-2 rounded-lg hover:bg-red-700 transition duration-200">Back to Home</a>
    </main>
    <footer class="bg-gray-800 text-white py-4 mt-8 dark:bg-gray-900">
        <div class="container mx-auto text-center">
            <p class="text-lg">Contact: 0794416767 | Delivery: R140 Nationwide | Location: Krugersdorp</p>
            <p class="text-sm mt-1">Last updated: <?php echo date('h:i A T, F j, Y', strtotime('10:37 AM SAST, June 23, 2025')); ?></p>
        </div>
    </footer>
</body>
</html>