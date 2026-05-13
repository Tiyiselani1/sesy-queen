<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

if (!defined('BREVO_API_KEY')) { include 'config.php'; }
include 'includes/db_connect.php';
if (!is_object($conn)) { die("Database connection failed."); }

$brevo_settings = [
    'api_key' => (defined('BREVO_API_KEY') ? BREVO_API_KEY : ''),
    'sender_email' => (defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : 'drivewaymotors01@gmail.com'),
    'sender_name'  => (defined('BREVO_SENDER_NAME')  ? BREVO_SENDER_NAME  : 'Sesy Queen'),
];

if (isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

$toast_message = ''; $toast_type = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $username = trim($_POST['username']); $email = trim($_POST['email']);
    $password = trim($_POST['password']); $confirm_password = trim($_POST['confirm_password']);
    $errors = [];

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) $errors[] = "All fields are required!";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format!";
    if (strlen($password) < 8 || !preg_match("/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/", $password)) $errors[] = "Password must be at least 8 characters with letters and numbers!";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match!";

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username=:u OR email=:e");
            $stmt->execute([':u'=>$username,':e'=>$email]);
            if ($stmt->fetchColumn() > 0) { $errors[] = "Username or email already exists!"; }
            else {
                $cols = $conn->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
                $has_ca = in_array('created_at',$cols); $has_ia = in_array('is_admin',$cols);
                $q = "INSERT INTO users (username,email,password".($has_ia?",is_admin":"").($has_ca?",created_at":"").") VALUES (:u,:e,:p".($has_ia?",0":"").($has_ca?",NOW()":"").")";
                $conn->prepare($q)->execute([':u'=>$username,':e'=>$email,':p'=>password_hash($password,PASSWORD_DEFAULT)]);
                $user_id = $conn->lastInsertId();
                $_SESSION['user_id'] = $user_id; $_SESSION['username'] = $username;
                sendBrevoEmail($email,"Welcome to Sesy Queen!",['username'=>$username,'timestamp'=>date('Y-m-d H:i:s')],'welcome');
                header("Location: index.php?registered=1"); exit();
            }
        } catch (PDOException $e) { error_log("Reg error: ".$e->getMessage()); $errors[] = "Registration failed. Please try again!"; }
    }
    if (!empty($errors)) { $toast_message = implode(" | ", $errors); }
}

