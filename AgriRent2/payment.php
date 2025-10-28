<?php
session_start();
require_once 'auth/config.php';

if (!isset($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$payment_details = null;
$error = "";

// Get payment details from session (new system)
if (isset($_SESSION['pending_payment_request'])) {
    $payment_details = $_SESSION['pending_payment_request'];
} elseif (isset($_GET['payment_id'])) {
    // Legacy support for existing payments
    $payment_id = intval($_GET['payment_id']);

    $query = "SELECT p.*, us.user_id, sp.Plan_name 
              FROM payments p
              JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id
              JOIN subscription_plans sp ON us.plan_id = sp.plan_id
              WHERE p.Payment_id = $payment_id AND us.user_id = {$_SESSION['user_id']} AND p.Status = 'P'";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $payment_details = [
            'payment_id' => $row['Payment_id'],
            'subscription_id' => $row['Subscription_id'],
            'plan_name' => $row['Plan_name'],
            'amount' => $row['Amount'],
            'transaction_id' => $row['transaction_id']
        ];
    } else {
        $error = "Invalid payment transaction.";
    }
} else {
    header('Location: subscription_plans.php');
    exit;
}

// Handle payment completion - now creates the actual database records
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_payment'])) {
    $upi_transaction_id = trim($_POST['upi_transaction_id']);

    if (empty($upi_transaction_id)) {
        $error = "Please enter the UPI Transaction ID from your payment app.";
    } else {
        if (!isset($_SESSION['pending_payment_request'])) {
            $error = "Payment session expired. Please start again.";
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

                    // Create payment record with UPI transaction ID
                    $payment_query = "INSERT INTO payments (Subscription_id, Amount, transaction_id, UPI_transaction_id, Status) 
                                     VALUES ($subscription_id, {$payment_data['amount']}, '{$payment_data['transaction_id']}', '$upi_transaction_id', 'P')";

                    if ($conn->query($payment_query)) {
                        $payment_id = $conn->insert_id;

                        // Commit transaction
                        $conn->commit();

                        // Clear pending payment request
                        unset($_SESSION['pending_payment_request']);

                        // Set success message
                        $_SESSION['payment_pending_approval'] = "Payment submitted successfully! Your payment is pending admin verification. You will be notified within 2-3 hours. NO CANCELLATION OR REFUND AVAILABLE.";

                        // Redirect to subscription plans
                        header('Location: subscription_plans.php');
                        exit;
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
            }
        }
    }
}

