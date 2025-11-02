<?php
session_start();
require_once 'auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] == 'A') {
    header('Location: login.php');
    exit;
}

$message = "";
$error = "";

// Handle subscription purchase with payment integration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['subscribe_plan'])) {
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        header("Location: login.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $user_type = $_SESSION['user_type'];
    $plan_id = intval($_POST['plan_id']);

    try {
        // Check if user already has an ACTIVE subscription
        $active_query = "SELECT * FROM user_subscriptions WHERE user_id = $user_id AND Status = 'A' AND (end_date IS NULL OR end_date >= CURDATE())";
        $active_result = $conn->query($active_query);

        if ($active_result && $active_result->num_rows > 0) {
            $error = "You already have an active subscription. You cannot subscribe to another plan while your current subscription is active.";
        } else {
            // Check if user already has a PENDING subscription/payment
            $existing_query = "SELECT * FROM user_subscriptions WHERE user_id = $user_id AND Status = 'P'";
            $existing_result = $conn->query($existing_query);

            if ($existing_result && $existing_result->num_rows > 0) {
                $error = "You already have a pending subscription request. Please wait for admin approval.";
            } else {
                // Get plan details
                $plan_query = "SELECT * FROM subscription_plans WHERE plan_id = $plan_id AND user_type = '$user_type'";
                $plan_result = $conn->query($plan_query);
                
                if (!$plan_result || $plan_result->num_rows == 0) {
                    $error = "Invalid subscription plan selected.";
                } else {
                    $plan = $plan_result->fetch_assoc();
                    
                    // Create transaction reference for payment
                    $transaction_ref = 'TXN' . time() . rand(1000, 9999);
                    
                    // Store payment details in session for payment page - WITHOUT creating DB records yet
                    $_SESSION['pending_payment_request'] = [
                        'plan_id' => $plan_id,
                        'plan_name' => $plan['Plan_name'],
                        'amount' => $plan['price'],
                        'transaction_id' => $transaction_ref,
                        'user_id' => $user_id,
                        'user_type' => $user_type
                    ];
                    
                    // Redirect to payment page
                    header("Location: payment.php");
                    exit();
                }
            }
        }
    } catch (Exception $e) {
        $error = "System error: " . $e->getMessage();
    }
}

// Handle successful payment confirmation (called from payment page)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_payment'])) {
    if (!isset($_SESSION['pending_payment_request'])) {
        $error = "No pending payment request found.";
    } else {
        $payment_data = $_SESSION['pending_payment_request'];
        
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Create subscription record
            $subscription_query = "INSERT INTO user_subscriptions (user_id, plan_id, start_date, end_date, Status) 
                                  VALUES ({$payment_data['user_id']}, {$payment_data['plan_id']}, NULL, NULL, 'P')";
            
            if ($conn->query($subscription_query)) {
                $subscription_id = $conn->insert_id;
                
                // Create payment record
                $payment_query = "INSERT INTO payments (Subscription_id, Amount, transaction_id, Status) 
                                 VALUES ($subscription_id, {$payment_data['amount']}, '{$payment_data['transaction_id']}', 'P')";
                
                if ($conn->query($payment_query)) {
                    $payment_id = $conn->insert_id;
                    
                    // Commit transaction
                    $conn->commit();
                    
                    // Update session with confirmed payment details
                    $_SESSION['pending_payment'] = [
                        'payment_id' => $payment_id,
                        'subscription_id' => $subscription_id,
                        'plan_id' => $payment_data['plan_id'],
                        'plan_name' => $payment_data['plan_name'],
                        'amount' => $payment_data['amount'],
                        'transaction_id' => $payment_data['transaction_id']
                    ];
                    
                    // Clear the pending request
                    unset($_SESSION['pending_payment_request']);
                    
                    // Redirect back to subscription page with success
                    header("Location: subscription.php");
                    exit();
                } else {
                    throw new Exception("Failed to create payment record");
                }
            } else {
                throw new Exception("Failed to create subscription record");
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = "Payment confirmation failed: " . $e->getMessage();
            
            // Clear the pending request on error
            unset($_SESSION['pending_payment_request']);
        }
    }
}

