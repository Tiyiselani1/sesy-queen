<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

if (!defined('BREVO_API_KEY')) { include 'config.php'; }
include 'includes/db_connect.php';
if (!is_object($conn)) { die("Database connection failed."); }

$brevo_settings = [
    'api_key'      => (defined('BREVO_API_KEY') ? BREVO_API_KEY : ''),
    'sender_email' => (defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : 'drivewaymotors01@gmail.com'),
    'sender_name'  => (defined('BREVO_SENDER_NAME')  ? BREVO_SENDER_NAME  : 'Sesy Queen'),
];

/* ── Auto-add reset columns if missing ──────────────────────────── */
try {
    $existing = $conn->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reset_token',   $existing)) {
        $conn->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL DEFAULT NULL");
    }
    if (!in_array('reset_expires', $existing)) {
        $conn->exec("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL DEFAULT NULL");
    }
} catch (PDOException $e) {
    error_log("alter users error: " . $e->getMessage());
}

/* ── Brevo send function ─────────────────────────────────────────── */
function sendResetEmail($toEmail, $username, $resetLink) {
    global $brevo_settings;
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email: ' . $toEmail];
    }
    $html = "
    <html><body style='font-family:Arial,sans-serif;color:#333;margin:0;padding:0;'>
    <div style='max-width:600px;margin:0 auto;border:1px solid #eee;border-radius:8px;overflow:hidden;'>
      <div style='background:linear-gradient(135deg,#8B5CF6,#EC4899);padding:28px 20px;text-align:center;'>
        <h2 style='color:white;margin:0;font-size:22px;'>Password Reset Request</h2>
      </div>
      <div style='padding:32px 28px;'>
        <p style='font-size:16px;'>Hi <strong>" . htmlspecialchars($username) . "</strong>,</p>
        <p>We received a request to reset your Sesy Queen password. Click the button below to set a new one:</p>
        <div style='text-align:center;margin:32px 0;'>
          <a href='" . htmlspecialchars($resetLink) . "'
             style='background:linear-gradient(135deg,#8B5CF6,#EC4899);color:white;padding:14px 36px;
                    text-decoration:none;border-radius:50px;font-weight:700;font-size:15px;display:inline-block;'>
            Reset My Password
          </a>
        </div>
        <p style='font-size:13px;color:#666;'>Or copy this link into your browser:</p>
        <p style='font-size:12px;color:#888;word-break:break-all;background:#f8f8f8;padding:10px;border-radius:6px;'>" . htmlspecialchars($resetLink) . "</p>
        <p style='margin-top:24px;font-size:13px;color:#999;'>This link expires in <strong>1 hour</strong>. If you didn't request this, you can safely ignore this email.</p>
      </div>
      <div style='background:#f9f9f9;padding:16px;text-align:center;font-size:12px;color:#aaa;'>
        &copy; Sesy Queen &nbsp;|&nbsp; <a href='mailto:info@sunrisenwse.co.za' style='color:#8B5CF6;'>info@sunrisenwse.co.za</a>
      </div>
    </div>
    </body></html>";

    $payload = json_encode([
        'sender'      => ['name' => $brevo_settings['sender_name'], 'email' => $brevo_settings['sender_email']],
        'to'          => [['email' => $toEmail]],
        'subject'     => 'Password Reset – Sesy Queen',
        'htmlContent' => $html,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . $brevo_settings['api_key'],
        ],
    ]);
    $body      = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    error_log("Brevo reset email → HTTP $httpCode | body: $body");

    if ($curlErr) return ['success' => false, 'error' => 'cURL: ' . $curlErr];
    $resp = json_decode($body, true);
    if ($httpCode >= 200 && $httpCode < 300) return ['success' => true];
    return ['success' => false, 'error' => ($resp['message'] ?? "HTTP $httpCode: $body")];
}

