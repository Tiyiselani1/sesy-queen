<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Generate CSRF token for form security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Load configuration
require_once 'config.php';

// Database connection
$conn = include 'includes/db_connect.php';
if (!is_object($conn)) {
    die("Database connection failed.");
}

// Check for login success
$show_welcome = isset($_GET['login']) && $_GET['login'] === 'success' && isset($_SESSION['user_id']);

// Fetch user data if logged in
$user_name = '';
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("SELECT first_name, last_name, username FROM users WHERE id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $user_name = !empty($user['first_name']) && !empty($user['last_name']) 
                ? htmlspecialchars($user['first_name'] . ' ' . $user['last_name'])
                : htmlspecialchars($user['username']);
        }
    } catch (PDOException $e) {
        error_log("User fetch failed: " . $e->getMessage());
    }
}

// Handle search, category filter, and sorting
$search = isset($_POST['search']) ? trim($_POST['search']) : '';
$category = isset($_POST['category']) ? trim($_POST['category']) : '';
$sort = isset($_POST['sort']) ? trim($_POST['sort']) : 'price ASC';
$price_min = isset($_POST['price_min']) ? (float)$_POST['price_min'] : 0;
$price_max = isset($_POST['price_max']) ? (float)$_POST['price_max'] : PHP_INT_MAX;

$query = "SELECT * FROM products WHERE 1=1";
$params = [];
if ($search) {
    $query .= " AND item LIKE :search_query";
    $params[':search_query'] = "%$search%";
}
if ($category) {
    $query .= " AND category = :category";
    $params[':category'] = $category;
}
$query .= " AND price BETWEEN :price_min AND :price_max ORDER BY $sort LIMIT 10";
$params[':price_min'] = $price_min;
$params[':price_max'] = $price_max;

try {
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Query failed: " . $e->getMessage());
    $products = [];
}

// Handle newsletter and contact submissions with CSRF validation
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "<div class='alert alert-danger text-center'>Invalid CSRF token.</div>";
    } else {
        if (isset($_POST['subscribe_email'])) {
            $email = filter_var($_POST['subscribe_email'], FILTER_SANITIZE_EMAIL);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $stmt = $conn->prepare("INSERT IGNORE INTO newsletter (email) VALUES (:email)");
                    $stmt->execute([':email' => $email]);

                    $details = [
                        'email' => $email,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];

                    $customer_email_result = sendBrevoEmailNotification(
                        $email,
                        "Welcome to Sesy Queen Newsletter!",
                        $details,
                        'newsletter_customer'
                    );

                    $admin_email_result = sendBrevoEmailNotification(
                        BREVO_SENDER_EMAIL,
                        "New Newsletter Subscriber",
                        $details,
                        'newsletter_admin'
                    );

                    if (!$customer_email_result['success']) {
                        error_log("Newsletter customer email failed for $email: " . $customer_email_result['error']);
                        $message = "<div class='alert alert-warning text-center'>Subscribed successfully, but failed to send confirmation email.</div>";
                    } elseif (!$admin_email_result['success']) {
                        error_log("Newsletter admin email failed for $email: " . $admin_email_result['error']);
                        $message = "<div class='alert alert-warning text-center'>Subscribed successfully, but failed to notify admin.</div>";
                    } else {
                        $message = "<div class='alert alert-success text-center'>Subscribed successfully! Check your email for confirmation.</div>";
                    }
                } catch (PDOException $e) {
                    error_log("Newsletter subscription failed: " . $e->getMessage());
                    $message = "<div class='alert alert-danger text-center'>Failed to subscribe: {$e->getMessage()}.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger text-center'>Invalid email address.</div>";
            }
        } elseif (isset($_POST['email']) && isset($_POST['message'])) {
            $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
            $user_message = trim($_POST['message']);
            $user_message = htmlspecialchars($user_message, ENT_QUOTES, 'UTF-8');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && $user_message) {
                try {
                    $details = [
                        'email' => $email,
                        'message' => $user_message,
                        'timestamp' => date('Y-m-d H:i:s')
                    ];

                    $customer_email_result = sendBrevoEmailNotification(
                        $email,
                        "Thank You for Contacting Sesy Queen!",
                        $details,
                        'contact_customer'
                    );

                    $admin_email_result = sendBrevoEmailNotification(
                        BREVO_SENDER_EMAIL,
                        "New Contact Inquiry",
                        $details,
                        'contact_admin'
                    );

                    if (!$customer_email_result['success']) {
                        error_log("Contact customer email failed for $email: " . $customer_email_result['error']);
                        $message = "<div class='alert alert-warning text-center'>Message sent successfully, but failed to send confirmation email.</div>";
                    } elseif (!$admin_email_result['success']) {
                        error_log("Contact admin email failed for $email: " . $admin_email_result['error']);
                        $message = "<div class='alert alert-warning text-center'>Message sent successfully, but failed to notify admin.</div>";
                    } else {
                        $message = "<div class='alert alert-success text-center'>Message sent successfully! Check your email for confirmation.</div>";
                    }
                } catch (Exception $e) {
                    error_log("Contact form processing failed: " . $e->getMessage());
                    $message = "<div class='alert alert-danger text-center'>Failed to process message: {$e->getMessage()}.</div>";
                }
            } else {
                $message = "<div class='alert alert-danger text-center'>Invalid email address or message.</div>";
            }
        }
    }
}

// Get cart and wishlist counts
$cart_count = 0;
$wishlist_count = 0;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $cart_count = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $conn->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $wishlist_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Count query failed: " . $e->getMessage());
    }
}

// Display messages from add_to_cart.php or add_to_wishlist.php
if (isset($_GET['message'])) {
    switch ($_GET['message']) {
        case 'cart_success':
            $message = "<div class='alert alert-success text-center'>Item added to cart!</div>";
            break;
        case 'cart_error':
            $message = "<div class='alert alert-danger text-center'>Failed to add item to cart!</div>";
            break;
        case 'wishlist_success':
            $message = "<div class='alert alert-success text-center'>Item added to wishlist!</div>";
            break;
        case 'wishlist_error':
            $message = "<div class='alert alert-danger text-center'>Failed to add item to wishlist!</div>";
            break;
    }
}

