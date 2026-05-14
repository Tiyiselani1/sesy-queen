<?php
session_start();
ini_set('display_errors', 1); error_reporting(E_ALL);
if (!isset($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
if (!defined('BREVO_API_KEY')) { include 'config.php'; }
$conn = include 'includes/db_connect.php';
if (!is_object($conn)) { die("Database connection failed."); }

$brevo_settings = [
    'api_key'      => defined('BREVO_API_KEY')      ? BREVO_API_KEY      : '',
    'sender_email' => defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : '',
    'sender_name'  => defined('BREVO_SENDER_NAME')  ? BREVO_SENDER_NAME  : 'Sesy Queen',
];

if (!isset($_SESSION['user_id'])) { header("Location: login_user.php"); exit; }

$message = '';
$status_filter = trim($_GET['status'] ?? '');
$search_query  = trim($_GET['search']  ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

/* ── Email helper ──────────────────────────────────────────────── */
function sendCancelEmail($email, $details) {
    global $brevo_settings;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($brevo_settings['api_key'])) return ['success'=>false,'error'=>'Invalid email or no API key'];
    $html = "<html><body style='font-family:Arial,sans-serif;color:#333'>
    <div style='max-width:600px;margin:0 auto;border:1px solid #eee;border-radius:8px;overflow:hidden'>
      <div style='background:linear-gradient(135deg,#8B5CF6,#EC4899);padding:20px;text-align:center'><h2 style='color:#fff;margin:0'>Order Cancelled</h2></div>
      <div style='padding:28px'>
        <p>Your order <strong>#{$details['order_id']}</strong> for <strong>{$details['item']}</strong> (Qty: {$details['quantity']}) has been cancelled.</p>
        <p>Total: <strong>R".number_format($details['total'],2)."</strong></p>
        <p>If you have questions, reply to this email.</p>
        <p>Best regards,<br>Sesy Queen Team</p>
      </div>
    </div></body></html>";
    $ch=curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['accept: application/json','api-key: '.$brevo_settings['api_key'],'content-type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['sender'=>['name'=>$brevo_settings['sender_name'],'email'=>$brevo_settings['sender_email']],
            'to'=>[['email'=>$email]],'subject'=>'Order Cancellation – Sesy Queen','htmlContent'=>$html])]);
    $code=0; curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return $code>=200&&$code<300 ? ['success'=>true] : ['success'=>false,'error'=>"HTTP $code"];
}