// Generate UPI payment link and QR code data
$upi_link = "";
$qr_code_data = "";
if ($payment_details) {
    $merchant_vpa = "harmish@superyes"; // Replace with your actual UPI ID
    $merchant_name = "AgriRent";
    $amount = $payment_details['amount'];
    $transaction_id = $payment_details['transaction_id'];
    $note = "Subscription: " . $payment_details['plan_name'];

    $upi_link = "upi://pay?pa=$merchant_vpa&pn=$merchant_name&am=$amount&tr=$transaction_id&tn=$note";
    $qr_code_data = $upi_link;
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment - AgriRent</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <!-- QR Code Library -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
        <style>
            /* Your existing payment page styles */
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Arial', sans-serif;
                background:white;
                min-height: 100vh;
                padding: 20px;
            }

            .payment-container {
                max-width: 700px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                box-shadow: 0 15px 40px rgba(0,0,0,0.3);
                overflow: hidden;
            }

            .payment-header {
                background: #234a23;
                color: white;
                padding: 25px;
                text-align: center;
            }

            .payment-body {
                padding: 35px;
            }

            /* NO REFUND WARNING */
            .no-refund-warning {
                background: #ffcdd2;
                color: #c62828;
                padding: 20px;
                border-radius: 10px;
                margin-bottom: 25px;
                text-align: center;
                font-weight: bold;
            }

            .order-summary {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 25px;
                margin-bottom: 30px;
            }

            .payment-methods {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 25px;
                margin: 30px 0;
            }

            .qr-section, .upi-apps-section {
                background: #ffffff;
                border: 2px solid #e3f2fd;
                border-radius: 12px;
                padding: 25px;
                text-align: center;
            }

            .qr-code-container {
                background: white;
                padding: 15px;
                border-radius: 10px;
                display: inline-block;
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                margin: 15px 0;
            }

            .upi-apps {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin: 15px 0;
            }

            .upi-app-btn {
                background: #234a23;
                color: white;
                text-decoration: none;
                padding: 12px 15px;
                border-radius: 8px;
                font-weight: bold;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }

            .upi-app-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 12px rgba(103, 58, 183, 0.3);
                color: white;
                text-decoration: none;
            }

            .payment-form {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 25px;
                margin-top: 30px;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: bold;
                color: #234a23;
            }

            .form-group input {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #e9ecef;
                border-radius: 8px;
                font-size: 16px;
            }

            .pay-btn {
                background: #234a23;
                color: white;
                border: none;
                padding: 18px 35px;
                border-radius: 10px;
                font-size: 18px;
                font-weight: bold;
                cursor: pointer;
                width: 100%;
            }

            .back-btn {
                background: #6c757d;
                color: white;
                text-decoration: none;
                padding: 12px 25px;
                border-radius: 8px;
                display: inline-block;
                margin: 25px 0;
            }

            .back-btn:hover {
                color: white;
                text-decoration: none;
                background: #5a6268;
            }

            .error {
                background: #f8d7da;
                color: #721c24;
                padding: 15px;
                border-radius: 8px;
                margin: 15px 0;
                border-left: 4px solid #dc3545;
            }

            .instructions {
                background: linear-gradient(135deg, #d4edda, #c3e6cb);
                ;
                color: #234a23;
                padding: 20px;
                border-radius: 10px;
                margin: 20px 0;

            }

            .abandon-warning {
                background: #fff3cd;
                color: #856404;
                padding: 15px;
                border-radius: 8px;
                margin: 15px 0;
                border-left: 4px solid #ffc107;
                text-align: center;
                font-weight: 500;
            }

            @media (max-width: 768px) {
                .payment-methods {
                    grid-template-columns: 1fr;
                }
                .upi-apps {
                    grid-template-columns: 1fr;
                }

                .payment-body {
                    padding: 20px;
                }
            }
        </style>
    </head>
    <body>

        <div class="payment-container">
            <div class="payment-header">
                <h2>AgriRent Payment</h2>

            </div>

            <div class="payment-body">
                <!-- Warning about abandoning payment -->


                <?php if ($error): ?>
                    <div class="error">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($payment_details): ?>
                    <div class="order-summary">
                        <h3><i class="fas fa-receipt"></i> Order Summary</h3><br>
                        <div style="display: flex; justify-content: space-between; margin: 10px 0;">
                            <span><strong>Plan:</strong></span>
                            <span><?= htmlspecialchars($payment_details['plan_name']) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 10px 0;">
                            <span><strong>Transaction ID:</strong></span>
                            <span><?= htmlspecialchars($payment_details['transaction_id']) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 1.2em; font-weight: bold; border-top: 2px solid #dee2e6; padding-top: 10px; margin-top: 15px;">
                            <span>Total Amount:</span>
                            <span>₹<?= number_format($payment_details['amount'], 2) ?></span>
                        </div>
                    </div>

                    <div class="payment-methods">
                        <!-- QR Code Section -->
                        <div class="qr-section">
                            <h3><i class="fas fa-qrcode"></i> Scan QR Code</h3>
                            <div class="qr-code-container">
                                <canvas id="qr-code"></canvas>
                            </div>
                            <small>Scan with any UPI app</small>
                        </div>

                        <!-- UPI Apps Section -->
                        <div class="upi-apps-section">
                            <h3> UPI Apps</h3>
                            <div class="upi-apps">
                                <a href="<?= $upi_link ?>" class="upi-app-btn">
                                    <i class="fas fa-phone"></i> PhonePe
                                </a>
                                <a href="<?= $upi_link ?>" class="upi-app-btn">
                                    <i class="fab fa-google-pay"></i> Google Pay
                                </a>
                                <a href="<?= $upi_link ?>" class="upi-app-btn">
                                    <i class="fas fa-wallet"></i> Paytm
                                </a>
                                <a href="<?= $upi_link ?>" class="upi-app-btn">
                                    <i class="fas fa-rupee-sign"></i> BHIM UPI
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="instructions">
                        <h4><i class="fas fa-list-ol"></i> Payment Instructions</h4><br>
                        <ol>
                            <li> Scan QR code or click on your UPI app above</li>
                            <li> Complete payment of <strong>₹<?= number_format($payment_details['amount'], 2) ?></strong></li>
                            <li> Copy the <strong>UPI Transaction ID</strong> from your app</li>
                            <li> Enter Transaction ID below and submit</li>
                            <li><strong> Payment will be verified within 2-3 hours</strong></li>
                        </ol>
                    </div>

                    <form method="POST" class="payment-form">
                        <h4><i class="fas fa-check-circle"></i> Confirm Payment</h4><br>

                        <div class="form-group">
                            <label for="upi_transaction_id">UPI Transaction ID *</label>
                            <input type="text" id="upi_transaction_id" name="upi_transaction_id" 
                                   placeholder="e.g., 123456789012" 
                                   required pattern="[0-9]{12}">
                            <small>12-digit ID from your UPI app</small>
                        </div>

                        <button type="submit" name="confirm_payment" class="pay-btn"
                                <i class="fas fa-check-circle"></i> Submit Payment
                        </button>
                    </form>

                    <a href="subscription_plans.php" class="back-btn" onclick="return confirmBack();">
                        <i class="fas fa-arrow-left"></i> Cancel & Go Back
                    </a>

                <?php else: ?>
                    <div class="error">No payment details found. Please start the payment process again.</div>
                    <a href="subscription_plans.php" class="back-btn">Back to Plans</a>
                <?php endif; ?>
            </div>
        </div>

        <script>
            // Generate QR Code
<?php if ($payment_details && $qr_code_data): ?>
                document.addEventListener('DOMContentLoaded', function () {
                    var qr = new QRious({
                        element: document.getElementById('qr-code'),
                        value: '<?= $qr_code_data ?>',
                        size: 200,
                        background: 'white',
                        foreground: '#234a23',
                        level: 'M'
                    });
                });
<?php endif; ?>

            // Form validation - only allow numbers for UPI transaction ID
            document.getElementById('upi_transaction_id').addEventListener('input', function (e) {
                this.value = this.value.replace(/\D/g, '').substring(0, 12);
            });

            // Confirm back navigation
            function confirmBack() {
                return confirm("Are you sure you want to go back?");
            }

            // Prevent accidental page refresh/close
            window.addEventListener('beforeunload', function (e) {
                var upiId = document.getElementById('upi_transaction_id').value.trim();
                if (upiId === "") {
                    e.preventDefault();
                    e.returnValue = 'Are you sure you want to leave? Your payment process will be cancelled.';
                }
            });

            // Auto-cleanup if user stays on page too long (15 minutes)
            setTimeout(function () {
                alert('Payment session expired. Redirecting to subscription page.');
                window.location.href = 'subscription_plans.php';
            }, 15 * 60 * 1000); // 15 minutes
        </script>
    </body>
</html>
