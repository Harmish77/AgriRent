<?php
$user_has_active_subscription = false;

if (isset($_SESSION['logged_in']) && !empty($_SESSION['user_id']) && 
    (isset($_SESSION['user_type']) && ($_SESSION['user_type'] == 'O' || $_SESSION['user_type'] == 'F'))) {
    
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

if (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'F' && !$user_has_active_subscription) {
    header('Location: ../index.php');
    exit();
}
?>


<div class="sidebar">
    <a href="../index.php" class="logo" style="display: flex; align-items: center; gap: 12px; text-decoration: none;">
        <img src="../Logo.png" alt="AgriRent Logo" style="height: 50px; width: 50px; background: white; border-radius: 50%; padding: 5px; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
        <span style="font-size: 24px; font-weight: bold; color: #ffffff; letter-spacing: 1px; font-family: Arial, sans-serif;">AgriRent</span>
    </a>
    </br></br> 
    <div id="google_translate_element"></div>
    <a href="../index.php" >Home </a>
    
    <a href="../owner/dashboard.php" >Dashboard </a>
    
    <a href="manage_equipment.php">Manage Equipment </a>
    
    <a href="equipment_bookings.php">Equipment Bookings </a>
    
    <a href="manage_products.php">Manage Product </a>
    
    <a href="product_orders.php">Product Orders </a>
    
    <a href="earnings_report.php">Earnings Report </a>
    
    <a href="subscription.php">Subscription </a>
    
    <a href="add_complaints.php"> File complaint</a>
    
    <a href="reviews.php">Reviews </a>
 
    <a href="../logout.php" >Logout </a>
</div>
