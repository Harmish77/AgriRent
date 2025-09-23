<?php
session_start();
require_once('auth/config.php');

$message = '';
$error = '';

// Handle subscription purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_plan'])) {
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $user_type = $_SESSION['user_type'];
    $plan_id = intval($_POST['plan_id']);

    try {
        // Get plan details
        $plan_stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE plan_id = ? AND user_type = ?");
        $plan_stmt->bind_param("is", $plan_id, $user_type);
        $plan_stmt->execute();
        $selected_plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();

        if (!$selected_plan) {
            $error = "Invalid plan selected for your account type.";
        } else {
            // Check existing subscription
            $existing_stmt = $conn->prepare("SELECT * FROM user_subscriptions WHERE user_id = ? AND Status = 'A' AND end_date > CURDATE()");
            $existing_stmt->bind_param("i", $user_id);
            $existing_stmt->execute();
            $existing_sub = $existing_stmt->get_result()->fetch_assoc();
            $existing_stmt->close();

            if ($existing_sub) {
                $error = "You already have active dashboard access.";
            } else {
                // Activate subscription
                $end_date = ($selected_plan['Plan_type'] == 'M') ?
                        date('Y-m-d', strtotime('+1 month')) :
                        date('Y-m-d', strtotime('+1 year'));

                // Create tables if they don't exist
                $conn->query("CREATE TABLE IF NOT EXISTS user_subscriptions (
                    subscription_id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    plan_id INT NOT NULL,
                    start_date DATE NOT NULL,
                    end_date DATE NOT NULL,
                    Status CHAR(1) DEFAULT 'A' CHECK (Status IN ('A', 'E', 'C')),
                    FOREIGN KEY (user_id) REFERENCES users(user_id),
                    FOREIGN KEY (plan_id) REFERENCES subscription_plans(plan_id)
                )");

                // Insert subscription
                $sub_stmt = $conn->prepare("INSERT INTO user_subscriptions (user_id, plan_id, start_date, end_date, Status) VALUES (?, ?, CURDATE(), ?, 'A')");
                $sub_stmt->bind_param("iis", $user_id, $plan_id, $end_date);

                if ($sub_stmt->execute()) {
                    $message = "🎉 Dashboard access activated! You can now access your dashboard and start listing unlimited " .
                            ($user_type == 'F' ? 'products' : ($user_type == 'S' ? 'products' : 'equipment')) . ".";

                    // Redirect to appropriate dashboard
                    $dashboard_url = ($user_type == 'F') ? 'farmer/dashboard.php' :
                            (($user_type == 'S') ? 'seller/dashboard.php' : 'owner/dashboard.php');
                    echo "<script>setTimeout(function(){ window.location.href = '$dashboard_url'; }, 3000);</script>";
                } else {
                    $error = "Failed to activate subscription. Please try again.";
                }
                $sub_stmt->close();
            }
        }
    } catch (Exception $e) {
        error_log("Subscription error: " . $e->getMessage());
        $error = "System error. Please try again later.";
    }
}

