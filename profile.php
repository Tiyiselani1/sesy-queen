<?php
// Start session only if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting for password updates
if (!isset($_SESSION['password_attempts'])) {
    $_SESSION['password_attempts'] = ['count' => 0, 'last_attempt' => time()];
}
$max_attempts = 5;
$lockout_time = 900; // 15 minutes in seconds

require_once 'config.php';
include 'includes/db_connect.php';
if (!is_object($conn)) {
    error_log("Database connection failed in profile.php");
    die("Database connection failed.");
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login_user.php");
    exit();
}

// Fetch cart and wishlist counts for navbar
$cart_count = 0;
$wishlist_count = 0;
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

// Fetch current user data
try {
    $stmt = $conn->prepare("SELECT username, email, first_name, last_name, address, phone, profile_picture FROM users WHERE id = :id AND is_admin = 0");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header("Location: login_user.php");
        exit();
    }
    // Debug image path
    $profile_picture = $user['profile_picture'] ?? 'default_image.jpeg';
    error_log("Profile picture path: public/images/profiles/$profile_picture");
} catch (PDOException $e) {
    error_log("Fetch user error: " . $e->getMessage());
    die("An error occurred. Please try again!");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone']);
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    $errors = [];

    // Handle profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['profile_picture'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 2 * 1024 * 1024; // 2MB
        $upload_dir = 'public/images/profiles/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $file_name = 'profile_' . $_SESSION['user_id'] . '_' . uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;

        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Invalid file type. Only JPEG and PNG are allowed.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "File size exceeds 2MB.";
        } elseif (!is_dir($upload_dir) || !is_writable($upload_dir)) {
            $errors[] = "Upload directory is not accessible.";
        } else {
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // Delete old profile picture if not default
                if ($user['profile_picture'] && file_exists($upload_dir . $user['profile_picture']) && $user['profile_picture'] !== 'default_image.jpeg') {
                    unlink($upload_dir . $user['profile_picture']);
                }
                $user['profile_picture'] = $file_name;
            } else {
                $errors[] = "Failed to upload profile picture.";
            }
        }
    }

    // Rate limiting check
    if ($_SESSION['password_attempts']['count'] >= $max_attempts && (time() - $_SESSION['password_attempts']['last_attempt']) < $lockout_time) {
        $errors[] = "Too many attempts. Please try again later!";
    } else {
        // Validate inputs
        if (empty($first_name) || empty($last_name)) {
            $errors[] = "First name and last name are required!";
        }
        if (!empty($phone) && !preg_match("/^\+?[1-9]\d{1,14}$/", $phone)) {
            $errors[] = "Invalid phone number format!";
        }
        if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $errors[] = "All password fields are required to change password!";
            } elseif (strlen($new_password) < 8 || !preg_match("/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/", $new_password)) {
                $errors[] = "New password must be at least 8 characters and contain letters and numbers!";
            } elseif ($new_password !== $confirm_password) {
                $errors[] = "New passwords do not match!";
            }
        }

        if (empty($errors)) {
            try {
                // Verify current password if provided
                $password_updated = false;
                if (!empty($current_password)) {
                    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id AND is_admin = 0");
                    $stmt->execute([':id' => $_SESSION['user_id']]);
                    $db_user = $stmt->fetch();
                    if ($db_user && password_verify($current_password, $db_user['password'])) {
                        $password_updated = true;
                        $_SESSION['password_attempts'] = ['count' => 0, 'last_attempt' => time()];
                    } else {
                        $errors[] = "Current password is incorrect!";
                        $_SESSION['password_attempts']['count']++;
                        $_SESSION['password_attempts']['last_attempt'] = time();
                    }
                }

                if (empty($errors)) {
                    // Update profile fields
                    $query = "UPDATE users SET first_name = :first_name, last_name = :last_name, address = :address, phone = :phone" . 
                             ($password_updated ? ", password = :password" : "") . 
                             (isset($user['profile_picture']) ? ", profile_picture = :profile_picture" : "") . 
                             " WHERE id = :id";
                    $stmt = $conn->prepare($query);
                    $params = [
                        ':first_name' => $first_name,
                        ':last_name' => $last_name,
                        ':address' => $address ?: null,
                        ':phone' => $phone ?: null,
                        ':id' => $_SESSION['user_id']
                    ];
                    if ($password_updated) {
                        $params[':password'] = password_hash($new_password, PASSWORD_DEFAULT);
                    }
                    if (isset($user['profile_picture'])) {
                        $params[':profile_picture'] = $user['profile_picture'];
                    }
                    $stmt->execute($params);
                    $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>Profile updated successfully!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    // Update session username
                    $_SESSION['username'] = $user['username'];
                    $user = array_merge($user, [
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'address' => $address,
                        'phone' => $phone
                    ]);
                }
            } catch (PDOException $e) {
                error_log("Profile update error: " . $e->getMessage());
                $errors[] = "Failed to update profile. Please try again!";
            }
        }
    }

    if (!empty($errors)) {
        $error_message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>" . implode("<br>", $errors) . "</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Your Profile - Sesy Queen Premium Kitchenware">
    <meta name="theme-color" content="#8B5CF6">
    <title>My Profile - Sesy Queen</title>
    
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            line-height: 1.6;
            padding: 2rem;
        }

        /* Particles Background */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }

        /* Floating Orbs */
        .orb {
            position: fixed;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
            animation: float 20s infinite ease-in-out;
            z-index: 1;
            pointer-events: none;
        }

        .orb-1 {
            width: 300px;
            height: 300px;
            top: -150px;
            right: -150px;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.3), rgba(236, 72, 153, 0.3));
            animation-delay: 0s;
        }

        .orb-2 {
            width: 400px;
            height: 400px;
            bottom: -200px;
            left: -200px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(59, 130, 246, 0.3));
            animation-delay: -5s;
        }

        .orb-3 {
            width: 200px;
            height: 200px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.05);
            filter: blur(40px);
            animation: pulse 8s infinite;
        }

        @keyframes float {
            0%, 100% {
                transform: translate(0, 0) rotate(0deg);
            }
            33% {
                transform: translate(30px, -30px) rotate(120deg);
            }
            66% {
                transform: translate(-20px, 20px) rotate(240deg);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0.3;
            }
            50% {
                transform: translate(-50%, -50%) scale(1.5);
                opacity: 0.5;
            }
        }

        /* Navigation */
        .navbar {
            position: sticky;
            top: 1rem;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 2rem;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-lg);
            border: var(--glass-border);
            border-radius: 50px;
            max-width: 1400px;
            margin: 0 auto 2rem;
        }

        .nav-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            position: relative;
            z-index: 1001;
        }

        .logo img {
            height: 50px;
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

        /* Main Container */
        .main-container {
            position: relative;
            z-index: 10;
            max-width: 900px;
            margin: 0 auto;
        }

        /* Profile Card */
        .profile-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: var(--glass-border);
            border-radius: 40px;
            padding: 3rem;
            box-shadow: var(--shadow-2xl);
            position: relative;
            overflow: hidden;
        }

        .profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-2);
        }

        .profile-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: var(--gradient-2);
            opacity: 0.05;
            border-radius: 50%;
            transform: translate(30%, 30%);
            pointer-events: none;
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

        /* Profile Picture */
        .profile-pic-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 2rem;
            cursor: pointer;
        }

        .profile-pic {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid var(--primary);
            box-shadow: var(--shadow-xl);
            transition: all 0.3s ease;
        }

        .profile-pic:hover {
            transform: scale(1.05);
            border-color: var(--secondary);
        }

        .upload-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            opacity: 0;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
            border: 4px solid transparent;
        }

        .profile-pic-container:hover .upload-overlay {
            opacity: 1;
        }

        .upload-overlay i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .upload-overlay span {
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.5rem;
            border: 2px solid transparent;
            border-radius: 16px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            color: var(--dark);
            transition: all 0.3s ease;
            font-family: 'Space Grotesk', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1);
        }

        .form-control:disabled {
            background: rgba(0, 0, 0, 0.05);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .form-text {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Password Section */
        .password-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid rgba(139, 92, 246, 0.2);
        }

        .password-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--primary);
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn-submit:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4);
        }

        .btn-submit i {
            transition: transform 0.3s ease;
        }

        .btn-submit:hover i {
            transform: translateX(5px);
        }

        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            margin-top: 1.5rem;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            color: var(--secondary);
            transform: translateX(-5px);
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
            body {
                padding: 1rem;
            }

            .navbar {
                padding: 0.75rem 1rem;
                top: 0.5rem;
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

            .profile-card {
                padding: 2rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .profile-pic-container {
                width: 120px;
                height: 120px;
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
            .profile-card {
                padding: 1.5rem;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .profile-pic-container {
                width: 100px;
                height: 100px;
            }
        }

        /* Dark Mode Support */
        [data-theme="dark"] .form-control {
            color: var(--dark);
        }

        [data-theme="dark"] .form-control::placeholder {
            color: var(--gray);
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
    <!-- Floating Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <!-- Particles Container -->
    <div class="particles" id="particles"></div>

    <!-- Navbar -->
    <nav class="navbar" id="navbar" data-aos="fade-down">
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
                            <a href="profile.php" class="dropdown-item active">Profile</a>
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
    <?php if (isset($error_message)) echo $error_message; ?>
    <?php if (isset($message)) echo $message; ?>

    <!-- Main Container -->
    <div class="main-container" data-aos="fade-up">
        <div class="profile-card">
            <div class="section-header">
                <div class="section-subtitle">Your Account</div>
                <h1 class="section-title">Profile <span>Settings</span></h1>
            </div>

            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <!-- Profile Picture -->
                <div class="profile-pic-container">
                    <img src="public/images/profiles/<?php echo htmlspecialchars($profile_picture); ?>" class="profile-pic" alt="Profile Picture" onerror="this.src='public/images/profiles/default_image.jpeg'; this.onerror=null;">
                    <label for="profile_picture" class="upload-overlay">
                        <i class="bi bi-camera"></i>
                        <span>Change Photo</span>
                    </label>
                    <input type="file" name="profile_picture" id="profile_picture" class="d-none" accept="image/jpeg,image/png,image/jpg">
                </div>

                <!-- Form Grid -->
                <div class="form-grid">
                    <!-- Username (Disabled) -->
                    <div class="form-group">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" id="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                        <div class="form-text">Username cannot be changed</div>
                    </div>

                    <!-- Email (Disabled) -->
                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                        <div class="form-text">Email cannot be changed</div>
                    </div>

                    <!-- First Name -->
                    <div class="form-group">
                        <label for="first_name" class="form-label">First Name</label>
                        <input type="text" name="first_name" id="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                    </div>

                    <!-- Last Name -->
                    <div class="form-group">
                        <label for="last_name" class="form-label">Last Name</label>
                        <input type="text" name="last_name" id="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                    </div>

                    <!-- Address (Full Width) -->
                    <div class="form-group full-width">
                        <label for="address" class="form-label">Address</label>
                        <input type="text" name="address" id="address" class="form-control" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" placeholder="Enter your address">
                        <div class="form-text">Optional</div>
                    </div>

                    <!-- Phone -->
                    <div class="form-group full-width">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="tel" name="phone" id="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="e.g., +27834456789">
                        <div class="form-text">Optional - Include country code</div>
                    </div>
                </div>

                <!-- Password Change Section -->
                <div class="password-section">
                    <h3 class="password-title">
                        <i class="bi bi-shield-lock"></i>
                        Change Password
                    </h3>

                    <div class="form-grid">
                        <!-- Current Password -->
                        <div class="form-group">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" name="current_password" id="current_password" class="form-control" placeholder="Enter current password">
                        </div>

                        <!-- New Password -->
                        <div class="form-group">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Enter new password">
                            <div class="form-text">Min. 8 characters with letters & numbers</div>
                        </div>

                        <!-- Confirm Password (Full Width) -->
                        <div class="form-group full-width">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm new password">
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit">
                    <span>Update Profile</span>
                    <i class="bi bi-arrow-right"></i>
                </button>

                <!-- Back Link -->
                <div style="text-align: center; margin-top: 1.5rem;">
                    <a href="index.php" class="back-link">
                        <i class="bi bi-arrow-left"></i>
                        Back to Home
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 1000,
            once: true
        });

        // Particles.js
        particlesJS('particles', {
            particles: {
                number: {
                    value: 50,
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
                    value: 0.3,
                    random: true
                },
                size: {
                    value: 3,
                    random: true
                },
                line_linked: {
                    enable: true,
                    distance: 150,
                    color: '#ffffff',
                    opacity: 0.2,
                    width: 1
                },
                move: {
                    enable: true,
                    speed: 2,
                    direction: 'none',
                    random: true,
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
                        mode: 'grab'
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

        // Trigger file input on overlay click
        document.querySelector('.upload-overlay')?.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelector('#profile_picture')?.click();
        });

        // Show filename when file selected
        document.querySelector('#profile_picture')?.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const fileName = this.files[0].name;
                // Optional: Show toast notification
                showToast('success', `Selected: ${fileName}`);
            }
        });

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

        // Manual close button handler
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toast-close')) {
                const button = e.target.closest('.toast-close');
                closeToast(button);
            }
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

        // Form validation
        const form = document.querySelector('form');
        form.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword || confirmPassword) {
                if (newPassword.length > 0 && newPassword.length < 8) {
                    e.preventDefault();
                    showToast('error', 'Password must be at least 8 characters long');
                } else if (!/[A-Za-z]/.test(newPassword) || !/[0-9]/.test(newPassword)) {
                    e.preventDefault();
                    showToast('error', 'Password must contain both letters and numbers');
                } else if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    showToast('error', 'Passwords do not match');
                }
            }
        });

        // Toast helper function
        function showToast(type, message) {
            const toast = document.createElement('div');
            toast.className = `toast show ${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-title">${type === 'success' ? 'Success' : 'Error'}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="closeToast(this)">
                    <i class="bi bi-x"></i>
                </button>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Password visibility toggle (optional enhancement)
        const passwordFields = document.querySelectorAll('input[type="password"]');
        passwordFields.forEach(field => {
            const wrapper = document.createElement('div');
            wrapper.style.position = 'relative';
            field.parentNode.insertBefore(wrapper, field);
            wrapper.appendChild(field);
            
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'password-toggle';
            toggleBtn.innerHTML = '<i class="bi bi-eye"></i>';
            toggleBtn.style.position = 'absolute';
            toggleBtn.style.right = '10px';
            toggleBtn.style.top = '50%';
            toggleBtn.style.transform = 'translateY(-50%)';
            toggleBtn.style.background = 'none';
            toggleBtn.style.border = 'none';
            toggleBtn.style.cursor = 'pointer';
            toggleBtn.style.color = 'var(--gray)';
            
            toggleBtn.addEventListener('click', function() {
                const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
                field.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
            
            wrapper.appendChild(toggleBtn);
        });
    </script>
</body>
</html>