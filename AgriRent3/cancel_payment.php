<?php
session_start();
require_once 'auth/config.php';

if (!isset($_SESSION['logged_in']) || !isset($_GET['payment_id'])) {
    header('Location: subscription_plans.php');
    exit;
}

$payment_id = intval($_GET['payment_id']);

// Get payment and subscription details
$query = "SELECT p.*, us.user_id, us.subscription_id 
          FROM payments p
          JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id
          WHERE p.Payment_id = $payment_id AND us.user_id = {$_SESSION['user_id']} AND p.Status = 'P'";

$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    $payment_data = $result->fetch_assoc();
    
    // Cancel the payment
    $cancel_payment = "UPDATE payments SET Status = 'C' WHERE Payment_id = $payment_id";
    
    // Cancel the subscription
    $cancel_subscription = "UPDATE user_subscriptions SET Status = 'C' WHERE subscription_id = {$payment_data['subscription_id']}";
    
    if ($conn->query($cancel_payment) && $conn->query($cancel_subscription)) {
        unset($_SESSION['pending_payment']);
    }
}

header('Location: subscription_plans.php');
exit;
?>
