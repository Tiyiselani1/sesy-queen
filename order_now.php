<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token for form security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!defined('BREVO_API_KEY')) { include 'config.php'; }
$conn = include 'includes/db_connect.php';
if (!is_object($conn)) {
    die("Database connection failed.");
}

$cart_items = [];
$total = 0;
$discount = 0;
$delivery_fee = 0.00;
$message = '';
$errors = [];
$user_info = [];
$promo_code = '';
$delivery_method = isset($_POST['delivery_method']) ? $_POST['delivery_method'] : '';
$order_success = false;

// Enable error logging
error_log("=== Checkout Process Started at " . date('Y-m-d H:i:s') . " ===");

// Brevo API key
// BREVO_API_KEY defined in config.php
define('ADMIN_EMAIL', defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : 'drivewaymotors01@gmail.com');

function sendBrevoEmail($toEmail, $toName, $subject, $htmlContent) {
    if (empty($toEmail)) {
        error_log("Failed to send email: No recipient email provided for subject '$subject'");
        return false;
    }
    $url = 'https://api.brevo.com/v3/smtp/email';
    $headers = [
        'accept: application/json',
        'api-key: ' . BREVO_API_KEY,
        'content-type: application/json'
    ];
    $data = [
        'sender' => [
            'name'  => defined('BREVO_SENDER_NAME')  ? BREVO_SENDER_NAME  : 'Sesy Queen',
            'email' => defined('BREVO_SENDER_EMAIL') ? BREVO_SENDER_EMAIL : 'drivewaymotors01@gmail.com'
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $toName
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("Brevo email sent successfully to $toEmail for subject '$subject'");
        return true;
    } else {
        error_log("Brevo email failed to $toEmail for subject '$subject': HTTP $httpCode, Response: $response, Error: $curlError");
        return false;
    }
}

// Fetch user info and cart items
if (isset($_SESSION['user_id'])) {
    try {
        error_log("Fetching user info for user_id: " . $_SESSION['user_id']);
        
        // Fetch user info
        $stmt = $conn->prepare("SELECT username, email, first_name, last_name FROM users WHERE id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $user_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user_info) {
            error_log("No user found for user_id: " . $_SESSION['user_id']);
            $errors[] = "User not found. Please login again.";
        } elseif (empty($user_info['email'])) {
            error_log("User email empty for user_id: " . $_SESSION['user_id']);
            $errors[] = "User email not found. Please update your profile.";
        } else {
            error_log("User found: " . $user_info['username'] . ", Email: " . $user_info['email']);
        }

        // Fetch cart items with product quantities
        $stmt = $conn->prepare("
            SELECT c.id, c.product_id, c.quantity, p.item, p.price, p.image, p.quantity AS stock
            FROM cart c
            JOIN products p ON c.product_id = p.id
            WHERE c.user_id = :user_id
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Cart items found: " . count($cart_items));

        // Calculate subtotal and total weight
        $total_weight = 0;
        foreach ($cart_items as $item) {
            $total += $item['price'] * $item['quantity'];
            $total_weight += $item['quantity'] * 0.2;
            error_log("Product: {$item['item']}, Qty: {$item['quantity']}, Stock: {$item['stock']}, Price: {$item['price']}");
        }

        // Determine delivery fee
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $delivery_method) {
            if ($delivery_method === 'pep' || $delivery_method === 'pudo') {
                $delivery_fee = 140.00;
            } elseif ($delivery_method === 'home') {
                $delivery_fee = $total_weight > 20 ? 260.00 : 180.00;
            } else {
                $errors[] = "Please select a valid delivery method.";
            }
            error_log("Delivery method: $delivery_method, Fee: $delivery_fee");
        }

        // Handle order submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
            error_log("Processing order submission...");
            
            // Validate form inputs
            $customer_name = trim($_POST['customer_name'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $promo_code = trim($_POST['promo_code'] ?? '');

            error_log("Form data - Name: $customer_name, Contact: $contact, Address: $address, Promo: $promo_code");

            if (empty($customer_name)) {
                $errors[] = "Customer name is required.";
            }
            if (empty($contact) || !preg_match('/^\d{10}$/', $contact)) {
                $errors[] = "Valid 10-digit contact number is required.";
            }
            if (empty($address)) {
                $errors[] = "Delivery address is required.";
            }
            if (empty($cart_items)) {
                $errors[] = "Your cart is empty.";
            }
            if (empty($delivery_method)) {
                $errors[] = "Please select a delivery method.";
            }

            // Validate stock availability
            foreach ($cart_items as $item) {
                if ((int)$item['quantity'] > (int)$item['stock']) {
                    $errors[] = "Insufficient stock for " . htmlspecialchars($item['item']) . ". Available: " . $item['stock'] . ", Requested: " . $item['quantity'];
                    error_log("Stock validation failed for product {$item['product_id']}: Available {$item['stock']}, Requested {$item['quantity']}");
                }
            }

            // Validate promo code
            if ($promo_code === 'SESQUEEN10' && $total > 0) {
                $discount = $total * 0.10;
                error_log("Promo code applied. Discount: $discount");
            } elseif ($promo_code !== '') {
                $errors[] = "Invalid promo code.";
                error_log("Invalid promo code: $promo_code");
            }

            if (empty($errors)) {
                try {
                    error_log("Starting database transaction...");
                    $conn->beginTransaction();
                    
                    $order_ids = [];

                    // Insert orders and update product quantities
                    foreach ($cart_items as $item) {
                        error_log("Processing item: {$item['item']} (ID: {$item['product_id']})");
                        
                        // Insert order
                        $stmt = $conn->prepare("
                            INSERT INTO orders (user_id, product_id, quantity, customer_name, customer_email, contact, status, address, delivery_method, delivery_fee, total, discount)
                            VALUES (:user_id, :product_id, :quantity, :customer_name, :customer_email, :contact, :status, :address, :delivery_method, :delivery_fee, :total, :discount)
                        ");
                        
                        $item_total = $item['price'] * $item['quantity'];
                        $item_discount = $total > 0 ? ($discount / $total) * $item_total : 0;
                        $item_delivery_fee = count($cart_items) > 0 ? $delivery_fee / count($cart_items) : 0;
                        
                        error_log("Order values - Item total: $item_total, Item discount: $item_discount, Item delivery fee: $item_delivery_fee");
                        
                        $result = $stmt->execute([
                            ':user_id' => $_SESSION['user_id'],
                            ':product_id' => $item['product_id'],
                            ':quantity' => $item['quantity'],
                            ':customer_name' => $customer_name,
                            ':customer_email' => $user_info['email'],
                            ':contact' => $contact,
                            ':status' => 'Pending',
                            ':address' => $address,
                            ':delivery_method' => $delivery_method,
                            ':delivery_fee' => $item_delivery_fee,
                            ':total' => $item_total,
                            ':discount' => $item_discount
                        ]);
                        
                        if (!$result) {
                            $errorInfo = $stmt->errorInfo();
                            throw new PDOException("Failed to insert order: " . $errorInfo[2]);
                        }
                        
                        $order_id = $conn->lastInsertId();
                        $order_ids[] = $order_id;
                        error_log("Order inserted successfully with ID: $order_id");

                        // Check current stock with lock
                        $stmt = $conn->prepare("SELECT quantity FROM products WHERE id = :product_id FOR UPDATE");
                        $stmt->execute([':product_id' => $item['product_id']]);
                        $current_stock = (int)$stmt->fetchColumn(); // cast varchar to int
                        
                        error_log("Product ID {$item['product_id']}: Current stock = $current_stock, Requested quantity = {$item['quantity']}");
                        
                        if ($current_stock === false) {
                            throw new PDOException("Product ID {$item['product_id']} not found in database");
                        }
                        
                        if ($current_stock < $item['quantity']) {
                            throw new PDOException("Insufficient stock for product ID {$item['product_id']}. Available: $current_stock, Requested: {$item['quantity']}");
                        }

                        // Update product quantity — cast to int because column is varchar
                        $new_qty = $current_stock - (int)$item['quantity'];
                        $stmt = $conn->prepare("
                            UPDATE products 
                            SET quantity = :new_qty 
                            WHERE id = :product_id
                        ");
                        $result = $stmt->execute([
                            ':new_qty' => $new_qty,
                            ':product_id' => $item['product_id']
                        ]);
                        
                        if (!$result) {
                            $errorInfo = $stmt->errorInfo();
                            throw new PDOException("Failed to update product quantity: " . $errorInfo[2]);
                        }

                        $affected_rows = $stmt->rowCount();
                        if ($affected_rows === 0) {
                            // Check current stock again
                            $stmt = $conn->prepare("SELECT quantity FROM products WHERE id = :product_id");
                            $stmt->execute([':product_id' => $item['product_id']]);
                            $new_stock = $stmt->fetchColumn();
                            
                            error_log("Update failed for product ID {$item['product_id']}. Current stock: $new_stock");
                            
                            if ($new_stock < $item['quantity']) {
                                throw new PDOException("Insufficient stock for product ID {$item['product_id']}. Available: $new_stock, Requested: {$item['quantity']}");
                            } else {
                                throw new PDOException("Failed to update quantity for product ID {$item['product_id']}. No rows affected.");
                            }
                        }
                        
                        error_log("Successfully updated quantity for product ID {$item['product_id']}. New stock: " . ($current_stock - $item['quantity']));
                    }

                    // Clear cart
                    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = :user_id");
                    $result = $stmt->execute([':user_id' => $_SESSION['user_id']]);
                    
                    if (!$result) {
                        $errorInfo = $stmt->errorInfo();
                        throw new PDOException("Failed to clear cart: " . $errorInfo[2]);
                    }
                    
                    error_log("Cart cleared successfully");

                    $conn->commit();
                    error_log("Transaction committed successfully");

                    // Generate order items HTML for email
                    $order_items_html = '';
                    foreach ($cart_items as $item) {
                        $order_items_html .= "
                            <tr style='border-bottom: 1px solid #eee;'>
                                <td style='padding: 12px;'>" . htmlspecialchars($item['item']) . "</td>
                                <td style='padding: 12px; text-align: center;'>" . htmlspecialchars($item['quantity']) . "</td>
                                <td style='padding: 12px; text-align: right;'>R" . number_format($item['price'] * $item['quantity'], 2) . "</td>
                            </tr>";
                    }

                    // Payment instructions
                    $payment_instructions = "
                        <div style='background: #f8f9fa; padding: 20px; border-radius: 12px; margin: 20px 0;'>
                            <h3 style='color: #8B5CF6; margin-bottom: 15px;'>Payment Instructions</h3>
                            <p style='margin-bottom: 10px;'>Please make payment to the following bank account to complete your order:</p>
                            <table style='width: 100%;'>
                                <tr>
                                    <td style='padding: 5px 0;'><strong>Bank:</strong></td>
                                    <td style='padding: 5px 0;'>Capitec</td>
                                </tr>
                                <tr>
                                    <td style='padding: 5px 0;'><strong>Account Number:</strong></td>
                                    <td style='padding: 5px 0;'>1450812013</td>
                                </tr>
                                <tr>
                                    <td style='padding: 5px 0;'><strong>Account Holder:</strong></td>
                                    <td style='padding: 5px 0;'>Sesy Queen</td>
                                </tr>
                                <tr>
                                    <td style='padding: 5px 0;'><strong>Reference:</strong></td>
                                    <td style='padding: 5px 0;'>Please use your full name (" . htmlspecialchars($customer_name) . ")</td>
                                </tr>
                            </table>
                            <p style='margin-top: 15px; color: #10B981;'><strong>Note:</strong> Once payment is confirmed, we will process and ship your order promptly.</p>
                        </div>";

                    // Send confirmation email to customer
                    $order_id_list = implode(', ', $order_ids);
                    $full_name = htmlspecialchars($user_info['first_name'] ?? $user_info['username']);
                    $subject_customer = "Sesy Queen Order Confirmation - Order #$order_id_list";
                    $htmlContent_customer = "
                        <html>
                        <head>
                            <style>
                                body { font-family: 'Arial', sans-serif; line-height: 1.6; color: #333; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                .header { background: linear-gradient(135deg, #8B5CF6, #EC4899); color: white; padding: 30px; text-align: center; border-radius: 20px 20px 0 0; }
                                .content { background: white; padding: 30px; border-radius: 0 0 20px 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                                .order-summary { background: #f8f9fa; padding: 20px; border-radius: 12px; margin: 20px 0; }
                                table { width: 100%; border-collapse: collapse; }
                                th { background: #8B5CF6; color: white; padding: 12px; text-align: left; }
                                .total-row { font-weight: bold; background: #f0f0f0; }
                                .footer { text-align: center; margin-top: 30px; color: #666; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h1 style='margin: 0;'>Thank You for Your Order!</h1>
                                </div>
                                <div class='content'>
                                    <p>Dear $full_name,</p>
                                    <p>Thank you for choosing Sesy Queen! Your order has been received and is being processed.</p>
                                    
                                    <div class='order-summary'>
                                        <h3 style='color: #8B5CF6; margin-bottom: 15px;'>Order Summary #$order_id_list</h3>
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th style='text-align: center;'>Qty</th>
                                                    <th style='text-align: right;'>Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                $order_items_html
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan='2' style='padding: 12px; text-align: right;'><strong>Subtotal:</strong></td>
                                                    <td style='padding: 12px; text-align: right;'>R" . number_format($total, 2) . "</td>
                                                </tr>
                                                <tr>
                                                    <td colspan='2' style='padding: 12px; text-align: right;'><strong>Discount:</strong></td>
                                                    <td style='padding: 12px; text-align: right; color: #10B981;'>-R" . number_format($discount, 2) . "</td>
                                                </tr>
                                                <tr>
                                                    <td colspan='2' style='padding: 12px; text-align: right;'><strong>Delivery (" . ucfirst($delivery_method) . "):</strong></td>
                                                    <td style='padding: 12px; text-align: right;'>R" . number_format($delivery_fee, 2) . "</td>
                                                </tr>
                                                <tr class='total-row'>
                                                    <td colspan='2' style='padding: 12px; text-align: right;'><strong>Total:</strong></td>
                                                    <td style='padding: 12px; text-align: right; color: #8B5CF6; font-size: 1.2em;'>R" . number_format($total - $discount + $delivery_fee, 2) . "</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        
                                        <div style='margin-top: 20px;'>
                                            <p><strong>Delivery Address:</strong><br>" . htmlspecialchars($address) . "</p>
                                            <p><strong>Contact Number:</strong><br>" . htmlspecialchars($contact) . "</p>
                                        </div>
                                    </div>
                                    
                                    $payment_instructions
                                    
                                    <p>We'll notify you once your order ships!</p>
                                    <p>Best regards,<br><strong>Sesy Queen Team</strong></p>
                                </div>
                                <div class='footer'>
                                    <p>© " . date('Y') . " Sesy Queen. All rights reserved.</p>
                                </div>
                            </div>
                        </body>
                        </html>";

                    // Send confirmation email to admin
                    $subject_admin = "New Order Notification - Order #$order_id_list";
                    $htmlContent_admin = "
                        <html>
                        <head>
                            <style>
                                body { font-family: 'Arial', sans-serif; line-height: 1.6; color: #333; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                .header { background: linear-gradient(135deg, #8B5CF6, #EC4899); color: white; padding: 30px; text-align: center; border-radius: 20px 20px 0 0; }
                                .content { background: white; padding: 30px; border-radius: 0 0 20px 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
                                table { width: 100%; border-collapse: collapse; }
                                th { background: #8B5CF6; color: white; padding: 12px; text-align: left; }
                                .info-box { background: #f8f9fa; padding: 20px; border-radius: 12px; margin: 20px 0; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h1 style='margin: 0;'>New Order Received</h1>
                                </div>
                                <div class='content'>
                                    <p>A new order (Order #$order_id_list) has been placed on Sesy Queen.</p>
                                    
                                    <div class='info-box'>
                                        <h3 style='color: #8B5CF6; margin-bottom: 15px;'>Customer Information</h3>
                                        <p><strong>Name:</strong> $full_name</p>
                                        <p><strong>Email:</strong> " . htmlspecialchars($user_info['email']) . "</p>
                                        <p><strong>Contact:</strong> " . htmlspecialchars($contact) . "</p>
                                        <p><strong>Delivery Address:</strong> " . htmlspecialchars($address) . "</p>
                                        <p><strong>Delivery Method:</strong> " . ucfirst($delivery_method) . "</p>
                                    </div>
                                    
                                    <h3 style='color: #8B5CF6; margin: 20px 0 10px;'>Order Details</h3>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th style='text-align: center;'>Qty</th>
                                                <th style='text-align: right;'>Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            $order_items_html
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan='2' style='padding: 12px; text-align: right;'><strong>Subtotal:</strong></td>
                                                <td style='padding: 12px; text-align: right;'>R" . number_format($total, 2) . "</td>
                                            </tr>
                                            <tr>
                                                <td colspan='2' style='padding: 12px; text-align: right;'><strong>Discount:</strong></td>
                                                <td style='padding: 12px; text-align: right; color: #10B981;'>-R" . number_format($discount, 2) . "</td>
                                            </tr>
                                            <tr>
                                                <td colspan='2' style='padding: 12px; text-align: right;'><strong>Delivery Fee:</strong></td>
                                                <td style='padding: 12px; text-align: right;'>R" . number_format($delivery_fee, 2) . "</td>
                                            </tr>
                                            <tr>
                                                <td colspan='2' style='padding: 12px; text-align: right;'><strong>Total:</strong></td>
                                                <td style='padding: 12px; text-align: right; color: #8B5CF6; font-size: 1.2em;'>R" . number_format($total - $discount + $delivery_fee, 2) . "</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    
                                    <p style='margin-top: 20px;'>Please process this order upon payment confirmation.</p>
                                </div>
                            </div>
                        </body>
                        </html>";

                    $customer_email_sent = sendBrevoEmail($user_info['email'], $customer_name, $subject_customer, $htmlContent_customer);
                    $admin_email_sent = sendBrevoEmail(ADMIN_EMAIL, 'Admin', $subject_admin, $htmlContent_admin);

                    if ($customer_email_sent && $admin_email_sent) {
                        $message = "<div class='toast show success'><div class='toast-icon'><i class='bi bi-check-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Success</div><div class='toast-message'>Order placed successfully! Check your email for payment instructions.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    } elseif ($customer_email_sent && !$admin_email_sent) {
                        $message = "<div class='toast show warning'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Warning</div><div class='toast-message'>Order placed! Check your email, but admin notification failed.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    } elseif (!$customer_email_sent && $admin_email_sent) {
                        $message = "<div class='toast show warning'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Warning</div><div class='toast-message'>Order placed! Admin notified, but your confirmation email failed.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    } else {
                        $message = "<div class='toast show warning'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Warning</div><div class='toast-message'>Order placed, but failed to send confirmation emails.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
                    }

                    $order_success = true;
                    $cart_items = [];
                    $total = 0;

                } catch (PDOException $e) {
                    $conn->rollBack();
                    $error_message = $e->getMessage();
                    error_log("=== DATABASE ERROR ===");
                    error_log("Error message: " . $error_message);
                    error_log("Error code: " . $e->getCode());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    
                    // Check for specific error conditions
                    if (strpos($error_message, 'Insufficient stock') !== false) {
                        $errors[] = "Sorry, some items in your cart are no longer available in the requested quantity. Please review your cart and try again.";
                    } elseif (strpos($error_message, 'Foreign key') !== false) {
                        $errors[] = "Database constraint error. Please contact support.";
                        error_log("Foreign key error detected");
                    } elseif (strpos($error_message, 'Duplicate entry') !== false) {
                        $errors[] = "Duplicate order detected. Please try again.";
                        error_log("Duplicate entry error detected");
                    } elseif (strpos($error_message, 'Connection') !== false) {
                        $errors[] = "Database connection error. Please try again later.";
                        error_log("Connection error detected");
                    } else {
                        $errors[] = "Failed to process order. Please try again or contact support.";
                    }
                    
                    // Log additional debug info
                    error_log("User ID: " . $_SESSION['user_id']);
                    error_log("Cart items count: " . count($cart_items));
                    error_log("POST data: " . print_r($_POST, true));
                } catch (Exception $e) {
                    $conn->rollBack();
                    error_log("=== GENERAL EXCEPTION ===");
                    error_log("Error: " . $e->getMessage());
                    error_log("Trace: " . $e->getTraceAsString());
                    $errors[] = "An unexpected error occurred. Please try again.";
                }
            }

            if (!empty($errors)) {
                $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-x-circle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>" . implode('<br>', $errors) . "</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
            }
        }

        // Get cart count for navbar
        $cart_count = count($cart_items);

        // Get wishlist count
        $stmt = $conn->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $wishlist_count = (int)$stmt->fetchColumn();

    } catch (PDOException $e) {
        error_log("=== INITIAL QUERY FAILED ===");
        error_log("Error: " . $e->getMessage());
        error_log("Trace: " . $e->getTraceAsString());
        $message = "<div class='toast show error'><div class='toast-icon'><i class='bi bi-exclamation-triangle-fill'></i></div><div class='toast-content'><div class='toast-title'>Error</div><div class='toast-message'>Error loading data. Please try again.</div></div><button class='toast-close' onclick='closeToast(this)'><i class='bi bi-x'></i></button></div>";
    }
} else {
    header("Location: login_user.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Checkout - Sesy Queen Premium Kitchenware">
    <meta name="theme-color" content="#8B5CF6">
    <title>Checkout - Sesy Queen</title>
    
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
            --warning: #F59E0B;
            --danger: #EF4444;
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

        /* Main Container */
        .main-container {
            position: relative;
            z-index: 10;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Checkout Card */
        .checkout-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: var(--glass-border);
            border-radius: 40px;
            padding: 3rem;
            box-shadow: var(--shadow-2xl);
            position: relative;
            overflow: hidden;
        }

        .checkout-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-2);
        }

        .checkout-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 300px;
            height: 300px;
            background: var(--gradient-2);
            opacity: 0.03;
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

        /* Checkout Grid */
        .checkout-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
        }

        /* Order Summary */
        .order-summary {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 30px;
            padding: 2rem;
            border: var(--glass-border);
        }

        .summary-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 2rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .product-list {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 2rem;
        }

        .product-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
            transition: all 0.3s ease;
        }

        .product-item:hover {
            background: rgba(139, 92, 246, 0.05);
        }

        .product-item:last-child {
            border-bottom: none;
        }

        .product-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
        }

        .product-details {
            flex: 1;
        }

        .product-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .product-meta {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.25rem;
        }

        .product-price {
            font-weight: 700;
            color: var(--primary);
        }

        .stock-status {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            display: inline-block;
        }

        .stock-available {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent);
        }

        .stock-low {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        /* Summary Totals */
        .summary-totals {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
        }

        .total-row:last-child {
            border-bottom: none;
        }

        .total-row.grand-total {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            padding-top: 1rem;
        }

        .weight-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(139, 92, 246, 0.1);
            border-radius: 50px;
            font-size: 0.9rem;
            margin-top: 1rem;
        }

        /* Payment Instructions */
        .payment-instructions {
            background: rgba(16, 185, 129, 0.1);
            border-radius: 20px;
            padding: 1.5rem;
            margin-top: 2rem;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .payment-instructions h5 {
            color: var(--accent);
            margin-bottom: 1rem;
        }

        .bank-details {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .bank-details p {
            margin-bottom: 0.5rem;
        }

        /* Checkout Form */
        .checkout-form {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 30px;
            padding: 2rem;
            border: var(--glass-border);
        }

        .form-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 2rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-select {
            width: 100%;
            padding: 1rem 1.5rem;
            border: 2px solid transparent;
            border-radius: 16px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.1);
            color: var(--dark);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.15);
        }

        /* Promo Code Input */
        .promo-group {
            display: flex;
            gap: 1rem;
        }

        .promo-input {
            flex: 1;
        }

        .promo-btn {
            padding: 1rem 2rem;
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
            border-radius: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .promo-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Place Order Button */
        .place-order-btn {
            width: 100%;
            padding: 1.2rem;
            background: var(--gradient-3);
            color: white;
            border: none;
            border-radius: 16px;
            font-weight: 700;
            font-size: 1.2rem;
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

        .place-order-btn::before {
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

        .place-order-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .place-order-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
        }

        /* Success State */
        .success-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .success-icon {
            font-size: 5rem;
            color: var(--accent);
            margin-bottom: 2rem;
        }

        .success-state h4 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .success-state p {
            color: var(--gray);
            margin-bottom: 2rem;
        }

        .btn-continue {
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

        .btn-continue:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4);
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
            border-left: 4px solid var(--danger);
        }

        .toast.success {
            border-left: 4px solid var(--accent);
        }

        .toast.warning {
            border-left: 4px solid var(--warning);
        }

        .toast-icon {
            font-size: 1.5rem;
        }

        .toast.error .toast-icon {
            color: var(--danger);
        }

        .toast.success .toast-icon {
            color: var(--accent);
        }

        .toast.warning .toast-icon {
            color: var(--warning);
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

        /* Loading Spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
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
            border-radius: 40px 40px 0 0;
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

            .checkout-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
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

            .checkout-card {
                padding: 2rem;
            }

            .order-summary,
            .checkout-form {
                padding: 1.5rem;
            }

            .promo-group {
                flex-direction: column;
            }

            .promo-btn {
                width: 100%;
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
            .product-item {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .product-image {
                width: 120px;
                height: 120px;
            }

            .footer-info {
                flex-direction: column;
                align-items: center;
            }
        }

        /* Dark Mode Support */
        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            color: var(--dark);
        }

        [data-theme="dark"] .bank-details {
            background: rgba(255, 255, 255, 0.05);
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
                        <span class="username-highlight"><?php echo htmlspecialchars($user_info['username'] ?? ''); ?></span>
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
                            <span class="dropdown-item">Hello, <?php echo htmlspecialchars($user_info['username'] ?? ''); ?></span>
                            <a href="profile.php" class="dropdown-item">Profile</a>
                            <a href="order_tracking.php" class="dropdown-item">Track Orders</a>
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
            <a href="order_tracking.php" class="nav-link">Track Orders</a>
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

    <!-- Main Container -->
    <div class="main-container" data-aos="fade-up">
        <div class="checkout-card">
            <div class="section-header">
                <div class="section-subtitle">Complete Your Purchase</div>
                <h1 class="section-title">Secure <span>Checkout</span></h1>
            </div>

            <?php if ($order_success): ?>
                <!-- Success State -->
                <div class="success-state">
                    <div class="success-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h4 class="gradient-text">Thank You for Your Order!</h4>
                    <p>A confirmation has been sent to <strong><?php echo htmlspecialchars($user_info['email']); ?></strong><br>with payment instructions and order details.</p>
                    <a href="index.php" class="btn-continue">
                        <i class="bi bi-arrow-left"></i>
                        Continue Shopping
                    </a>
                </div>
            <?php elseif (empty($cart_items)): ?>
                <!-- Empty Cart -->
                <div class="success-state">
                    <div class="success-icon">
                        <i class="bi bi-cart-x"></i>
                    </div>
                    <h4>Your cart is empty</h4>
                    <p>Looks like you haven't added any items to your cart yet.</p>
                    <a href="index.php#products" class="btn-continue">
                        <i class="bi bi-shop"></i>
                        Start Shopping
                    </a>
                </div>
            <?php else: ?>
                <!-- Checkout Grid -->
                <div class="checkout-grid">
                    <!-- Order Summary -->
                    <div class="order-summary">
                        <h3 class="summary-title">
                            <i class="bi bi-cart-check"></i>
                            Order Summary
                        </h3>

                        <div class="product-list">
                            <?php foreach ($cart_items as $item): ?>
                                <?php
                                    $stock_status = $item['stock'] > 10 ? 'stock-available' : ($item['stock'] > 0 ? 'stock-low' : '');
                                    $stock_text = $item['stock'] > 10 ? 'In Stock' : ($item['stock'] > 0 ? 'Low Stock (' . $item['stock'] . ' left)' : 'Out of Stock');
                                ?>
                                <div class="product-item">
                                    <img src="images/<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['item']); ?>" class="product-image" onerror="this.src='images/default.jpg';">
                                    <div class="product-details">
                                        <div class="product-name"><?php echo htmlspecialchars($item['item']); ?></div>
                                        <div class="product-meta">
                                            Quantity: <?php echo $item['quantity']; ?> × R<?php echo number_format($item['price'], 2); ?>
                                        </div>
                                        <div class="product-price">R<?php echo number_format($item['price'] * $item['quantity'], 2); ?></div>
                                        <span class="stock-status <?php echo $stock_status; ?>">
                                            <i class="bi bi-<?php echo $item['stock'] > 0 ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                                            <?php echo $stock_text; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="summary-totals">
                            <div class="total-row">
                                <span>Subtotal</span>
                                <span>R<?php echo number_format($total, 2); ?></span>
                            </div>
                            <?php if ($discount > 0): ?>
                            <div class="total-row">
                                <span>Discount (10%)</span>
                                <span class="text-success">-R<?php echo number_format($discount, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="total-row">
                                <span>Delivery Fee</span>
                                <span id="delivery-fee-display">R<?php echo number_format($delivery_fee, 2); ?></span>
                            </div>
                            <div class="total-row grand-total">
                                <span>Total</span>
                                <span id="total-display">R<?php echo number_format($total - $discount + $delivery_fee, 2); ?></span>
                            </div>
                        </div>

                        <div class="weight-badge">
                            <i class="bi bi-box-seam"></i>
                            <span>Estimated Weight: <?php echo number_format($total_weight, 2); ?> kg</span>
                        </div>

                        <!-- Payment Instructions -->
                        <div class="payment-instructions">
                            <h5>
                                <i class="bi bi-bank"></i>
                                Payment Instructions
                            </h5>
                            <p>Please make payment to:</p>
                            <div class="bank-details">
                                <p><strong>Bank:</strong> Capitec</p>
                                <p><strong>Account:</strong> 1450812013</p>
                                <p><strong>Holder:</strong> Sesy Queen</p>
                                <p><strong>Reference:</strong> Your full name</p>
                            </div>
                            <p class="mb-0"><small>Orders are processed upon payment confirmation.</small></p>
                        </div>
                    </div>

                    <!-- Checkout Form -->
                    <div class="checkout-form">
                        <h3 class="form-title">
                            <i class="bi bi-truck"></i>
                            Delivery Details
                        </h3>

                        <form method="post" id="checkout-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="form-group">
                                <label for="customer_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="customer_name" name="customer_name" 
                                       value="<?php echo htmlspecialchars($user_info['first_name'] ?? $user_info['username'] ?? ''); ?>" 
                                       placeholder="Enter your full name" required>
                            </div>

                            <div class="form-group">
                                <label for="contact" class="form-label">Contact Number</label>
                                <input type="tel" class="form-control" id="contact" name="contact" 
                                       placeholder="e.g., 0794416767" pattern="\d{10}" required>
                                <small class="form-text text-muted">10-digit mobile number</small>
                            </div>

                            <div class="form-group">
                                <label for="address" class="form-label">Delivery Address</label>
                                <textarea class="form-control" id="address" name="address" rows="4" 
                                          placeholder="Enter your full delivery address" required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="delivery_method" class="form-label">Delivery Method</label>
                                <select class="form-select" id="delivery_method" name="delivery_method" required>
                                    <option value="" disabled <?php echo !$delivery_method ? 'selected' : ''; ?>>Select delivery method</option>
                                    <option value="pep" <?php echo $delivery_method === 'pep' ? 'selected' : ''; ?>>PEP Delivery (R140)</option>
                                    <option value="pudo" <?php echo $delivery_method === 'pudo' ? 'selected' : ''; ?>>Pudo (R140)</option>
                                    <option value="home" <?php echo $delivery_method === 'home' ? 'selected' : ''; ?>>Home Delivery (<?php echo $total_weight > 20 ? 'R260' : 'R180'; ?>)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="promo_code" class="form-label">Promo Code</label>
                                <div class="promo-group">
                                    <input type="text" class="form-control promo-input" id="promo_code" name="promo_code" 
                                           value="<?php echo htmlspecialchars($promo_code); ?>" placeholder="Enter promo code">
                                    <button type="submit" class="promo-btn">Apply</button>
                                </div>
                            </div>

                            <button type="submit" class="place-order-btn" id="place-order-btn">
                                <span>Place Order</span>
                                <i class="bi bi-lock-fill"></i>
                                <span class="spinner" id="order-spinner"></span>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
                    <span>Nationwide Delivery: R140-R260</span>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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

        // Manual close button handler
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toast-close')) {
                const button = e.target.closest('.toast-close');
                closeToast(button);
            }
        });

        // Dynamic delivery fee update
        const deliveryMethodSelect = document.getElementById('delivery_method');
        const deliveryFeeDisplay = document.getElementById('delivery-fee-display');
        const totalDisplay = document.getElementById('total-display');
        const subtotal = <?php echo $total; ?>;
        const discount = <?php echo $discount; ?>;
        const totalWeight = <?php echo $total_weight; ?>;

        if (deliveryMethodSelect) {
            deliveryMethodSelect.addEventListener('change', function() {
                const method = this.value;
                let deliveryFee = 0;
                
                if (method === 'pep' || method === 'pudo') {
                    deliveryFee = 140.00;
                } else if (method === 'home') {
                    deliveryFee = totalWeight > 20 ? 260.00 : 180.00;
                }
                
                deliveryFeeDisplay.textContent = 'R' + deliveryFee.toFixed(2);
                const newTotal = subtotal - discount + deliveryFee;
                totalDisplay.textContent = 'R' + newTotal.toFixed(2);
            });
        }

        // Spinner for order submission
        const checkoutForm = document.getElementById('checkout-form');
        const placeOrderBtn = document.getElementById('place-order-btn');
        const orderSpinner = document.getElementById('order-spinner');

        if (checkoutForm) {
            checkoutForm.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                } else {
                    placeOrderBtn.disabled = true;
                    orderSpinner.style.display = 'inline-block';
                }
            });
        }

        // Phone number validation
        const contactInput = document.getElementById('contact');
        if (contactInput) {
            contactInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 10) {
                    this.value = this.value.slice(0, 10);
                }
            });
        }
    </script>
</body>
</html>