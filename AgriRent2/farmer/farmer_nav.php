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
    
    </br></br>  
    <div id="google_translate_element"></div>
    
     <a href="../index.php">Home </a>
    
     <a href="dashboard.php" class="active"> Dashboard</a>
    
    <a href="manage_products.php">Manage Products </a>
    
    <a href="equipment_bookings.php"> My Bookings</a>
    
    <a href="product_orders.php"> Product Orders</a>
    
    <a href="subscription.php"> Subscription</a>
    
    <a href="complaints.php"> Complaints</a>
    
    <a href="reviews.php"> Reviews </a>
    
    
    <a href="../logout.php" > Logout </a>
</div>
