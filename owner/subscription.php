<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$message = '';

// Handle subscription actions
if ($_POST) {
    if (isset($_POST['subscribe_plan'])) {
        $plan_id = intval($_POST['plan_id']);
        
        // Get plan details
        $plan_stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE plan_id = ?");
        $plan_stmt->bind_param("i", $plan_id);
        $plan_stmt->execute();
        $plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();
        
        if ($plan) {
            // Calculate end date
            $end_date = ($plan['Plan_type'] == 'M') ? 
                date('Y-m-d', strtotime('+1 month')) : 
                date('Y-m-d', strtotime('+1 year'));
            
            // Insert subscription
            $sub_stmt = $conn->prepare("INSERT INTO user_subscriptions (user_id, plan_id, start_date, end_date, Status) VALUES (?, ?, CURDATE(), ?, 'A')");
            $sub_stmt->bind_param("iis", $owner_id, $plan_id, $end_date);
            
            if ($sub_stmt->execute()) {
                $subscription_id = $conn->insert_id;
                
                // Insert payment record
                $pay_stmt = $conn->prepare("INSERT INTO payments (Subscription_id, Amount, Status, payment_date) VALUES (?, ?, 'C', NOW())");
                $pay_stmt->bind_param("id", $subscription_id, $plan['Price']);
                $pay_stmt->execute();
                $pay_stmt->close();
                
                $message = "Successfully subscribed to " . $plan['Plan_name'] . "!";
            } else {
                $message = "Error processing subscription. Please try again.";
            }
            $sub_stmt->close();
        }
    }
    
    if (isset($_POST['cancel_subscription'])) {
        $subscription_id = intval($_POST['subscription_id']);
        $cancel_stmt = $conn->prepare("UPDATE user_subscriptions SET Status = 'C' WHERE subscription_id = ? AND user_id = ?");
        $cancel_stmt->bind_param("ii", $subscription_id, $owner_id);
        
        if ($cancel_stmt->execute()) {
            $message = "Subscription cancelled successfully.";
        } else {
            $message = "Error cancelling subscription.";
        }
        $cancel_stmt->close();
    }
}

// Get available subscription plans
$plans_query = "SELECT * FROM subscription_plans ORDER BY Price ASC";
$plans_result = $conn->query($plans_query);
$plans = [];
while ($plan = $plans_result->fetch_assoc()) {
    $plans[] = $plan;
}

// Get current user subscriptions
$current_subs_query = "SELECT us.*, sp.Plan_name, sp.Plan_type, sp.Price 
                       FROM user_subscriptions us 
                       JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
                       WHERE us.user_id = ? 
                       ORDER BY us.start_date DESC";
$current_subs_stmt = $conn->prepare($current_subs_query);
$current_subs_stmt->bind_param("i", $owner_id);
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

// Get payment history
$payments_query = "SELECT p.*, sp.Plan_name 
                   FROM payments p 
                   JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id 
                   JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
                   WHERE us.user_id = ? 
                   ORDER BY p.payment_date DESC LIMIT 10";
