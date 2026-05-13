<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'includes/db_connect.php';

if (isset($_SESSION['admin_logged_in'])) { header("Location: admin/index.php"); exit(); }

$toast_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if (empty($username) || empty($password)) {
        $toast_msg = "Username and password are required!";
    } else {
        $stmt = $conn->prepare("SELECT id, password, is_admin FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password']) && $user['is_admin'] == 1) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username']  = $username;
            $_SESSION['admin_id']        = $user['id'];
            header("Location: admin/index.php"); exit();
        } else {
            $toast_msg = "Invalid credentials or not an admin account!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#8B5CF6">
  <title>Admin Login – Sesy Queen</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{--primary:#8B5CF6;--secondary:#EC4899;--dark:#0F172A;--gray:#64748B;
          --g2:linear-gradient(135deg,#8B5CF6,#EC4899);
          --shadow-lg:0 10px 15px rgba(0,0,0,.1);--shadow-2xl:0 25px 50px rgba(0,0,0,.25);
          --glass:rgba(255,255,255,.95);--glass-b:1px solid rgba(255,255,255,.2)}
    [data-theme=dark]{--dark:#F8FAFC;--gray:#94A3B8;--glass:rgba(15,23,42,.95);--glass-b:1px solid rgba(255,255,255,.1)}
    body{font-family:'Space Grotesk',sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);
         min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;overflow-x:hidden}
    .orb{position:fixed;border-radius:50%;animation:float 20s infinite ease-in-out;z-index:1;pointer-events:none}
    .orb-1{width:300px;height:300px;top:-150px;right:-150px;background:linear-gradient(135deg,rgba(139,92,246,.3),rgba(236,72,153,.3))}
    .orb-2{width:400px;height:400px;bottom:-200px;left:-200px;background:linear-gradient(135deg,rgba(16,185,129,.3),rgba(59,130,246,.3));animation-delay:-5s}
    @keyframes float{0%,100%{transform:translate(0,0) rotate(0deg)}33%{transform:translate(30px,-30px) rotate(120deg)}66%{transform:translate(-20px,20px) rotate(240deg)}}
    .theme-btn{position:fixed;top:1.5rem;right:1.5rem;z-index:100;width:46px;height:46px;border-radius:50%;background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.2rem;transition:all .3s;box-shadow:var(--shadow-lg)}
    .theme-btn:hover{transform:rotate(20deg)}
    .wrap{position:relative;z-index:10;width:100%;max-width:420px}
    .card{background:var(--glass);backdrop-filter:blur(20px);border:var(--glass-b);border-radius:36px;padding:2.5rem 2rem;box-shadow:var(--shadow-2xl);position:relative;overflow:hidden}
    .card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--g2)}
    .card::after{content:'';position:absolute;bottom:0;right:0;width:120px;height:120px;background:var(--g2);opacity:.07;border-radius:50%;transform:translate(40%,40%);pointer-events:none}
    .logo{text-align:center;margin-bottom:1.25rem}
    .logo img{height:64px;filter:drop-shadow(0 8px 16px rgba(139,92,246,.3));transition:transform .3s}
    .logo img:hover{transform:scale(1.05)}
    .admin-badge{display:inline-flex;align-items:center;gap:.4rem;background:linear-gradient(135deg,rgba(139,92,246,.12),rgba(236,72,153,.12));border:1px solid rgba(139,92,246,.25);border-radius:50px;padding:.35rem 1rem;font-size:.8rem;font-weight:600;color:var(--primary);margin:0 auto .75rem;width:fit-content}
    .card-h{text-align:center;margin-bottom:1.75rem}
    .card-h h1{font-size:1.75rem;font-weight:700;background:var(--g2);-webkit-background-clip:text;background-clip:text;color:transparent}
    .card-h p{color:var(--gray);font-size:.88rem;margin-top:.35rem}
    .fgroup{margin-bottom:1.1rem}
    .flabel{display:block;margin-bottom:.4rem;font-weight:500;font-size:.88rem;color:var(--dark)}
    .iwrap{position:relative}
    .iico{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--gray);font-size:1rem;z-index:2;pointer-events:none}
    .finput{width:100%;padding:.85rem 3rem .85rem 3rem;border:2px solid rgba(139,92,246,.2);border-radius:14px;font-size:1rem;font-family:inherit;background:rgba(255,255,255,.8);color:var(--dark);transition:all .3s;outline:none}
    [data-theme=dark] .finput{background:rgba(15,23,42,.5);border-color:rgba(139,92,246,.3)}
    .finput:focus{border-color:var(--primary);background:rgba(255,255,255,.95);box-shadow:0 0 0 4px rgba(139,92,246,.1)}
    .eye-btn{position:absolute;right:1rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray);cursor:pointer;font-size:1rem;z-index:2;padding:.2rem;transition:color .3s}
    .eye-btn:hover{color:var(--primary)}
    .btn-login{width:100%;padding:.95rem;background:var(--g2);color:#fff;border:none;border-radius:14px;font-size:1rem;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:all .3s;margin-top:.5rem}
    .btn-login:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(139,92,246,.4)}
    .back-link{text-align:center;margin-top:1.1rem}
    .back-link a{color:var(--gray);text-decoration:none;font-size:.88rem;transition:color .3s}
    .back-link a:hover{color:var(--primary)}
    .toast{position:fixed;top:1.5rem;right:1.5rem;z-index:4000;background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);border-left:4px solid #EF4444;border-radius:14px;padding:1rem 1.25rem;box-shadow:var(--shadow-2xl);display:flex;align-items:flex-start;gap:.75rem;min-width:280px;max-width:420px;animation:slideIn .3s ease}
    .t-ico{font-size:1.3rem;color:#EF4444;flex-shrink:0}
    .t-body{flex:1}
    .t-title{font-weight:700;font-size:.9rem;margin-bottom:.15rem}
    .t-msg{font-size:.82rem;color:var(--gray)}
    .t-close{background:none;border:none;color:var(--gray);cursor:pointer;font-size:1rem;flex-shrink:0}
    @keyframes slideIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}
    @keyframes spin{to{transform:rotate(360deg)}}
    @media(max-width:480px){.card{padding:2rem 1.25rem;border-radius:24px}.theme-btn{top:1rem;right:1rem}}
  </style>