/* ── Handle POST ─────────────────────────────────────────────────── */
$toast_msg  = '';
$toast_type = 'error';
$success    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {

    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $toast_msg = "Please enter a valid email address.";
    } else {
        try {
            /* Look up by email — users table has no reset columns until we added them above */
            $stmt = $conn->prepare(
                "SELECT id, username FROM users WHERE email = :email AND is_admin = 0 LIMIT 1"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $stmt = $conn->prepare(
                    "UPDATE users SET reset_token = :token, reset_expires = :expires WHERE id = :id"
                );
                $stmt->execute([':token' => $token, ':expires' => $expires, ':id' => $user['id']]);

                /* Build URL dynamically so it works on any host */
                $proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host       = $_SERVER['HTTP_HOST'];
                $scriptDir  = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $resetLink  = $proto . '://' . $host . $scriptDir . '/reset_password.php?token=' . urlencode($token);

                $result = sendResetEmail($email, $user['username'], $resetLink);

                if ($result['success']) {
                    $success    = true;
                    $toast_msg  = "Reset link sent! Check your inbox and spam folder.";
                    $toast_type = 'success';
                } else {
                    error_log("Reset email failed for $email: " . ($result['error'] ?? ''));
                    $toast_msg = "Email send failed: " . ($result['error'] ?? 'Unknown error') . ". Please contact support.";
                }
            } else {
                /* Don't reveal whether the email exists */
                $success    = true;
                $toast_msg  = "If that email is registered, a reset link has been sent.";
                $toast_type = 'success';
            }
        } catch (PDOException $e) {
            error_log("forgot_password DB error: " . $e->getMessage());
            $toast_msg = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#8B5CF6">
  <title>Forgot Password – Sesy Queen</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{
      --primary:#8B5CF6;--secondary:#EC4899;--dark:#0F172A;--gray:#64748B;
      --g2:linear-gradient(135deg,#8B5CF6,#EC4899);
      --shadow-lg:0 10px 15px rgba(0,0,0,.1);--shadow-2xl:0 25px 50px rgba(0,0,0,.25);
      --glass:rgba(255,255,255,.95);--glass-b:1px solid rgba(255,255,255,.2)
    }
    [data-theme=dark]{
      --dark:#F8FAFC;--gray:#94A3B8;
      --glass:rgba(15,23,42,.95);--glass-b:1px solid rgba(255,255,255,.1)
    }
    body{font-family:'Space Grotesk',sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);
         min-height:100vh;display:flex;align-items:center;justify-content:center;
         padding:1rem;overflow-x:hidden}
    .orb{position:fixed;border-radius:50%;animation:float 20s infinite ease-in-out;z-index:1;pointer-events:none}
    .orb-1{width:300px;height:300px;top:-150px;right:-150px;background:linear-gradient(135deg,rgba(139,92,246,.3),rgba(236,72,153,.3))}
    .orb-2{width:400px;height:400px;bottom:-200px;left:-200px;background:linear-gradient(135deg,rgba(16,185,129,.3),rgba(59,130,246,.3));animation-delay:-5s}
    @keyframes float{0%,100%{transform:translate(0,0) rotate(0deg)}33%{transform:translate(30px,-30px) rotate(120deg)}66%{transform:translate(-20px,20px) rotate(240deg)}}
    .back-btn{position:fixed;top:2rem;left:2rem;z-index:100;display:flex;align-items:center;gap:.5rem;
      padding:.7rem 1.4rem;background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);
      border-radius:50px;color:var(--dark);text-decoration:none;font-weight:500;
      transition:all .3s;box-shadow:var(--shadow-lg)}
    .back-btn:hover{transform:translateX(-5px);background:var(--g2);color:#fff;border-color:transparent}
    .theme-btn{position:fixed;top:2rem;right:2rem;z-index:100;width:48px;height:48px;border-radius:50%;
      background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);cursor:pointer;
      display:flex;align-items:center;justify-content:center;font-size:1.3rem;
      transition:all .3s;box-shadow:var(--shadow-lg);color:var(--dark)}
    .theme-btn:hover{transform:rotate(20deg)}
    .wrap{position:relative;z-index:10;width:100%;max-width:430px}
    .card{background:var(--glass);backdrop-filter:blur(20px);border:var(--glass-b);
      border-radius:36px;padding:2.5rem 2rem;box-shadow:var(--shadow-2xl);position:relative;overflow:hidden}
    .card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--g2)}
    .logo{text-align:center;margin-bottom:1.2rem}
    .logo img{height:62px;filter:drop-shadow(0 8px 16px rgba(139,92,246,.3))}
    .icon-ring{width:76px;height:76px;border-radius:50%;
      background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(236,72,153,.15));
      display:flex;align-items:center;justify-content:center;margin:0 auto 1.1rem;
      font-size:2rem;color:var(--primary)}
    .card-h{text-align:center;margin-bottom:1.4rem}
    .card-h h1{font-size:1.75rem;font-weight:700;background:var(--g2);
      -webkit-background-clip:text;background-clip:text;color:transparent}
    .card-h p{color:var(--gray);font-size:.9rem;margin-top:.4rem}
    .fgroup{margin-bottom:1.2rem}
    .flabel{display:block;margin-bottom:.4rem;font-weight:500;font-size:.88rem;color:var(--dark)}
    .iwrap{position:relative}
    .iico{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--gray);font-size:1rem;z-index:2;pointer-events:none}
    .finput{width:100%;padding:.85rem 1rem .85rem 3rem;border:2px solid rgba(139,92,246,.2);
      border-radius:14px;font-size:1rem;font-family:inherit;
      background:rgba(255,255,255,.8);color:var(--dark);transition:all .3s;outline:none}
    [data-theme=dark] .finput{background:rgba(15,23,42,.5);border-color:rgba(139,92,246,.3)}
    .finput:focus{border-color:var(--primary);background:rgba(255,255,255,.95);box-shadow:0 0 0 4px rgba(139,92,246,.1)}
    .hint{font-size:.77rem;color:var(--gray);margin-top:.3rem;padding-left:.4rem}
    .btn-send{width:100%;padding:.95rem;background:var(--g2);color:#fff;border:none;
      border-radius:14px;font-size:1rem;font-weight:600;font-family:inherit;cursor:pointer;
      display:flex;align-items:center;justify-content:center;gap:.5rem;
      transition:all .3s;margin-top:.2rem}
    .btn-send:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(139,92,246,.4)}
    .divider{text-align:center;margin:1rem 0;position:relative}
    .divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:rgba(139,92,246,.2)}
    .divider span{position:relative;background:var(--glass);padding:0 1rem;color:var(--gray);font-size:.84rem}
    .link-row{text-align:center}
    .link-row a{color:var(--primary);text-decoration:none;font-weight:600}
    .link-row a:hover{text-decoration:underline}
    /* success state */
    .success-box{text-align:center;padding:.5rem 0}
    .s-icon{width:76px;height:76px;border-radius:50%;background:linear-gradient(135deg,#10B981,#3B82F6);
      display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:2.4rem;color:#fff}
    .success-box h3{font-size:1.4rem;font-weight:700;color:var(--dark);margin-bottom:.6rem}
    .success-box p{color:var(--gray);font-size:.93rem;line-height:1.65}
    /* toast */
    .toast{position:fixed;top:1.5rem;right:1.5rem;z-index:4000;background:var(--glass);
      backdrop-filter:blur(10px);border:var(--glass-b);border-radius:14px;
      padding:1rem 1.25rem;box-shadow:var(--shadow-2xl);
      display:flex;align-items:flex-start;gap:.85rem;min-width:290px;max-width:460px;
      animation:slideIn .3s ease}
    .toast.error{border-left:4px solid #EF4444}
    .toast.success{border-left:4px solid #10B981}
    .t-ico{font-size:1.4rem;flex-shrink:0;margin-top:.05rem}
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
  <a href="index.php" class="back-btn"><i class="bi bi-arrow-left"></i> Home</a>
  <button class="theme-btn" id="themeBtn">🌙</button>

  <?php if ($toast_msg): ?>
  <div class="toast <?= htmlspecialchars($toast_type) ?>" id="toast">
    <div class="t-ico">
      <i class="bi bi-<?= $toast_type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"></i>
    </div>
    <div class="t-body">
      <div class="t-title"><?= $toast_type === 'success' ? 'Email Sent' : 'Error' ?></div>
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
        <div class="success-box">
          <div class="s-icon"><i class="bi bi-envelope-check"></i></div>
          <h3>Check Your Email</h3>
          <p>We sent a password reset link to your email.<br>
             Please check your <strong>inbox and spam folder</strong>.<br><br>
             The link expires in <strong>1 hour</strong>.</p>
          <div style="margin-top:1.5rem">
            <a href="login_user.php" style="color:var(--primary);font-weight:600;text-decoration:none">
              <i class="bi bi-arrow-left"></i> Back to Login
            </a>
          </div>
        </div>

      <?php else: ?>
        <div class="icon-ring"><i class="bi bi-shield-lock"></i></div>
        <div class="card-h">
          <h1>Forgot Password?</h1>
          <p>Enter your email — we'll send you a reset link</p>
        </div>

        <form method="post" id="fpForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div class="fgroup">
            <label class="flabel" for="email">Email Address</label>
            <div class="iwrap">
              <i class="bi bi-envelope iico"></i>
              <input type="email" name="email" id="email" class="finput"
                     placeholder="your@email.com"
                     value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                     required autocomplete="email">
            </div>
            <div class="hint">Enter the email linked to your Sesy Queen account.</div>
          </div>
          <button type="submit" class="btn-send" id="sendBtn">
            <i class="bi bi-send"></i> Send Reset Link
          </button>
        </form>

        <div class="divider"><span>Remember your password?</span></div>
        <div class="link-row">
          <a href="login_user.php"><i class="bi bi-box-arrow-in-right"></i> Back to Login</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script>
    AOS.init({once:true});
    // theme
    const tb = document.getElementById('themeBtn');
    if (localStorage.getItem('theme')==='dark'){document.body.dataset.theme='dark';tb.textContent='☀️';}
    tb.addEventListener('click',()=>{
      const d=document.body.dataset.theme==='dark';
      document.body.dataset.theme=d?'':'dark';
      tb.textContent=d?'🌙':'☀️';
      localStorage.setItem('theme',d?'light':'dark');
    });
    // loading state
    const form=document.getElementById('fpForm');
    if(form) form.addEventListener('submit',()=>{
      const b=document.getElementById('sendBtn');
      b.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;display:inline-block"></i> Sending…';
      b.style.opacity='.75'; b.style.pointerEvents='none';
    });
    // auto-dismiss toast
    const t=document.getElementById('toast');
    if(t) setTimeout(()=>t.remove(), t.classList.contains('error')?12000:6000);
  </script>
</body>
</html>
