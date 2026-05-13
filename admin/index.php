<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$message = '';
if (!defined('BREVO_API_KEY')) { include '../config.php'; }
include '../includes/db_connect.php';
if (!is_object($conn)) { die("Database connection failed."); }
if (!isset($_SESSION['admin_logged_in'])) { header("Location: ../login.php"); exit(); }

/* ── Email ─────────────────────────────────────────────────────── */
function sendBrevoEmail($toEmail,$toName,$subject,$htmlContent){
    $apiKey=$senderEmail=$senderName='';
    if(defined('BREVO_API_KEY'))     $apiKey      =BREVO_API_KEY;
    if(defined('BREVO_SENDER_EMAIL'))$senderEmail =BREVO_SENDER_EMAIL;
    if(defined('BREVO_SENDER_NAME')) $senderName  =BREVO_SENDER_NAME;
    if(empty($apiKey)||empty($senderEmail)) return false;
    $ch=curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['accept: application/json','api-key: '.$apiKey,'content-type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['sender'=>['name'=>$senderName,'email'=>$senderEmail],
            'to'=>[['email'=>$toEmail,'name'=>$toName]],'subject'=>$subject,'htmlContent'=>$htmlContent])]);
    $code=0; curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return $code>=200&&$code<300;
}

/* ── Stock update ───────────────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['update_stock'],$_POST['product_id'],$_POST['quantity'],$_POST['csrf_token'])&&$_POST['csrf_token']===$_SESSION['csrf_token']){
    $pid=(int)$_POST['product_id']; $qty=(int)$_POST['quantity'];
    if($qty>=0){
        try{ $conn->prepare("UPDATE products SET quantity=:q WHERE id=:id")->execute([':q'=>(string)$qty,':id'=>$pid]);
             $message="<div class='sq-alert success'><i class='bi bi-check-circle-fill'></i> Stock updated for product #$pid.</div>";
        }catch(PDOException $e){ $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> ".$e->getMessage()."</div>"; }
    } else { $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> Quantity cannot be negative.</div>"; }
}

/* ── Order status update ────────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['order_id'],$_POST['status'],$_POST['csrf_token'])&&$_POST['csrf_token']===$_SESSION['csrf_token']){
    $oid=(int)$_POST['order_id'];
    $status=in_array($_POST['status'],['Pending','Shipped','Delivered'])?$_POST['status']:'Pending';
    try{
        $conn->beginTransaction();
        $stmt=$conn->prepare("SELECT o.product_id,o.quantity,o.status,o.customer_email AS email,o.customer_name,p.item FROM orders o JOIN products p ON o.product_id=p.id WHERE o.id=:id");
        $stmt->execute([':id'=>$oid]); $order=$stmt->fetch(PDO::FETCH_ASSOC);
        if($order){
            if($status==='Delivered'&&$order['status']!=='Delivered'){
                $cur=(int)$conn->query("SELECT quantity FROM products WHERE id=".(int)$order['product_id'])->fetchColumn();
                $oqty=(int)$order['quantity'];
                if($cur<$oqty){ $conn->rollBack(); $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> Insufficient stock.</div>"; goto done; }
                $conn->prepare("UPDATE products SET quantity=:q WHERE id=:pid")->execute([':q'=>(string)($cur-$oqty),':pid'=>$order['product_id']]);
            }
            $conn->prepare("UPDATE orders SET status=:s WHERE id=:id")->execute([':s'=>$status,':id'=>$oid]);
            $stmt=$conn->prepare("SELECT o.*,p.item,o.customer_email AS email FROM orders o JOIN products p ON o.product_id=p.id WHERE o.id=:id");
            $stmt->execute([':id'=>$oid]); $upd=$stmt->fetch(PDO::FETCH_ASSOC);
            $conn->commit();
            if($upd&&!empty($upd['email'])){
                $html="<html><body style='font-family:Arial,sans-serif;color:#333'><div style='max-width:600px;margin:0 auto;border:1px solid #eee;border-radius:8px;overflow:hidden'><div style='background:linear-gradient(135deg,#8B5CF6,#EC4899);padding:24px;text-align:center'><h2 style='color:#fff;margin:0'>Order Update</h2></div><div style='padding:28px'><p>Hi <strong>{$upd['customer_name']}</strong>,</p><p>Your order <strong>#{$upd['id']}</strong> status is now <strong>$status</strong>.</p><table style='width:100%;border-collapse:collapse;margin:16px 0'><tr style='background:#f8f8f8'><td style='padding:10px;font-weight:600'>Product</td><td style='padding:10px'>{$upd['item']}</td></tr><tr><td style='padding:10px;font-weight:600'>Qty</td><td style='padding:10px'>{$upd['quantity']}</td></tr><tr style='background:#f8f8f8'><td style='padding:10px;font-weight:600'>Total</td><td style='padding:10px'>R".number_format($upd['total'],2)."</td></tr><tr><td style='padding:10px;font-weight:600'>Delivery</td><td style='padding:10px'>".ucfirst($upd['delivery_method'])." – R".number_format($upd['delivery_fee'],2)."</td></tr></table><div style='text-align:center;margin:24px 0'><a href='https://sunrisenwse.co.za/order_tracking.php' style='background:linear-gradient(135deg,#8B5CF6,#EC4899);color:#fff;padding:12px 28px;text-decoration:none;border-radius:50px;font-weight:700;display:inline-block'>Track My Order</a></div><p>Thank you for shopping with Sesy Queen!</p></div></div></body></html>";
                $sent=sendBrevoEmail($upd['email'],$upd['customer_name'],"Order Update – Sesy Queen #".$upd['id'],$html);
                $message=$sent?"<div class='sq-alert success'><i class='bi bi-check-circle-fill'></i> Status updated &amp; email sent to customer.</div>":"<div class='sq-alert warning'><i class='bi bi-exclamation-triangle-fill'></i> Status updated but email failed.</div>";
            } else { $message="<div class='sq-alert warning'><i class='bi bi-exclamation-triangle-fill'></i> Status updated. No customer email on file.</div>"; }
        } else { $conn->rollBack(); $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> Order not found.</div>"; }
    }catch(PDOException $e){ $conn->rollBack(); $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> ".$e->getMessage()."</div>"; }
}
done:

/* ── Clear delivered ────────────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['clear_delivered'],$_POST['csrf_token'])&&$_POST['csrf_token']===$_SESSION['csrf_token']){
    try{ $conn->prepare("DELETE FROM orders WHERE status='Delivered'")->execute();
         $message="<div class='sq-alert success'><i class='bi bi-check-circle-fill'></i> All delivered orders cleared.</div>";
    }catch(PDOException $e){ $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> ".$e->getMessage()."</div>"; }
}

/* ── Delete user ────────────────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['delete_user_id'],$_POST['csrf_token'])&&$_POST['csrf_token']===$_SESSION['csrf_token']){
    $uid=(int)$_POST['delete_user_id'];
    try{
        $u=$conn->prepare("SELECT role FROM users WHERE id=:id"); $u->execute([':id'=>$uid]); $ur=$u->fetch();
        if($ur&&$ur['role']==='admin'){ $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> Cannot delete admin user.</div>"; }
        else{
            $conn->prepare("DELETE FROM orders WHERE user_id=:id")->execute([':id'=>$uid]);
            $conn->prepare("DELETE FROM users  WHERE id=:id")->execute([':id'=>$uid]);
            $message="<div class='sq-alert success'><i class='bi bi-check-circle-fill'></i> User #$uid deleted.</div>";
        }
    }catch(PDOException $e){ $message="<div class='sq-alert danger'><i class='bi bi-x-circle-fill'></i> ".$e->getMessage()."</div>"; }
}

/* ── Queries ────────────────────────────────────────────────────── */
$search       = trim($_GET['search'] ?? '');
$order_filter = trim($_GET['order_status'] ?? '');

