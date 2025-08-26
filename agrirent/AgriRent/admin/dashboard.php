<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$users = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$admins = $conn->query("SELECT COUNT(*) as c FROM users WHERE User_type = 'A'")->fetch_assoc()['c'];
$owners = $conn->query("SELECT COUNT(*) as c FROM users WHERE User_type = 'O'")->fetch_assoc()['c'];
$farmers = $conn->query("SELECT COUNT(*) as c FROM users WHERE User_type = 'F'")->fetch_assoc()['c'];
$equipment = $conn->query("SELECT COUNT(*) as c FROM equipment WHERE Approval_status = 'CON'")->fetch_assoc()['c'];
$products = $conn->query("SELECT COUNT(*) as c FROM product WHERE Approval_status = 'CON'")->fetch_assoc()['c'];
$bookings = $conn->query("SELECT COUNT(*) as c FROM equipment_bookings ")->fetch_assoc()['c'];
$orders = $conn->query("SELECT COUNT(*) as c FROM product_orders")->fetch_assoc()['c'];
$subscriptions = $conn->query("SELECT COUNT(*) as c FROM user_subscriptions")->fetch_assoc()['c'];
$complaints = $conn->query("SELECT COUNT(*) as c FROM complaints")->fetch_assoc()['c'];
$reviews = $conn->query("SELECT COUNT(*) as c FROM reviews")->fetch_assoc()['c'];

$pending_equipment = $conn->query("SELECT COUNT(*) as c FROM equipment WHERE Approval_status = 'PEN'")->fetch_assoc()['c'];
$pending_products = $conn->query("SELECT COUNT(*) as c FROM product WHERE Approval_status = 'PEN'")->fetch_assoc()['c'];
$expired_subs = $conn->query("SELECT COUNT(*) as c FROM user_subscriptions WHERE Status = 'E'")->fetch_assoc()['c'];
$open_complaints = $conn->query("SELECT COUNT(*) as c FROM complaints WHERE Status = 'O'")->fetch_assoc()['c'];

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Dashboard</h1>
    <h2>Welcome <?= $_SESSION['user_name']?></h2>
    
    <div class="cards">
        <div class="card" onclick="window.location.href='users.php'">
            <h3>Total Users</h3>
            <div class="count"><?= $users ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='users.php?filter=A'">
            <h3>Admins</h3>
            <div class="count"><?= $admins ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='users.php?filter=O'">
            <h3>Equipment Owners</h3>
            <div class="count"><?= $owners ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='users.php?filter=F'">
            <h3>Farmers</h3>
            <div class="count"><?= $farmers ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='equipment.php'">
            <h3>Equipment</h3>
            <div class="count"><?= $equipment ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='products.php'">
            <h3>Products</h3>
            <div class="count"><?= $products ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='bookings.php'">
            <h3>Bookings</h3>
            <div class="count"><?= $bookings ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='orders.php'">
            <h3>Orders</h3>
            <div class="count"><?= $orders ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='subscriptions.php'">
            <h3>Subscriptions</h3>
            <div class="count"><?= $subscriptions ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='complaints.php'">
            <h3>Complaints</h3>
            <div class="count"><?= $complaints ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='reviews.php'">
            <h3>Reviews</h3>
            <div class="count"><?= $reviews ?></div>
        </div>
        
        
    </div>
    
    
    <div class="cards">
        <div class="card" onclick="window.location.href='equipment.php?status=PEN'">
            <h3>Equipment Pending</h3>
            <div class="count"><?= $pending_equipment ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='products.php?status=PEN'">
            <h3>Products Pending</h3>
            <div class="count"><?= $pending_products ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='subscriptions.php?status=E'">
            <h3>Subscriptions Expired</h3>
            <div class="count"><?= $expired_subs ?></div>
        </div>
        
        <div class="card" onclick="window.location.href='complaints.php?status=O'">
            <h3>Complaints Pending</h3>
            <div class="count"><?= $open_complaints ?></div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