// Display approval message
if (isset($_SESSION['payment_pending_approval'])) {
    $approval_message = $_SESSION['payment_pending_approval'];
    unset($_SESSION['payment_pending_approval']);
}

// Check if user has active subscription
$current_subscription = null;
$has_active_subscription = false;
$service_name = "Agricultural Equipment";

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        $sub_query = "
            SELECT us.*, sp.Plan_name, sp.price, sp.Plan_type
            FROM user_subscriptions us 
            JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
            WHERE us.user_id = {$_SESSION['user_id']} AND us.Status = 'A' AND (us.end_date IS NULL OR us.end_date >= CURDATE())
            ORDER BY us.end_date DESC LIMIT 1
        ";
        $sub_result = $conn->query($sub_query);

        if ($sub_result && $sub_result->num_rows > 0) {
            $current_subscription = $sub_result->fetch_assoc();
            $has_active_subscription = true;
            $service_name = $_SESSION['user_type'] == 'O' ? 'Equipment Listings' : 'Farm Product Listings';
        }
    } catch (Exception $e) {
        // Handle error silently
    }
}

// Check if user has pending subscription or payment
$pending_subscription = null;
$pending_payment = null;

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        // Check pending payment with subscription details
        $payment_query = "
            SELECT p.*, us.subscription_id, sp.Plan_name, sp.price, sp.Plan_type, sp.user_type,
                   u.Name as user_name, u.Email as user_email, u.Phone
            FROM payments p
            JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id
            JOIN subscription_plans sp ON us.plan_id = sp.plan_id
            JOIN users u ON us.user_id = u.user_id
            WHERE us.user_id = {$_SESSION['user_id']} AND p.Status = 'P' AND us.Status = 'P'
            ORDER BY p.Payment_id DESC LIMIT 1
        ";
        $payment_result = $conn->query($payment_query);
        
        if ($payment_result && $payment_result->num_rows > 0) {
            $pending_payment = $payment_result->fetch_assoc();
        } else {
            // Check pending subscription without payment
            $pending_query = "
                SELECT us.*, sp.Plan_name, sp.price 
                FROM user_subscriptions us 
                JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
                WHERE us.user_id = {$_SESSION['user_id']} AND us.Status = 'P'
                ORDER BY us.subscription_id DESC LIMIT 1
            ";
            $pending_result = $conn->query($pending_query);
            
            if ($pending_result && $pending_result->num_rows > 0) {
                $pending_subscription = $pending_result->fetch_assoc();
            }
        }
    } catch (Exception $e) {
        // Handle silently
    }
}

// Get available plans
$plans = [];
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    $user_type = $_SESSION['user_type'];
    try {
        $plans_query = "SELECT * FROM subscription_plans WHERE user_type = '$user_type' ORDER BY price ASC";
        $plans_result = $conn->query($plans_query);

        if ($plans_result) {
            while ($plan = $plans_result->fetch_assoc()) {
                $plans[] = $plan;
            }
        }
    } catch (Exception $e) {
        // Handle error silently
    }
}
?>

<?php
$adminWhatsAppNumber = '';
$query = "SELECT Phone FROM users WHERE User_type = 'A' LIMIT 1";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $adminWhatsAppNumber = $row['Phone'];
}
?>