$pq = "SELECT * FROM products WHERE 1=1".($search?" AND item LIKE :s":"")." ORDER BY id DESC";
$ps = $conn->prepare($pq); $search?$ps->execute([':s'=>"%$search%"]):$ps->execute(); $products=$ps->fetchAll(PDO::FETCH_ASSOC);

$oq = "SELECT o.id,p.item,o.quantity,o.customer_name,o.contact,o.address,o.status,o.order_date,o.total,o.discount,o.delivery_method,o.delivery_fee FROM orders o JOIN products p ON o.product_id=p.id".($order_filter&&in_array($order_filter,['Pending','Shipped','Delivered'])?" WHERE o.status=:st":"")." ORDER BY o.order_date DESC";
$os=$conn->prepare($oq); $order_filter?$os->execute([':st'=>$order_filter]):$os->execute(); $orders=$os->fetchAll(PDO::FETCH_ASSOC);

$users=$conn->prepare("SELECT id,username,email,role FROM users ORDER BY id DESC"); $users->execute(); $users=$users->fetchAll(PDO::FETCH_ASSOC);

$cart_total     = (int)($conn->query("SELECT SUM(quantity) FROM cart")->fetchColumn()     ?: 0);
$wishlist_total = (int)($conn->query("SELECT COUNT(*) FROM wishlist")->fetchColumn()      ?: 0);
$user_total     = (int)($conn->query("SELECT COUNT(*) FROM users")->fetchColumn()         ?: 0);
$pending_total  = (int)($conn->query("SELECT COUNT(*) FROM orders WHERE status='Pending'")->fetchColumn() ?: 0);
$revenue_total  = (float)($conn->query("SELECT SUM(total) FROM orders WHERE status='Delivered'")->fetchColumn() ?: 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex"><meta name="theme-color" content="#8B5CF6">
  <title>Admin Dashboard – Sesy Queen</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{
      --primary:#8B5CF6;--primary-dark:#7C3AED;--secondary:#EC4899;
      --accent:#10B981;--warning:#F59E0B;--danger:#EF4444;--info:#3B82F6;
      --dark:#0F172A;--darker:#020617;--light:#F8FAFC;--gray:#64748B;
      --g2:linear-gradient(135deg,#8B5CF6,#EC4899);
      --g3:linear-gradient(135deg,#F4A261,#E9C46A);
      --shadow-sm:0 2px 4px rgba(0,0,0,.08);
      --shadow-md:0 4px 12px rgba(0,0,0,.1);
      --shadow-lg:0 10px 25px rgba(0,0,0,.12);
      --shadow-2xl:0 25px 50px rgba(0,0,0,.2);
      --glass:rgba(255,255,255,.95);
      --glass-b:1px solid rgba(255,255,255,.2);
      --card-bg:#fff;--body-bg:#F1F5F9;--text:#0F172A;--subtext:#64748B;
      --border:rgba(139,92,246,.15);--th-bg:linear-gradient(135deg,#8B5CF6,#EC4899)
    }
    [data-theme=dark]{
      --card-bg:#1E293B;--body-bg:#0F172A;--text:#F8FAFC;--subtext:#94A3B8;
      --border:rgba(139,92,246,.2);--glass:rgba(15,23,42,.95);--glass-b:1px solid rgba(255,255,255,.08)
    }
    body{font-family:'Space Grotesk',sans-serif;background:var(--body-bg);color:var(--text);transition:background .3s,color .3s;min-height:100vh}

    /* ── Navbar ── */
    .sq-nav{background:var(--g2);padding:.75rem 0;position:sticky;top:0;z-index:1000;box-shadow:0 4px 20px rgba(139,92,246,.35)}
    .sq-nav .container{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
    .sq-brand{display:flex;align-items:center;gap:.75rem;text-decoration:none}
    .sq-brand img{height:52px;filter:drop-shadow(0 2px 8px rgba(0,0,0,.2))}
    .sq-brand span{color:#fff;font-size:1.1rem;font-weight:700;letter-spacing:-.3px}
    .sq-brand small{display:block;color:rgba(255,255,255,.75);font-size:.72rem;font-weight:400;margin-top:-.1rem}
    .nav-actions{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
    .nav-pill{display:inline-flex;align-items:center;gap:.35rem;background:rgba(255,255,255,.15);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.25);border-radius:50px;padding:.35rem .9rem;color:#fff;font-size:.82rem;font-weight:500;white-space:nowrap}
    .nav-pill i{font-size:.9rem}
    .btn-nav{display:inline-flex;align-items:center;gap:.35rem;background:rgba(255,255,255,.2);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.3);border-radius:50px;padding:.4rem 1rem;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .25s;white-space:nowrap}
    .btn-nav:hover{background:rgba(255,255,255,.35);color:#fff}
    .btn-nav.danger-nav{background:rgba(239,68,68,.25);border-color:rgba(239,68,68,.4)}
    .btn-nav.danger-nav:hover{background:rgba(239,68,68,.45)}
    .theme-pill{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .25s;color:#fff}
    .theme-pill:hover{background:rgba(255,255,255,.35);transform:rotate(20deg)}

    /* ── Toast/Alert ── */
    .sq-alert{display:flex;align-items:center;gap:.75rem;padding:1rem 1.25rem;border-radius:14px;font-weight:500;font-size:.9rem;margin-bottom:1.5rem;animation:fadeIn .3s ease}
    .sq-alert.success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#059669}
    .sq-alert.warning{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#D97706}
    .sq-alert.danger {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#DC2626}
    @keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

    /* ── Stats ── */
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:1rem;margin-bottom:2rem}
    .stat-card{background:var(--card-bg);border-radius:20px;padding:1.4rem 1.25rem;box-shadow:var(--shadow-md);display:flex;align-items:center;gap:1rem;border:1px solid var(--border);transition:transform .25s,box-shadow .25s;position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
    .stat-card.c1::before{background:var(--g2)}
    .stat-card.c2::before{background:linear-gradient(135deg,#EF4444,#F97316)}
    .stat-card.c3::before{background:linear-gradient(135deg,#10B981,#3B82F6)}
    .stat-card.c4::before{background:linear-gradient(135deg,#F59E0B,#EF4444)}
    .stat-card.c5::before{background:linear-gradient(135deg,#3B82F6,#8B5CF6)}
    .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}
    .stat-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
    .stat-num{font-size:1.75rem;font-weight:700;line-height:1;color:var(--text)}
    .stat-lbl{font-size:.78rem;color:var(--subtext);margin-top:.2rem;font-weight:500}

    /* ── Section cards ── */
    .sq-section{background:var(--card-bg);border-radius:20px;padding:1.75rem;box-shadow:var(--shadow-md);border:1px solid var(--border);margin-bottom:2rem}
    .sq-section-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid var(--border)}
    .sq-section-title{font-size:1.15rem;font-weight:700;background:var(--g2);-webkit-background-clip:text;background-clip:text;color:transparent;display:flex;align-items:center;gap:.5rem}
    .sq-section-title i{background:var(--g2);-webkit-background-clip:text;background-clip:text}

    /* ── Buttons ── */
    .btn-sq{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1.1rem;border-radius:10px;font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;border:none;transition:all .25s;text-decoration:none;white-space:nowrap}
    .btn-sq:hover{transform:translateY(-1px)}
    .btn-sq.primary{background:var(--g2);color:#fff;box-shadow:0 4px 12px rgba(139,92,246,.3)}
    .btn-sq.primary:hover{box-shadow:0 6px 18px rgba(139,92,246,.45);color:#fff}
    .btn-sq.success{background:linear-gradient(135deg,#10B981,#059669);color:#fff}
    .btn-sq.danger{background:linear-gradient(135deg,#EF4444,#DC2626);color:#fff}
    .btn-sq.outline{background:transparent;border:1.5px solid var(--primary);color:var(--primary)}
    .btn-sq.outline:hover{background:var(--g2);color:#fff;border-color:transparent}
    .btn-sq.sm{padding:.38rem .75rem;font-size:.78rem}

    /* ── Search / Filter bar ── */
    .sq-search-bar{display:flex;gap:.6rem;margin-bottom:1.25rem;flex-wrap:wrap}
    .sq-input{padding:.65rem 1rem;border:1.5px solid var(--border);border-radius:10px;font-size:.88rem;font-family:inherit;background:var(--card-bg);color:var(--text);outline:none;transition:border .25s}
    .sq-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(139,92,246,.1)}

    /* ── Table ── */
    .sq-table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border)}
    .sq-table{width:100%;border-collapse:collapse;font-size:.85rem}
    .sq-table thead th{background:var(--g2);color:#fff;padding:.85rem 1rem;font-weight:600;white-space:nowrap;text-align:left}
    .sq-table thead th:first-child{border-radius:0}
    .sq-table tbody tr{border-bottom:1px solid var(--border);transition:background .2s}
    .sq-table tbody tr:hover{background:rgba(139,92,246,.04)}
    .sq-table tbody tr:last-child{border-bottom:none}
    .sq-table td{padding:.8rem 1rem;vertical-align:middle;color:var(--text)}
    .product-thumb{width:56px;height:56px;object-fit:cover;border-radius:10px;box-shadow:var(--shadow-sm)}

    /* ── Badges ── */
    .sq-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.3em .75em;border-radius:6px;font-size:.78rem;font-weight:600}
    .sq-badge.pending {background:rgba(245,158,11,.12);color:#D97706;border:1px solid rgba(245,158,11,.25)}
    .sq-badge.shipped {background:rgba(59,130,246,.12);color:#2563EB;border:1px solid rgba(59,130,246,.25)}
    .sq-badge.delivered{background:rgba(16,185,129,.12);color:#059669;border:1px solid rgba(16,185,129,.25)}
    .sq-badge.cancelled{background:rgba(239,68,68,.12);color:#DC2626;border:1px solid rgba(239,68,68,.25)}
    .sq-badge.admin{background:rgba(139,92,246,.12);color:var(--primary);border:1px solid rgba(139,92,246,.25)}
    .sq-badge.user{background:rgba(100,116,139,.1);color:var(--gray);border:1px solid rgba(100,116,139,.2)}
    .stock-low{color:#EF4444;font-weight:700}
    .stock-ok{color:#10B981;font-weight:600}

    /* ── Status select ── */
    .sq-select{padding:.4rem .75rem;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;font-family:inherit;background:var(--card-bg);color:var(--text);cursor:pointer;outline:none}
    .sq-select:focus{border-color:var(--primary)}

    /* ── Stock input group ── */
    .stock-group{display:flex;gap:.4rem;align-items:center}
    .stock-input{width:80px;padding:.4rem .6rem;border:1.5px solid var(--border);border-radius:8px;font-size:.85rem;font-family:inherit;background:var(--card-bg);color:var(--text);outline:none;text-align:center}
    .stock-input:focus{border-color:var(--primary)}

    /* ── Footer ── */
    .sq-footer{background:var(--darker);color:rgba(255,255,255,.6);text-align:center;padding:1.5rem;font-size:.82rem;margin-top:2rem}
    .sq-footer a{color:rgba(255,255,255,.8);text-decoration:none}

    /* ── Back to top ── */
    #btt{position:fixed;bottom:1.5rem;right:1.5rem;z-index:500;width:42px;height:42px;border-radius:50%;background:var(--g2);color:#fff;border:none;cursor:pointer;display:none;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:var(--shadow-lg);transition:all .25s}
    #btt:hover{transform:translateY(-3px)}

    @media(max-width:768px){
      .sq-nav .container{gap:.5rem}
      .nav-pill{display:none}
      .sq-section{padding:1.25rem}
      .stats-grid{grid-template-columns:repeat(2,1fr)}
    }
    @media(max-width:480px){
      .stats-grid{grid-template-columns:1fr 1fr}
      .sq-section-header{flex-direction:column;align-items:flex-start}
    }
  </style>
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────────── -->
<nav class="sq-nav">
  <div class="container">
    <a href="../index.php" class="sq-brand">
      <img src="../images/logo.png" alt="Sesy Queen" onerror="this.style.display='none'">
      <div><span>Sesy Queen</span><small>Admin Dashboard</small></div>
    </a>
    <div class="nav-actions">
      <span class="nav-pill"><i class="bi bi-people-fill"></i> <?= $user_total ?> Users</span>
      <span class="nav-pill"><i class="bi bi-clock-fill"></i> <?= $pending_total ?> Pending</span>
      <a href="../index.php" class="btn-nav"><i class="bi bi-shop"></i> Store</a>
      <a href="../logout.php" class="btn-nav danger-nav"><i class="bi bi-box-arrow-right"></i> Logout</a>
      <button class="theme-pill" id="themeBtn" title="Toggle dark mode">🌙</button>
    </div>
  </div>
</nav>

<!-- ── Alert ────────────────────────────────────────────────────── -->
<?php if($message): ?>
<div class="container mt-3"><?= $message ?></div>
<?php endif; ?>

<main class="container py-4">

  <!-- ── Stats ──────────────────────────────────────────────────── -->
  <div class="stats-grid" data-aos="fade-up">
    <div class="stat-card c1">
      <div class="stat-icon" style="background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(236,72,153,.15))"><i class="bi bi-bag-fill" style="color:#8B5CF6;font-size:1.5rem"></i></div>
      <div><div class="stat-num"><?= $pending_total ?></div><div class="stat-lbl">Pending Orders</div></div>
    </div>
    <div class="stat-card c2">
      <div class="stat-icon" style="background:rgba(239,68,68,.1)"><i class="bi bi-people-fill" style="color:#EF4444;font-size:1.5rem"></i></div>
      <div><div class="stat-num"><?= $user_total ?></div><div class="stat-lbl">Registered Users</div></div>
    </div>
    <div class="stat-card c3">
      <div class="stat-icon" style="background:rgba(16,185,129,.1)"><i class="bi bi-cart-fill" style="color:#10B981;font-size:1.5rem"></i></div>
      <div><div class="stat-num"><?= $cart_total ?></div><div class="stat-lbl">Items in Carts</div></div>
    </div>
    <div class="stat-card c4">
      <div class="stat-icon" style="background:rgba(245,158,11,.1)"><i class="bi bi-heart-fill" style="color:#F59E0B;font-size:1.5rem"></i></div>
      <div><div class="stat-num"><?= $wishlist_total ?></div><div class="stat-lbl">Wishlist Items</div></div>
    </div>
    <div class="stat-card c5">
      <div class="stat-icon" style="background:rgba(59,130,246,.1)"><i class="bi bi-currency-dollar" style="color:#3B82F6;font-size:1.5rem"></i></div>
      <div><div class="stat-num">R<?= number_format($revenue_total,0) ?></div><div class="stat-lbl">Revenue (Delivered)</div></div>
    </div>
  </div>

  <!-- ── Products ───────────────────────────────────────────────── -->
  <div class="sq-section" data-aos="fade-up">
    <div class="sq-section-header">
      <div class="sq-section-title"><i class="bi bi-box-seam-fill"></i> Product Management</div>
      <a href="add_product.php" class="btn-sq success"><i class="bi bi-plus-lg"></i> Add Product</a>
    </div>
    <form action="" method="get" class="sq-search-bar">
      <input type="text" name="search" class="sq-input" placeholder="🔍  Search products…" value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:200px">
      <button type="submit" class="btn-sq primary"><i class="bi bi-search"></i> Search</button>
      <?php if($search): ?><a href="?" class="btn-sq outline"><i class="bi bi-x"></i> Clear</a><?php endif; ?>
    </form>
    <div class="sq-table-wrap">
      <table class="sq-table">
        <thead>
          <tr>
            <th>Images</th>
            <th>Product</th>
            <th>Stock</th>
            <th>Price</th>
            <th>Update Stock</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($products as $r): ?>
          <tr>
            <td>
              <div style="display:flex;gap:.4rem">
                <img src="../images/<?= htmlspecialchars($r['image']) ?>" class="product-thumb" onerror="this.src='../images/hero.jpg'" alt="">
                <?php if(!empty($r['image2'])): ?><img src="../images/<?= htmlspecialchars($r['image2']) ?>" class="product-thumb" onerror="this.style.display='none'" alt=""><?php endif; ?>
                <?php if(!empty($r['image3'])): ?><img src="../images/<?= htmlspecialchars($r['image3']) ?>" class="product-thumb" onerror="this.style.display='none'" alt=""><?php endif; ?>
              </div>
            </td>
            <td><strong><?= htmlspecialchars($r['item']) ?></strong><br><small style="color:var(--subtext)"><?= htmlspecialchars($r['category']) ?></small></td>
            <td>
              <?php $qty=(int)$r['quantity']; ?>
              <span class="<?= $qty<=5?'stock-low':'stock-ok' ?>">
                <?= $qty ?><?= $qty==0?' <span style="font-size:.72rem">(OUT)</span>':($qty<=5?' ⚠️':'') ?>
              </span>
            </td>
            <td><strong>R<?= number_format($r['price'],2) ?></strong></td>
            <td>
              <form method="post" class="stock-group">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="product_id" value="<?= $r['id'] ?>">
                <input type="number" name="quantity" value="<?= htmlspecialchars($r['quantity']) ?>" min="0" class="stock-input" required>
                <button type="submit" name="update_stock" class="btn-sq primary sm">Save</button>
              </form>
            </td>
            <td>
              <div style="display:flex;gap:.4rem">
                <a href="edit_product.php?id=<?= $r['id'] ?>" class="btn-sq outline sm"><i class="bi bi-pencil"></i> Edit</a>
                <a href="delete_product.php?id=<?= $r['id'] ?>" class="btn-sq danger sm" onclick="return confirm('Delete this product?')"><i class="bi bi-trash"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($products)): ?>
          <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--subtext)"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem"></i>No products found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Orders ─────────────────────────────────────────────────── -->
  <div class="sq-section" data-aos="fade-up">
    <div class="sq-section-header">
      <div class="sq-section-title"><i class="bi bi-bag-check-fill"></i> Order Management</div>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
        <form action="" method="get" style="display:flex;gap:.5rem;align-items:center">
          <select name="order_status" class="sq-select" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="Pending"   <?= $order_filter==='Pending'  ?'selected':'' ?>>Pending</option>
            <option value="Shipped"   <?= $order_filter==='Shipped'  ?'selected':'' ?>>Shipped</option>
            <option value="Delivered" <?= $order_filter==='Delivered'?'selected':'' ?>>Delivered</option>
          </select>
        </form>
        <form method="post" onsubmit="return confirm('Delete all delivered orders?')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <button type="submit" name="clear_delivered" class="btn-sq danger sm"><i class="bi bi-trash3"></i> Clear Delivered</button>
        </form>
      </div>
    </div>
    <div class="sq-table-wrap">
      <table class="sq-table">
        <thead>
          <tr>
            <th>#ID</th><th>Product</th><th>Qty</th><th>Customer</th>
            <th>Contact</th><th>Address</th><th>Delivery</th>
            <th>Fee</th><th>Status</th><th>Date</th><th>Total</th><th>Update</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($orders as $r):
            $bc = strtolower($r['status']);
            $bc = in_array($bc,['pending','shipped','delivered','cancelled'])?$bc:'pending';
          ?>
          <tr>
            <td><strong>#<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></strong></td>
            <td><?= htmlspecialchars($r['item']) ?></td>
            <td><?= htmlspecialchars($r['quantity']) ?></td>
            <td><?= htmlspecialchars($r['customer_name']) ?></td>
            <td><?= htmlspecialchars($r['contact']) ?></td>
            <td style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['address']) ?>"><?= htmlspecialchars($r['address']) ?></td>
            <td><span class="sq-badge <?= $bc ?>" style="text-transform:capitalize"><?= htmlspecialchars(ucfirst($r['delivery_method'])) ?></span></td>
            <td>R<?= number_format($r['delivery_fee'],2) ?></td>
            <td><span class="sq-badge <?= $bc ?>"><i class="bi bi-<?= $bc==='pending'?'clock':($bc==='shipped'?'truck':($bc==='delivered'?'check-circle':'x-circle')) ?>"></i> <?= htmlspecialchars($r['status']) ?></span></td>
            <td style="white-space:nowrap"><?= date('d M Y',strtotime($r['order_date'])) ?><br><small style="color:var(--subtext)"><?= date('H:i',strtotime($r['order_date'])) ?></small></td>
            <td><strong>R<?= number_format($r['total']??0,2) ?></strong><?php if(($r['discount']??0)>0): ?><br><small style="color:#10B981">-R<?= number_format($r['discount'],2) ?></small><?php endif; ?></td>
            <td>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="order_id" value="<?= $r['id'] ?>">
                <select name="status" class="sq-select" onchange="this.form.submit()">
                  <option value="Pending"   <?= $r['status']==='Pending'  ?'selected':'' ?>>Pending</option>
                  <option value="Shipped"   <?= $r['status']==='Shipped'  ?'selected':'' ?>>Shipped</option>
                  <option value="Delivered" <?= $r['status']==='Delivered'?'selected':'' ?>>Delivered</option>
                </select>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($orders)): ?>
          <tr><td colspan="12" style="text-align:center;padding:2rem;color:var(--subtext)"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem"></i>No orders found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Users ──────────────────────────────────────────────────── -->
  <div class="sq-section" data-aos="fade-up">
    <div class="sq-section-header">
      <div class="sq-section-title"><i class="bi bi-people-fill"></i> Registered Users</div>
      <span class="sq-badge admin"><?= count($users) ?> total</span>
    </div>
    <div class="sq-table-wrap">
      <table class="sq-table">
        <thead><tr><th>#ID</th><th>Username</th><th>Email</th><th>Role</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach($users as $r): ?>
          <tr>
            <td><?= $r['id'] ?></td>
            <td><strong><?= htmlspecialchars($r['username']??'—') ?></strong></td>
            <td><?= htmlspecialchars($r['email']??'—') ?></td>
            <td><span class="sq-badge <?= $r['role']==='admin'?'admin':'user' ?>"><?= htmlspecialchars($r['role']??'user') ?></span></td>
            <td>
              <?php if($r['role']!=='admin'): ?>
              <form method="post" onsubmit="return confirm('Delete user <?= htmlspecialchars($r['username']) ?>? This also deletes their orders.')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="delete_user_id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn-sq danger sm"><i class="bi bi-trash"></i> Delete</button>
              </form>
              <?php else: ?>
              <span style="color:var(--subtext);font-size:.8rem">Protected</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($users)): ?>
          <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--subtext)">No users found</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<button id="btt" onclick="window.scrollTo({top:0,behavior:'smooth'})"><i class="bi bi-arrow-up"></i></button>

<footer class="sq-footer">
  <p>Contact: 0794416767 &nbsp;|&nbsp; Delivery: PEP/Pudo R140, Home R180, Heavy &gt;20kg R260 &nbsp;|&nbsp; Krugersdorp</p>
  <p style="margin-top:.35rem">© <?= date('Y') ?> Sesy Queen. All rights reserved.</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
  AOS.init({once:true,offset:60});

  // Theme
  const tb=document.getElementById('themeBtn');
  if(localStorage.getItem('theme')==='dark'){document.body.dataset.theme='dark';tb.textContent='☀️';}
  tb.addEventListener('click',()=>{const d=document.body.dataset.theme==='dark';document.body.dataset.theme=d?'':'dark';tb.textContent=d?'🌙':'☀️';localStorage.setItem('theme',d?'light':'dark');});

  // Back to top
  const btt=document.getElementById('btt');
  window.addEventListener('scroll',()=>{btt.style.display=window.scrollY>400?'flex':'none';});

  // Auto-dismiss alerts
  document.querySelectorAll('.sq-alert').forEach(el=>{setTimeout(()=>{el.style.opacity='0';el.style.transition='opacity .5s';setTimeout(()=>el.remove(),500);},5000);});
</script>
</body>
</html>
