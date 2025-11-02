<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle subscription purchase with payment integration
if ($_POST) {
    if (isset($_POST['subscribe_plan'])) {
        $plan_id = intval($_POST['plan_id']);
        
        try {
            // Check if user already has an ACTIVE subscription
            $active_check = $conn->prepare("SELECT * FROM user_subscriptions WHERE user_id = ? AND Status = 'A' AND (end_date IS NULL OR end_date >= CURDATE())");
            $active_check->bind_param("i", $farmer_id);
            $active_check->execute();
            $active_result = $active_check->get_result();

            if ($active_result->num_rows > 0) {
                $error = "You already have an active subscription. You cannot subscribe to another plan.";
            } else {
                // Check if user already has a PENDING subscription/payment
                $pending_check = $conn->prepare("SELECT * FROM user_subscriptions WHERE user_id = ? AND Status = 'P'");
                $pending_check->bind_param("i", $farmer_id);
                $pending_check->execute();
                $pending_result = $pending_check->get_result();

                if ($pending_result->num_rows > 0) {
                    $error = "You already have a pending subscription request. Please wait for admin approval.";
                } else {
                    // Get plan details with correct field names
                    $plan_stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE plan_id = ? AND user_type = 'F'");
                    $plan_stmt->bind_param("i", $plan_id);
                    $plan_stmt->execute();
                    $plan_result = $plan_stmt->get_result();

                    if ($plan_result->num_rows > 0) {
                        $plan = $plan_result->fetch_assoc();
                        
                        // Create pending subscription first
                        $subscription_stmt = $conn->prepare("INSERT INTO user_subscriptions (user_id, plan_id, start_date, end_date, Status) VALUES (?, ?, NULL, NULL, 'P')");
                        $subscription_stmt->bind_param("ii", $farmer_id, $plan_id);
                        
                        if ($subscription_stmt->execute()) {
                            $subscription_id = $conn->insert_id;
                            
                            // Create payment record - using correct field name 'price'
                            $transaction_ref = 'TXN' . time() . rand(1000, 9999);
                            
                            $payment_stmt = $conn->prepare("INSERT INTO payments (Subscription_id, Amount, transaction_id, Status) VALUES (?, ?, ?, 'P')");
                            $payment_stmt->bind_param("ids", $subscription_id, $plan['price'], $transaction_ref);
                            
                            if ($payment_stmt->execute()) {
                                $payment_id = $conn->insert_id;
                                
                                // Store payment details in session for payment page
                                $_SESSION['pending_payment'] = [
                                    'payment_id' => $payment_id,
                                    'subscription_id' => $subscription_id,
                                    'plan_id' => $plan_id,
                                    'plan_name' => $plan['Plan_name'],
                                    'amount' => $plan['price'],
                                    'transaction_id' => $transaction_ref
                                ];
                                
                                // Redirect to payment page
                                header("Location: ../payment.php");
                                exit();
                            } else {
                                // If payment creation fails, remove the subscription
                                $conn->query("DELETE FROM user_subscriptions WHERE subscription_id = $subscription_id");
                                $error = "Failed to initiate payment. Please try again.";
                            }
                            $payment_stmt->close();
                        } else {
                            $error = "Failed to create subscription. Please try again.";
                        }
                        $subscription_stmt->close();
                    } else {
                        $error = "Invalid subscription plan selected.";
                    }
                    $plan_stmt->close();
                }
                $pending_check->close();
            }
            $active_check->close();
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
}

// Get available subscription plans for Farmers - using correct field names
$plans_stmt = $conn->prepare("SELECT plan_id, Plan_name, Plan_type, user_type, price FROM subscription_plans WHERE user_type = 'F' ORDER BY price ASC");
$plans_stmt->execute();
$plans_result = $plans_stmt->get_result();
$plans = [];
while ($plan = $plans_result->fetch_assoc()) {
    $plans[] = $plan;
}
$plans_stmt->close();

// Get current user subscriptions - using correct field names
$current_subs_stmt = $conn->prepare("SELECT us.*, sp.Plan_name, sp.Plan_type, sp.price 
                                    FROM user_subscriptions us 
                                    JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
                                    WHERE us.user_id = ? 
                                    ORDER BY us.start_date DESC");
$current_subs_stmt->bind_param("i", $farmer_id);
$current_subs_stmt->execute();
$current_subs_result = $current_subs_stmt->get_result();
$current_subscriptions = [];
while ($sub = $current_subs_result->fetch_assoc()) {
    $current_subscriptions[] = $sub;
}
$current_subs_stmt->close();

// Get active subscription
$active_subscription = null;
foreach ($current_subscriptions as $sub) {
    if ($sub['Status'] == 'A' && strtotime($sub['end_date']) > time()) {
        $active_subscription = $sub;
        break;
    }
}

// Check for pending payment - using correct field names
$pending_payment = null;
$pending_payment_stmt = $conn->prepare("
    SELECT p.*, us.subscription_id, sp.Plan_name, sp.price
    FROM payments p
    JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id
    JOIN subscription_plans sp ON us.plan_id = sp.plan_id
    WHERE us.user_id = ? AND p.Status = 'P' AND us.Status = 'P'
    ORDER BY p.Payment_id DESC LIMIT 1
");
$pending_payment_stmt->bind_param("i", $farmer_id);
$pending_payment_stmt->execute();
$pending_payment_result = $pending_payment_stmt->get_result();

if ($pending_payment_result->num_rows > 0) {
    $pending_payment = $pending_payment_result->fetch_assoc();
}
$pending_payment_stmt->close();

// Get payment history - using correct field names
$payments_stmt = $conn->prepare("SELECT p.*, sp.Plan_name 
                                FROM payments p 
                                JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id 
                                JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
                                WHERE us.user_id = ? 
                                ORDER BY p.payment_date DESC LIMIT 10");
$payments_stmt->bind_param("i", $farmer_id);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();
$payments = [];
while ($payment = $payments_result->fetch_assoc()) {
    $payments[] = $payment;
}
$payments_stmt->close();

require 'fheader.php';
require 'farmer_nav.php';
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../assets/css/equipment.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* Subscription Management Complete CSS */

/* Main Container */
.main-content {
    padding: 20px;
    max-width: 1200px;
    margin: 10 auto;
    font-family: 'Arial', sans-serif;
}

.main-content h1 {
    font-size: 2.5em;
    color: #234a23;
    margin-bottom: 10px;
    text-align: center;
}

.main-content > p {
    text-align: center;
    color: #666;
    font-size: 1.1em;
    margin-bottom: 30px;
}

/* Alert Messages */
.message {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid #28a745;
    font-weight: 500;
}

.error {
    background: linear-gradient(135deg, #f8d7da, #f5c6cb);
    color: #721c24;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid #dc3545;
    font-weight: 500;
}

/* Policy Notice */
.policy-notice {
    background: linear-gradient(135deg, #ffebee, #ffcdd2);
    color: #c62828;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    text-align: center;
    border-left: 4px solid #d32f2f;
    font-weight: 500;
}

.policy-notice h3 {
    margin-bottom: 10px;
    font-size: 1.3em;
}

/* Payment Notice */
.payment-notice {
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    color: #0d47a1;
    padding: 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
    border-left: 4px solid #2196f3;
}

.payment-notice h3 {
    margin-bottom: 8px;
    font-size: 1.2em;
}

.payment-notice .payment-actions {
    margin-top: 15px;
}

.payment-notice a {
    background: #2196f3;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    text-decoration: none;
    display: inline-block;
    margin: 0 5px;
    font-weight: bold;
    transition: background 0.3s;
}

.payment-notice a:hover {
    background: #1976d2;
    color: white;
    text-decoration: none;
}

/* Section Headers */
.subscription-status,
.available-plans,
.subscription-history,
.payment-history,
.benefits-section {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    
}

.subscription-status h2,
.available-plans h2,
.subscription-history h2,
.payment-history h2,
.benefits-section h2 {
    font-size: 1.8em;
    color: #234a23;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Current Plan Display */
.current-plan {
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    border-radius: 12px;
    padding: 25px;
    border-left: 4px solid #4caf50;
}

.plan-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 15px;
}

.plan-header h3 {
    font-size: 1.6em;
    color: #234a23;
    margin: 0;
    font-weight: 600;
}

.plan-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: bold;
    font-size: 0.9em;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.plan-badge.active {
    background: #4caf50;
    color: white;
}

.plan-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.detail-item {
    padding: 12px;
    background: rgba(255, 255, 255, 0.7);
    border-radius: 8px;
}

.detail-item strong {
    color: #234a23;
    display: block;
    margin-bottom: 5px;
}

/* No Subscription */
.no-subscription {
    text-align: center;
    padding: 40px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 2px dashed #dee2e6;
}

.no-subscription h3 {
    font-size: 1.4em;
    color: #dc3545;
    margin-bottom: 10px;
}

/* Plans Grid */
.plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 25px;
    margin-top: 25px;
}

/* Plan Cards - MAIN STYLING */
.plan-card {
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 16px;
    padding: 25px;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
}

.plan-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #234a23, #28a745);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.plan-card:hover {
    border-color: #234a23;
    transform: translateY(-5px);
    box-shadow: 0 12px 30px rgba(35, 74, 35, 0.15);
}

.plan-card:hover::before {
    transform: scaleX(1);
}

/* Plan Header inside Card */
.plan-card .plan-header {
    display: block;
    text-align: center;
    border-bottom: 2px solid #f0f0f0;
    padding-bottom: 20px;
    margin-bottom: 25px;
}

/* Plan Name - First Line */
.plan-card .plan-header h3 {
    font-size: 1.4em !important;
    color: #234a23 !important;
    margin: 0 0 15px 0 !important;
    line-height: 1.3 !important;
    font-weight: 600 !important;
    text-align: center !important;
    display: block !important;
    min-height: 40px;
}

/* Plan Price - Second Line */
.plan-price {
    font-size: 2.2em !important;
    font-weight: bold !important;
    color: #28a745 !important;
    margin: 15px 0 !important;
    line-height: 1.2 !important;
    text-align: center !important;
    display: block !important;
}

.plan-price span {
    font-size: 0.45em !important;
    color: #666 !important;
    font-weight: normal !important;
    display: block !important;
    margin-top: 5px !important;
    text-transform: lowercase !important;
}

/* Plan Features */
.plan-features {
    margin: 25px 0;
}

.plan-features ul {
    list-style: none;
    padding: 0;
    text-align: left;
}

.plan-features li {
    padding: 8px 0;
    font-size: 0.95em;
    border-bottom: 1px solid #f5f5f5;
    display: flex;
    align-items: center;
    gap: 8px;
}

.plan-features li:last-child {
    border-bottom: none;
}

/* Plan Action Buttons */
.plan-action {
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px solid #f0f0f0;
}

/* Buttons */
.btn {
    padding: 14px 28px;
    border: none;
    border-radius: 25px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-size: 1em;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    width: 100%;
}

.btn-primary {
    background: linear-gradient(135deg, #234a23, #28a745);
    color: white;
    box-shadow: 0 4px 15px rgba(35, 74, 35, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(35, 74, 35, 0.4);
}

.btn-current {
    background: #17a2b8;
    color: white;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:disabled,
.btn-current:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 0.85em;
    width: auto;
}

/* Tables */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

table thead {
    background: linear-gradient(135deg, #234a23, #28a745);
    color: white;
}

table th,
table td {
    padding: 15px 12px;
    text-align: left;
    border-bottom: 1px solid #e0e0e0;
}

table th {
    font-weight: bold;
    text-transform: uppercase;
    font-size: 0.9em;
    letter-spacing: 0.5px;
}

table tr:hover {
    background: #f8f9fa;
}

/* Status Badges */
.status-badge {
    padding: 6px 12px;
    border-radius: 15px;
    font-weight: bold;
    font-size: 0.85em;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.status-a,
.status-badge.status-s {
    background: #d4edda;
    color: #155724;
}

.status-badge.status-p {
    background: #fff3cd;
    color: #856404;
}

.status-badge.status-c {
    background: #f8d7da;
    color: #721c24;
}

.status-badge.status-e {
    background: #e2e3e5;
    color: #6c757d;
}

/* Text Colors */
.text-success {
    color: #28a745 !important;
    font-weight: bold;
}

.text-warning {
    color: #ffc107 !important;
    font-weight: bold;
}

/* Benefits Grid */
.benefits-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 25px;
}

.benefit-item {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid #28a745;
    transition: all 0.3s ease;
}

.benefit-item:hover {
    background: #e8f5e9;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.benefit-item h4 {
    font-size: 1.2em;
    color: #234a23;
    margin-bottom: 10px;
}

.benefit-item p {
    color: #666;
    font-size: 0.95em;
    line-height: 1.5;
}

/* Responsive Design */
@media (max-width: 768px) {
    .main-content {
        padding: 15px;
    }
    
    .plans-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .plan-details {
        grid-template-columns: 1fr;
    }
    
    .benefits-grid {
        grid-template-columns: 1fr;
    }
    
    .plan-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    table {
        font-size: 0.9em;
    }
    
    table th,
    table td {
        padding: 10px 8px;
    }
    
    .plan-card .plan-header h3 {
        font-size: 1.2em !important;
        min-height: auto;
    }
    
    .plan-price {
        font-size: 1.8em !important;
    }
}

@media (max-width: 480px) {
    .main-content h1 {
        font-size: 2em;
    }
    
    .subscription-status,
    .available-plans,
    .subscription-history,
    .payment-history,
    .benefits-section {
        padding: 15px;
    }
    
    .current-plan {
        padding: 15px;
    }
    
    .plan-card {
        padding: 15px;
    }
}

/* Form Elements */
form {
    display: inline-block;
    width: 100%;
}

input[type="hidden"] {
    display: none;
}

/* Additional Spacing */
.subscription-actions {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
}

/* Icons */
.fa, .fas, .far {
    margin-right: 5px;
}

/* Loading and Hover Effects */
.btn:focus,
.btn:active {
    outline: none;
    box-shadow: 0 0 0 3px rgba(35, 74, 35, 0.2);
}

.plan-card:focus-within {
    border-color: #234a23;
    box-shadow: 0 0 0 3px rgba(35, 74, 35, 0.1);
}

/* Debug Information */
.debug-info {
    background: #e7f3ff;
    border-left: 4px solid #2196f3;
    padding: 15px;
    margin: 15px 0;
    border-radius: 4px;
    font-family: monospace;
}
</style>

<!-- MAIN CONTENT WITH PROPER SPACING -->
<div class="main-content">
    <h1>Subscription</h1>
    <p style="color: #666; margin-bottom: 30px;">Manage your AgriRent farmer subscription plans and billing</p>
        
        <!-- Success/Error Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- NO REFUND POLICY NOTICE -->
        

        <!-- Pending Payment Notice -->
        <?php if ($pending_payment): ?>
            <div class="payment-notice">
                <h3><i class="fas fa-credit-card"></i> Payment Pending Verification</h3>
                <p>Your payment for <strong><?= htmlspecialchars($pending_payment['Plan_name']) ?></strong> (₹<?= number_format($pending_payment['Amount'], 2) ?>) is pending admin verification.</p>
                <p>Transaction ID: <strong><?= htmlspecialchars($pending_payment['transaction_id']) ?></strong></p>
                <p><i class="fas fa-info-circle"></i> <strong>Payment will be verified within 2-3 hours</strong></p>
                
                <div class="payment-actions">
                    <a href="../view_payment_details.php?payment_id=<?= $pending_payment['Payment_id'] ?>" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View Payment Details
                    </a>
                </div>
                
                <p style="margin-top: 15px; font-size: 0.9em;">
                    <i class="fas fa-lock"></i> <strong>No cancellation available</strong> - All payments are final
                </p>
            </div>
        <?php endif; ?>

        <!-- Current Subscription Status -->
       <div class="subscription-status">
        <h2> Current Subscription Status</h2>
        <?php if ($active_subscription): ?>
            <div class="current-plan">
                <div class="plan-header">
                    <h3><?= htmlspecialchars($active_subscription['Plan_name']) ?></h3>
                    <span class="plan-badge active">Active</span>
                </div>
                <div class="plan-details">
                    <div class="detail-item">
                        <strong>Plan Type:</strong> <?= $active_subscription['Plan_type'] == 'M' ? 'Monthly' : 'Yearly' ?>
                    </div>
                    <div class="detail-item">
                        <strong>Price:</strong> ₹<?= number_format($active_subscription['price'], 2) ?>/<?= $active_subscription['Plan_type'] == 'M' ? 'month' : 'year' ?>
                    </div>
                    <div class="detail-item">
                        <strong>Start Date:</strong> <?= date('M j, Y', strtotime($active_subscription['start_date'])) ?>
                    </div>
                    <div class="detail-item">
                        <strong>End Date:</strong> <?= date('M j, Y', strtotime($active_subscription['end_date'])) ?>
                    </div>
                    <div class="detail-item">
                        <?php 
                        $days_remaining = ceil((strtotime($active_subscription['end_date']) - time()) / (60 * 60 * 24));
                        ?>
                        <strong>Days Remaining:</strong> 
                        <span class="<?= $days_remaining < 30 ? 'text-warning' : 'text-success' ?>">
                            <?= $days_remaining ?> days
                        </span>
                    </div>
                </div>
                
                <!-- No Cancellation Notice -->
                <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin-top: 15px; text-align: center;">
                    <strong><i class="fas fa-info-circle"></i> NO CANCELLATION POLICY</strong><br>
                    This subscription cannot be cancelled or refunded. It will automatically expire on the end date.
                </div>
            </div>
        <?php else: ?>
            <div class="no-subscription">
                <h3>No Active Subscription</h3>
                <p>You don't have an active subscription. Choose a plan below to get started!</p>
            </div>
        <?php endif; ?>
    </div>

        
    <!-- Subscription History -->
    <div class="subscription-history">
        <h2>Subscription History</h2>
        <?php if (count($current_subscriptions) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Plan Name</th>
                        <th>Type</th>
                        <th>Price</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($current_subscriptions as $sub): ?>
                        <tr>
                            <td><?= htmlspecialchars($sub['Plan_name']) ?></td>
                            <td><?= $sub['Plan_type'] == 'M' ? 'Monthly' : 'Yearly' ?></td>
                            <td>₹<?= number_format($sub['price'], 2) ?></td>
                            <td><?= $sub['start_date'] ? date('M j, Y', strtotime($sub['start_date'])) : 'N/A' ?></td>
                            <td><?= $sub['end_date'] ? date('M j, Y', strtotime($sub['end_date'])) : 'N/A' ?></td>
                            <td>
                                <span class="status-badge status-<?= strtolower($sub['Status']) ?>">
                                    <?= $sub['Status'] == 'A' ? 'Active' : ($sub['Status'] == 'P' ? 'Pending' : ($sub['Status'] == 'E' ? 'Expired' : 'Cancelled')) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No subscription history found.</p>
        <?php endif; ?>
    </div>

        
        

        <!-- Payment History -->
       <div class="subscription-history">
                    <h2>Payment History</h2>
            
            
            <?php if (count($payments) > 0): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Transaction ID</th>
                                <th>UPI ID</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= date('M j, Y g:i A', strtotime($payment['payment_date'])) ?></td>
                                    <td><?= htmlspecialchars($payment['Plan_name']) ?></td>
                                    <td>₹<?= number_format($payment['Amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($payment['transaction_id'] ?? 'N/A') ?></td>
                                    <td><?= !empty($payment['UPI_transaction_id']) && $payment['UPI_transaction_id'] !== '0' ? htmlspecialchars($payment['UPI_transaction_id']) : 'N/A' ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $payment['Status'] == 'S' ? 'active' : ($payment['Status'] == 'P' ? 'pending' : 'expired') ?>">
                                            <?= $payment['Status'] == 'S' ? 'Success' : ($payment['Status'] == 'P' ? 'Pending' : ($payment['Status'] == 'C' ? 'Cancelled' : 'Failed')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="../view_payment_details.php?payment_id=<?= $payment['Payment_id'] ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #666; padding: 20px;">No payment history found.</p>
            <?php endif; ?>
        </div>

        
    </div>
</div>

<script>
// Add loading state to buttons when clicked
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
        const button = this.querySelector('button[type="submit"]');
        if (button && !button.disabled) {
            button.classList.add('loading');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            button.disabled = true;
        }
    });
});

// Add smooth scroll behavior
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-20px)';
        setTimeout(() => alert.remove(), 300);
    });
}, 5000);
</script>

<?php 
require 'ffooter.php';
?>