<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Subscription Plans - Agricultural Equipment Rental</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />

        <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --primary: #234a23;
        --accent: #28a745;
        --warning: #ffc107;
        --error: #dc3545;
        --success-bg: linear-gradient(135deg, #d4edda, #c3e6cb);
        --error-bg: linear-gradient(135deg, #f8d7da, #f5c6cb);
        --pending-bg: linear-gradient(135deg, #fff3cd, #ffeaa7);
        --text-dark: #2c3e50;
        --text-light: #666;
        --white: #fff;
    }

    .container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }

    .alert {
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.95em;
    }

    .alert.success {
        background: var(--success-bg);
        color: #155724;
        
    }

    .alert.error {
        background: var(--error-bg);
        color: #721c24;
        
    }

    .current-subscription {
        background: linear-gradient(135deg, var(--primary), #234a23);
        color: var(--white);
        padding: 25px;
        border-radius: 15px;
        margin-bottom: 30px;
        text-align: center;
        box-shadow: 0 12px 25px rgba(40, 167, 69, 0.3);
    }

    .current-subscription h2 {
        font-size: 1.8em;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    .subscription-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }

    .detail-item {
        background: rgba(255, 255, 255, 0.15);
        padding: 15px;
        border-radius: 12px;
        backdrop-filter: blur(8px);
    }

    .detail-item strong {
        display: block;
        font-size: 1em;
        opacity: 0.9;
        margin-bottom: 5px;
    }

    .detail-item .value {
        font-size: 1.1em;
        font-weight: 600;
    }

    .plans-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        padding: 30px;
        margin-bottom: 25px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
    }

    .plans-section h2 {
        font-size: 2em;
        color: var(--text-dark);
        margin-bottom: 10px;
        text-align: center;
    }

    .plans-section > p {
        text-align: center;
        color: var(--text-light);
        font-size: 1em;
        margin-bottom: 25px;
    }

    .plans-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .plan-card {
        background: var(--white);
        border: 2px solid transparent;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        transition: all 0.3s ease;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
        position: relative;
        overflow: hidden;
        min-height: 600px;
        display: flex;
        flex-direction: column;
    }

    .plan-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--primary);
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .plan-card:hover {
        border-color: var(--primary);
        transform: translateY(-6px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
    }

    .plan-card:hover::before {
        transform: scaleX(1);
    }

    .plan-card h3 {
        font-size: 1.4em;
        color: var(--text-dark);
        margin-bottom: 12px;
        font-weight: 600;
    }

    .plan-price {
        font-size: 2.5em;
        font-weight: 700;
        background: var(--primary);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin: 15px 0;
        line-height: 1;
    }

    .plan-features {
        list-style: none;
        margin: 20px 0;
        text-align: left;
        flex-grow: 1;
    }

    .plan-features li {
        padding: 6px 0;
        color: #555;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9em;
        line-height: 1.4;
    }

    .plan-features li i {
        color: var(--accent);
        font-size: 1em;
        width: 18px;
        flex-shrink: 0;
    }

    .plan-btn {
        background: var(--primary);
        color: var(--white);
        border: none;
        padding: 12px 25px;
        border-radius: 40px;
        font-size: 1em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 100%;
        margin-top: auto;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .plan-btn:hover {
        transform: translateY(-2px);
    }

    .plan-btn:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
    }

    .login-prompt {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        padding: 40px 25px;
        text-align: center;
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.1);
    }

    .login-prompt h2 {
        font-size: 2em;
        color: var(--text-dark);
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    .login-btn {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: var(--white);
        text-decoration: none;
        padding: 15px 30px;
        border-radius: 40px;
        display: inline-block;
        margin-top: 20px;
        font-size: 1em;
        font-weight: 600;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .login-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
    }

    .cta-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        padding: 30px;
        text-align: center;
        margin-top: 25px;
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.1);
    }

    .cta-section h3 {
        font-size: 1.6em;
        color: var(--text-dark);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    .pending-notice {
        background: var(--pending-bg);
        color: #856404;
        padding: 18px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        border-left: 4px solid var(--warning);
        font-size: 0.95em;
    }

    .pending-notice h3 {
        margin-bottom: 8px;
        font-size: 1.2em;
    }

    .payment-notice {
        background: var(--success-bg);
        color: #155724;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        font-size: 0.95em;
        box-shadow: 0 6px 15px rgba(0,0,0,0.08);
    }

    .payment-notice h3 {
        margin-bottom: 8px;
        font-size: 1.2em;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .payment-notice h3::before {
        content: "";
        font-size: 1.3em;
    }

    .payment-actions {
        margin-top: 15px;
    }

    .payment-btn {
        background: #234a23;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        text-decoration: none;
        display: inline-block;
        margin: 0 5px;
        font-weight: bold;
        transition: background 0.3s;
        cursor: pointer;
    }

    .payment-btn:hover {
        background: #1c381c;
        color: white;
        text-decoration: none;
    }

    .no-refund-policy {
        background: linear-gradient(135deg, #ffebee, #ffcdd2);
        color: #c62828;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        text-align: center;
        border-left: 4px solid #d32f2f;
        font-size: 1em;
        font-weight: 500;
    }

    .no-refund-policy h3 {
        margin-bottom: 10px;
        font-size: 1.3em;
    }

    /* Modal Styling */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
    }

    .modal-content {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 25px;
        border-radius: 10px;
        max-width: 600px;
        width: 90%;
        max-height: 80%;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
    }

    .modal-header h2 {
        margin: 0;
        color: #333;
        font-size: 18px;
    }

    .close-btn {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #999;
        padding: 0;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .close-btn:hover {
        color: #333;
    }

    .details-form {
        padding: 0;
    }

    .form-row {
        display: flex;
        margin-bottom: 12px;
        align-items: center;
    }

    .form-group {
        display: flex;
        width: 100%;
        align-items: center;
        background: #f8f9fa;
    }

    .form-group label {
        font-weight: 600;
        color: #333;
        min-width: 140px;
        margin: 0;
        padding-right: 15px;
        font-size: 14px;
    }

    .form-value {
        flex: 1;
        color: #555;
        font-size: 14px;
        padding: 8px 12px;
        border-radius: 4px;
        word-break: break-all;
    }

    .status-active { color: #234a23; font-weight: bold; }
    .status-pending { color: #ffc107; font-weight: bold; }
    .status-expired { color: #dc3545; font-weight: bold; }
    .status-cancelled { color: #6c757d; font-weight: bold; }

    .payment-success { color: #28a745; font-weight: bold; }
    .payment-pending { color: #ffc107; font-weight: bold; }
    .payment-failed { color: #dc3545; font-weight: bold; }

    .modal-footer {
        margin-top: 25px;
        padding-top: 15px;
        border-top: 2px solid #eee;
        text-align: center;
    }

    .modal-close-btn {
        background: #234a23;
        color: white;
        padding: 12px 25px;
        border-radius: 5px;
        border: none;
        font-weight: bold;
        cursor: pointer;
        transition: background 0.3s;
    }

    .modal-close-btn:hover {
        background: #1a3a1a;
    }

    @media (max-width: 768px) {
        .plans-grid {
            grid-template-columns: 1fr;
        }

        .subscription-details {
            grid-template-columns: 1fr;
        }

        .container {
            padding: 15px;
        }

        .form-row {
            flex-direction: column;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .form-group {
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
        
        .form-group label {
            min-width: auto;
            font-weight: bold;
            padding-right: 0;
        }
        
        .form-value {
            width: 100%;
        }

        .modal-content {
            padding: 20px;
            width: 95%;
        }

        .plan-card {
            min-height: auto;
        }
    }

    @media (max-width: 480px) {
        .current-subscription,
        .plans-section,
        .login-prompt,
        .cta-section {
            padding: 20px;
        }

        .plan-price {
            font-size: 2em;
        }

        .modal-content {
            padding: 15px;
        }

        .form-group label {
            min-width: 120px;
        }
    }
</style>

    </head>
    <body>
        <?php include 'includes/header.php' ?> 
        <?php include 'includes/navigation.php' ?> 
        <br>
        <br>
        <div class="container">

            <?php if ($message): ?>
                <div class="alert success">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($approval_message)): ?>
                <div class="alert" style="background: linear-gradient(135deg, #e8f5e8, #d4edda); color: #155724; border-left: 5px solid #28a745;">
                    <?= htmlspecialchars($approval_message) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>

                <?php if ($pending_payment): ?>
                    <!-- Pending Payment Notice -->
                    <div class="payment-notice">
                        <h3>Payment Pending Verification</h3>
                        <p>Your payment for <strong><?= htmlspecialchars($pending_payment['Plan_name']) ?></strong> (₹<?= number_format($pending_payment['Amount'], 2) ?>) is pending admin verification.</p>
                        <p>Transaction ID: <strong><?= htmlspecialchars($pending_payment['transaction_id']) ?></strong></p>
                        <p><strong>Payment will be verified within 2-3 hours</strong></p>
                        
                        <div class="payment-actions">
                            <button onclick="showPaymentDetails(<?= $pending_payment['Payment_id'] ?>, <?= htmlspecialchars(json_encode($pending_payment), ENT_QUOTES) ?>)" 
                                    class="payment-btn">
                                 View Payment Details
                            </button>
                        </div>
                        
                        <p style="margin-top: 15px; font-size: 0.9em; color: #555;">
                             <strong>No cancellation available</strong>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($current_subscription): ?>
                    <!-- Current Active Subscription -->
                    <div class="current-subscription">
                        <h2> You're All Set!</h2>
                        <p>You have unlimited access to list and manage your <?= $service_name ?></p>

                        <div class="subscription-details">
                            <div class="detail-item">
                                <strong>Plan</strong>
                                <div class="value"><?= htmlspecialchars($current_subscription['Plan_name']) ?></div>
                            </div>
                            <div class="detail-item">
                                <strong>Valid Until</strong>
                                <div class="value"><?= date('M j, Y', strtotime($current_subscription['end_date'])) ?></div>
                            </div>
                            <div class="detail-item">
                                <strong>Amount Paid</strong>
                                <div class="value">₹<?= number_format($current_subscription['price'], 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Show available plans for reference only -->
                    <?php if (!empty($plans)): ?>
                        <div class="plans-section">
                            <h2>Available Plans</h2>
                            <p>You currently have an active subscription. These plans are shown for reference only.</p>

                            <div class="plans-grid">
                                <?php foreach ($plans as $plan): ?>
                                    <div class="plan-card">
                                        <h3><?= htmlspecialchars($plan['Plan_name']) ?></h3>
                                        <div class="plan-price">₹<?= number_format($plan['price'], 2) ?></div>

                                        <ul class="plan-features">
                                            <li><i class="fas fa-check"></i> 
                                                Unlimited 
                                                <?php
                                                $planType = htmlspecialchars($plan['Plan_type']);
                                                echo $planType === 'M' ? 'Monthly' : ($planType === 'Y' ? 'Yearly' : 'Unknown');
                                                ?> Listings
                                            </li>
                                            <li><i class="fas fa-check"></i> Professional Dashboard Access</li>
                                            <li><i class="fas fa-check"></i> Free UPI Payments</li>
                                            <li><i class="fas fa-ban"></i> <strong>NO Cancellation/Refund</strong></li>
                                            
                                            <?php if ($plan['user_type'] == 'O'): ?>
                                                <!-- Equipment Owner Features -->
                                                <li><i class="fas fa-check"></i> Equipment Management Tools</li>
                                                <li><i class="fas fa-check"></i> Advanced Booking Calendar</li>
                                                <li><i class="fas fa-check"></i> Revenue Analytics & Reports</li>
                                                <li><i class="fas fa-check"></i> Location-Based Listings</li>
                                                <li><i class="fas fa-check"></i> Hourly & Daily Rates Setup</li>
                                                <li><i class="fas fa-check"></i> Booking Notifications</li>
                                                
                                            <?php elseif ($plan['user_type'] == 'F'): ?>
                                                <!-- Farmer Features -->
                                                <li><i class="fas fa-check"></i> Farm Product Catalog Management</li>
                                                <li><i class="fas fa-check"></i> Sales Analytics & Reports</li>
                                                <li><i class="fas fa-check"></i>Order Management</li>
                                                <li><i class="fas fa-check"></i> Price Management</li>
                                                
                                            <?php endif; ?>
                                        </ul>

                                        <button type="button" class="plan-btn" disabled>Already Subscribed</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>

                    <?php if ($pending_subscription && !$pending_payment): ?>
                        <!-- Pending Subscription Notice -->
                        <div class="pending-notice">
                            <h3>Subscription Pending Approval</h3>
                            <p>Your subscription request for <strong><?= htmlspecialchars($pending_subscription['Plan_name']) ?></strong> (₹<?= number_format($pending_subscription['price'], 2) ?>) is pending admin approval.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Available Plans -->
                    <?php if (!empty($plans)): ?>
                        <div class="plans-section">
                            <h2>Subscription Plans</h2>
                            <?php if ($pending_subscription || $pending_payment): ?>
                                <p>These plans are shown for reference only.</p>
                            <?php else: ?>
                                <p>Select Your Perfect Plan • Secure UPI Payment • <strong>NO REFUND POLICY</strong></p>
                            <?php endif; ?>

                            <div class="plans-grid">
                                <?php foreach ($plans as $plan): ?>
                                    <div class="plan-card">
                                        <h3><?= htmlspecialchars($plan['Plan_name']) ?></h3>
                                        <div class="plan-price">₹<?= number_format($plan['price'], 2) ?></div>

                                        <ul class="plan-features">
                                            <li><i class="fas fa-check"></i> 
                                                Unlimited 
                                                <?php
                                                $planType = htmlspecialchars($plan['Plan_type']);
                                                echo $planType === 'M' ? 'Monthly' : ($planType === 'Y' ? 'Yearly' : 'Unknown');
                                                ?> Listings
                                            </li>
                                            <li><i class="fas fa-check"></i> Professional Dashboard Access</li>
                                            <li><i class="fas fa-check"></i> Free UPI Payments</li>
                                            <li><i class="fas fa-ban"></i> <strong>NO Cancellation/Refund</strong></li>
                                            
                                            <?php if ($plan['user_type'] == 'O'): ?>
                                                <!-- Equipment Owner Specific Features -->
                                                <li><i class="fas fa-check"></i> Equipment Management</li>
                                                <li><i class="fas fa-check"></i> Revenue Analytics & Reports</li>
                                                <li><i class="fas fa-check"></i> Location-Based Listings</li>
                                                <li><i class="fas fa-check"></i> Hourly & Daily Rates Setup</li>
                                                <li><i class="fas fa-check"></i> Customer Rating System</li>
                                                
                                            <?php elseif ($plan['user_type'] == 'F'): ?>
                                                <!-- Farmer Specific Features -->
                                                <li><i class="fas fa-seedling"></i> Farm Product Management</li>
                                                <li><i class="fas fa-chart-bar"></i> Sales Analytics & Reports</li>
                                                <li><i class="fas fa-shopping-cart"></i> Order Management</li>
                                                <li><i class="fas fa-tags"></i> Price Management</li>
                                                
                                            <?php endif; ?>
                                        </ul>

                                        <?php if ($pending_subscription || $pending_payment): ?>
                                            <button type="button" class="plan-btn" disabled>
                                                <?= $pending_payment ? 'Payment Pending' : 'Subscription Pending' ?>
                                            </button>
                                        <?php else: ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="plan_id" value="<?= $plan['plan_id'] ?>">
                                                <button type="submit" name="subscribe_plan" class="plan-btn" 
                                                        onclick="return confirm('Do you want to proceed?');">
                                                     Pay Now
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="cta-section">
                            <h3>100% Secure Payment • NO REFUND POLICY</h3>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            <?php else: ?>
                <!-- Login Prompt -->
                <div class="login-prompt">
                    <h2>Please Login to View Subscription Plans</h2>
                    <p style="font-size: 1.2em; color: #666; margin-top: 15px;">Access our comprehensive agricultural equipment rental and product sales platform</p>
                    <a href="login.php" class="login-btn">Login to Continue</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment Details Modal -->
        <div id="paymentModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modalTitle">Payment Details</h2>
                    <button onclick="closePaymentModal()" class="close-btn">&times;</button>
                </div>
                
                <div id="modalContent" class="details-form">
                    <!-- Content will be populated by JavaScript -->
                </div>
                
                <div class="modal-footer">
                    <button onclick="closePaymentModal()" class="modal-close-btn">Close</button>
                </div>
            </div>
        </div>

        <br><br>
        <?php include 'includes/footer.php' ?> 
        
        <script>
            // Prevent back button resubmission
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            function showPaymentDetails(paymentId, data) {
                document.getElementById('modalTitle').innerHTML = 'Payment Details';
                
                // Format dates
                var paymentDate = data.payment_date ? new Date(data.payment_date).toLocaleDateString() + ' ' + new Date(data.payment_date).toLocaleTimeString() : 'Not Available';
                
                // Determine user type
                var userType = '';
                switch(data.user_type) {
                    case 'O': userType = 'Equipment Owner'; break;
                    case 'F': userType = 'Farmer'; break;
                    case 'A': userType = 'Admin'; break;
                    default: userType = 'Unknown';
                }
                
                // Determine payment status
                var paymentStatusClass = '';
                var paymentStatusText = '';
                var status = data.Status.toLowerCase();
                if (status === 'success' || status === 'completed' || status === 'paid') {
                    paymentStatusClass = 'payment-success';
                    paymentStatusText = 'Success';
                } else if (status === 'pending' || status === 'p') {
                    paymentStatusClass = 'payment-pending';
                    paymentStatusText = 'Pending';
                } else if (status === 'failed' || status === 'cancelled') {
                    paymentStatusClass = 'payment-failed';
                    paymentStatusText = 'Failed';
                } else {
                    paymentStatusClass = '';
                    paymentStatusText = data.Status;
                }
                
                // Build compact form-style content like in the image
                var content = `
                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment ID:</label>
                            <span class="form-value">${paymentId}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>User Name:</label>
                            <span class="form-value">${data.user_name || 'Unknown User'}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>User Email:</label>
                            <span class="form-value">${data.user_email || 'No Email'}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number:</label>
                            <span class="form-value">${data.Phone || 'No Phone Number'}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>User Type:</label>
                            <span class="form-value">${userType}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Plan Name:</label>
                            <span class="form-value">${data.Plan_name || 'Unknown Plan'}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Plan Type:</label>
                            <span class="form-value">${data.Plan_type === 'M' ? 'Monthly' : (data.Plan_type === 'Y' ? 'Yearly' : 'Unknown Type')}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Amount:</label>
                            <span class="form-value">Rs. ${parseFloat(data.Amount || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Date:</label>
                            <span class="form-value">${paymentDate}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Transaction ID:</label>
                            <span class="form-value">${data.transaction_id || 'Not Available'}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>UPI Transaction ID:</label>
                            <span class="form-value">${data.UPI_transaction_id || 'Not Available'}</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Status:</label>
                            <span class="form-value"><span class="${paymentStatusClass}">${paymentStatusText}</span></span>
                        </div>
                    </div>
                `;
                
                document.getElementById('modalContent').innerHTML = content;
                document.getElementById('paymentModal').style.display = 'block';
            }

            function closePaymentModal() {
                document.getElementById('paymentModal').style.display = 'none';
            }

            // Close modal when clicking outside
            document.getElementById('paymentModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closePaymentModal();
                }
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closePaymentModal();
                }
            });
        </script>
        <?php if ($adminWhatsAppNumber): ?>
<a 
  href="https://wa.me/<?php echo $adminWhatsAppNumber; ?>?text=Hello%20Admin%2C%20I%20have%20a%20payment%20query." 
  target="_blank" 
  style="
    position: fixed; bottom: 25px; right: 25px; z-index: 9999; background: #25D366; color: white;
    border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(0,0,0,0.15); text-decoration: none; font-size: 2em;"
  title="Contact Admin on WhatsApp"
>
  <i class="fab fa-whatsapp"></i>
</a>
<?php endif; ?>

    </body>
</html>
