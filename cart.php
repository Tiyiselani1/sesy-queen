<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token for form security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$conn = include 'includes/db_connect.php';
if (!is_object($conn)) {
    die("Database connection failed.");
}

$cart_items = [];
$total = 0;
$message = '';

if (isset($_SESSION['user_id'])) {
    try {
        // Fetch cart items with product details
        $stmt = $conn->prepare("
            SELECT c.id, c.product_id, c.quantity, p.item, p.price, p.image, p.quantity as stock_quantity
            FROM cart c
            JOIN products p ON c.product_id = p.id
            WHERE c.user_id = :user_id
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate total
        foreach ($cart_items as $item) {
            $total += $item['price'] * $item['quantity'];
        }

        // Handle quantity update or item removal
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
            if (isset($_POST['update_quantity'])) {
                $cart_id = (int)$_POST['cart_id'];
                $quantity = (int)$_POST['quantity'];
                if ($quantity > 0) {
                    // Check stock availability
                    $current_item = array_filter($cart_items, fn($item) => $item['id'] == $cart_id);
                    $current_item = reset($current_item);
                    
                    if ($quantity <= $current_item['stock_quantity']) {
                        $stmt = $conn->prepare("UPDATE cart SET quantity = :quantity WHERE id = :cart_id AND user_id = :user_id");
                        $stmt->execute([':quantity' => $quantity, ':cart_id' => $cart_id, ':user_id' => $_SESSION['user_id']]);
                        $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>Quantity updated!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    } else {
                        $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Requested quantity exceeds available stock!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    }
                } else {
                    $stmt = $conn->prepare("DELETE FROM cart WHERE id = :cart_id AND user_id = :user_id");
                    $stmt->execute([':cart_id' => $cart_id, ':user_id' => $_SESSION['user_id']]);
                    $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>Item removed from cart!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                }
                // Refresh cart items after update
                $stmt = $conn->prepare("
                    SELECT c.id, c.product_id, c.quantity, p.item, p.price, p.image, p.quantity as stock_quantity
                    FROM cart c
                    JOIN products p ON c.product_id = p.id
                    WHERE c.user_id = :user_id
                ");
                $stmt->execute([':user_id' => $_SESSION['user_id']]);
                $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $total = 0;
                foreach ($cart_items as $item) {
                    $total += $item['price'] * $item['quantity'];
                }
            } elseif (isset($_POST['remove_item'])) {
                $cart_id = (int)$_POST['cart_id'];
                $stmt = $conn->prepare("DELETE FROM cart WHERE id = :cart_id AND user_id = :user_id");
                $stmt->execute([':cart_id' => $cart_id, ':user_id' => $_SESSION['user_id']]);
                $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>Item removed from cart!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                // Refresh cart items
                $stmt = $conn->prepare("
                    SELECT c.id, c.product_id, c.quantity, p.item, p.price, p.image, p.quantity as stock_quantity
                    FROM cart c
                    JOIN products p ON c.product_id = p.id
                    WHERE c.user_id = :user_id
                ");
                $stmt->execute([':user_id' => $_SESSION['user_id']]);
                $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $total = 0;
                foreach ($cart_items as $item) {
                    $total += $item['price'] * $item['quantity'];
                }
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-x-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Invalid CSRF token.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
        }
    } catch (PDOException $e) {
        error_log("Cart query failed: " . $e->getMessage());
        $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Error loading cart. Please try again.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
    }
} else {
    header("Location: login_user.php");
    exit;
}

// Get cart and wishlist counts for navbar
$cart_count = count($cart_items);
$wishlist_count = 0;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $wishlist_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Wishlist count query failed: " . $e->getMessage());
    }
}

// Calculate estimated delivery
$delivery_fee = 140;
$grand_total = $total + ($total > 0 ? $delivery_fee : 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Your Shopping Cart - Sesy Queen Premium Kitchenware">
    <meta name="theme-color" content="#8B5CF6">
    <title>Your Cart - Sesy Queen</title>
    
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
    
    <style>
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
            min-height: 100vh;
            display: flex;
            flex-direction: column;
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
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 2rem;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-lg);
            border-bottom: var(--glass-border);
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

        /* Main Content */
        .main-content {
            flex: 1;
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
            width: 100%;
        }

        /* Cart Container */
        .cart-container {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 2rem;
            box-shadow: var(--shadow-2xl);
            border: var(--glass-border);
        }

        .section-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-subtitle {
            color: var(--primary);
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 0.5rem;
        }

        .section-title {
            font-size: clamp(2rem, 4vw, 2.5rem);
            font-weight: 700;
        }

        .section-title span {
            background: var(--gradient-2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Cart Table */
        .cart-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 1rem;
        }

        .cart-table th {
            padding: 1rem;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 2px solid rgba(139, 92, 246, 0.2);
        }

        .cart-table td {
            padding: 1rem;
            vertical-align: middle;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }

        .product-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
        }

        .product-name {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .product-price {
            font-weight: 600;
            color: var(--primary);
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quantity-input {
            width: 80px;
            padding: 0.5rem;
            border: 2px solid transparent;
            border-radius: 8px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            color: var(--dark);
            transition: all 0.3s ease;
            text-align: center;
        }

        .quantity-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.15);
        }

        .btn-update {
            padding: 0.5rem 1rem;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-remove {
            padding: 0.5rem;
            background: none;
            border: none;
            color: #EF4444;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 50%;
        }

        .btn-remove:hover {
            background: rgba(239, 68, 68, 0.1);
            transform: scale(1.1);
        }

        .subtotal {
            font-weight: 700;
            color: var(--primary);
        }

        /* Cart Summary */
        .cart-summary {
            margin-top: 2rem;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            border: var(--glass-border);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
        }

        .summary-row.total {
            border-bottom: none;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .summary-label {
            color: var(--gray);
        }

        .summary-value {
            font-weight: 600;
        }

        .delivery-note {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 12px;
            color: var(--accent);
        }

        .checkout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 1rem;
            margin-top: 2rem;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .checkout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4);
        }

        .checkout-btn i {
            transition: transform 0.3s ease;
        }

        .checkout-btn:hover i {
            transform: translateX(5px);
        }

        /* Empty Cart */
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-cart-icon {
            font-size: 5rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-cart h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .empty-cart p {
            color: var(--gray);
            margin-bottom: 2rem;
        }

        .continue-shopping {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            background: var(--gradient-2);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .continue-shopping:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
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
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            width: 30px;
            height: 30px;
        }

        .toast-close:hover {
            background: rgba(0, 0, 0, 0.1);
            color: var(--primary);
            transform: rotate(90deg);
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

        /* Back to Top Button */
        #back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 1.5rem;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-2xl);
            z-index: 999;
            transition: all 0.3s ease;
            animation: fadeIn 0.3s ease;
        }

        #back-to-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Footer */
        .footer {
            background: var(--darker);
            color: white;
            padding: 3rem 2rem 1.5rem;
            margin-top: 4rem;
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

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .footer-info {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .footer-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .footer-info-item i {
            color: var(--primary);
        }

        .footer-copyright {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.9rem;
        }

        /* Loading Spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Responsive Design */
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

            .main-content {
                padding: 0 1rem;
            }

            .cart-container {
                padding: 1.5rem;
            }

            .cart-table,
            .cart-table thead,
            .cart-table tbody,
            .cart-table tr,
            .cart-table td {
                display: block;
            }

            .cart-table thead {
                display: none;
            }

            .cart-table tr {
                margin-bottom: 1.5rem;
                padding: 1rem;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 12px;
            }

            .cart-table td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0.5rem;
                border-bottom: 1px solid rgba(139, 92, 246, 0.1);
            }

            .cart-table td:last-child {
                border-bottom: none;
            }

            .cart-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--gray);
            }

            .product-image {
                width: 60px;
                height: 60px;
            }

            .quantity-control {
                width: 100%;
                justify-content: flex-end;
            }

            .toast {
                min-width: auto;
                width: calc(100% - 2rem);
                top: 1rem;
                right: 1rem;
                left: 1rem;
            }

            .footer-content {
                flex-direction: column;
                text-align: center;
            }

            .footer-info {
                justify-content: center;
            }

            #back-to-top {
                bottom: 20px;
                right: 20px;
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }
        }

        @media (max-width: 480px) {
            .footer-info {
                flex-direction: column;
                align-items: center;
            }
        }

        /* Dark Mode Support */
        [data-theme="dark"] .cart-table td {
            background: rgba(255, 255, 255, 0.02);
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
                <a href="index.php" class="nav-link">Home</a>
                <a href="index.php#products" class="nav-link">Products</a>
                <a href="index.php#about" class="nav-link">About</a>
                <a href="index.php#services" class="nav-link">Services</a>
                <a href="index.php#contact" class="nav-link">Contact</a>
            </div>

            <div class="nav-icons">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Enhanced User Welcome -->
                    <div class="user-welcome">
                        <span class="welcome-text">Welcome,</span>
                        <span class="username-highlight"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
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
                            <span class="dropdown-item">Hello, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
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
        <a href="index.php" class="nav-link">Home</a>
        <a href="index.php#products" class="nav-link">Products</a>
        <a href="index.php#about" class="nav-link">About</a>
        <a href="index.php#services" class="nav-link">Services</a>
        <a href="index.php#contact" class="nav-link">Contact</a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="profile.php" class="nav-link">Profile</a>
            <a href="order_tracking.php" class="nav-link">Orders</a>
            <a href="logout.php" class="nav-link">Logout</a>
        <?php else: ?>
            <a href="login_user.php" class="nav-link">Login</a>
            <a href="register_user.php" class="nav-link">Register</a>
        <?php endif; ?>
    </div>

    <!-- Toast Messages -->
    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="cart-container" data-aos="fade-up">
            <div class="section-header">
                <div class="section-subtitle">Your Cart</div>
                <h1 class="section-title">Shopping <span>Cart</span></h1>
            </div>

            <?php if (empty($cart_items)): ?>
                <div class="empty-cart">
                    <div class="empty-cart-icon">
                        <i class="bi bi-cart-x"></i>
                    </div>
                    <h3>Your cart is empty</h3>
                    <p>Looks like you haven't added any items to your cart yet.</p>
                    <a href="index.php#products" class="continue-shopping">
                        <i class="bi bi-arrow-left"></i>
                        Continue Shopping
                    </a>
                </div>
            <?php else: ?>
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cart_items as $item): ?>
                            <tr>
                                <td data-label="Product">
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <img src="images/<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['item']); ?>" class="product-image" onerror="this.src='images/default.jpg';">
                                        <span class="product-name"><?php echo htmlspecialchars($item['item']); ?></span>
                                    </div>
                                </td>
                                <td data-label="Price">
                                    <span class="product-price">R<?php echo number_format($item['price'], 2); ?></span>
                                </td>
                                <td data-label="Quantity">
                                    <div class="quantity-control">
                                        <form method="post" style="display: flex; gap: 0.5rem; align-items: center;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="cart_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                            <input type="number" name="quantity" value="<?php echo htmlspecialchars($item['quantity']); ?>" min="0" max="<?php echo $item['stock_quantity']; ?>" class="quantity-input">
                                            <button type="submit" name="update_quantity" class="btn-update" aria-label="Update quantity">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                <td data-label="Subtotal">
                                    <span class="subtotal">R<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                                </td>
                                <td data-label="Actions">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="cart_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                        <button type="submit" name="remove_item" class="btn-remove" aria-label="Remove item">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="cart-summary">
                    <div class="summary-row">
                        <span class="summary-label">Subtotal</span>
                        <span class="summary-value">R<?php echo number_format($total, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Delivery Fee</span>
                        <span class="summary-value">R<?php echo number_format($delivery_fee, 2); ?></span>
                    </div>
                    <div class="summary-row total">
                        <span class="summary-label">Total</span>
                        <span class="summary-value">R<?php echo number_format($grand_total, 2); ?></span>
                    </div>
                    
                    <div class="delivery-note">
                        <i class="bi bi-truck"></i>
                        <span>Free delivery on orders over R1000!</span>
                    </div>

                    <a href="order_now.php" class="checkout-btn">
                        <span>Proceed to Checkout</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-info">
                <div class="footer-info-item">
                    <i class="bi bi-telephone"></i>
                    <span>079 441 6767</span>
                </div>
                <div class="footer-info-item">
                    <i class="bi bi-truck"></i>
                    <span>Nationwide Delivery: R140</span>
                </div>
                <div class="footer-info-item">
                    <i class="bi bi-geo-alt"></i>
                    <span>Krugersdorp</span>
                </div>
            </div>
            <a href="index.php" class="logo">
                <img src="images/logo.png" alt="Sesy Queen" style="height: 40px;" onerror="this.src='images/default.jpg';">
            </a>
        </div>
        <div class="footer-copyright">
            &copy; <?php echo date('Y'); ?> Sesy Queen. All rights reserved.
        </div>
    </footer>

    <!-- Back to Top Button -->
    <button id="back-to-top" aria-label="Back to top">
        <i class="bi bi-arrow-up"></i>
    </button>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 1000,
            once: true
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

        // Toast close function
        function closeToast(button) {
            const toast = button.closest('.toast');
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => {
                toast.remove();
            }, 300);
        }

        // Auto-hide toast messages
        setTimeout(() => {
            document.querySelectorAll('.toast').forEach(toast => {
                toast.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 300);
            });
        }, 5000);

        // Back-to-top button
        window.addEventListener('scroll', () => {
            const backToTop = document.getElementById('back-to-top');
            if (window.scrollY > 300) {
                backToTop.style.display = 'flex';
            } else {
                backToTop.style.display = 'none';
            }
        });

        document.getElementById('back-to-top').addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

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

        // Quantity input validation
        document.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('change', function() {
                const max = parseInt(this.getAttribute('max'));
                const value = parseInt(this.value);
                if (value > max) {
                    this.value = max;
                    alert('Quantity exceeds available stock!');
                }
            });
        });

        // Manual close button handler for any toast that might not have the onclick attribute
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toast-close')) {
                const button = e.target.closest('.toast-close');
                closeToast(button);
            }
        });
    </script>
</body>
</html>