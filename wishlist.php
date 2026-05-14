<?php
// Start session only if not active
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

$wishlist_items = [];
$message = '';
$cart_count = 0;
$wishlist_count = 0;

if (isset($_SESSION['user_id'])) {
    try {
        // Fetch wishlist items
        $stmt = $conn->prepare("
            SELECT w.id, w.product_id, p.item, p.price, p.image, p.quantity AS stock
            FROM wishlist w
            JOIN products p ON w.product_id = p.id
            WHERE w.user_id = :user_id
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $wishlist_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get cart and wishlist counts
        $stmt = $conn->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $cart_count = (int)($stmt->fetchColumn() ?: 0);

        $wishlist_count = count($wishlist_items);

        // Handle actions (remove item, add to cart, move all to cart)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
            if (isset($_POST['remove_item'])) {
                $wishlist_id = (int)$_POST['wishlist_id'];
                $stmt = $conn->prepare("DELETE FROM wishlist WHERE id = :wishlist_id AND user_id = :user_id");
                $stmt->execute([':wishlist_id' => $wishlist_id, ':user_id' => $_SESSION['user_id']]);
                $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>Item removed from wishlist!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                // Refresh wishlist
                $stmt = $conn->prepare("
                    SELECT w.id, w.product_id, p.item, p.price, p.image, p.quantity AS stock
                    FROM wishlist w
                    JOIN products p ON w.product_id = p.id
                    WHERE w.user_id = :user_id
                ");
                $stmt->execute([':user_id' => $_SESSION['user_id']]);
                $wishlist_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $wishlist_count = count($wishlist_items);
            } elseif (isset($_POST['add_to_cart'])) {
                $product_id = (int)$_POST['product_id'];
                $stmt = $conn->prepare("SELECT quantity FROM products WHERE id = :product_id");
                $stmt->execute([':product_id' => $product_id]);
                $stock = $stmt->fetchColumn();
                if ($stock > 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO cart (user_id, product_id, quantity)
                        VALUES (:user_id, :product_id, 1)
                        ON DUPLICATE KEY UPDATE quantity = quantity + 1
                    ");
                    $stmt->execute([':user_id' => $_SESSION['user_id'], ':product_id' => $product_id]);
                    
                    // Remove from wishlist after adding to cart (optional - comment out if you want to keep in wishlist)
                    $stmt = $conn->prepare("DELETE FROM wishlist WHERE product_id = :product_id AND user_id = :user_id");
                    $stmt->execute([':product_id' => $product_id, ':user_id' => $_SESSION['user_id']]);
                    
                    $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>Item added to cart!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    
                    // Refresh wishlist
                    $stmt = $conn->prepare("
                        SELECT w.id, w.product_id, p.item, p.price, p.image, p.quantity AS stock
                        FROM wishlist w
                        JOIN products p ON w.product_id = p.id
                        WHERE w.user_id = :user_id
                    ");
                    $stmt->execute([':user_id' => $_SESSION['user_id']]);
                    $wishlist_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $wishlist_count = count($wishlist_items);
                    
                    // Update cart count
                    $stmt = $conn->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $_SESSION['user_id']]);
                    $cart_count = (int)($stmt->fetchColumn() ?: 0);
                } else {
                    $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Item out of stock!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                }
            } elseif (isset($_POST['move_all_to_cart'])) {
                $conn->beginTransaction();
                try {
                    $moved_count = 0;
                    foreach ($wishlist_items as $item) {
                        if ($item['stock'] > 0) {
                            $stmt = $conn->prepare("
                                INSERT INTO cart (user_id, product_id, quantity)
                                VALUES (:user_id, :product_id, 1)
                                ON DUPLICATE KEY UPDATE quantity = quantity + 1
                            ");
                            $stmt->execute([':user_id' => $_SESSION['user_id'], ':product_id' => $item['product_id']]);
                            $moved_count++;
                        }
                    }
                    // Clear wishlist
                    $stmt = $conn->prepare("DELETE FROM wishlist WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $_SESSION['user_id']]);
                    $conn->commit();
                    $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>$moved_count items moved to cart!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    $wishlist_items = [];
                    $wishlist_count = 0;
                    // Update cart count
                    $stmt = $conn->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $_SESSION['user_id']]);
                    $cart_count = (int)($stmt->fetchColumn() ?: 0);
                } catch (PDOException $e) {
                    $conn->rollback();
                    error_log("Move all to cart failed: " . $e->getMessage());
                    $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-x-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Error moving items to cart!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                }
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-x-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Invalid CSRF token.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
        }
    } catch (PDOException $e) {
        error_log("Wishlist query failed: " . $e->getMessage());
        $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Error loading wishlist. Please try again.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
    }
} else {
    header("Location: login_user.php");
    exit;
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
    <meta name="description" content="Your Wishlist - Sesy Queen Premium Kitchenware">
    <meta name="theme-color" content="#8B5CF6">
    <title>Your Wishlist - Sesy Queen</title>
    
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

        /* Wishlist Container */
        .wishlist-container {
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

        /* Wishlist Grid */
        .wishlist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
        }

        .wishlist-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            transition: all 0.3s ease;
            border: var(--glass-border);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .wishlist-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-2xl);
        }

        .card-image {
            position: relative;
            aspect-ratio: 1;
            overflow: hidden;
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
        }

        .card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .wishlist-card:hover .card-image img {
            transform: scale(1.1);
        }

        .stock-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            z-index: 2;
        }

        .stock-badge.in-stock {
            background: rgba(16, 185, 129, 0.9);
            color: white;
        }

        .stock-badge.low-stock {
            background: rgba(245, 158, 11, 0.9);
            color: white;
        }

        .stock-badge.out-of-stock {
            background: rgba(239, 68, 68, 0.9);
            color: white;
        }

        .card-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .product-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .stock-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 8px;
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

        .card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: auto;
        }

        .btn-add-cart {
            padding: 0.75rem;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .btn-add-cart:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-add-cart:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-remove {
            padding: 0.75rem;
            background: transparent;
            border: 2px solid #EF4444;
            color: #EF4444;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.95rem;
        }

        .btn-remove:hover {
            background: #EF4444;
            color: white;
            transform: translateY(-2px);
        }

        /* Empty Wishlist */
        .empty-wishlist {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-wishlist-icon {
            font-size: 5rem;
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .empty-wishlist h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .empty-wishlist p {
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

        /* Move All Button */
        .move-all-container {
            margin-top: 2rem;
            text-align: right;
        }

        .btn-move-all {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 2rem;
            background: var(--gradient-3);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-move-all:hover {
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
            width: 16px;
            height: 16px;
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

            .wishlist-container {
                padding: 1.5rem;
            }

            .wishlist-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
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

            .card-actions {
                grid-template-columns: 1fr;
            }

            .move-all-container {
                text-align: center;
            }

            .btn-move-all {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .footer-info {
                flex-direction: column;
                align-items: center;
            }
        }

        /* Dark Mode Support */
        [data-theme="dark"] .wishlist-card {
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
                    <a href="wishlist.php" class="icon-btn active">
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
        <div class="wishlist-container" data-aos="fade-up">
            <div class="section-header">
                <div class="section-subtitle">Your Collection</div>
                <h1 class="section-title">My <span>Wishlist</span></h1>
                <?php if (!empty($wishlist_items)): ?>
                    <p style="color: var(--gray); margin-top: 0.5rem;"><?php echo count($wishlist_items); ?> items saved</p>
                <?php endif; ?>
            </div>

            <?php if (empty($wishlist_items)): ?>
                <div class="empty-wishlist">
                    <div class="empty-wishlist-icon">
                        <i class="bi bi-heart"></i>
                    </div>
                    <h3>Your wishlist is empty</h3>
                    <p>Save items you love to your wishlist and they'll appear here.</p>
                    <a href="index.php#products" class="continue-shopping">
                        <i class="bi bi-arrow-left"></i>
                        Continue Shopping
                    </a>
                </div>
            <?php else: ?>
                <div class="wishlist-grid">
                    <?php foreach ($wishlist_items as $item): ?>
                        <?php
                            if ($item['stock'] > 10) {
                                $stock_class = 'in-stock';
                                $stock_text = 'In Stock';
                                $stock_detail = 'Available';
                            } elseif ($item['stock'] > 0) {
                                $stock_class = 'low-stock';
                                $stock_text = 'Low Stock';
                                $stock_detail = $item['stock'] . ' left';
                            } else {
                                $stock_class = 'out-of-stock';
                                $stock_text = 'Out of Stock';
                                $stock_detail = 'Currently unavailable';
                            }
                        ?>
                        
                        <div class="wishlist-card" data-aos="fade-up">
                            <div class="card-image">
                                <img src="images/<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['item']); ?>" onerror="this.src='images/default.jpg';">
                                <span class="stock-badge <?php echo $stock_class; ?>"><?php echo $stock_text; ?></span>
                            </div>
                            
                            <div class="card-body">
                                <h3 class="product-name"><?php echo htmlspecialchars($item['item']); ?></h3>
                                <div class="product-price">R<?php echo number_format($item['price'], 2); ?></div>
                                
                                <div class="stock-info">
                                    <span class="stock-indicator <?php echo $stock_class; ?>"></span>
                                    <span class="stock-text"><?php echo $stock_detail; ?></span>
                                </div>
                                
                                <div class="card-actions">
                                    <form method="post" style="flex: 1;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($item['product_id']); ?>">
                                        <button type="submit" name="add_to_cart" class="btn-add-cart" <?php echo $item['stock'] == 0 ? 'disabled' : ''; ?>>
                                            <i class="bi bi-cart-plus"></i>
                                            <span>Add to Cart</span>
                                        </button>
                                    </form>
                                    
                                    <form method="post" style="flex: 1;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="wishlist_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                        <button type="submit" name="remove_item" class="btn-remove">
                                            <i class="bi bi-trash3"></i>
                                            <span>Remove</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="move-all-container" data-aos="fade-up">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button type="submit" name="move_all_to_cart" class="btn-move-all">
                            <i class="bi bi-cart-check"></i>
                            <span>Move All to Cart</span>
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>
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

        // Manual close button handler for any toast that might not have the onclick attribute
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toast-close')) {
                const button = e.target.closest('.toast-close');
                closeToast(button);
            }
        });

        // Loading spinner for form submissions (optional enhancement)
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="spinner" style="display: inline-block;"></span>';
                    submitBtn.disabled = true;
                }
            });
        });
    </script>
</body>
</html>