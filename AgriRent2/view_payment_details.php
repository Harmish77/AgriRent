<?php
session_start();
require_once 'auth/config.php';

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$payment_details = null;
$error = "";

// Get payment ID from URL
if (isset($_GET['payment_id'])) {
    $payment_id = intval($_GET['payment_id']);
    
    // Get complete payment details
    $query = "SELECT p.*, us.user_id, us.start_date, us.end_date, us.Status as subscription_status,
                     sp.Plan_name, sp.Plan_type, sp.user_type,
                     u.Name as user_name, u.Email as user_email
              FROM payments p
              JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id
              JOIN subscription_plans sp ON us.plan_id = sp.plan_id
              JOIN users u ON us.user_id = u.user_id
              WHERE p.Payment_id = $payment_id AND us.user_id = {$_SESSION['user_id']}";
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $payment_details = $result->fetch_assoc();
    } else {
        $error = "Payment details not found.";
    }
} else {
    $error = "Invalid payment ID.";
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment Details - AgriRent</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                max-width: 300px;
                margin: 0 auto;
                padding: 10px;
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

            .alert.error {
                background: var(--error-bg);
                color: #721c24;
                border-left: 5px solid var(--error);
            }

            .payment-details {
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(10px);
                border-radius: 15px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
            }

            .payment-details h2 {
                font-size: 2em;
                color: var(--text-dark);
                margin-bottom: 30px;
                text-align: center;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
            }

            .detail-row {
                display: flex;
                justify-content: space-between;
                padding: 15px 0;
                border-bottom: 1px solid #e9ecef;
                align-items: center;
            }

            .detail-row:last-child {
                border-bottom: none;
            }

            .detail-label {
                font-weight: 600;
                color: var(--text-dark);
                display: flex;
                align-items: center;
                gap: 8px;
                min-width: 200px;
                font-size: 1em;
            }

            .detail-value {
                text-align: right;
                word-break: break-word;
                flex: 1;
                color: #234a23;
                font-size: 1em;
                font-weight: 500;
            }

            .status-badge {
                padding: 8px 15px;
                border-radius: 20px;
                font-weight: bold;
                font-size: 0.9em;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }

            .status-success {
                background: #d4edda;
                color: #155724;
            }

            .status-pending {
                background: #fff3cd;
                color: #856404;
            }

            .status-cancelled {
                background: #f8d7da;
                color: #721c24;
            }

            .upi-id {
                background: #e3f2fd;
                color: #234a23;
                padding: 12px 20px;
                border-radius: 8px;
                font-family: monospace;
                font-size: 1em;
                font-weight: 600;
                text-align: center;
                border: 2px solid #2196f3;
                letter-spacing: 1px;
                margin-top: 5px;
            }

            .no-upi-id {
                background: #fff3cd;
                color: #234a23;
                padding: 12px 20px;
                border-radius: 8px;
                text-align: center;
                font-style: italic;
                border: 2px solid #ffc107;
                margin-top: 5px;
                font-size: 1em;
            }

            .back-btn {
                background: var(--primary);
                color: var(--white);
                text-decoration: none;
                padding: 12px 25px;
                border-radius: 40px;
                display: inline-block;
                margin-top: 20px;
                font-weight: 600;
                transition: all 0.3s ease;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .back-btn:hover {
                transform: translateY(-2px);
                text-decoration: none;
                color: var(--white);
            }

            .btn-center {
                text-align: center;
                margin-top: 25px;
            }

            @media (max-width: 768px) {
                .container {
                    padding: 15px;
                }

                .payment-details {
                    padding: 20px;
                }

                .detail-row {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 8px;
                }

                .detail-label {
                    min-width: auto;
                }

                .detail-value {
                    text-align: left;
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <?php include 'includes/header.php' ?> 
        <?php include 'includes/navigation.php' ?> 
        <br><br>
        
        <div class="container">
            
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php elseif ($payment_details): ?>
                
                <div class="payment-details">
                    <h2><i class="fas fa-receipt"></i> Payment Details</h2>
                    
                    <!-- Payment ID -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-hashtag"></i> Payment ID
                        </div>
                        <div class="detail-value">
                            PAY-<?= $payment_details['Payment_id'] ?>
                        </div>
                    </div>
                    
                    <!-- Amount -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-money-bill-wave"></i> Amount
                        </div>
                        <div class="detail-value">
                            ₹<?= number_format($payment_details['Amount'], 2) ?>
                        </div>
                    </div>
                    
                    <!-- Payment Date -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-calendar"></i> Payment Date
                        </div>
                        <div class="detail-value">
                            <?= date('M j, Y - g:i A', strtotime($payment_details['payment_date'])) ?>
                        </div>
                    </div>
                    
                    
                    
                    <!-- Transaction ID -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-barcode"></i> Transaction ID
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($payment_details['transaction_id']) ?>
                        </div>
                    </div>
                    
                    <!-- UPI Transaction ID -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-mobile-alt"></i> UPI Transaction ID
                        </div>
                        <div class="detail-value">
                            <?php if (!empty($payment_details['UPI_transaction_id']) && $payment_details['UPI_transaction_id'] !== '0'): ?>
                                
                                    <?= htmlspecialchars($payment_details['UPI_transaction_id']) ?>
                                </div>
                            <?php else: ?>
                               
                                    <i class="fas fa-info-circle"></i> UPI Transaction ID not provided yet
                                
                            <?php endif; ?>
                        
                    </div>
                    
                    <!-- Plan Name -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-star"></i> Subscription Plan
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($payment_details['Plan_name']) ?>
                        </div>
                    </div>
                    
                    <!-- Plan Type -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-clock"></i> Plan Type
                        </div>
                        <div class="detail-value">
                            <?= $payment_details['Plan_type'] === 'M' ? 'Monthly Plan' : 'Yearly Plan' ?>
                        </div>
                    </div>
                    
                    <!-- User Type -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-user-tag"></i> User Type
                        </div>
                        <div class="detail-value">
                            <?= $payment_details['user_type'] === 'F' ? 'Farmer' : 'Equipment Owner' ?>
                        </div>
                    </div>
                    
                    <!-- Subscription Status -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-check-square"></i> Subscription Status
                        </div>
                        <div class="detail-value">
                            <?php 
                            $sub_status = $payment_details['subscription_status'];
                            if ($sub_status === 'A'): ?>
                                <span class="status-badge status-success">
                                    <i class="fas fa-check-circle"></i> Active
                                </span>
                            <?php elseif ($sub_status === 'P'): ?>
                                <span class="status-badge status-pending">
                                   Pending
                                </span>
                            <?php elseif ($sub_status === 'C'): ?>
                                <span class="status-badge status-cancelled">
                                    <i class="fas fa-times-circle"></i> Cancelled
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Valid Period (if active) -->
                    <?php if ($payment_details['start_date'] && $payment_details['end_date']): ?>
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-calendar-alt"></i> Valid Period
                        </div>
                        <div class="detail-value">
                            <?= date('M j, Y', strtotime($payment_details['start_date'])) ?> to <?= date('M j, Y', strtotime($payment_details['end_date'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- User Name -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-user"></i> Account Name
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($payment_details['user_name']) ?>
                        </div>
                    </div>
                    
                    <!-- User Email -->
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-envelope"></i> Email
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($payment_details['user_email']) ?>
                        </div>
                    </div>
                    
                   
                    
                </div>
            
            <?php endif; ?>
            
            <div class="btn-center">
                <a href="subscription_plans.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Plans
                </a>
            </div>
        </div>
        
        <br><br>
        <?php include 'includes/footer.php' ?> 
    </body>
</html>
