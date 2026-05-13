<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

include 'includes/db_connect.php';
if (!is_object($conn)) { die("Database connection failed."); }

/* ── Ensure reset columns exist ─────────────────────────────────── */
try {
    $cols = $conn->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reset_token',   $cols)) $conn->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL DEFAULT NULL");
    if (!in_array('reset_expires', $cols)) $conn->exec("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL DEFAULT NULL");
} catch (PDOException $e) { error_log("reset column check: " . $e->getMessage()); }

$token        = trim($_GET['token'] ?? '');
$toast_msg    = '';
$toast_type   = 'error';
$success      = false;
$invalid      = false;
$token_user   = null;

if (empty($token)) {
    $invalid = true;
} else {
    try {
        $s = $conn->prepare("SELECT id, username FROM users WHERE reset_token = :t AND reset_expires > NOW() AND is_admin = 0 LIMIT 1");
        $s->execute([':t' => $token]);
        $token_user = $s->fetch(PDO::FETCH_ASSOC);
        if (!$token_user) $invalid = true;
    } catch (PDOException $e) {
        error_log("reset token check: " . $e->getMessage());
        $invalid = true;
    }
}

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

    $password  = $_POST['password']  ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $errors    = [];

    if (strlen($password) < 8)                                              $errors[] = "Password must be at least 8 characters.";
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) $errors[] = "Password must contain letters and numbers.";
    if ($password !== $confirm)                                             $errors[] = "Passwords do not match.";

    if (empty($errors)) {
        try {
            $s = $conn->prepare("UPDATE users SET password = :p, reset_token = NULL, reset_expires = NULL WHERE id = :id");
            $s->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $token_user['id']]);
            $success    = true;
            $toast_msg  = "Password reset successfully!";
            $toast_type = 'success';
        } catch (PDOException $e) {
            error_log("reset password update: " . $e->getMessage());
            $toast_msg = "Database error. Please try again.";
        }
    } else {
        $toast_msg = implode(" | ", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="theme-color" content="#8B5CF6">
  <title>Reset Password – Sesy Queen</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{--primary:#8B5CF6;--dark:#0F172A;--gray:#64748B;--g2:linear-gradient(135deg,#8B5CF6,#EC4899);--shadow-lg:0 10px 15px rgba(0,0,0,.1);--shadow-2xl:0 25px 50px rgba(0,0,0,.25);--glass:rgba(255,255,255,.95);--glass-b:1px solid rgba(255,255,255,.2)}
    [data-theme=dark]{--dark:#F8FAFC;--gray:#94A3B8;--glass:rgba(15,23,42,.95);--glass-b:1px solid rgba(255,255,255,.1)}
    body{font-family:'Space Grotesk',sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;overflow-x:hidden}
    .orb{position:fixed;border-radius:50%;animation:float 20s infinite ease-in-out;z-index:1;pointer-events:none}
    .orb-1{width:300px;height:300px;top:-150px;right:-150px;background:linear-gradient(135deg,rgba(139,92,246,.3),rgba(236,72,153,.3))}
    .orb-2{width:400px;height:400px;bottom:-200px;left:-200px;background:linear-gradient(135deg,rgba(16,185,129,.3),rgba(59,130,246,.3));animation-delay:-5s}
    @keyframes float{0%,100%{transform:translate(0,0) rotate(0deg)}33%{transform:translate(30px,-30px) rotate(120deg)}66%{transform:translate(-20px,20px) rotate(240deg)}}
    .back-btn{position:fixed;top:2rem;left:2rem;z-index:100;display:flex;align-items:center;gap:.5rem;padding:.7rem 1.4rem;background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);border-radius:50px;color:var(--dark);text-decoration:none;font-weight:500;transition:all .3s;box-shadow:var(--shadow-lg)}
    .back-btn:hover{transform:translateX(-5px);background:var(--g2);color:#fff;border-color:transparent}
    .theme-btn{position:fixed;top:2rem;right:2rem;z-index:100;width:48px;height:48px;border-radius:50%;background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.3rem;transition:all .3s;box-shadow:var(--shadow-lg);color:var(--dark)}
    .theme-btn:hover{transform:rotate(20deg)}
    .wrap{position:relative;z-index:10;width:100%;max-width:430px}
    .card{background:var(--glass);backdrop-filter:blur(20px);border:var(--glass-b);border-radius:36px;padding:2.5rem 2rem;box-shadow:var(--shadow-2xl);position:relative;overflow:hidden}
    .card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--g2)}
    .logo{text-align:center;margin-bottom:1.2rem}
    .logo img{height:62px;filter:drop-shadow(0 8px 16px rgba(139,92,246,.3))}
    .icon-ring{width:76px;height:76px;border-radius:50%;background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(236,72,153,.15));display:flex;align-items:center;justify-content:center;margin:0 auto 1.1rem;font-size:2rem;color:var(--primary)}
    .card-h{text-align:center;margin-bottom:1.4rem}
    .card-h h1{font-size:1.75rem;font-weight:700;background:var(--g2);-webkit-background-clip:text;background-clip:text;color:transparent}
    .card-h p{color:var(--gray);font-size:.9rem;margin-top:.4rem}
    .fgroup{margin-bottom:1.1rem}
    .flabel{display:block;margin-bottom:.4rem;font-weight:500;font-size:.88rem;color:var(--dark)}
    .iwrap{position:relative}
    .iico{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--gray);font-size:1rem;z-index:2;pointer-events:none}
    .finput{width:100%;padding:.85rem 3rem .85rem 3rem;border:2px solid rgba(139,92,246,.2);border-radius:14px;font-size:1rem;font-family:inherit;background:rgba(255,255,255,.8);color:var(--dark);transition:all .3s;outline:none}
    [data-theme=dark] .finput{background:rgba(15,23,42,.5);border-color:rgba(139,92,246,.3)}
    .finput:focus{border-color:var(--primary);background:rgba(255,255,255,.95);box-shadow:0 0 0 4px rgba(139,92,246,.1)}
    .eye-btn{position:absolute;right:1rem;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray);cursor:pointer;font-size:1rem;z-index:2;padding:.2rem;transition:color .3s}
    .eye-btn:hover{color:var(--primary)}
    .hint{font-size:.77rem;color:var(--gray);margin-top:.3rem;padding-left:.4rem}
    .sbar{height:4px;border-radius:2px;background:#e2e8f0;overflow:hidden;margin-top:.4rem}
    .sfill{height:100%;border-radius:2px;transition:all .3s;width:0%}
    .stxt{font-size:.74rem;margin-top:.25rem;font-weight:500}
    .btn-reset{width:100%;padding:.95rem;background:var(--g2);color:#fff;border:none;border-radius:14px;font-size:1rem;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:all .3s;margin-top:.5rem}
    .btn-reset:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(139,92,246,.4)}
    .divider{text-align:center;margin:1rem 0;position:relative}
    .divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:rgba(139,92,246,.2)}
    .divider span{position:relative;background:var(--glass);padding:0 1rem;color:var(--gray);font-size:.84rem}
    .link-row{text-align:center}
    .link-row a{color:var(--primary);text-decoration:none;font-weight:600}
    .link-row a:hover{text-decoration:underline}
    .state-box{text-align:center;padding:.5rem 0}
    .s-ico{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:2.4rem;color:#fff}
    .s-ico.green{background:linear-gradient(135deg,#10B981,#3B82F6)}
    .s-ico.red{background:linear-gradient(135deg,#EF4444,#F97316)}
    .state-box h3{font-size:1.35rem;font-weight:700;color:var(--dark);margin-bottom:.5rem}
    .state-box p{color:var(--gray);font-size:.93rem;line-height:1.6}
    .btn-go{display:inline-flex;align-items:center;gap:.5rem;margin-top:1.2rem;padding:.85rem 2rem;background:var(--g2);color:#fff;text-decoration:none;border-radius:14px;font-weight:600;transition:all .3s}
    .btn-go:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(139,92,246,.4)}
    .toast{position:fixed;top:1.5rem;right:1.5rem;z-index:4000;background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);border-radius:14px;padding:1rem 1.25rem;box-shadow:var(--shadow-2xl);display:flex;align-items:flex-start;gap:.85rem;min-width:290px;max-width:460px;animation:slideIn .3s ease}
    .toast.error{border-left:4px solid #EF4444}
    .toast.success{border-left:4px solid #10B981}
    .t-ico{font-size:1.4rem;flex-shrink:0}
    .toast.error .t-ico{color:#EF4444}
    .toast.success .t-ico{color:#10B981}
    .t-body{flex:1}
    .t-title{font-weight:700;font-size:.95rem;margin-bottom:.2rem}
    .t-msg{font-size:.83rem;color:var(--gray);word-break:break-word;line-height:1.5}
    .t-close{background:none;border:none;color:var(--gray);cursor:pointer;font-size:1.1rem;flex-shrink:0}
    @keyframes slideIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}
    @keyframes spin{to{transform:rotate(360deg)}}
    @media(max-width:480px){.card{padding:2rem 1.25rem;border-radius:24px}.back-btn{display:none}.theme-btn{top:1rem;right:1rem}}
  </style>
</head>
<body>
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <a href="login_user.php" class="back-btn"><i class="bi bi-arrow-left"></i> Login</a>
  <button class="theme-btn" id="themeBtn">🌙</button>

  <?php if ($toast_msg): ?>
  <div class="toast <?= htmlspecialchars($toast_type) ?>" id="toast">
    <div class="t-ico"><i class="bi bi-<?= $toast_type==='success'?'check-circle-fill':'exclamation-circle-fill' ?>"></i></div>
    <div class="t-body">
      <div class="t-title"><?= $toast_type==='success'?'Success':'Error' ?></div>
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

      <?php if ($success): ?>
        <div class="state-box">
          <div class="s-ico green"><i class="bi bi-shield-check"></i></div>
          <h3>Password Changed!</h3>
          <p>Your password has been reset successfully.<br>You can now sign in with your new password.</p>
          <a href="login_user.php" class="btn-go"><i class="bi bi-box-arrow-in-right"></i> Go to Login</a>
        </div>

      <?php elseif ($invalid): ?>
        <div class="state-box">
          <div class="s-ico red"><i class="bi bi-shield-x"></i></div>
          <h3>Link Expired or Invalid</h3>
          <p>This password reset link is no longer valid.<br>Links expire after 1 hour.</p>
          <a href="forgot_password.php" class="btn-go"><i class="bi bi-arrow-clockwise"></i> Request New Link</a>
        </div>

      <?php else: ?>
        <div class="icon-ring"><i class="bi bi-key"></i></div>
        <div class="card-h">
          <h1>Reset Password</h1>
          <p>Create a new password for <strong><?= htmlspecialchars($token_user['username']) ?></strong></p>
        </div>

        <form method="post" id="rpForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

          <div class="fgroup">
            <label class="flabel" for="password">New Password</label>
            <div class="iwrap">
              <i class="bi bi-lock iico"></i>
              <input type="password" name="password" id="password" class="finput"
                     placeholder="Create a strong password" required autocomplete="new-password">
              <button type="button" class="eye-btn" onclick="togglePwd('password',this)"><i class="bi bi-eye"></i></button>
            </div>
            <div class="sbar"><div class="sfill" id="sfill"></div></div>
            <div class="stxt" id="stxt"></div>
            <div class="hint">Min 8 characters, must include letters and numbers.</div>
          </div>

          <div class="fgroup">
            <label class="flabel" for="confirm_password">Confirm New Password</label>
            <div class="iwrap">
              <i class="bi bi-lock-fill iico"></i>
              <input type="password" name="confirm_password" id="confirm_password" class="finput"
                     placeholder="Repeat your new password" required autocomplete="new-password">
              <button type="button" class="eye-btn" onclick="togglePwd('confirm_password',this)"><i class="bi bi-eye"></i></button>
            </div>
          </div>

          <button type="submit" class="btn-reset" id="resetBtn">
            <i class="bi bi-shield-lock"></i> Reset Password
          </button>
        </form>

        <div class="divider"><span>Remember it now?</span></div>
        <div class="link-row"><a href="login_user.php"><i class="bi bi-box-arrow-in-right"></i> Back to Login</a></div>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script>
    AOS.init({once:true});
    const tb=document.getElementById('themeBtn');
    if(localStorage.getItem('theme')==='dark'){document.body.dataset.theme='dark';tb.textContent='☀️';}
    tb.addEventListener('click',()=>{const d=document.body.dataset.theme==='dark';document.body.dataset.theme=d?'':'dark';tb.textContent=d?'🌙':'☀️';localStorage.setItem('theme',d?'light':'dark');});

    function togglePwd(id,btn){const f=document.getElementById(id),i=btn.querySelector('i');f.type=f.type==='password'?'text':'password';i.className=f.type==='text'?'bi bi-eye-slash':'bi bi-eye';}

    const pwdF=document.getElementById('password');
    if(pwdF){
      const fill=document.getElementById('sfill'),txt=document.getElementById('stxt');
      pwdF.addEventListener('input',function(){
        const v=this.value; let s=0;
        if(v.length>=8)s++; if(/[A-Z]/.test(v))s++; if(/[0-9]/.test(v))s++; if(/[^A-Za-z0-9]/.test(v))s++;
        const c=['','#EF4444','#F59E0B','#10B981','#10B981'],l=['','Weak','Fair','Good','Strong'],w=['0%','25%','50%','75%','100%'];
        fill.style.width=v.length?w[s]:'0%'; fill.style.background=c[s];
        txt.textContent=v.length?l[s]:''; txt.style.color=c[s];
      });
    }

    const form=document.getElementById('rpForm');
    if(form) form.addEventListener('submit',()=>{
      const b=document.getElementById('resetBtn');
      b.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;display:inline-block"></i> Resetting…';
      b.style.opacity='.75'; b.style.pointerEvents='none';
    });

    const t=document.getElementById('toast');
    if(t) setTimeout(()=>t.remove(),7000);
  </script>
</body>
</html>
