<?php
require_once 'auth/config.php';
// Add this subscription check at the top of your navigation file (after session_start and database connection)
$user_has_active_subscription = false;

if (isset($_SESSION['logged_in']) && !empty($_SESSION['user_id']) && 
    (isset($_SESSION['user_type']) && ($_SESSION['user_type'] == 'O' || $_SESSION['user_type'] == 'F'))) {
    
    // Check if user has active subscription
    $user_id = $_SESSION['user_id'];
    $subscription_check = $conn->prepare("SELECT Status FROM user_subscriptions WHERE user_id = ? AND Status = 'A' AND (end_date IS NULL OR end_date >= CURDATE()) LIMIT 1");
    $subscription_check->bind_param("i", $user_id);
    $subscription_check->execute();
    $result = $subscription_check->get_result();
    
    if ($result && $result->num_rows > 0) {
        $user_has_active_subscription = true;
    }
    
    $subscription_check->close();
}
?>

<nav class="navbar">
    <div class="container">
        <a href="index.php" class="logo">🚜 AgriRent</a>
        <button class="mobile-menu" id="mobile-menu">☰</button>
        <div class="nav-links" id="nav-links">
            <a href="index.php">Home</a>
            <a href="equipments.php">Equipment</a>
            <a href="products.php">Products</a>
            <a href="about.php">About</a>
            <a href="contact.php">Contact</a>
            <?php if (!empty($_SESSION['logged_in'])): ?>
                <div class="dropdown">
                    <button class="dropdown-toggle">
                        <div class="user-avatar">
                            <?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <div class="user-info">
                            <span class="username"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                            <span class="user-dropdown-arrow">▼</span>
                        </div>
                    </button>
                    <div class="dropdown-menu">
                        <a href="account.php">Account</a>

                        <?php if (isset($_SESSION['user_type']) && ($_SESSION['user_type'] == 'O' || $_SESSION['user_type'] == 'F')): ?>
                            <a href="subscription_plans.php">Subscription</a>
                            <a href='mybooking_orders.php'>My Booking/Orders</a>
                            
                        <?php endif; ?>

                        <?php 
                        // Show Dashboard only for Admins (always) or Users with active subscriptions
                        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'A'): ?>
                            <a href="admin/dashboard.php">Dashboard</a>
                        <?php elseif ($user_has_active_subscription): ?>
                            <a href="<?php
                            if ($_SESSION['user_type'] == 'F')
                                echo 'farmer/dashboard.php';
                            elseif ($_SESSION['user_type'] == 'O')
                                echo 'owner/dashboard.php';
                            ?>">Dashboard</a>
                        <?php endif; ?>

                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
        </div>
        <div id="google_translate_element"></div>
    </div>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const mobileMenuBtn = document.getElementById('mobile-menu');
        const navLinks = document.getElementById('nav-links');
        mobileMenuBtn.addEventListener('click', function () {
            navLinks.classList.toggle('show');
        });
    });
</script>