/* ── Cancel order ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cancel_order'],$_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) {
    $oid = (int)$_POST['order_id'];
    try {
        $s=$conn->prepare("SELECT o.*,p.item FROM orders o JOIN products p ON o.product_id=p.id WHERE o.id=:id AND o.user_id=:uid");
        $s->execute([':id'=>$oid,':uid'=>$_SESSION['user_id']]);
        $od=$s->fetch(PDO::FETCH_ASSOC);
        if ($od && $od['status']==='Pending') {
            $conn->prepare("UPDATE orders SET status='Cancelled',last_notified_status=NULL WHERE id=:id")->execute([':id'=>$oid]);
            $email = !empty($od['customer_email']) ? $od['customer_email'] : '';
            $r = $email ? sendCancelEmail($email,['order_id'=>$oid,'item'=>$od['item'],'quantity'=>$od['quantity'],'total'=>$od['total']]) : ['success'=>false];
            $message = "<div class='sq-toast success'><i class='bi bi-check-circle-fill'></i> Order #$oid cancelled".($r['success']?'. Confirmation email sent.':'.')."<button onclick='this.parentElement.remove()'>×</button></div>";
        } else {
            $message = "<div class='sq-toast error'><i class='bi bi-x-circle-fill'></i> Order cannot be cancelled.<button onclick='this.parentElement.remove()'>×</button></div>";
        }
    } catch(PDOException $e) {
        $message = "<div class='sq-toast error'><i class='bi bi-x-circle-fill'></i> ".htmlspecialchars($e->getMessage())."<button onclick='this.parentElement.remove()'>×</button></div>";
    }
}

/* ── Fetch data ────────────────────────────────────────────────── */
try {
    $stmt=$conn->prepare("SELECT username,email FROM users WHERE id=:id");
    $stmt->execute([':id'=>$_SESSION['user_id']]); $user_info=$stmt->fetch(PDO::FETCH_ASSOC);
    if (empty($user_info['email'])) {
        $fb=$conn->prepare("SELECT customer_email FROM orders WHERE user_id=:uid AND customer_email!='' LIMIT 1");
        $fb->execute([':uid'=>$_SESSION['user_id']]); $f=$fb->fetchColumn();
        if ($f) $user_info['email']=$f;
    }

    /* count */
    $cq = "SELECT COUNT(DISTINCT o.id) FROM orders o JOIN products p ON o.product_id=p.id WHERE o.user_id=:uid";
    $cp = [':uid'=>$_SESSION['user_id']];
    if ($status_filter && in_array($status_filter,['Pending','Shipped','Delivered','Cancelled'])) { $cq.=" AND o.status=:st"; $cp[':st']=$status_filter; }
    if ($search_query) { $cq.=" AND (CAST(o.id AS CHAR) LIKE :sq OR p.item LIKE :sq)"; $cp[':sq']="%$search_query%"; }
    $cs=$conn->prepare($cq); $cs->execute($cp); $total_orders=(int)$cs->fetchColumn();
    $total_pages=max(1,ceil($total_orders/$per_page));

    /* fetch orders — each row is one order (one product per order row by design) */
    $oq = "SELECT o.id, o.product_id, o.quantity, o.customer_name, o.contact, o.address,
                  o.status, o.order_date, o.total, o.discount, o.delivery_method, o.delivery_fee,
                  o.last_notified_status, p.item, p.image, p.price
           FROM orders o JOIN products p ON o.product_id=p.id
           WHERE o.user_id=:uid";
    $op=[':uid'=>$_SESSION['user_id']];
    if ($status_filter && in_array($status_filter,['Pending','Shipped','Delivered','Cancelled'])) { $oq.=" AND o.status=:st"; $op[':st']=$status_filter; }
    if ($search_query) { $oq.=" AND (CAST(o.id AS CHAR) LIKE :sq OR p.item LIKE :sq)"; $op[':sq']="%$search_query%"; }
    $oq.=" ORDER BY o.order_date DESC LIMIT :offset,:pp";
    $os=$conn->prepare($oq);
    $os->bindValue(':uid',$_SESSION['user_id']); $os->bindValue(':offset',($page-1)*$per_page,PDO::PARAM_INT); $os->bindValue(':pp',$per_page,PDO::PARAM_INT);
    if ($status_filter && in_array($status_filter,['Pending','Shipped','Delivered','Cancelled'])) $os->bindValue(':st',$status_filter);
    if ($search_query) $os->bindValue(':sq',"%$search_query%");
    $os->execute(); $orders=$os->fetchAll(PDO::FETCH_ASSOC);

    /* notify status changes */
    foreach ($orders as $ord) {
        if ($ord['status']!==$ord['last_notified_status'] && !empty($user_info['email'])) {
            $conn->prepare("UPDATE orders SET last_notified_status=:s WHERE id=:id")->execute([':s'=>$ord['status'],':id'=>$ord['id']]);
        }
    }

    $cart_count=(int)($conn->prepare("SELECT SUM(quantity) FROM cart WHERE user_id=:uid") && ($cst=$conn->prepare("SELECT SUM(quantity) FROM cart WHERE user_id=:uid")) && $cst->execute([':uid'=>$_SESSION['user_id']]) ? $cst->fetchColumn() : 0);
    $cst2=$conn->prepare("SELECT SUM(quantity) FROM cart WHERE user_id=:uid"); $cst2->execute([':uid'=>$_SESSION['user_id']]); $cart_count=(int)($cst2->fetchColumn()?:0);
    $wst=$conn->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id=:uid"); $wst->execute([':uid'=>$_SESSION['user_id']]); $wishlist_count=(int)$wst->fetchColumn();

} catch(PDOException $e) {
    error_log("order_tracking error: ".$e->getMessage());
    $orders=[]; $total_orders=0; $total_pages=1; $cart_count=0; $wishlist_count=0;
    $message="<div class='sq-toast error'><i class='bi bi-exclamation-triangle-fill'></i> Error loading orders.<button onclick='this.parentElement.remove()'>×</button></div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="apple-touch-icon" sizes="180x180" href="favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png">
    <link rel="manifest" href="favicon_io/site.webmanifest">
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="theme-color" content="#8B5CF6">
  <title>Order Tracking – Sesy Queen</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <style>
    *{margin:0;padding:0;box-sizing:border-box}
    :root{
      --primary:#8B5CF6;--primary-dark:#7C3AED;--secondary:#EC4899;
      --accent:#10B981;--warning:#F59E0B;--danger:#EF4444;--info:#3B82F6;
      --dark:#0F172A;--darker:#020617;--light:#F8FAFC;--gray:#64748B;
      --g2:linear-gradient(135deg,#8B5CF6,#EC4899);
      --shadow-sm:0 2px 4px rgba(0,0,0,.08);--shadow-md:0 4px 12px rgba(0,0,0,.1);
      --shadow-lg:0 10px 25px rgba(0,0,0,.12);--shadow-2xl:0 25px 50px rgba(0,0,0,.2);
      --glass:rgba(255,255,255,.95);--glass-b:1px solid rgba(255,255,255,.2)
    }
    [data-theme=dark]{--dark:#F8FAFC;--darker:#FFFFFF;--light:#0F172A;--gray:#94A3B8;--glass:rgba(15,23,42,.95);--glass-b:1px solid rgba(255,255,255,.1)}
    body{font-family:'Space Grotesk',sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;overflow-x:hidden;padding:2rem}
    .orb{position:fixed;border-radius:50%;animation:float 20s infinite ease-in-out;z-index:1;pointer-events:none}
    .orb-1{width:300px;height:300px;top:-150px;right:-150px;background:linear-gradient(135deg,rgba(139,92,246,.3),rgba(236,72,153,.3))}
    .orb-2{width:400px;height:400px;bottom:-200px;left:-200px;background:linear-gradient(135deg,rgba(16,185,129,.3),rgba(59,130,246,.3));animation-delay:-5s}
    @keyframes float{0%,100%{transform:translate(0,0) rotate(0deg)}33%{transform:translate(30px,-30px) rotate(120deg)}66%{transform:translate(-20px,20px) rotate(240deg)}}

    /* ── Navbar ── */
    .navbar{position:sticky;top:1rem;z-index:1000;padding:1rem 2rem;background:var(--glass);backdrop-filter:blur(10px);box-shadow:var(--shadow-lg);border:var(--glass-b);border-radius:50px;max-width:1400px;margin:0 auto 2rem}
    .nav-container{display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .logo img{height:50px;filter:drop-shadow(0 4px 6px rgba(0,0,0,.1));transition:transform .3s}
    .logo img:hover{transform:scale(1.05)}
    .nav-menu{display:flex;align-items:center;gap:2rem}
    .nav-link{color:var(--dark);text-decoration:none;font-weight:500;position:relative;padding:.5rem 0;transition:color .3s}
    .nav-link::after{content:'';position:absolute;bottom:0;left:0;width:0;height:2px;background:var(--g2);transition:width .3s}
    .nav-link:hover{color:var(--primary)}
    .nav-link:hover::after,.nav-link.active::after{width:100%}
    .nav-link.active{color:var(--primary)}
    .nav-icons{display:flex;align-items:center;gap:1rem}
    .icon-btn{background:none;border:none;color:var(--dark);font-size:1.2rem;cursor:pointer;position:relative;padding:.5rem;border-radius:50%;transition:all .3s;text-decoration:none;display:flex}
    .icon-btn:hover{background:var(--primary);color:#fff;transform:translateY(-2px)}
    .badge-pill{position:absolute;top:0;right:0;background:var(--secondary);color:#fff;font-size:.65rem;padding:.15rem .4rem;border-radius:50px;min-width:18px;text-align:center;line-height:1.4}
    .user-welcome{display:flex;align-items:center;gap:.5rem;padding:.4rem 1.1rem;background:var(--g2);border-radius:50px;color:#fff;font-weight:500;box-shadow:var(--shadow-md)}
    .welcome-text{font-size:.85rem;opacity:.9}
    .username-highlight{font-weight:700;font-size:.95rem}
    .dropdown{position:relative}
    .dropdown-menu-custom{position:absolute;top:110%;right:0;background:var(--glass);backdrop-filter:blur(10px);border-radius:14px;padding:.5rem;min-width:190px;box-shadow:var(--shadow-lg);border:var(--glass-b);display:none;z-index:2000}
    .dropdown-menu-custom.show{display:block}
    .dropdown-item-custom{display:block;padding:.65rem 1rem;color:var(--dark);text-decoration:none;border-radius:8px;font-size:.88rem;transition:all .25s}
    .dropdown-item-custom:hover,.dropdown-item-custom.active{background:var(--primary);color:#fff}
    .mobile-menu-btn{display:none;background:none;border:none;color:var(--dark);font-size:1.5rem;cursor:pointer}
    .mobile-menu{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:var(--glass);backdrop-filter:blur(20px);z-index:1500;padding:6rem 2rem 2rem;flex-direction:column;align-items:center;gap:2rem}
    .mobile-menu.active{display:flex}
    .mobile-menu .nav-link{font-size:1.4rem}

    /* ── Main card ── */
    .main-container{position:relative;z-index:10;max-width:1400px;margin:0 auto}
    .tracking-card{background:var(--glass);border:var(--glass-b);border-radius:40px;padding:3rem;box-shadow:var(--shadow-2xl);position:relative}
    .tracking-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--g2)}
    .section-header{text-align:center;margin-bottom:2.5rem}
    .section-subtitle{color:var(--primary);font-size:.95rem;text-transform:uppercase;letter-spacing:2px;font-weight:600;margin-bottom:.5rem}
    .section-title{font-size:2.2rem;font-weight:700;color:var(--dark)}
    .section-title span{background:var(--g2);-webkit-background-clip:text;background-clip:text;color:transparent}

    /* ── Filter ── */
    .filter-form{background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.15);border-radius:20px;padding:1.5rem;margin-bottom:2rem}
    .filter-grid{display:grid;grid-template-columns:1fr auto auto;gap:.75rem;align-items:center}
    .filter-input-group{position:relative}
    .filter-icon{position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--gray)}
    .filter-input,.filter-select{width:100%;padding:.75rem 1rem .75rem 2.75rem;border:1.5px solid rgba(139,92,246,.2);border-radius:12px;font-size:.9rem;font-family:inherit;background:var(--glass);color:var(--dark);outline:none;transition:border .25s}
    .filter-select{padding-left:1rem}
    .filter-input:focus,.filter-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(139,92,246,.1)}
    .filter-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;background:var(--g2);color:#fff;border:none;border-radius:12px;font-size:.9rem;font-weight:600;font-family:inherit;cursor:pointer;white-space:nowrap;transition:all .25s}
    .filter-btn:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(139,92,246,.4)}

    /* ── Orders grid ── */
    .orders-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.5rem}
    .order-card{background:rgba(255,255,255,.05);border-radius:20px;overflow:hidden;box-shadow:var(--shadow-md);transition:all .3s;border:var(--glass-b);display:flex;flex-direction:column}
    [data-theme=dark] .order-card{background:rgba(255,255,255,.03)}
    .order-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
    .order-header{padding:1.25rem 1.5rem;border-bottom:1px solid rgba(139,92,246,.15);display:flex;justify-content:space-between;align-items:center}
    .order-id{font-weight:700;font-size:1.05rem;color:var(--primary)}
    .order-date{font-size:.85rem;color:var(--gray)}
    .order-body{padding:1.5rem;flex:1}
    .product-info{display:flex;gap:1rem;margin-bottom:1rem}
    .product-image{width:76px;height:76px;object-fit:cover;border-radius:12px;box-shadow:var(--shadow-sm);flex-shrink:0}
    .product-name{font-weight:600;margin-bottom:.25rem;color:var(--dark)}
    .product-quantity{font-size:.88rem;color:var(--gray);margin-bottom:.25rem}
    .product-price{font-weight:700;color:var(--primary)}

    /* status badge */
    .status-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:50px;font-size:.85rem;font-weight:600}
    .status-pending {background:rgba(245,158,11,.12);color:#D97706;border:1px solid rgba(245,158,11,.25)}
    .status-shipped  {background:rgba(59,130,246,.12);color:#2563EB;border:1px solid rgba(59,130,246,.25)}
    .status-delivered{background:rgba(16,185,129,.12);color:#059669;border:1px solid rgba(16,185,129,.25)}
    .status-cancelled{background:rgba(239,68,68,.12); color:#DC2626;border:1px solid rgba(239,68,68,.25)}

    /* progress steps */
    .order-progress{margin:1rem 0}
    .progress-steps{display:flex;justify-content:space-between;position:relative}
    .progress-steps::before{content:'';position:absolute;top:14px;left:0;right:0;height:2px;background:rgba(139,92,246,.15);z-index:0}
    .step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative;z-index:1}
    .step-icon{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.15);border:2px solid rgba(139,92,246,.2);display:flex;align-items:center;justify-content:center;margin-bottom:.3rem;font-size:.8rem;transition:all .3s}
    .step.active .step-icon{background:var(--g2);border-color:transparent;color:#fff}
    .step.completed .step-icon{background:var(--accent);border-color:transparent;color:#fff}
    .step-label{font-size:.72rem;color:var(--gray);font-weight:500}
    .step.active .step-label{color:var(--primary);font-weight:700}
    .step.completed .step-label{color:var(--accent)}

    /* order details summary */
    .order-details{margin-top:1rem;padding-top:1rem;border-top:1px dashed rgba(139,92,246,.2)}
    .detail-row{display:flex;justify-content:space-between;margin-bottom:.4rem;font-size:.88rem}
    .detail-label{color:var(--gray)}
    .detail-value{font-weight:600;color:var(--dark)}

    /* footer buttons */
    .order-footer{padding:1.25rem 1.5rem;border-top:1px solid rgba(139,92,246,.15);display:flex;gap:.75rem}
    .btn-view{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.65rem 1rem;background:var(--g2);color:#fff;border:none;border-radius:12px;font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all .25s}
    .btn-view:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(139,92,246,.35)}
    .btn-cancel{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.65rem 1rem;background:rgba(239,68,68,.1);color:#DC2626;border:1.5px solid rgba(239,68,68,.3);border-radius:12px;font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all .25s;width:100%}
    .btn-cancel:hover{background:rgba(239,68,68,.2);transform:translateY(-2px)}

    /* ── Custom Modal (no Bootstrap JS needed) ── */
    .sq-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:3000;display:none;align-items:center;justify-content:center;padding:1rem}
    .sq-modal-overlay.active{display:flex}
    .sq-modal{background:var(--glass);backdrop-filter:blur(20px);border:var(--glass-b);border-radius:28px;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-2xl);animation:modalIn .25s ease}
    @keyframes modalIn{from{transform:scale(.92);opacity:0}to{transform:scale(1);opacity:1}}
    .sq-modal-header{padding:1.5rem;border-bottom:1px solid rgba(139,92,246,.15);display:flex;justify-content:space-between;align-items:center}
    .sq-modal-title{font-weight:700;font-size:1.1rem;color:var(--primary);display:flex;align-items:center;gap:.5rem}
    .sq-modal-close{background:none;border:none;color:var(--gray);font-size:1.4rem;cursor:pointer;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:all .25s}
    .sq-modal-close:hover{background:rgba(239,68,68,.1);color:#DC2626}
    .sq-modal-body{padding:1.75rem}
    .detail-section{margin-bottom:1.5rem}
    .detail-section-title{font-weight:700;color:var(--primary);font-size:.9rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem}
    .detail-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
    .detail-item small{display:block;color:var(--gray);font-size:.78rem;margin-bottom:.2rem}
    .detail-item p{font-weight:600;color:var(--dark);margin:0;font-size:.92rem}
    .product-modal-row{display:flex;gap:1rem;align-items:center}
    .product-modal-img{width:90px;height:90px;object-fit:cover;border-radius:14px;box-shadow:var(--shadow-md);flex-shrink:0}
    .sq-modal-footer{padding:1.25rem 1.75rem;border-top:1px solid rgba(139,92,246,.15);display:flex;justify-content:flex-end}
    .btn-close-modal{padding:.65rem 1.5rem;background:rgba(139,92,246,.1);color:var(--primary);border:1.5px solid rgba(139,92,246,.25);border-radius:12px;font-size:.88rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all .25s}
    .btn-close-modal:hover{background:var(--g2);color:#fff;border-color:transparent}

    /* ── Toast ── */
    .sq-toast{position:fixed;top:1.5rem;right:1.5rem;z-index:4000;background:var(--glass);backdrop-filter:blur(10px);border:var(--glass-b);border-radius:14px;padding:1rem 1.25rem;box-shadow:var(--shadow-2xl);display:flex;align-items:center;gap:.75rem;min-width:280px;max-width:420px;animation:slideIn .3s ease;font-weight:500;font-size:.9rem}
    .sq-toast.success{border-left:4px solid #10B981;color:#059669}
    .sq-toast.error  {border-left:4px solid #EF4444;color:#DC2626}
    .sq-toast.warning{border-left:4px solid #F59E0B;color:#D97706}
    .sq-toast button{background:none;border:none;color:var(--gray);cursor:pointer;font-size:1.1rem;margin-left:auto;padding:0}
    @keyframes slideIn{from{transform:translateX(110%);opacity:0}to{transform:translateX(0);opacity:1}}

    /* ── Empty state ── */
    .empty-state{text-align:center;padding:4rem 2rem}
    .empty-icon{font-size:5rem;color:var(--gray);margin-bottom:1rem}
    .empty-state h3{font-size:1.5rem;margin-bottom:.75rem;color:var(--dark)}
    .empty-state p{color:var(--gray);margin-bottom:2rem}
    .btn-shop{display:inline-flex;align-items:center;gap:.5rem;padding:1rem 2rem;background:var(--g2);color:#fff;text-decoration:none;border-radius:50px;font-weight:600;transition:all .3s}
    .btn-shop:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(139,92,246,.4)}

    /* ── Pagination ── */
    .pagination-container{display:flex;justify-content:center;margin-top:2.5rem}
    .pagination{display:flex;gap:.5rem;list-style:none}
    .page-link{display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.08);border:var(--glass-b);color:var(--dark);text-decoration:none;transition:all .3s}
    .page-link:hover,.page-item.active .page-link{background:var(--g2);color:#fff;border-color:transparent}
    .page-item.disabled .page-link{opacity:.4;pointer-events:none}

    /* ── Footer ── */
    .footer{background:var(--darker);color:#fff;padding:2.5rem 2rem 1.5rem;margin-top:4rem;border-radius:40px 40px 0 0}
    .footer-content{max-width:1400px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1.5rem}
    .footer-info{display:flex;gap:2rem;flex-wrap:wrap}
    .footer-info-item{display:flex;align-items:center;gap:.5rem;color:rgba(255,255,255,.75);font-size:.88rem}
    .footer-info-item i{color:var(--primary)}
    .footer-copyright{text-align:center;margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,.1);color:rgba(255,255,255,.45);font-size:.82rem}

    /* ── Back to top ── */
    #btt{position:fixed;bottom:1.5rem;right:1.5rem;z-index:500;width:46px;height:46px;border-radius:50%;background:var(--g2);color:#fff;border:none;cursor:pointer;display:none;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:var(--shadow-lg)}
    #btt:hover{transform:translateY(-3px)}

    /* ── Responsive ── */
    @media(max-width:1024px){.nav-menu{display:none}.mobile-menu-btn{display:block}.nav-icons{margin-left:auto}}
    @media(max-width:768px){body{padding:1rem}.navbar{padding:.75rem 1rem;top:.5rem}.tracking-card{padding:1.5rem}.orders-grid{grid-template-columns:1fr}.filter-grid{grid-template-columns:1fr}.welcome-text{display:none}.sq-toast{min-width:auto;width:calc(100% - 2rem);right:1rem;left:1rem}}
    @media(max-width:480px){.detail-grid-2{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<!-- ── Navbar ──────────────────────────────────────────────────── -->
<nav class="navbar" data-aos="fade-down">
  <div class="nav-container">
    <a href="index.php" class="logo"><img src="images/logo.png" alt="Sesy Queen" onerror="this.src='images/hero.jpg'"></a>
    <div class="nav-menu">
      <a href="index.php" class="nav-link">Home</a>
      <a href="index.php#products" class="nav-link">Products</a>
      <a href="index.php#about" class="nav-link">About</a>
      <a href="index.php#services" class="nav-link">Services</a>
      <a href="index.php#contact" class="nav-link">Contact</a>
    </div>
    <div class="nav-icons">
      <?php if(isset($_SESSION['user_id'])): ?>
        <div class="user-welcome">
          <span class="welcome-text">Welcome,</span>
          <span class="username-highlight"><?= htmlspecialchars($user_info['username']) ?></span>
          <i class="bi bi-star-fill" style="color:#FFD700"></i>
        </div>
        <a href="cart.php" class="icon-btn" style="position:relative">
          <i class="bi bi-cart3"></i>
          <?php if($cart_count>0): ?><span class="badge-pill"><?= $cart_count ?></span><?php endif; ?>
        </a>
        <a href="wishlist.php" class="icon-btn" style="position:relative">
          <i class="bi bi-heart"></i>
          <?php if($wishlist_count>0): ?><span class="badge-pill"><?= $wishlist_count ?></span><?php endif; ?>
        </a>
        <div class="dropdown">
          <button class="icon-btn" id="userDropdown"><i class="bi bi-person-circle"></i></button>
          <div class="dropdown-menu-custom" id="dropdownMenu">
            <span class="dropdown-item-custom" style="font-weight:600;cursor:default">👋 <?= htmlspecialchars($user_info['username']) ?></span>
            <a href="profile.php" class="dropdown-item-custom">Profile</a>
            <a href="order_tracking.php" class="dropdown-item-custom active">Track Orders</a>
            <a href="logout.php" class="dropdown-item-custom">Logout</a>
          </div>
        </div>
      <?php else: ?>
        <a href="login_user.php" class="icon-btn"><i class="bi bi-box-arrow-in-right"></i></a>
        <a href="register_user.php" class="icon-btn"><i class="bi bi-person-plus"></i></a>
      <?php endif; ?>
      <button class="icon-btn" id="themeToggle"><i class="bi bi-moon-stars" id="themeIcon"></i></button>
    </div>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="bi bi-list"></i></button>
  </div>
</nav>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
  <a href="index.php" class="nav-link">Home</a>
  <a href="index.php#products" class="nav-link">Products</a>
  <a href="index.php#about" class="nav-link">About</a>
  <a href="index.php#services" class="nav-link">Services</a>
  <a href="index.php#contact" class="nav-link">Contact</a>
  <?php if(isset($_SESSION['user_id'])): ?>
    <a href="profile.php" class="nav-link">Profile</a>
    <a href="order_tracking.php" class="nav-link active">Track Orders</a>
    <a href="logout.php" class="nav-link">Logout</a>
  <?php else: ?>
    <a href="login_user.php" class="nav-link">Login</a>
    <a href="register_user.php" class="nav-link">Register</a>
  <?php endif; ?>
</div>

<!-- Toast -->
<?php if($message) echo $message; ?>

<!-- ── Main ──────────────────────────────────────────────────────── -->
<div class="main-container" data-aos="fade-up">
  <div class="tracking-card">
    <div class="section-header">
      <div class="section-subtitle">My Orders</div>
      <h1 class="section-title">Order <span>Tracking</span></h1>
    </div>

    <!-- Filter -->
    <div class="filter-form">
      <form action="" method="get" class="filter-grid">
        <div class="filter-input-group">
          <i class="bi bi-search filter-icon"></i>
          <input type="text" name="search" class="filter-input" placeholder="Search by Order ID or product…" value="<?= htmlspecialchars($search_query) ?>">
        </div>
        <select name="status" class="filter-select">
          <option value="">All Statuses</option>
          <option value="Pending"   <?= $status_filter==='Pending'  ?'selected':'' ?>>Pending</option>
          <option value="Shipped"   <?= $status_filter==='Shipped'  ?'selected':'' ?>>Shipped</option>
          <option value="Delivered" <?= $status_filter==='Delivered'?'selected':'' ?>>Delivered</option>
          <option value="Cancelled" <?= $status_filter==='Cancelled'?'selected':'' ?>>Cancelled</option>
        </select>
        <button type="submit" class="filter-btn"><i class="bi bi-funnel"></i> Filter</button>
      </form>
    </div>

    <?php if(empty($orders)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-truck"></i></div>
        <h3>No Orders Found</h3>
        <p><?= ($search_query||$status_filter) ? 'No orders match your filters.' : "You haven't placed any orders yet." ?></p>
        <a href="index.php#products" class="btn-shop"><i class="bi bi-shop"></i> Start Shopping</a>
      </div>
    <?php else: ?>
      <div class="orders-grid">
        <?php 
        $modal_html = '';
        foreach($orders as $order):
          $sc = 'status-'.strtolower($order['status']);
          $pr = $order['status']==='Pending'?1:($order['status']==='Shipped'?2:($order['status']==='Delivered'?3:0));
          $icon = $order['status']==='Pending'?'clock':($order['status']==='Shipped'?'truck':($order['status']==='Delivered'?'check-circle':'x-circle'));
        ?>
        <div class="order-card" data-aos="fade-up">
          <div class="order-header">
            <span class="order-id">#<?= str_pad($order['id'],6,'0',STR_PAD_LEFT) ?></span>
            <span class="order-date"><?= date('M d, Y',strtotime($order['order_date'])) ?></span>
          </div>
          <div class="order-body">
            <div class="product-info">
              <img src="images/<?= htmlspecialchars($order['image']) ?>" alt="<?= htmlspecialchars($order['item']) ?>" class="product-image" onerror="this.src='images/hero.jpg'">
              <div>
                <div class="product-name"><?= htmlspecialchars($order['item']) ?></div>
                <div class="product-quantity">Quantity: <?= $order['quantity'] ?></div>
                <div class="product-price">R<?= number_format($order['total'],2) ?></div>
              </div>
            </div>
            <div style="display:flex;justify-content:center;margin:1rem 0">
              <span class="status-badge <?= $sc ?>"><i class="bi bi-<?= $icon ?>"></i> <?= $order['status'] ?></span>
            </div>
            <?php if($order['status']!=='Cancelled'): ?>
            <div class="order-progress">
              <div class="progress-steps">
                <?php foreach(['Pending'=>'clock','Shipped'=>'truck','Delivered'=>'check-circle'] as $step=>$sico):
                  $sn = $step==='Pending'?1:($step==='Shipped'?2:3);
                  $cls = ($sn<$pr)?'completed':($sn===$pr?'active':'');
                ?>
                <div class="step <?= $cls ?>">
                  <div class="step-icon"><i class="bi bi-<?= $sico ?>"></i></div>
                  <span class="step-label"><?= $step ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
            <div class="order-details">
              <div class="detail-row"><span class="detail-label">Total Amount:</span><span class="detail-value">R<?= number_format($order['total'],2) ?></span></div>
              <?php if($order['discount']>0): ?><div class="detail-row"><span class="detail-label">Discount:</span><span class="detail-value" style="color:#10B981">-R<?= number_format($order['discount'],2) ?></span></div><?php endif; ?>
              <div class="detail-row"><span class="detail-label">Delivery:</span><span class="detail-value"><?= htmlspecialchars(ucfirst($order['delivery_method'])) ?> – R<?= number_format($order['delivery_fee'],2) ?></span></div>
              <div class="detail-row"><span class="detail-label">Address:</span><span class="detail-value"><?= htmlspecialchars(mb_strimwidth($order['address'],0,35,'…')) ?></span></div>
            </div>
          </div>
          <div class="order-footer">
            <button class="btn-view" onclick="openModal(<?= $order['id'] ?>)"><i class="bi bi-eye"></i> View Details</button>
            <?php if($order['status']==='Pending'): ?>
            <form method="post" style="flex:1" onsubmit="return confirm('Cancel order #<?= $order['id'] ?>?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
              <button type="submit" name="cancel_order" class="btn-cancel"><i class="bi bi-x-circle"></i> Cancel</button>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <?php ob_start(); ?>
        <!-- Modal for this order -->
        <div class="sq-modal-overlay" id="modal-<?= $order['id'] ?>" onclick="if(event.target===this)closeModal(<?= $order['id'] ?>)">
          <div class="sq-modal">
            <div class="sq-modal-header">
              <div class="sq-modal-title"><i class="bi bi-box-seam"></i> Order #<?= str_pad($order['id'],6,'0',STR_PAD_LEFT) ?></div>
              <button class="sq-modal-close" onclick="closeModal(<?= $order['id'] ?>)"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="sq-modal-body">
              <!-- Order Info -->
              <div class="detail-section">
                <div class="detail-section-title"><i class="bi bi-info-circle"></i> Order Information</div>
                <div class="detail-grid-2">
                  <div class="detail-item"><small>Order Date</small><p><?= date('F j, Y g:i A',strtotime($order['order_date'])) ?></p></div>
                  <div class="detail-item"><small>Status</small><p><span class="status-badge <?= $sc ?>" style="display:inline-flex"><i class="bi bi-<?= $icon ?>"></i> <?= $order['status'] ?></span></p></div>
                  <div class="detail-item"><small>Total Amount</small><p style="color:var(--primary)">R<?= number_format($order['total'],2) ?></p></div>
                  <div class="detail-item"><small>Discount Applied</small><p>R<?= number_format($order['discount'],2) ?></p></div>
                </div>
              </div>
              <!-- Product -->
              <div class="detail-section">
                <div class="detail-section-title"><i class="bi bi-bag"></i> Product Details</div>
                <div class="product-modal-row">
                  <img src="images/<?= htmlspecialchars($order['image']) ?>" alt="<?= htmlspecialchars($order['item']) ?>" class="product-modal-img" onerror="this.src='images/hero.jpg'">
                  <div>
                    <p style="font-weight:700;font-size:1rem;margin-bottom:.35rem"><?= htmlspecialchars($order['item']) ?></p>
                    <p style="color:var(--gray);margin-bottom:.2rem">Quantity: <?= $order['quantity'] ?></p>
                    <p style="color:var(--gray)">Price: R<?= number_format($order['total']/$order['quantity'],2) ?> each</p>
                  </div>
                </div>
              </div>
              <!-- Delivery -->
              <div class="detail-section">
                <div class="detail-section-title"><i class="bi bi-truck"></i> Delivery Information</div>
                <div class="detail-grid-2">
                  <div class="detail-item"><small>Customer Name</small><p><?= htmlspecialchars($order['customer_name']) ?></p></div>
                  <div class="detail-item"><small>Contact Number</small><p><?= htmlspecialchars($order['contact']) ?></p></div>
                  <div class="detail-item"><small>Delivery Method</small><p><?= htmlspecialchars(ucfirst($order['delivery_method'])) ?></p></div>
                  <div class="detail-item"><small>Delivery Fee</small><p>R<?= number_format($order['delivery_fee'],2) ?></p></div>
                  <div class="detail-item" style="grid-column:1/-1"><small>Delivery Address</small><p><?= htmlspecialchars($order['address']) ?></p></div>
                </div>
              </div>
            </div>
            <div class="sq-modal-footer">
              <button class="btn-close-modal" onclick="closeModal(<?= $order['id'] ?>)">Close</button>
            </div>
          </div>
        </div>
        <?php $modal_html .= ob_get_clean(); ?>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if($total_pages>1): ?>
      <div class="pagination-container">
        <ul class="pagination">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="?page=<?= $page-1 ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search_query) ?>"><i class="bi bi-chevron-left"></i></a>
          </li>
          <?php for($i=1;$i<=$total_pages;$i++): ?>
          <li class="page-item <?= $page==$i?'active':'' ?>">
            <a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search_query) ?>"><?= $i ?></a>
          </li>
          <?php endfor; ?>
          <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
            <a class="page-link" href="?page=<?= $page+1 ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search_query) ?>"><i class="bi bi-chevron-right"></i></a>
          </li>
        </ul>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── Modals rendered outside tracking-card to avoid stacking context ── -->
<?php echo $modal_html ?? ''; ?>

<!-- ── Footer ─────────────────────────────────────────────────────── -->
<footer class="footer">
  <div class="footer-content">
    <div class="footer-info">
      <div class="footer-info-item"><i class="bi bi-telephone"></i><span>079 441 6767</span></div>
      <div class="footer-info-item"><i class="bi bi-truck"></i><span>Nationwide Delivery from R140</span></div>
      <div class="footer-info-item"><i class="bi bi-geo-alt"></i><span>Krugersdorp</span></div>
    </div>
    <a href="index.php"><img src="images/logo.png" alt="Sesy Queen" style="height:42px;filter:brightness(0) invert(1)" onerror="this.style.display='none'"></a>
  </div>
  <div class="footer-copyright">© <?= date('Y') ?> Sesy Queen. All rights reserved.</div>
</footer>

<button id="btt" onclick="window.scrollTo({top:0,behavior:'smooth'})"><i class="bi bi-arrow-up"></i></button>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
AOS.init({duration:800,once:true});

/* ── Modal ── */
function openModal(id){
  const m=document.getElementById('modal-'+id);
  if(m){m.classList.add('active');document.body.style.overflow='hidden';}
}
function closeModal(id){
  const m=document.getElementById('modal-'+id);
  if(m){m.classList.remove('active');document.body.style.overflow='';}
}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.sq-modal-overlay.active').forEach(m=>m.classList.remove('active'));document.body.style.overflow='';}});

/* ── Theme ── */
const ti=document.getElementById('themeIcon');
if(localStorage.getItem('theme')==='dark'){document.body.dataset.theme='dark';ti.className='bi bi-brightness-high-fill';}
document.getElementById('themeToggle').addEventListener('click',()=>{
  const d=document.body.dataset.theme==='dark';
  document.body.dataset.theme=d?'':'dark';
  ti.className=d?'bi bi-moon-stars':'bi bi-brightness-high-fill';
  localStorage.setItem('theme',d?'light':'dark');
});

/* ── Mobile menu ── */
const mmb=document.getElementById('mobileMenuBtn'),mm=document.getElementById('mobileMenu');
mmb.addEventListener('click',()=>{mm.classList.toggle('active');mmb.querySelector('i').className=mm.classList.contains('active')?'bi bi-x-lg':'bi bi-list';document.body.style.overflow=mm.classList.contains('active')?'hidden':'';});
mm.querySelectorAll('.nav-link').forEach(l=>l.addEventListener('click',()=>{mm.classList.remove('active');mmb.querySelector('i').className='bi bi-list';document.body.style.overflow='';}));

/* ── Dropdown ── */
const ud=document.getElementById('userDropdown'),dm=document.getElementById('dropdownMenu');
if(ud&&dm){ud.addEventListener('click',e=>{e.stopPropagation();dm.classList.toggle('show');});document.addEventListener('click',()=>dm.classList.remove('show'));}

/* ── Back to top ── */
const btt=document.getElementById('btt');
window.addEventListener('scroll',()=>{btt.style.display=window.scrollY>300?'flex':'none';});

/* ── Auto-dismiss toasts ── */
document.querySelectorAll('.sq-toast').forEach(t=>setTimeout(()=>{if(t.parentNode)t.remove();},6000));
</script>
</body>
</html>