$payments_stmt = $conn->prepare($payments_query);
$payments_stmt->bind_param("i", $owner_id);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();
$payments = [];
while ($payment = $payments_result->fetch_assoc()) {
    $payments[] = $payment;
}
$payments_stmt->close();

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Subscription Management</h1>
    <p style="color: #666; margin-bottom: 30px;">Manage your AgriRent subscription plans and billing</p>

    <?php if ($message): ?>
        <div class="<?= strpos($message, 'Error') === false && strpos($message, 'cancelled') === false ? 'message' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Current Subscription Status -->
    <div class="subscription-status">
        <h2>📋 Current Subscription Status</h2>
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
                        <strong>Price:</strong> ₹<?= number_format($active_subscription['Price'], 2) ?>/<?= $active_subscription['Plan_type'] == 'M' ? 'month' : 'year' ?>
                    </div>
                    <div class="detail-item">
                        <strong>Start Date:</strong> <?= date('M j, Y', strtotime($active_subscription['start_date'])) ?>
                    </div>
                    <div class="detail-item">
                        <strong>Renewal Date:</strong> <?= date('M j, Y', strtotime($active_subscription['end_date'])) ?>
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
                
                <!-- Subscription Actions -->
                <div class="subscription-actions">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="subscription_id" value="<?= $active_subscription['subscription_id'] ?>">
                        <button type="submit" name="cancel_subscription" class="btn btn-danger" 
                                onclick="return confirm('Are you sure you want to cancel your subscription?')">
                            ❌ Cancel Subscription
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="no-subscription">
                <h3>❌ No Active Subscription</h3>
                <p>You don't have an active subscription. Choose a plan below to get started!</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Available Plans -->
    <div class="available-plans">
        <h2>💰 Available Subscription Plans</h2>
        <div class="plans-grid">
            <?php foreach ($plans as $plan): ?>
                <div class="plan-card">
                    <div class="plan-header">
                        <h3><?= htmlspecialchars($plan['Plan_name']) ?></h3>
                        <div class="plan-price">
                            ₹<?= number_format($plan['Price'], 2) ?>
                            <span>/<?= $plan['Plan_type'] == 'M' ? 'month' : 'year' ?></span>
                        </div>
                    </div>
                    
                    <div class="plan-features">
                        <ul>
                            <li>✅ List unlimited equipment</li>
                            <li>✅ Accept bookings from farmers</li>
                            <li>✅ Direct messaging with customers</li>
                            <li>✅ Earnings reports and analytics</li>
                            <li>✅ Priority customer support</li>
                            <?php if ($plan['Plan_type'] == 'Y'): ?>
                                <li>⭐ 2 months free (annual plan)</li>
                                <li>⭐ Priority listing in search</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div class="plan-action">
                        <?php if ($active_subscription && $active_subscription['plan_id'] == $plan['plan_id']): ?>
                            <button class="btn btn-current" disabled>Current Plan</button>
                        <?php elseif ($active_subscription): ?>
                            <button class="btn btn-secondary" disabled>Subscription Active</button>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="plan_id" value="<?= $plan['plan_id'] ?>">
                                <button type="submit" name="subscribe_plan" class="btn btn-primary">
                                    🚀 Subscribe Now
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Subscription History -->
    <div class="subscription-history">
        <h2>📜 Subscription History</h2>
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
                            <td>₹<?= number_format($sub['Price'], 2) ?></td>
                            <td><?= date('M j, Y', strtotime($sub['start_date'])) ?></td>
                            <td><?= date('M j, Y', strtotime($sub['end_date'])) ?></td>
                            <td>
                                <span class="status-badge status-<?= strtolower($sub['Status']) ?>">
                                    <?= $sub['Status'] == 'A' ? 'Active' : ($sub['Status'] == 'E' ? 'Expired' : 'Cancelled') ?>
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
    <div class="payment-history">
        <h2>💳 Payment History</h2>
        <?php if (count($payments) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Plan</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Transaction ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= date('M j, Y g:i A', strtotime($payment['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($payment['Plan_name']) ?></td>
                            <td>₹<?= number_format($payment['Amount'], 2) ?></td>
                            <td>
                                <span class="status-badge status-<?= strtolower($payment['Status']) ?>">
                                    <?= $payment['Status'] == 'C' ? 'Completed' : ($payment['Status'] == 'P' ? 'Pending' : ($payment['Status'] == 'F' ? 'Failed' : 'Refunded')) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($payment['transaction_id'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No payment history found.</p>
        <?php endif; ?>
    </div>

    <!-- Benefits Section -->
    <div class="benefits-section">
        <h2>🌟 Subscription Benefits</h2>
        <div class="benefits-grid">
            <div class="benefit-item">
                <h4>🚜 Unlimited Equipment Listings</h4>
                <p>List as many pieces of equipment as you own without any restrictions.</p>
            </div>
            <div class="benefit-item">
                <h4>📈 Advanced Analytics</h4>
                <p>Get detailed reports on your earnings, popular equipment, and booking trends.</p>
            </div>
            <div class="benefit-item">
                <h4>💬 Direct Communication</h4>
                <p>Chat directly with farmers and customers through our messaging system.</p>
            </div>
            <div class="benefit-item">
                <h4>🏆 Priority Support</h4>
                <p>Get priority customer support and faster response times.</p>
            </div>
            <div class="benefit-item">
                <h4>📊 Business Tools</h4>
                <p>Access to business management tools, booking calendar, and more.</p>
            </div>
            <div class="benefit-item">
                <h4>🔒 Secure Payments</h4>
                <p>Secure payment processing and automated billing management.</p>
            </div>
        </div>
    </div>
</div>

<?php 
    require 'ofooter.php';
?>