// Get subscription plans for current user type
$plans = [];
if (isset($_SESSION['user_type'])) {
    try {
        $plans_stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE user_type = ? ORDER BY price ASC");
        $plans_stmt->bind_param("s", $_SESSION['user_type']);
        $plans_stmt->execute();
        $plans_result = $plans_stmt->get_result();
        while ($plan = $plans_result->fetch_assoc()) {
            $plans[] = $plan;
        }
        $plans_stmt->close();
    } catch (Exception $e) {
        error_log("Plans fetch error: " . $e->getMessage());

        // Create subscription_plans table if it doesn't exist
        $conn->query("CREATE TABLE IF NOT EXISTS subscription_plans (
            plan_id INT PRIMARY KEY AUTO_INCREMENT,
            Plan_name VARCHAR(50) NOT NULL,
            Plan_type CHAR(1) NOT NULL CHECK (Plan_type IN ('M', 'Y')),
            user_type CHAR(1) NOT NULL CHECK (user_type IN ('F', 'O', 'S')),
            price DECIMAL(10,2) NOT NULL
        )");

        // Insert default plans
        $conn->query("INSERT IGNORE INTO subscription_plans (Plan_name, Plan_type, user_type, price) VALUES
            ('Farmer Monthly Access', 'M', 'F', 299.00),
            ('Farmer Yearly Access', 'Y', 'F', 2990.00),
            ('Seller Monthly Access', 'M', 'S', 299.00),
            ('Seller Yearly Access', 'Y', 'S', 2990.00),
            ('Owner Monthly Access', 'M', 'O', 499.00),
            ('Owner Yearly Access', 'Y', 'O', 4990.00)");

        // Retry fetching plans
        $plans_stmt = $conn->prepare("SELECT * FROM subscription_plans WHERE user_type = ? ORDER BY price ASC");
        $plans_stmt->bind_param("s", $_SESSION['user_type']);
        $plans_stmt->execute();
        $plans_result = $plans_stmt->get_result();
        while ($plan = $plans_result->fetch_assoc()) {
            $plans[] = $plan;
        }
        $plans_stmt->close();
    }
}

// Get current subscription
$current_subscription = null;
if (isset($_SESSION['user_id'])) {
    try {
        $current_stmt = $conn->prepare("SELECT us.*, sp.Plan_name, sp.price, sp.Plan_type FROM user_subscriptions us 
                                       JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
                                       WHERE us.user_id = ? AND us.Status = 'A' AND us.end_date > CURDATE()");
        $current_stmt->bind_param("i", $_SESSION['user_id']);
        $current_stmt->execute();
        $current_subscription = $current_stmt->get_result()->fetch_assoc();
        $current_stmt->close();
    } catch (Exception $e) {
        // Table might not exist yet
    }
}

// Determine user type names and service names
$user_type_name = 'User';
$service_name = 'items';
$user_color = '#7cb342';

if (isset($_SESSION['user_type'])) {
    switch ($_SESSION['user_type']) {
        case 'F':
            $user_type_name = 'Farmer';
            $service_name = 'products';
            $user_color = '#7cb342';
            break;
        case 'S':
            $user_type_name = 'Seller';
            $service_name = 'products';
            $user_color = '#7cb342';
            break;
        case 'O':
            $user_type_name = 'Equipment Owner';
            $service_name = 'equipment';
            $user_color = '#2d4a22';
            break;
    }
}

include 'includes/header.php';
include 'includes/navigation.php';
?>

<link rel="stylesheet" href="css/main.css">
<style>
    /* Subscription Page Specific Styles */
    .subscription-hero {
        background: linear-gradient(135deg, rgba(45, 74, 34, 0.9), rgba(122, 179, 66, 0.9)),
            url('images/farm1.jpg') center/cover;
        color: white;
        padding: 100px 0;
        text-align: center;
        position: relative;
    }

    .subscription-hero h1 {
        font-size: 48px;
        margin-bottom: 20px;
        text-shadow: 2px 2px 8px rgba(0,0,0,0.8);
    }

    .subscription-hero p {
        font-size: 20px;
        margin-bottom: 30px;
        text-shadow: 1px 1px 4px rgba(0,0,0,0.8);
    }

    .user-badge {
        background: rgba(255,255,255,0.2);
        backdrop-filter: blur(10px);
        padding: 12px 24px;
        border-radius: 30px;
        font-size: 18px;
        display: inline-block;
        border: 1px solid rgba(255,255,255,0.3);
    }

    .subscription-container {
        max-width: 1200px;
        margin: -60px auto 0;
        padding: 0 20px;
        position: relative;
        z-index: 3;
    }

    .alert-message {
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 30px;
        font-size: 16px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .alert-success {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        color: #155724;
        border-left: 5px solid #28a745;
    }

    .alert-error {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
        color: #721c24;
        border-left: 5px solid #dc3545;
    }

    .access-status-card {
        background: white;
        border-radius: 15px;
        padding: 40px;
        margin-bottom: 40px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        text-align: center;
    }

    .access-granted {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        border: 3px solid #28a745;
    }

    .access-needed {
        background: linear-gradient(135deg, #fff3cd, #ffeeba);
        border: 3px solid #ffc107;
    }

    .access-status-icon {
        font-size: 80px;
        margin-bottom: 20px;
    }

    .access-status-title {
        color: #2d4a22;
        font-size: 32px;
        margin-bottom: 15px;
        font-weight: 700;
    }

    .access-status-text {
        font-size: 18px;
        color: #666;
        margin-bottom: 30px;
    }

    .subscription-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid rgba(0,0,0,0.1);
    }

    .subscription-info .details {
        text-align: left;
    }

    .subscription-info .details p {
        margin: 5px 0;
        font-size: 16px;
    }

    .dashboard-btn {
        background: linear-gradient(135deg, #7cb342, #6aa136);
        color: white;
        padding: 15px 30px;
        border: none;
        border-radius: 25px;
        font-size: 18px;
        font-weight: 600;
        text-decoration: none;
        display: inline-block;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(124, 179, 66, 0.3);
    }

    .dashboard-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(124, 179, 66, 0.4);
        color: white;
    }

    .plans-section-title {
        text-align: center;
        margin: 60px 0 40px;
        color: white;
        font-size: 42px;
        font-weight: 700;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    }

    .plans-grid {
        display: flex;
        justify-content: center;
        gap: 30px;
        margin: 40px 0;
        flex-wrap: wrap;
    }

    .plan-card {
        background: white;
        border-radius: 20px;
        padding: 40px 30px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        transition: all 0.4s ease;
        border: 3px solid transparent;
        text-align: center;
        width: 350px;
        position: relative;
        overflow: hidden;
    }

    .plan-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    }

    .plan-card.featured {
        border-color: #7cb342;
        transform: scale(1.05);
    }

    .plan-card.featured::after {
        content: "💰 Best Value";
        position: absolute;
        top: 0;
        right: 0;
        background: linear-gradient(45deg, #7cb342, #6aa136);
        color: white;
        padding: 8px 15px;
        font-size: 14px;
        font-weight: bold;
        transform: translateX(35%) translateY(25%) rotate(45deg);
        width: 180px;
        text-align: center;
        box-shadow: 0 4px 15px rgba(124,179,66,0.4);
    }

    .plan-title {
        color: #2d4a22;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 20px;
    }

    .plan-price {
        font-size: 48px;
        font-weight: 800;
        color: #7cb342;
        margin: 20px 0;
        line-height: 1;
    }

    .plan-period {
        color: #666;
        font-size: 18px;
        margin-bottom: 20px;
    }

    .savings-badge {
        background: linear-gradient(45deg, #28a745, #20c997);
        color: white;
        padding: 6px 15px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 20px;
        display: inline-block;
    }

    .plan-features {
        margin: 30px 0;
    }

    .feature-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .feature-item:last-child {
        border-bottom: none;
    }

    .feature-icon {
        color: #7cb342;
        font-size: 20px;
        width: 24px;
        text-align: center;
    }

    .feature-text {
        flex-grow: 1;
        text-align: left;
        font-size: 16px;
        color: #333;
    }

    .plan-btn {
        width: 100%;
        padding: 18px 0;
        border: none;
        border-radius: 30px;
        font-size: 18px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-top: 20px;
    }

    .plan-btn.primary {
        background: linear-gradient(135deg, #7cb342, #6aa136);
        color: white;
    }

    .plan-btn.primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(124, 179, 66, 0.4);
    }

    .plan-btn.disabled {
        background: #6c757d;
        color: white;
        cursor: not-allowed;
    }

    .benefits-section {
        background: white;
        border-radius: 20px;
        padding: 60px 40px;
        margin: 60px 0;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }

    .benefits-title {
        text-align: center;
        font-size: 36px;
        font-weight: 700;
        color: #2d4a22;
        margin-bottom: 40px;
    }

    .benefits-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 30px;
        margin-top: 30px;
    }

    .benefit-card {
        text-align: center;
        padding: 30px 20px;
        background: rgba(124, 179, 66, 0.05);
        border-radius: 15px;
        border: 2px solid rgba(124, 179, 66, 0.1);
        transition: transform 0.3s ease;
    }

    .benefit-card:hover {
        transform: translateY(-5px);
    }

    .benefit-icon {
        font-size: 60px;
        margin-bottom: 20px;
    }

    .benefit-title {
        color: #2d4a22;
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 15px;
    }

    .guarantee-section {
        text-align: center;
        background: linear-gradient(135deg, #7cb342, #6aa136);
        color: white;
        padding: 50px 40px;
        border-radius: 20px;
        margin: 60px 0;
        box-shadow: 0 10px 30px rgba(124, 179, 66, 0.3);
    }

    .guarantee-title {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 20px;
    }

    .guarantee-text {
        font-size: 18px;
        opacity: 0.9;
    }

    @media (max-width: 768px) {
        .subscription-hero h1 {
            font-size: 32px;
        }

        .subscription-hero p {
            font-size: 16px;
        }

        .plans-grid {
            flex-direction: column;
            align-items: center;
        }

        .plan-card.featured {
            transform: none;
        }

        .plan-price {
            font-size: 40px;
        }

        .subscription-info {
            flex-direction: column;
            text-align: center;
        }

        .subscription-info .details {
            text-align: center;
            margin-bottom: 20px;
        }
    }
</style>

<!-- Hero Section -->
<section class="subscription-hero">



    <!-- Subscription Plans -->
    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] && !$current_subscription && !empty($plans)): ?>
        <h2 class="plans-section-title">Choose Your Access Plan</h2>
        <p style="text-align: center; color: rgba(255,255,255,0.9); font-size: 18px; margin-bottom: 40px;">
            Simple pricing, unlimited <?= $service_name ?> listings
        </p>

        <div class="plans-grid">
            <?php
            foreach ($plans as $index => $plan):
                $is_yearly = $plan['Plan_type'] == 'Y';
                $monthly_equivalent = $is_yearly ? $plan['price'] / 12 : $plan['price'];
                $is_featured = $is_yearly;
                ?>
                <div class="plan-card <?= $is_featured ? 'featured' : '' ?>">
                    <h3 class="plan-title">
        <?= $is_yearly ? 'Yearly Access' : 'Monthly Access' ?>
                    </h3>

        <?php if ($is_yearly): ?>
                        <div class="savings-badge">
                            💰 Save ₹<?= number_format(($monthly_equivalent * 14) - $plan['price'], 0) ?>
                        </div>
        <?php endif; ?>

                    <div class="plan-price">
                        ₹<?= number_format($plan['price'], 0) ?>
                    </div>
                    <div class="plan-period">
                        <?= $is_yearly ? 'per year' : 'per month' ?>
        <?php if ($is_yearly): ?>
                            <br><span style="color: #7cb342; font-weight: 600;">
                                (₹<?= number_format($monthly_equivalent, 0) ?>/month)
                            </span>
        <?php endif; ?>
                    </div>

                    <div class="plan-features">
                        <div class="feature-item">
                            <span class="feature-icon">∞</span>
                            <div class="feature-text">
                                <strong>Unlimited Listings</strong><br>
                                <small>No restrictions on <?= $service_name ?></small>
                            </div>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">📊</span>
                            <div class="feature-text">
                                <strong>Full Dashboard Access</strong><br>
                                <small>Complete management interface</small>
                            </div>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon"><?= $_SESSION['user_type'] == 'O' ? '🚜' : '🌾' ?></span>
                            <div class="feature-text">
                                <strong><?= $_SESSION['user_type'] == 'O' ? 'Equipment' : 'Product' ?> Management</strong><br>
                                <small>Add, edit, and organize listings</small>
                            </div>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">💬</span>
                            <div class="feature-text">
                                <strong>Customer Messages</strong><br>
                                <small>Direct communication tools</small>
                            </div>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon"><?= $_SESSION['user_type'] == 'O' ? '📅' : '📦' ?></span>
                            <div class="feature-text">
                                <strong><?= $_SESSION['user_type'] == 'O' ? 'Booking' : 'Order' ?> Management</strong><br>
                                <small>Track and manage <?= $_SESSION['user_type'] == 'O' ? 'bookings' : 'orders' ?></small>
                            </div>
                        </div>
                        <div class="feature-item">
                            <span class="feature-icon">🎧</span>
                            <div class="feature-text">
                                <strong>24/7 Support</strong><br>
                                <small>Always here to help</small>
                            </div>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="plan_id" value="<?= $plan['plan_id'] ?>">
                        <button type="submit" name="subscribe_plan" class="plan-btn primary">
        <?= $is_yearly ? '🎯 Get Yearly Access' : '🚀 Get Monthly Access' ?>
                        </button>
                    </form>
                </div>
        <?php endforeach; ?>
        </div>
<?php endif; ?>

</section>


<script>
// Add loading state to subscription buttons
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function (e) {
            const button = this.querySelector('button[type="submit"]');
            if (button && !button.disabled) {
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                button.disabled = true;

                // Re-enable after 10 seconds if no redirect
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 10000);
            }
        });
    });

// Auto-hide success alerts
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.opacity = '0';
            successAlert.style.transform = 'translateY(-20px)';
        }, 5000);
    }

// Auto-hide error alerts
    const errorAlert = document.querySelector('.alert-error');
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.opacity = '0';
            errorAlert.style.transform = 'translateY(-20px)';
            setTimeout(() => errorAlert.remove(), 500);
        }, 5000);
    }
</script>

<?php include 'includes/footer.php'; ?>