</head>
<body>
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <button class="theme-btn" id="themeBtn">🌙</button>

  <?php if ($toast_msg): ?>
  <div class="toast" id="toast">
    <div class="t-ico"><i class="bi bi-exclamation-circle-fill"></i></div>
    <div class="t-body">
      <div class="t-title">Login Failed</div>
      <div class="t-msg"><?= htmlspecialchars($toast_msg) ?></div>
    </div>
    <button class="t-close" onclick="this.closest('.toast').remove()">×</button>
  </div>
  <?php endif; ?>

  <div class="wrap" data-aos="fade-up" data-aos-duration="500">
    <div class="card">
      <div class="logo">
        <img src="images/logo.png" alt="Sesy Queen" onerror="this.style.display='none'">
      </div>
      <div style="text-align:center">
        <div class="admin-badge"><i class="bi bi-shield-lock-fill"></i> Admin Portal</div>
      </div>
      <div class="card-h">
        <h1>Welcome Back</h1>
        <p>Sign in to manage your store</p>
      </div>
      <form method="post" id="loginForm" novalidate>
        <div class="fgroup">
          <label class="flabel" for="username">Username</label>
          <div class="iwrap">
            <i class="bi bi-person iico"></i>
            <input type="text" name="username" id="username" class="finput"
                   placeholder="Admin username" required autocomplete="username"
                   value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
          </div>
        </div>
        <div class="fgroup">
          <label class="flabel" for="password">Password</label>
          <div class="iwrap">
            <i class="bi bi-lock iico"></i>
            <input type="password" name="password" id="password" class="finput"
                   placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="eye-btn" onclick="togglePwd()"><i class="bi bi-eye" id="eyeIcon"></i></button>
          </div>
        </div>
        <button type="submit" class="btn-login" id="loginBtn">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </form>
      <div class="back-link">
        <a href="index.php"><i class="bi bi-arrow-left"></i> Back to Store</a>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script>
    AOS.init({once:true});
    const tb=document.getElementById('themeBtn');
    if(localStorage.getItem('theme')==='dark'){document.body.dataset.theme='dark';tb.textContent='☀️';}
    tb.addEventListener('click',()=>{const d=document.body.dataset.theme==='dark';document.body.dataset.theme=d?'':'dark';tb.textContent=d?'🌙':'☀️';localStorage.setItem('theme',d?'light':'dark');});
    function togglePwd(){const f=document.getElementById('password'),i=document.getElementById('eyeIcon');f.type=f.type==='password'?'text':'password';i.className=f.type==='text'?'bi bi-eye-slash':'bi bi-eye';}
    document.getElementById('loginForm').addEventListener('submit',()=>{const b=document.getElementById('loginBtn');b.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;display:inline-block"></i> Signing in…';b.style.opacity='.75';b.style.pointerEvents='none';});
    const t=document.getElementById('toast');if(t)setTimeout(()=>t.remove(),5000);
  </script>
</body>
</html>