function sendBrevoEmail($email,$subject,$details,$type) {
    global $brevo_settings;
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) return ['success'=>false,'error'=>'Invalid email'];
    $html = $type==='welcome' ? "<html><body style='font-family:Arial,sans-serif;color:#333;'><div style='max-width:600px;margin:0 auto;padding:20px;border:1px solid #eee;border-radius:8px;'><div style='background:linear-gradient(135deg,#8B5CF6,#EC4899);padding:20px;border-radius:8px 8px 0 0;text-align:center;'><h2 style='color:white;margin:0;'>Welcome to Sesy Queen!</h2></div><div style='padding:30px;'><p>Dear {$details['username']},</p><p>Your account has been created successfully.</p><p><strong>Username:</strong> {$details['username']}</p><p><strong>Registered on:</strong> {$details['timestamp']}</p><p>Start shopping now at <a href='https://sunrisenwse.co.za'>sunrisenwse.co.za</a>.</p><p>Best regards,<br>Sesy Queen Team</p></div></div></body></html>" : '';
    $payload=['sender'=>['name'=>$brevo_settings['sender_name'],'email'=>$brevo_settings['sender_email']],'to'=>[['email'=>$email]],'subject'=>$subject,'htmlContent'=>$html];
    $ch=curl_init(); curl_setopt_array($ch,[CURLOPT_URL=>'https://api.brevo.com/v3/smtp/email',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','api-key: '.$brevo_settings['api_key']]]);
    $result=curl_exec($ch); curl_close($ch);
    $response=json_decode($result,true);
    return isset($response['messageId'])?['success'=>true]:['success'=>false,'error'=>$response['message']??'Unknown'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="theme-color" content="#8B5CF6">
    <title>Register - Sesy Queen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        :root{--primary:#8B5CF6;--primary-dark:#7C3AED;--secondary:#EC4899;--accent:#10B981;--dark:#0F172A;--gray:#64748B;--gradient-2:linear-gradient(135deg,#8B5CF6 0%,#EC4899 100%);--shadow-lg:0 10px 15px rgba(0,0,0,.1);--shadow-2xl:0 25px 50px rgba(0,0,0,.25);--glass-bg:rgba(255,255,255,.95);--glass-border:1px solid rgba(255,255,255,.2);}
        [data-theme="dark"]{--primary:#9F7AEA;--dark:#F8FAFC;--gray:#94A3B8;--glass-bg:rgba(15,23,42,.95);--glass-border:1px solid rgba(255,255,255,.1);}
        body{font-family:'Space Grotesk',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;overflow-x:hidden;padding:1rem;}
        .orb{position:fixed;border-radius:50%;animation:float 20s infinite ease-in-out;z-index:1;pointer-events:none;}
        .orb-1{width:300px;height:300px;top:-150px;right:-150px;background:linear-gradient(135deg,rgba(139,92,246,.3),rgba(236,72,153,.3));}
        .orb-2{width:400px;height:400px;bottom:-200px;left:-200px;background:linear-gradient(135deg,rgba(16,185,129,.3),rgba(59,130,246,.3));animation-delay:-5s;}
        @keyframes float{0%,100%{transform:translate(0,0) rotate(0deg);}33%{transform:translate(30px,-30px) rotate(120deg);}66%{transform:translate(-20px,20px) rotate(240deg);}}
        .back-home{position:fixed;top:2rem;left:2rem;z-index:100;display:flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;background:var(--glass-bg);backdrop-filter:blur(10px);border:var(--glass-border);border-radius:50px;color:var(--dark);text-decoration:none;font-weight:500;transition:all .3s ease;box-shadow:var(--shadow-lg);}
        .back-home:hover{transform:translateX(-5px);background:var(--gradient-2);color:white;border-color:transparent;}
        .theme-toggle{position:fixed;top:2rem;right:2rem;z-index:100;width:50px;height:50px;border-radius:50%;background:var(--glass-bg);backdrop-filter:blur(10px);border:var(--glass-border);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.3rem;transition:all .3s ease;box-shadow:var(--shadow-lg);color:var(--dark);}
        .theme-toggle:hover{transform:rotate(20deg);}
        .register-container{position:relative;z-index:10;width:100%;max-width:480px;}
        .register-card{background:var(--glass-bg);backdrop-filter:blur(20px);border:var(--glass-border);border-radius:40px;padding:2.5rem 2rem;box-shadow:var(--shadow-2xl);position:relative;overflow:hidden;}
        .register-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--gradient-2);}
        .logo{text-align:center;margin-bottom:1.25rem;}
        .logo img{height:65px;width:auto;filter:drop-shadow(0 10px 20px rgba(139,92,246,.3));transition:transform .3s ease;}
        .logo img:hover{transform:scale(1.05);}
        .register-title{text-align:center;margin-bottom:1.5rem;}
        .register-title h1{font-size:1.8rem;font-weight:700;background:var(--gradient-2);-webkit-background-clip:text;background-clip:text;color:transparent;margin-bottom:.25rem;}
        .register-title p{color:var(--gray);font-size:.9rem;}
        .form-group{margin-bottom:1.1rem;}
        .form-label{display:block;margin-bottom:.4rem;font-weight:500;font-size:.9rem;color:var(--dark);}
        .input-wrapper{position:relative;}
        .input-icon{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--gray);font-size:1rem;z-index:2;pointer-events:none;}
        .form-input{width:100%;padding:.85rem 1rem .85rem 3rem;border:2px solid rgba(139,92,246,.2);border-radius:16px;font-size:1rem;font-family:'Space Grotesk',sans-serif;background:rgba(255,255,255,.8);color:var(--dark);transition:all .3s ease;outline:none;}
        [data-theme="dark"] .form-input{background:rgba(15,23,42,.5);border-color:rgba(139,92,246,.3);}
        .form-input:focus{border-color:var(--primary);background:rgba(255,255,255,.95);box-shadow:0 0 0 4px rgba(139,92,246,.1);}
        .form-hint{font-size:.78rem;color:var(--gray);margin-top:.35rem;padding-left:.5rem;}
        .toggle-password{position:absolute;right:1rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray);cursor:pointer;font-size:1rem;z-index:2;padding:.25rem;transition:color .3s ease;}
        .toggle-password:hover{color:var(--primary);}
        .btn-register{width:100%;padding:.95rem;background:var(--gradient-2);color:white;border:none;border-radius:16px;font-size:1rem;font-weight:600;font-family:'Space Grotesk',sans-serif;cursor:pointer;transition:all .3s ease;display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:.5rem;}
        .btn-register:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(139,92,246,.4);}
        .btn-register:active{transform:translateY(0);}
        .strength-bar{height:4px;border-radius:2px;background:#e2e8f0;overflow:hidden;margin-top:.4rem;}
        .strength-fill{height:100%;border-radius:2px;transition:all .3s ease;width:0%;}
        .strength-text{font-size:.75rem;margin-top:.3rem;font-weight:500;}
        .divider{text-align:center;margin:1.1rem 0;position:relative;}
        .divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:rgba(139,92,246,.2);}
        .divider span{position:relative;background:var(--glass-bg);padding:0 1rem;color:var(--gray);font-size:.85rem;}
        .login-link{text-align:center;margin-top:.5rem;}
        .login-link a{color:var(--primary);text-decoration:none;font-weight:600;transition:color .3s ease;}
        .login-link a:hover{color:var(--primary-dark);text-decoration:underline;}
        .toast-notification{position:fixed;top:2rem;right:2rem;z-index:3000;background:var(--glass-bg);backdrop-filter:blur(10px);border:var(--glass-border);border-radius:16px;padding:1rem 1.5rem;box-shadow:var(--shadow-2xl);display:flex;align-items:center;gap:1rem;min-width:300px;max-width:420px;animation:slideIn .3s ease;border-left:4px solid #EF4444;}
        .toast-icon{font-size:1.5rem;color:#EF4444;}
        .toast-content{flex:1;}
        .toast-title{font-weight:700;margin-bottom:.2rem;}
        .toast-msg{font-size:.88rem;color:var(--gray);}
        .toast-close{background:none;border:none;color:var(--gray);cursor:pointer;font-size:1.2rem;padding:0;}
        @keyframes slideIn{from{transform:translateX(100%);opacity:0;}to{transform:translateX(0);opacity:1;}}
        @keyframes spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}
        @media(max-width:480px){.register-card{padding:2rem 1.25rem;border-radius:24px;}.back-home{display:none;}.theme-toggle{top:1rem;right:1rem;}}
    </style>
</head>
<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <a href="index.php" class="back-home"><i class="bi bi-arrow-left"></i> Back to Home</a>
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">🌙</button>

    <?php if (!empty($toast_message)): ?>
    <div class="toast-notification" id="toastNotif">
        <div class="toast-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
        <div class="toast-content">
            <div class="toast-title">Registration Error</div>
            <div class="toast-msg"><?php echo htmlspecialchars($toast_message); ?></div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <div class="register-container" data-aos="fade-up" data-aos-duration="600">
        <div class="register-card">
            <div class="logo">
                <img src="images/logo.png" alt="Sesy Queen" onerror="this.style.display='none'">
            </div>
            <div class="register-title">
                <h1>Create Account</h1>
                <p>Join Sesy Queen — premium kitchenware</p>
            </div>
            <form method="post" id="registerForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" name="username" id="username" class="form-input" placeholder="Choose a username" value="<?php echo isset($_POST['username'])?htmlspecialchars($_POST['username']):''; ?>" required autocomplete="username">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope input-icon"></i>
                        <input type="email" name="email" id="email" class="form-input" placeholder="your@email.com" value="<?php echo isset($_POST['email'])?htmlspecialchars($_POST['email']):''; ?>" required autocomplete="email">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" name="password" id="password" class="form-input" placeholder="Create a strong password" required autocomplete="new-password">
                        <button type="button" class="toggle-password" onclick="togglePwd('password',this)"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <div class="strength-text" id="strengthText"></div>
                    <div class="form-hint">At least 8 characters with letters and numbers.</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock-fill input-icon"></i>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Repeat your password" required autocomplete="new-password">
                        <button type="button" class="toggle-password" onclick="togglePwd('confirm_password',this)"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn-register" id="registerBtn">
                    <i class="bi bi-person-plus"></i> Create Account
                </button>
            </form>
            <div class="divider"><span>Already have an account?</span></div>
            <div class="login-link"><a href="login_user.php"><i class="bi bi-box-arrow-in-right"></i> Sign In Instead</a></div>
        </div>
    </div>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({once:true});
        const themeToggle=document.getElementById('themeToggle');
        if(localStorage.getItem('theme')==='dark'){document.body.dataset.theme='dark';themeToggle.textContent='☀️';}
        themeToggle.addEventListener('click',()=>{const d=document.body.dataset.theme==='dark';document.body.dataset.theme=d?'':'dark';themeToggle.textContent=d?'🌙':'☀️';localStorage.setItem('theme',d?'light':'dark');});
        function togglePwd(id,btn){const f=document.getElementById(id),i=btn.querySelector('i');f.type=f.type==='password'?'text':'password';i.className=f.type==='text'?'bi bi-eye-slash':'bi bi-eye';}
        const pwdField=document.getElementById('password'),fill=document.getElementById('strengthFill'),txt=document.getElementById('strengthText');
        pwdField.addEventListener('input',function(){const v=this.value;let s=0;if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;const c=['','#EF4444','#F59E0B','#10B981','#10B981'],l=['','Weak','Fair','Good','Strong'],w=['0%','25%','50%','75%','100%'];fill.style.width=v.length>0?w[s]:'0%';fill.style.background=c[s];txt.textContent=v.length>0?l[s]:'';txt.style.color=c[s];});
        document.getElementById('registerForm').addEventListener('submit',function(){const b=document.getElementById('registerBtn');b.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i> Creating...';b.style.opacity='.8';b.style.pointerEvents='none';});
        const toast=document.getElementById('toastNotif');if(toast)setTimeout(()=>toast.remove(),5000);
    </script>
</body>
</html>
