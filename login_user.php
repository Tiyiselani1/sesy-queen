<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'includes/db_connect.php';

error_log("login_user.php Session at " . date('Y-m-d H:i:s') . ": " . json_encode($_SESSION));
error_log("PHPSESSID Cookie: " . (isset($_COOKIE['PHPSESSID']) ? $_COOKIE['PHPSESSID'] : 'Not set'));

$login_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        error_log("Login attempt with empty credentials for username: " . $username);
        $error = "<div class='toast show error' id='errorToast'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Username and password are required!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, is_admin FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password']) && $user['is_admin'] == 0) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['login_time'] = time();
            $login_success = true;
            error_log("Login successful for user_id: " . $user['id'] . " at " . date('Y-m-d H:i:s'));
            
            // Redirect with success message
            header("Location: index.php?login=success");
            exit();
        } else {
            error_log("Login failed for username: " . $username . " at " . date('Y-m-d H:i:s'));
            $error = "<div class='toast show error' id='errorToast'><div class='toast-icon'><i class='bi bi-x-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Invalid credentials or admin account!</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
        }
    }
}

if (isset($_SESSION['user_id']) && !$login_success) {
    header("Location: index.php");
    exit();
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Login to Sesy Queen - Premium Kitchenware">
    <meta name="theme-color" content="#8B5CF6">
    <title>Login - Sesy Queen</title>
    
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
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            padding: 1rem;
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

        /* Main Container */
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }

        /* Back to Home Button */
        .back-home {
            position: fixed;
            top: 2rem;
            left: 2rem;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: var(--glass-border);
            border-radius: 50px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-lg);
        }

        .back-home:hover {
            transform: translateX(-5px);
            background: var(--gradient-2);
            color: white;
            border-color: transparent;
        }

        .back-home i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .back-home:hover i {
            transform: translateX(-3px);
        }

        /* Login Card */
        .login-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: var(--glass-border);
            border-radius: 40px;
            padding: 3rem 2rem;
            box-shadow: var(--shadow-2xl);
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-2);
        }

        .login-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: var(--gradient-2);
            opacity: 0.1;
            border-radius: 50%;
            transform: translate(50%, 50%);
            pointer-events: none;
        }

        /* Logo */
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo img {
            height: 80px;
            width: auto;
            filter: drop-shadow(0 10px 20px rgba(139, 92, 246, 0.3));
            transition: transform 0.3s ease;
        }

        .logo img:hover {
            transform: scale(1.05);
        }

        /* Title */
        .login-title {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-title h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: var(--gradient-2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .login-title p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Form */
        .login-form {
            position: relative;
            z-index: 10;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            color: var(--gray);
            font-size: 1.2rem;
            z-index: 10;
            transition: color 0.3s ease;
        }

        .form-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid transparent;
            border-radius: 16px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            color: var(--dark);
            transition: all 0.3s ease;
            font-family: 'Space Grotesk', sans-serif;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1);
        }

        .form-input:focus + .input-icon {
            color: var(--primary);
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.2rem;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        /* Remember Me & Forgot Password */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-custom {
            width: 20px;
            height: 20px;
            border: 2px solid var(--gray);
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .checkbox-custom i {
            color: white;
            font-size: 0.8rem;
            opacity: 0;
            transform: scale(0);
            transition: all 0.3s ease;
        }

        input[type="checkbox"] {
            display: none;
        }

        input[type="checkbox"]:checked + .checkbox-custom {
            background: var(--gradient-2);
            border-color: transparent;
        }

        input[type="checkbox"]:checked + .checkbox-custom i {
            opacity: 1;
            transform: scale(1);
        }

        .remember-text {
            color: var(--dark);
            font-size: 0.95rem;
        }

        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .forgot-link:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 1rem;
            background: var(--gradient-2);
            color: white;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
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

        .login-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .login-btn:hover i {
            transform: translateX(5px);
        }

        /* Register Link */
        .register-section {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .register-section p {
            color: var(--gray);
            margin-bottom: 1rem;
        }

        .register-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 2rem;
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            text-decoration: none;
            border-radius: 50px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .register-link:hover {
            background: var(--gradient-2);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 92, 246, 0.3);
        }

        .register-link i {
            transition: transform 0.3s ease;
        }

        .register-link:hover i {
            transform: translateX(5px);
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

        /* Loading State */
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
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
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
        @media (max-width: 768px) {
            .back-home {
                top: 1rem;
                left: 1rem;
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }

            .login-card {
                padding: 2rem 1.5rem;
            }

            .logo img {
                height: 60px;
            }

            .login-title h2 {
                font-size: 1.8rem;
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
            .form-options {
                flex-direction: column;
                align-items: flex-start;
            }

            .login-card {
                padding: 1.5rem;
            }

            .login-title h2 {
                font-size: 1.5rem;
            }
        }

        /* Dark Mode Support */
        [data-theme="dark"] .form-input {
            color: var(--dark);
        }

        [data-theme="dark"] .form-input::placeholder {
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

    <!-- Back to Home Button -->
    <a href="index.php" class="back-home" data-aos="fade-right">
        <i class="bi bi-arrow-left"></i>
        <span>Back to Home</span>
    </a>

    <!-- Main Container -->
    <div class="login-container" data-aos="fade-up" data-aos-duration="1000">
        <div class="login-card">
            <!-- Logo -->
            <div class="logo">
                <img src="images/logo.png" alt="Sesy Queen" onerror="this.src='images/default.jpg';">
            </div>

            <!-- Title -->
            <div class="login-title">
                <h2>Welcome Back</h2>
                <p>Please enter your details to sign in</p>
            </div>

            <!-- Error Messages -->
            <?php if (isset($error)) echo $error; ?>

            <!-- Login Form -->
            <form class="login-form" action="" method="post" id="loginForm">
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" name="username" id="username" class="form-input" placeholder="Enter your username" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" name="password" id="password" class="form-input" placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" id="remember">
                        <span class="checkbox-custom">
                            <i class="bi bi-check"></i>
                        </span>
                        <span class="remember-text">Remember me</span>
                    </label>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="login-btn" id="submitBtn">
                    <span>Sign In</span>
                    <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <!-- Register Section -->
            <div class="register-section">
                <p>Don't have an account?</p>
                <a href="register_user.php" class="register-link">
                    <span>Create Account</span>
                    <i class="bi bi-person-plus"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
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

        // Password visibility toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }

        // Form submission with loading state
        const loginForm = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');

        loginForm.addEventListener('submit', (e) => {
            if (!loginForm.checkValidity()) {
                e.preventDefault();
            } else {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
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

        // Auto-hide toast messages after 5 seconds
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

        // Add slide out animation if not already present
        const style = document.createElement('style');
        style.textContent = `
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
        `;
        if (!document.querySelector('style[data-toast-animation]')) {
            style.setAttribute('data-toast-animation', 'true');
            document.head.appendChild(style);
        }

        // Dark mode detection (from landing page)
        if (localStorage.getItem('theme') === 'dark') {
            document.body.dataset.theme = 'dark';
        }

        // Input focus effects
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('focus', () => {
                input.parentElement.querySelector('.input-icon').style.color = 'var(--primary)';
            });

            input.addEventListener('blur', () => {
                input.parentElement.querySelector('.input-icon').style.color = 'var(--gray)';
            });
        });

        // Remember me checkbox effect
        const rememberCheckbox = document.getElementById('remember');
        const checkboxCustom = document.querySelector('.checkbox-custom');

        if (rememberCheckbox && checkboxCustom) {
            rememberCheckbox.addEventListener('change', () => {
                if (rememberCheckbox.checked) {
                    checkboxCustom.style.background = 'var(--gradient-2)';
                    checkboxCustom.style.borderColor = 'transparent';
                } else {
                    checkboxCustom.style.background = 'transparent';
                    checkboxCustom.style.borderColor = 'var(--gray)';
                }
            });
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

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