// Function to send Brevo email notifications
function sendBrevoEmailNotification($email, $subject, $details, $recipient_type) {
    $api_url = 'https://api.brevo.com/v3/smtp/email';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid recipient email: ' . $email];
    }

    $htmlContent = '';
    if ($recipient_type === 'newsletter_customer') {
        $htmlContent = "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee;'>
                    <h1 style='color: #F4A261;'>Welcome to Sesy Queen!</h1>
                    <p>Thank you for subscribing to our newsletter!</p>
                    <p>You'll receive updates on our latest premium kitchenware and exclusive offers.</p>
                    <p><strong>Email:</strong> {$details['email']}</p>
                    <p><strong>Subscribed on:</strong> {$details['timestamp']}</p>
                    <p>Best regards,<br>Sesy Queen Team</p>
                </div>
            </body>
            </html>";
    } elseif ($recipient_type === 'newsletter_admin') {
        $htmlContent = "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee;'>
                    <h1 style='color: #F4A261;'>New Newsletter Subscriber</h1>
                    <p><strong>Email:</strong> {$details['email']}</p>
                    <p><strong>Subscribed on:</strong> {$details['timestamp']}</p>
                </div>
            </body>
            </html>";
    } elseif ($recipient_type === 'contact_customer') {
        $htmlContent = "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee;'>
                    <h1 style='color: #F4A261;'>Thank You for Contacting Sesy Queen!</h1>
                    <p>We have received your inquiry and will get back to you soon.</p>
                    <p><strong>Your Email:</strong> {$details['email']}</p>
                    <p><strong>Your Message:</strong> {$details['message']}</p>
                    <p><strong>Submitted on:</strong> {$details['timestamp']}</p>
                    <p>Best regards,<br>Sesy Queen Team</p>
                </div>
            </body>
            </html>";
    } elseif ($recipient_type === 'contact_admin') {
        $htmlContent = "
            <html>
            <body style='font-family: Arial, sans-serif; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee;'>
                    <h1 style='color: #F4A261;'>New Contact Inquiry</h1>
                    <p><strong>Email:</strong> {$details['email']}</p>
                    <p><strong>Message:</strong> {$details['message']}</p>
                    <p><strong>Submitted on:</strong> {$details['timestamp']}</p>
                </div>
            </body>
            </html>";
    }

    $payload = [
        'sender' => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'htmlContent' => $htmlContent
    ];

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n" .
                        "Accept: application/json\r\n" .
                        "api-key: " . BREVO_API_KEY . "\r\n",
            'method' => 'POST',
            'content' => json_encode($payload),
        ],
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($api_url, false, $context);

    if ($result === false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . BREVO_API_KEY
        ]);

        $result = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            return ['success' => false, 'error' => 'cURL failed: ' . $curl_error];
        }
    }

    $response = json_decode($result, true);
    if (isset($response['messageId'])) {
        return ['success' => true, 'messageId' => $response['messageId']];
    } else {
        return ['success' => false, 'error' => 'Brevo API error: ' . ($response['message'] ?? 'Unknown error')];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="apple-touch-icon" sizes="180x180" href="favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png">
    <link rel="manifest" href="favicon_io/site.webmanifest">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Sesy Queen - Premium Kitchenware & Innovative Solutions for South African Homes">
    <meta name="theme-color" content="#8B5CF6">
    
    <title>Sesy Queen | Premium Kitchenware</title>
    
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <style>
        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --primary-light: #A78BFA;
            --secondary: #EC4899;
            --accent: #10B981;
            --dark: #0F172A;
            --darker: #020617;
            --light: #F8FAFC;
            --gray: #64748B;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #8B5CF6 0%, #EC4899 100%);
            --gradient-3: linear-gradient(135deg, #10B981 0%, #3B82F6 100%);
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px rgba(0,0,0,0.1);
            --shadow-2xl: 0 25px 50px rgba(0,0,0,0.25);
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: 1px solid rgba(255, 255, 255, 0.2);
        }

        [data-theme="dark"] {
            --primary: #9F7AEA;
            --primary-dark: #805AD5;
            --primary-light: #B794F4;
            --dark: #F8FAFC;
            --darker: #FFFFFF;
            --light: #0F172A;
            --gray: #94A3B8;
            --glass-bg: rgba(15, 23, 42, 0.95);
            --glass-border: 1px solid rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Space Grotesk', sans-serif;
            background: var(--light);
            color: var(--dark);
            transition: background-color 0.3s, color 0.3s;
            overflow-x: hidden;
            line-height: 1.6;
        }

        /* Modern Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 2rem;
            transition: all 0.3s ease;
            background: transparent;
        }

        .navbar.scrolled {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-lg);
            padding: 0.75rem 2rem;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            position: relative;
            z-index: 1001;
        }

        .logo img {
            height: 60px;
            width: auto;
            transition: transform 0.3s ease;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
        }

        .logo img:hover {
            transform: scale(1.05);
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .nav-link {
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            position: relative;
            padding: 0.5rem 0;
            transition: color 0.3s ease;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--gradient-2);
            transition: width 0.3s ease;
        }

        .nav-link:hover {
            color: var(--primary);
        }

        .nav-link:hover::after {
            width: 100%;
        }

        .nav-link.active {
            color: var(--primary);
        }

        .nav-link.active::after {
            width: 100%;
        }

        .nav-icons {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .icon-btn {
            background: none;
            border: none;
            color: var(--dark);
            font-size: 1.2rem;
            cursor: pointer;
            position: relative;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .icon-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .badge {
            position: absolute;
            top: 0;
            right: 0;
            background: var(--secondary);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 50px;
            min-width: 20px;
            text-align: center;
        }

        /* Enhanced User Welcome */
        .user-welcome {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.2rem;
            background: var(--gradient-2);
            border-radius: 50px;
            color: white;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.5s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .welcome-text {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .username-highlight {
            font-weight: 700;
            font-size: 1rem;
            text-transform: capitalize;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(20px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--dark);
            font-size: 1.5rem;
            cursor: pointer;
            z-index: 1001;
        }

        /* Mobile Menu */
        .mobile-menu {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            z-index: 1000;
            padding: 6rem 2rem 2rem;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
        }

        .mobile-menu.active {
            display: flex;
        }

        .mobile-menu .nav-link {
            font-size: 1.5rem;
        }

        /* Dropdown */
        .dropdown {
            position: relative;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 0.5rem;
            min-width: 200px;
            box-shadow: var(--shadow-xl);
            border: var(--glass-border);
            display: none;
            z-index: 1001;
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-item {
            display: block;
            padding: 0.75rem 1rem;
            color: var(--dark);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .dropdown-item:hover {
            background: var(--primary);
            color: white;
        }

        /* Hero Section */
        .hero {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .hero-particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            color: white;
            max-width: 900px;
            padding: 2rem;
            animation: floatIn 1.5s ease;
        }

        .hero-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .hero-title {
            font-size: clamp(2.5rem, 8vw, 5rem);
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .hero-title span {
            display: block;
            font-size: clamp(1.5rem, 4vw, 2.5rem);
            background: linear-gradient(135deg, #fff 0%, #e0e0e0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            font-size: clamp(1rem, 2vw, 1.25rem);
            margin-bottom: 2.5rem;
            opacity: 0.95;
        }

        .hero-cta {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-primary {
            background: white;
            color: var(--primary);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.3);
        }

        .btn-outline {
            background: transparent;
            color: white;
            border: 2px solid white;
        }

        .btn-outline:hover {
            background: white;
            color: var(--primary);
            transform: translateY(-3px);
        }

        /* Search Section */
        .search-section {
            position: relative;
            margin-top: -50px;
            z-index: 10;
            padding: 0 2rem;
        }

        .search-container {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-2xl);
            border: var(--glass-border);
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .search-group {
            flex: 2;
            min-width: 250px;
            position: relative;
        }

        .search-group i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .search-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid transparent;
            border-radius: 12px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }

        .filter-select {
            flex: 1;
            min-width: 150px;
            padding: 1rem;
            border: 2px solid transparent;
            border-radius: 12px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }

        .price-range {
            display: flex;
            gap: 0.5rem;
            flex: 1;
            min-width: 200px;
        }

        .price-input {
            flex: 1;
            padding: 1rem;
            border: 2px solid transparent;
            border-radius: 12px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .price-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }

        .search-btn {
            padding: 1rem 2rem;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Products Section */
        .products-section {
            max-width: 1400px;
            margin: 6rem auto;
            padding: 0 2rem;
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-subtitle {
            color: var(--primary);
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: clamp(2rem, 4vw, 3rem);
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .section-title span {
            background: var(--gradient-2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
        }

        .product-card {
            background: var(--glass-bg);
            border-radius: 30px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            transition: all 0.3s ease;
            position: relative;
            border: var(--glass-border);
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .product-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-2xl);
        }

        .product-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            z-index: 10;
            background: var(--gradient-2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .product-wishlist {
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 10;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            color: var(--primary);
            font-size: 1.2rem;
        }

        .product-wishlist:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.1);
        }

        /* Fixed image container with consistent dimensions */
        .product-carousel {
            position: relative;
            width: 100%;
            height: 300px;
            overflow: hidden;
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
        }

        .product-carousel .swiper {
            width: 100%;
            height: 100%;
        }

        .product-carousel .swiper-slide {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
        }

        .product-carousel .swiper-slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            transition: transform 0.5s ease;
        }

        .product-card:hover .product-carousel .swiper-slide img {
            transform: scale(1.1);
        }

        .product-carousel .swiper-pagination {
            position: absolute;
            bottom: 10px;
            left: 0;
            right: 0;
            z-index: 10;
        }

        .product-carousel .swiper-pagination-bullet {
            width: 8px;
            height: 8px;
            background: white;
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .product-carousel .swiper-pagination-bullet-active {
            background: var(--primary);
            opacity: 1;
            transform: scale(1.2);
        }

        .product-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-category {
            color: var(--primary);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .product-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            line-height: 1.4;
            min-height: 3.5rem;
        }

        .product-price {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .current-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .old-price {
            font-size: 1rem;
            color: var(--gray);
            text-decoration: line-through;
        }

        .product-stock {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .stock-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .stock-indicator.in-stock {
            background: var(--accent);
            box-shadow: 0 0 10px var(--accent);
        }

        .stock-indicator.low-stock {
            background: #F59E0B;
            box-shadow: 0 0 10px #F59E0B;
        }

        .stock-indicator.out-of-stock {
            background: #EF4444;
            box-shadow: 0 0 10px #EF4444;
        }

        .stock-text {
            font-size: 0.9rem;
            color: var(--gray);
        }

        .product-actions {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            margin-top: auto;
        }

        .btn-add-cart {
            background: var(--gradient-2);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
        }

        .btn-add-cart:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-add-cart:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-quick-view {
            width: 50px;
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-quick-view:hover {
            background: var(--primary);
            color: white;
        }

        /* Features Section */
        .features-section {
            background: var(--gradient-2);
            padding: 6rem 2rem;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .features-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.1"><path d="M0 0 L100 100 M100 0 L0 100" stroke="white" stroke-width="1"/></svg>');
            background-size: 30px 30px;
        }

        .features-grid {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            position: relative;
            z-index: 1;
        }

        .feature-item {
            text-align: center;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 30px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }

        .feature-item:hover {
            transform: translateY(-10px);
            background: rgba(255, 255, 255, 0.2);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: var(--primary);
            font-size: 2rem;
            transition: all 0.3s ease;
        }

        .feature-item:hover .feature-icon {
            transform: rotateY(180deg);
        }

        .feature-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        /* About Section */
        .about-section {
            max-width: 1400px;
            margin: 6rem auto;
            padding: 0 2rem;
        }

        .about-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        .about-image {
            position: relative;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: var(--shadow-2xl);
            aspect-ratio: 4/3;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .about-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .about-image:hover img {
            transform: scale(1.1);
        }

        .about-image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(236, 72, 153, 0.2));
            pointer-events: none;
        }

        .about-image-badge {
            position: absolute;
            bottom: 30px;
            right: 30px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 1.5rem;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: float 6s ease-in-out infinite;
        }

        .about-image-badge i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .about-image-badge span {
            font-weight: 600;
            color: var(--dark);
        }

        .about-content {
            padding: 2rem;
        }

        .about-content h3 {
            font-size: clamp(1.8rem, 3vw, 2.5rem);
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.3;
            background: var(--gradient-2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .about-content p {
            color: var(--gray);
            margin-bottom: 2rem;
            line-height: 1.8;
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            margin: 3rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 1.5rem;
            background: var(--glass-bg);
            border-radius: 20px;
            border: var(--glass-border);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
            line-height: 1;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .about-cta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Newsletter Section */
        .newsletter-section {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            max-width: 1200px;
            margin: 6rem auto;
            padding: 4rem;
            text-align: center;
            border: var(--glass-border);
            box-shadow: var(--shadow-2xl);
        }

        .newsletter-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .newsletter-description {
            color: var(--gray);
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .newsletter-form {
            display: flex;
            gap: 1rem;
            max-width: 500px;
            margin: 0 auto;
        }

        .newsletter-input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: 2px solid transparent;
            border-radius: 12px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .newsletter-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }

        .newsletter-btn {
            padding: 1rem 2rem;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .newsletter-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Contact Section */
        .contact-section {
            max-width: 1200px;
            margin: 6rem auto;
            padding: 0 2rem;
        }

        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
        }

        .contact-info {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 3rem;
            border: var(--glass-border);
        }

        .contact-info h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 2rem;
        }

        .info-item {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .info-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient-2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .info-content h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .info-content p {
            color: var(--gray);
        }

        .contact-form {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 3rem;
            border: var(--glass-border);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 1rem 1.5rem;
            border: 2px solid transparent;
            border-radius: 12px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .form-textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }

        /* Map Section */
        .map-section {
            height: 500px;
            position: relative;
            overflow: hidden;
        }

        #location-map {
            height: 100%;
            width: 100%;
            z-index: 1;
        }

        .map-overlay {
            position: absolute;
            top: 50%;
            right: 50px;
            transform: translateY(-50%);
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            padding: 2rem;
            border-radius: 20px;
            border: var(--glass-border);
            max-width: 300px;
            z-index: 2;
            box-shadow: var(--shadow-2xl);
        }

        .map-overlay h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .map-overlay p {
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .direction-btn {
            display: inline-block;
            width: 100%;
            padding: 0.75rem 1.5rem;
            background: var(--gradient-2);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s ease;
        }

        .direction-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Footer */
        .footer {
            background: var(--darker);
            color: white;
            padding: 4rem 2rem 2rem;
            position: relative;
            overflow: hidden;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--primary), transparent);
        }

        .footer-grid {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            margin-bottom: 3rem;
        }

        .footer-logo img {
            height: 50px;
            width: auto;
            margin-bottom: 1rem;
            filter: brightness(0) invert(1);
        }

        .footer-logo p {
            color: rgba(255, 255, 255, 0.7);
            line-height: 1.6;
        }

        .footer h4 {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .footer h4::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 2px;
            background: var(--gradient-2);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-links a:hover {
            color: white;
            transform: translateX(5px);
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-link {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .social-link:hover {
            background: var(--primary);
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.9rem;
        }

        /* WhatsApp Float */
        .whatsapp-float {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            background: #25D366;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            text-decoration: none;
            box-shadow: 0 10px 30px rgba(37, 211, 102, 0.4);
            z-index: 999;
            transition: all 0.3s ease;
            animation: pulse 2s infinite;
        }

        .whatsapp-float:hover {
            transform: scale(1.1) translateY(-5px);
            box-shadow: 0 15px 40px rgba(37, 211, 102, 0.6);
        }

        /* Quick View Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: 30px;
            max-width: 1000px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
            position: relative;
            border: var(--glass-border);
            box-shadow: var(--shadow-2xl);
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            border-radius: 50%;
            color: var(--dark);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            background: var(--primary);
            color: white;
            transform: rotate(90deg);
        }

        .modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .modal-image {
            width: 100%;
            border-radius: 20px;
            overflow: hidden;
        }

        .modal-image img {
            width: 100%;
            height: auto;
            display: block;
        }

        .modal-info h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .modal-price {
            font-size: 2rem;
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .modal-stock {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .modal-description {
            color: var(--gray);
            margin-bottom: 2rem;
            line-height: 1.8;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
        }

        .modal-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 2rem;
            right: 2rem;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            padding: 1rem 1.5rem;
            border-radius: 16px;
            box-shadow: var(--shadow-2xl);
            z-index: 2000;
            border: var(--glass-border);
            display: flex;
            align-items: center;
            gap: 1rem;
            min-width: 350px;
            animation: slideIn 0.3s ease;
        }

        .toast.error {
            border-left: 4px solid #EF4444;
        }

        .toast.success {
            border-left: 4px solid var(--accent);
        }

        .toast.warning {
            border-left: 4px solid #F59E0B;
        }

        .toast-icon {
            font-size: 1.5rem;
        }

        .toast.error .toast-icon {
            color: #EF4444;
        }

        .toast.success .toast-icon {
            color: var(--accent);
        }

        .toast.warning .toast-icon {
            color: #F59E0B;
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .toast-message {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0.25rem;
            transition: color 0.3s ease;
        }

        .toast-close:hover {
            color: var(--primary);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7);
            }
            70% {
                box-shadow: 0 0 0 20px rgba(37, 211, 102, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(37, 211, 102, 0);
            }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .nav-menu {
                display: none;
            }

            .mobile-menu-btn {
                display: block;
            }

            .nav-icons {
                margin-left: auto;
            }

            .hero-cta {
                flex-direction: column;
                align-items: center;
            }

            .hero-cta .btn {
                width: 100%;
                max-width: 300px;
            }

            .about-grid,
            .contact-grid,
            .modal-grid {
                grid-template-columns: 1fr;
            }

            .map-overlay {
                position: relative;
                top: auto;
                right: auto;
                transform: none;
                margin: 2rem auto 0;
                max-width: 90%;
            }

            .map-section {
                height: auto;
                padding: 2rem;
            }

            #location-map {
                height: 400px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 1rem;
            }

            .logo img {
                height: 40px;
            }

            .user-welcome {
                padding: 0.3rem 0.8rem;
                font-size: 0.9rem;
            }

            .welcome-text {
                display: none;
            }

            .search-section {
                margin-top: -30px;
                padding: 0 1rem;
            }

            .search-container {
                padding: 1.5rem;
            }

            .search-form {
                flex-direction: column;
            }

            .search-group,
            .filter-select,
            .price-range,
            .search-btn {
                width: 100%;
            }

            .price-range {
                flex-direction: row;
            }

            .products-section {
                padding: 0 1rem;
            }

            .products-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .product-carousel {
                height: 250px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .about-cta {
                flex-direction: column;
            }

            .about-cta .btn {
                width: 100%;
            }

            .newsletter-section {
                margin: 3rem 1rem;
                padding: 2rem;
            }

            .newsletter-form {
                flex-direction: column;
            }

            .contact-info,
            .contact-form {
                padding: 2rem;
            }

            .footer-grid {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .footer h4::after {
                left: 50%;
                transform: translateX(-50%);
            }

            .social-links {
                justify-content: center;
            }

            .whatsapp-float {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
                bottom: 20px;
                right: 20px;
            }

            .toast {
                min-width: auto;
                width: calc(100% - 2rem);
                top: 1rem;
                right: 1rem;
                left: 1rem;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 2rem;
            }

            .hero-title span {
                font-size: 1.2rem;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .product-carousel {
                height: 200px;
            }

            .product-title {
                min-height: 2.8rem;
                font-size: 1rem;
            }

            .product-actions {
                grid-template-columns: 1fr;
            }

            .btn-quick-view {
                width: 100%;
                padding: 0.75rem;
            }

            .about-image-badge {
                bottom: 15px;
                right: 15px;
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
        }

        /* Loading States */
        .loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 30px;
            height: 30px;
            margin: -15px 0 0 -15px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Accessibility */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }

        :focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 2px;
        }

        /* Print Styles */
        @media print {
            .navbar,
            .hero-particles,
            .whatsapp-float,
            .modal,
            .toast,
            .footer {
                display: none !important;
            }

            body {
                background: white;
                color: black;
            }

            .product-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar" id="navbar">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <img src="images/logo.png" alt="Sesy Queen" onerror="this.src='images/default.jpg';">
            </a>

            <div class="nav-menu" id="navMenu">
                <a href="#home" class="nav-link active">Home</a>
                <a href="#products" class="nav-link">Products</a>
                <a href="#about" class="nav-link">About</a>
                <a href="#services" class="nav-link">Services</a>
                <a href="#contact" class="nav-link">Contact</a>
            </div>

            <div class="nav-icons">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Enhanced User Welcome -->
                    <div class="user-welcome">
                        <span class="welcome-text">Welcome back,</span>
                        <span class="username-highlight"><?php echo $user_name; ?></span>
                        <i class="bi bi-star-fill" style="color: #FFD700;"></i>
                    </div>

                    <a href="cart.php" class="icon-btn">
                        <i class="bi bi-cart3"></i>
                        <?php if ($cart_count > 0): ?>
                            <span class="badge"><?php echo $cart_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="wishlist.php" class="icon-btn">
                        <i class="bi bi-heart"></i>
                        <?php if ($wishlist_count > 0): ?>
                            <span class="badge"><?php echo $wishlist_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown">
                        <button class="icon-btn" id="userDropdown">
                            <i class="bi bi-person-circle"></i>
                        </button>
                        <div class="dropdown-menu">
                            <span class="dropdown-item">Hello, <?php echo $user_name; ?></span>
                            <a href="profile.php" class="dropdown-item">Profile</a>
                            <a href="order_tracking.php" class="dropdown-item">Orders</a>
                            <a href="logout.php" class="dropdown-item">Logout</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="login_user.php" class="icon-btn">
                        <i class="bi bi-box-arrow-in-right"></i>
                    </a>
                    <a href="register_user.php" class="icon-btn">
                        <i class="bi bi-person-plus"></i>
                    </a>
                <?php endif; ?>
                
                <button class="icon-btn" id="themeToggle">
                    <i class="bi bi-moon-stars"></i>
                </button>
            </div>

            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <i class="bi bi-list"></i>
            </button>
        </div>
    </nav>

    <!-- Mobile Menu -->
    <div class="mobile-menu" id="mobileMenu">
        <a href="#home" class="nav-link">Home</a>
        <a href="#products" class="nav-link">Products</a>
        <a href="#about" class="nav-link">About</a>
        <a href="#services" class="nav-link">Services</a>
        <a href="#contact" class="nav-link">Contact</a>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="toast show <?php echo strpos($message, 'success') !== false ? 'success' : (strpos($message, 'warning') !== false ? 'warning' : 'error'); ?>">
            <div class="toast-icon">
                <i class="bi bi-<?php echo strpos($message, 'success') !== false ? 'check-circle-fill' : (strpos($message, 'warning') !== false ? 'exclamation-triangle-fill' : 'x-circle-fill'); ?>"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title"><?php echo strpos($message, 'success') !== false ? 'Success' : (strpos($message, 'warning') !== false ? 'Warning' : 'Error'); ?></div>
                <div class="toast-message"><?php echo strip_tags($message); ?></div>
            </div>
            <button class="toast-close" onclick="this.parentElement.classList.remove('show')">
                <i class="bi bi-x"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Welcome Message for New Login -->
    <?php if ($show_welcome): ?>
        <div class="toast show success" id="welcomeToast">
            <div class="toast-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">🎉 Welcome Back!</div>
                <div class="toast-message">Hello, <?php echo htmlspecialchars($user_name); ?>! You have successfully logged in.</div>
            </div>
            <button class="toast-close" onclick="this.closest('#welcomeToast').style.animation='slideOut 0.4s ease forwards';setTimeout(()=>this.closest('#welcomeToast').remove(),400)">
                <i class="bi bi-x"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="hero-particles" id="particles"></div>
        <div class="hero-content" data-aos="fade-up">
            <span class="hero-badge">Welcome to Sesy Queen</span>
            <h1 class="hero-title">
                Premium Kitchenware
                <span>For Modern Living</span>
            </h1>
            <p class="hero-subtitle">Discover our collection of innovative and elegant kitchen solutions designed for South African homes.</p>
            <div class="hero-cta">
                <a href="#products" class="btn btn-primary">
                    <i class="bi bi-shop"></i>
                    Shop Now
                </a>
                <a href="#about" class="btn btn-outline">
                    <i class="bi bi-play-circle"></i>
                    Learn More
                </a>
            </div>
        </div>
    </section>

    <!-- Search Section -->
    <section class="search-section">
        <div class="search-container" data-aos="fade-up">
            <form class="search-form" action="index.php" method="post" id="searchForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="search-group">
                    <i class="bi bi-search"></i>
                    <input type="search" name="search" class="search-input" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>" id="searchInput">
                    <div id="suggestions" class="suggestions"></div>
                </div>
                
                <select name="category" class="filter-select">
                    <option value="">All Categories</option>
                    <?php
                    try {
                        $stmt = $conn->query("SELECT DISTINCT category FROM products");
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $selected = $row['category'] === $category ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($row['category']) . "' $selected>" . htmlspecialchars($row['category']) . "</option>";
                        }
                    } catch (PDOException $e) {
                        error_log("Category query failed: " . $e->getMessage());
                    }
                    ?>
                </select>
                
                <select name="sort" class="filter-select">
                    <option value="price ASC" <?php echo $sort === 'price ASC' ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price DESC" <?php echo $sort === 'price DESC' ? 'selected' : ''; ?>>Price: High to Low</option>
                    <option value="item ASC" <?php echo $sort === 'item ASC' ? 'selected' : ''; ?>>Name: A to Z</option>
                </select>
                
                <div class="price-range">
                    <input type="number" name="price_min" class="price-input" placeholder="Min" value="<?php echo $price_min > 0 ? $price_min : ''; ?>">
                    <input type="number" name="price_max" class="price-input" placeholder="Max" value="<?php echo $price_max < PHP_INT_MAX ? $price_max : ''; ?>">
                </div>
                
                <button type="submit" class="search-btn">
                    <i class="bi bi-search"></i>
                    Search
                </button>
            </form>
        </div>
    </section>

    <!-- Products Section -->
    <section class="products-section" id="products">
        <div class="section-header" data-aos="fade-up">
            <span class="section-subtitle">Our Collection</span>
            <h2 class="section-title">Featured <span>Products</span></h2>
        </div>
        
        <div class="products-grid">
            <?php if (empty($products)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 4rem;">
                    <i class="bi bi-emoji-frown" style="font-size: 4rem; color: var(--gray);"></i>
                    <h3 style="margin-top: 1rem;">No products found</h3>
                    <p style="color: var(--gray);">Try adjusting your search filters</p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $index => $row): ?>
                    <?php
                        $stock = (int)$row['quantity'];
                        if ($stock > 5) {
                            $stock_class = 'in-stock';
                            $stock_text = 'In Stock';
                            $stock_detail = "$stock available";
                        } elseif ($stock > 0) {
                            $stock_class = 'low-stock';
                            $stock_text = 'Low Stock';
                            $stock_detail = "Only $stock left!";
                        } else {
                            $stock_class = 'out-of-stock';
                            $stock_text = 'Out of Stock';
                            $stock_detail = 'Currently unavailable';
                        }
                    ?>
                    
                    <div class="product-card" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <?php if ($stock <= 5 && $stock > 0): ?>
                            <span class="product-badge">Limited Stock</span>
                        <?php endif; ?>
                        
                        <button class="product-wishlist" onclick="addToWishlist(<?php echo $row['id']; ?>)">
                            <i class="bi bi-heart"></i>
                        </button>
                        
                        <div class="product-carousel">
                            <div class="swiper product-swiper-<?php echo $row['id']; ?>">
                                <div class="swiper-wrapper">
                                    <div class="swiper-slide">
                                        <img src="images/<?php echo htmlspecialchars($row['image']); ?>" alt="<?php echo htmlspecialchars($row['item']); ?>" onerror="this.src='images/default.jpg';">
                                    </div>
                                    <?php if (!empty($row['image2'])): ?>
                                        <div class="swiper-slide">
                                            <img src="images/<?php echo htmlspecialchars($row['image2']); ?>" alt="<?php echo htmlspecialchars($row['item']); ?>" onerror="this.src='images/default.jpg';">
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['image3'])): ?>
                                        <div class="swiper-slide">
                                            <img src="images/<?php echo htmlspecialchars($row['image3']); ?>" alt="<?php echo htmlspecialchars($row['item']); ?>" onerror="this.src='images/default.jpg';">
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="swiper-pagination"></div>
                            </div>
                        </div>
                        
                        <div class="product-body">
                            <div class="product-category"><?php echo htmlspecialchars($row['category']); ?></div>
                            <h3 class="product-title"><?php echo htmlspecialchars($row['item']); ?></h3>
                            
                            <div class="product-price">
                                <span class="current-price">R<?php echo number_format($row['price'], 2); ?></span>
                                <?php if ($row['price'] > 100): ?>
                                    <span class="old-price">R<?php echo number_format($row['price'] * 1.2, 2); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-stock">
                                <span class="stock-indicator <?php echo $stock_class; ?>"></span>
                                <span class="stock-text"><?php echo $stock_detail; ?></span>
                            </div>
                            
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <div class="product-actions">
                                    <form method="post" action="add_to_cart.php" style="flex: 1;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($row['id']); ?>">
                                        <button type="submit" name="add_to_cart" class="btn-add-cart" <?php echo $stock === 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-cart-plus"></i>
                                            <?php echo $stock === 0 ? 'Out of Stock' : 'Add to Cart'; ?>
                                        </button>
                                    </form>
                                    <button class="btn-quick-view" onclick="openQuickView(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <a href="login_user.php" class="btn-add-cart" style="text-decoration: none; text-align: center;">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                    Login to Purchase
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="services">
        <div class="features-grid">
            <div class="feature-item" data-aos="fade-up">
                <div class="feature-icon">
                    <i class="bi bi-truck"></i>
                </div>
                <h3 class="feature-title">Nationwide Delivery</h3>
                <p class="feature-description">Fast and reliable delivery across South Africa for R140</p>
            </div>
            
            <div class="feature-item" data-aos="fade-up" data-aos-delay="100">
                <div class="feature-icon">
                    <i class="bi bi-headset"></i>
                </div>
                <h3 class="feature-title">24/7 Support</h3>
                <p class="feature-description">Round-the-clock customer support for all your needs</p>
            </div>
            
            <div class="feature-item" data-aos="fade-up" data-aos-delay="200">
                <div class="feature-icon">
                    <i class="bi bi-gear"></i>
                </div>
                <h3 class="feature-title">Customization</h3>
                <p class="feature-description">Personalized kitchenware to suit your style</p>
            </div>
            
            <div class="feature-item" data-aos="fade-up" data-aos-delay="300">
                <div class="feature-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <h3 class="feature-title">Warranty</h3>
                <p class="feature-description">Hassle-free warranty and after-sales support</p>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about-section" id="about">
        <div class="about-grid">
            <div class="about-image" data-aos="fade-right">
                <?php
                // Check if about image exists, otherwise use first product image
                $about_image = 'images/about.jpg';
                if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $about_image) && !empty($products)) {
                    $about_image = 'images/' . $products[0]['image'];
                }
                ?>
                <img src="<?php echo $about_image; ?>" 
                     alt="About Sesy Queen" 
                     onerror="this.onerror=null; this.src='images/default.jpg';"
                     loading="lazy">
                
                <div class="about-image-overlay"></div>
                <div class="about-image-badge">
                    <i class="bi bi-award-fill"></i>
                    <span>Est. 2020</span>
                </div>
            </div>
            
            <div class="about-content" data-aos="fade-left">
                <span class="section-subtitle">About Us</span>
                <h3>Your Trusted Kitchen Partner</h3>
                <p>Sesy Queen (Pty) Ltd is dedicated to bringing innovative kitchenware solutions to South African homes, with a focus on quality and style. We believe that every meal deserves the perfect setting, and every kitchen deserves the best tools.</p>
                
                <div class="stats-grid">
                    <div class="stat-item" data-aos="fade-up" data-aos-delay="100">
                        <div class="stat-number">5+</div>
                        <div class="stat-label">Years Experience</div>
                    </div>
                    <div class="stat-item" data-aos="fade-up" data-aos-delay="200">
                        <div class="stat-number">1000+</div>
                        <div class="stat-label">Happy Customers</div>
                    </div>
                    <div class="stat-item" data-aos="fade-up" data-aos-delay="300">
                        <div class="stat-number">50+</div>
                        <div class="stat-label">Products</div>
                    </div>
                </div>
                
                <div class="about-cta" data-aos="fade-up" data-aos-delay="400">
                    <a href="#contact" class="btn btn-primary">
                        <i class="bi bi-chat-dots"></i>
                        Get in Touch
                    </a>
                    <a href="#products" class="btn btn-outline">
                        <i class="bi bi-shop"></i>
                        Browse Products
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Newsletter Section -->
    <section class="newsletter-section" data-aos="fade-up">
        <h2 class="newsletter-title">Subscribe to Our Newsletter</h2>
        <p class="newsletter-description">Stay updated with exclusive offers, new arrivals, and kitchen inspiration delivered straight to your inbox.</p>
        
        <form method="post" class="newsletter-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="email" name="subscribe_email" class="newsletter-input" placeholder="Enter your email address" required>
            <button type="submit" class="newsletter-btn">
                <i class="bi bi-envelope-paper"></i>
                Subscribe
            </button>
        </form>
    </section>

    <!-- Contact Section -->
    <section class="contact-section" id="contact">
        <div class="section-header" data-aos="fade-up">
            <span class="section-subtitle">Get In Touch</span>
            <h2 class="section-title">Contact <span>Us</span></h2>
        </div>
        
        <div class="contact-grid">
            <div class="contact-info" data-aos="fade-right">
                <h3>Let's Talk</h3>
                
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-geo-alt"></i>
                    </div>
                    <div class="info-content">
                        <h4>Visit Us</h4>
                        <p>12 Smuts Street, Randfontein<br>South Africa</p>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-telephone"></i>
                    </div>
                    <div class="info-content">
                        <h4>Call Us</h4>
                        <p>079 441 6767</p>
                        <p>Mon-Fri, 8am-5pm</p>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-envelope"></i>
                    </div>
                    <div class="info-content">
                        <h4>Email Us</h4>
                        <p>info@sunrisenwse.co.za</p>
                        <p>We'll respond within 24 hours</p>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="info-content">
                        <h4>Business Hours</h4>
                        <p>Monday - Friday: 8am - 5pm</p>
                        <p>Saturday: 9am - 1pm</p>
                        <p>Sunday: Closed</p>
                    </div>
                </div>
            </div>
            
            <div class="contact-form" data-aos="fade-left">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    
                    <div class="form-group">
                        <input type="email" name="email" class="form-input" placeholder="Your Email" required>
                    </div>
                    
                    <div class="form-group">
                        <textarea name="message" class="form-textarea" placeholder="Your Message" required></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="bi bi-send"></i>
                        Send Message
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- Map Section -->
    <section class="map-section">
        <div id="location-map"></div>
        <div class="map-overlay" data-aos="fade-left">
            <h3>Our Location</h3>
            <p>12 Smuts Street, Randfontein, South Africa</p>
            <a href="https://www.google.com/maps/dir/?api=1&destination=-26.1766,27.7047" target="_blank" class="direction-btn">
                <i class="bi bi-compass"></i>
                Get Directions
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-grid">
            <div class="footer-logo">
                <img src="images/logo.png" alt="Sesy Queen" onerror="this.src='images/default.jpg';">
                <p>Premium kitchenware for South African homes, bringing innovation and elegance to your kitchen.</p>
                <div class="social-links">
                    <a href="#" class="social-link"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="social-link"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="social-link"><i class="bi bi-twitter-x"></i></a>
                    <a href="#" class="social-link"><i class="bi bi-pinterest"></i></a>
                </div>
            </div>
            
            <div>
                <h4>Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="#home"><i class="bi bi-chevron-right"></i> Home</a></li>
                    <li><a href="#products"><i class="bi bi-chevron-right"></i> Products</a></li>
                    <li><a href="#about"><i class="bi bi-chevron-right"></i> About</a></li>
                    <li><a href="#services"><i class="bi bi-chevron-right"></i> Services</a></li>
                    <li><a href="#contact"><i class="bi bi-chevron-right"></i> Contact</a></li>
                </ul>
            </div>
            
            <div>
                <h4>Customer Service</h4>
                <ul class="footer-links">
                    <li><a href="#"><i class="bi bi-chevron-right"></i> FAQ</a></li>
                    <li><a href="#"><i class="bi bi-chevron-right"></i> Shipping Policy</a></li>
                    <li><a href="#"><i class="bi bi-chevron-right"></i> Returns & Exchanges</a></li>
                    <li><a href="#"><i class="bi bi-chevron-right"></i> Privacy Policy</a></li>
                    <li><a href="#"><i class="bi bi-chevron-right"></i> Terms & Conditions</a></li>
                </ul>
            </div>
            
            <div>
                <h4>Contact Info</h4>
                <ul class="footer-links">
                    <li><i class="bi bi-geo-alt"></i> 12 Smuts Street, Randfontein</li>
                    <li><i class="bi bi-telephone"></i> 079 441 6767</li>
                    <li><i class="bi bi-envelope"></i> info@sunrisenwse.co.za</li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Sesy Queen (Pty) Ltd. All rights reserved. | Designed with <i class="bi bi-heart-fill" style="color: var(--secondary);"></i> in South Africa</p>
        </div>
    </footer>

    <!-- WhatsApp Float -->
    <a href="https://wa.me/27794416767" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="bi bi-whatsapp"></i>
    </a>

    <!-- Quick View Modal -->
    <div class="modal" id="quickViewModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeQuickView()">
                <i class="bi bi-x-lg"></i>
            </button>
            <div class="modal-grid" id="quickViewContent"></div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 1000,
            once: true,
            offset: 100,
            easing: 'ease-in-out'
        });

        // Refresh AOS after images load
        window.addEventListener('load', function() {
            AOS.refresh();
        });

        // Navbar scroll effect
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Mobile Menu
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const mobileMenuIcon = mobileMenuBtn.querySelector('i');

        mobileMenuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('active');
            if (mobileMenu.classList.contains('active')) {
                mobileMenuIcon.className = 'bi bi-x-lg';
                document.body.style.overflow = 'hidden';
            } else {
                mobileMenuIcon.className = 'bi bi-list';
                document.body.style.overflow = 'auto';
            }
        });

        // Close mobile menu when clicking a link
        document.querySelectorAll('.mobile-menu .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                mobileMenu.classList.remove('active');
                mobileMenuIcon.className = 'bi bi-list';
                document.body.style.overflow = 'auto';
            });
        });

        // Dark mode toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        themeToggle.addEventListener('click', () => {
            const currentTheme = document.body.dataset.theme;
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.body.dataset.theme = newTheme;
            localStorage.setItem('theme', newTheme);
            
            if (newTheme === 'dark') {
                themeIcon.className = 'bi bi-brightness-high-fill';
            } else {
                themeIcon.className = 'bi bi-moon-stars';
            }
        });

        // Load saved theme
        if (localStorage.getItem('theme') === 'dark') {
            document.body.dataset.theme = 'dark';
            themeIcon.className = 'bi bi-brightness-high-fill';
        }

        // Particles.js
        particlesJS('particles', {
            particles: {
                number: {
                    value: 80,
                    density: {
                        enable: true,
                        value_area: 800
                    }
                },
                color: {
                    value: '#ffffff'
                },
                shape: {
                    type: 'circle'
                },
                opacity: {
                    value: 0.5,
                    random: false
                },
                size: {
                    value: 3,
                    random: true
                },
                line_linked: {
                    enable: true,
                    distance: 150,
                    color: '#ffffff',
                    opacity: 0.4,
                    width: 1
                },
                move: {
                    enable: true,
                    speed: 6,
                    direction: 'none',
                    random: false,
                    straight: false,
                    out_mode: 'out',
                    bounce: false
                }
            },
            interactivity: {
                detect_on: 'canvas',
                events: {
                    onhover: {
                        enable: true,
                        mode: 'repulse'
                    },
                    onclick: {
                        enable: true,
                        mode: 'push'
                    },
                    resize: true
                }
            },
            retina_detect: true
        });

        // Initialize Swiper for each product
        <?php foreach ($products as $row): ?>
            new Swiper('.product-swiper-<?php echo $row['id']; ?>', {
                loop: true,
                autoplay: {
                    delay: 3000,
                    disableOnInteraction: false,
                },
                pagination: {
                    el: '.swiper-pagination',
                    clickable: true,
                },
            });
        <?php endforeach; ?>

        // Live search with debounce
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        const suggestions = document.getElementById('suggestions');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                const query = searchInput.value;
                
                if (query.length < 2) {
                    suggestions.style.display = 'none';
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    fetch(`search_suggestions.php?query=${encodeURIComponent(query)}`)
                        .then(response => response.text())
                        .then(data => {
                            suggestions.innerHTML = data;
                            suggestions.style.display = 'block';
                        });
                }, 300);
            });
        }

        // Close suggestions when clicking outside
        document.addEventListener('click', (e) => {
            if (suggestions && !searchInput.contains(e.target) && !suggestions.contains(e.target)) {
                suggestions.style.display = 'none';
            }
        });

        // Form validation and loading states
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    form.classList.add('was-validated');
                } else {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.classList.add('loading');
                        submitBtn.disabled = true;
                    }
                }
            });
        });

        // Quick View functions
        function openQuickView(product) {
            const modal = document.getElementById('quickViewModal');
            const content = document.getElementById('quickViewContent');
            
            const stockClass = product.quantity > 5 ? 'in-stock' : (product.quantity > 0 ? 'low-stock' : 'out-of-stock');
            const stockText = product.quantity > 5 ? 'In Stock' : (product.quantity > 0 ? 'Low Stock' : 'Out of Stock');
            const stockDetail = product.quantity > 5 ? `${product.quantity} available` : 
                               (product.quantity > 0 ? `Only ${product.quantity} left!` : 'Currently unavailable');
            
            content.innerHTML = `
                <div class="modal-image">
                    <img src="images/${product.image}" alt="${product.item}" onerror="this.src='images/default.jpg';">
                </div>
                <div class="modal-info">
                    <span class="section-subtitle">${product.category}</span>
                    <h2>${product.item}</h2>
                    <div class="modal-price">R${Number(product.price).toFixed(2)}</div>
                    
                    <div class="modal-stock">
                        <span class="stock-indicator ${stockClass}"></span>
                        <span>${stockDetail}</span>
                    </div>
                    
                    <p class="modal-description">
                        Premium kitchenware designed for elegance and functionality. Perfect for modern South African homes.
                    </p>
                    
                    <div class="modal-actions">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <form method="post" action="add_to_cart.php" style="flex: 1;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="product_id" value="${product.id}">
                                <button type="submit" class="btn btn-primary" ${product.quantity == 0 ? 'disabled' : ''}>
                                    <i class="bi bi-cart-plus"></i>
                                    ${product.quantity == 0 ? 'Out of Stock' : 'Add to Cart'}
                                </button>
                            </form>
                            
                            <form method="post" action="add_to_wishlist.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="product_id" value="${product.id}">
                                <button type="submit" class="btn btn-outline">
                                    <i class="bi bi-heart"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="login_user.php" class="btn btn-primary" style="flex: 1;">
                                <i class="bi bi-box-arrow-in-right"></i>
                                Login to Purchase
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeQuickView() {
            document.getElementById('quickViewModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        document.getElementById('quickViewModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('quickViewModal')) {
                closeQuickView();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeQuickView();
            }
        });

        // Add to wishlist function
        function addToWishlist(productId) {
            fetch('add_to_wishlist.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `product_id=${productId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.success ? 'success' : 'error', data.message);
            });
        }

        // Toast notification
        function showToast(type, message) {
            const toast = document.createElement('div');
            toast.className = `toast show ${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'x-circle-fill'}"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${type === 'success' ? 'Success' : 'Error'}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="bi bi-x"></i>
                </button>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Initialize map
        const map = L.map('location-map').setView([-26.1766, 27.7047], 15);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        const marker = L.marker([-26.1766, 27.7047]).addTo(map);
        marker.bindPopup(`
            <b>Sesy Queen</b><br>
            12 Smuts Street, Randfontein<br>
            <a href="https://www.google.com/maps/dir/?api=1&destination=-26.1766,27.7047" target="_blank">Get Directions</a>
        `).openPopup();

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Active nav link on scroll
        const sections = document.querySelectorAll('section');
        const navLinks = document.querySelectorAll('.nav-link');

        window.addEventListener('scroll', () => {
            let current = '';
            
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                const sectionHeight = section.clientHeight;
                if (window.scrollY >= sectionTop - 200) {
                    current = section.getAttribute('id');
                }
            });

            navLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === `#${current}`) {
                    link.classList.add('active');
                }
            });
        });

        // Auto-hide toast messages
        setTimeout(() => {
            document.querySelectorAll('.toast').forEach(toast => {
                toast.classList.remove('show');
            });
        }, 5000);

        // Dropdown menu
        const userDropdown = document.getElementById('userDropdown');
        if (userDropdown) {
            userDropdown.addEventListener('click', (e) => {
                e.stopPropagation();
                const dropdown = document.querySelector('.dropdown-menu');
                dropdown.classList.toggle('show');
            });

            document.addEventListener('click', () => {
                document.querySelector('.dropdown-menu')?.classList.remove('show');
            });
        }

        // Auto-hide welcome toast after 5 seconds
        <?php if ($show_welcome): ?>
            setTimeout(() => {
                const welcomeToast = document.getElementById('welcomeToast');
                if (welcomeToast) {
                    welcomeToast.style.animation = 'slideOut 0.4s ease forwards';
                    setTimeout(() => welcomeToast.remove(), 400);
                }
            }, 4000);
            
            // Remove the login parameter from URL without refreshing
            if (window.history.replaceState) {
                const url = new URL(window.location);
                url.searchParams.delete('login');
                window.history.replaceState({}, document.title, url);
            }
        <?php endif; ?>
    </script>
</body>
</html>