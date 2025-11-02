<?php
session_start();
require_once('../auth/config.php');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header("Location: ../login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];


$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN Approval_status = 'CON' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN Approval_status = 'PEN' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN Approval_status = 'REJ' THEN 1 ELSE 0 END) as rejected
    FROM equipment
    WHERE Owner_id = ?
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$equipment_stats = $stmt->get_result()->fetch_assoc();
$total_equipment   = $equipment_stats['total'] ?? 0;
$approved_equipment = $equipment_stats['approved'] ?? 0;
$pending_equipment = $equipment_stats['pending'] ?? 0;
$rejected_equipment = $equipment_stats['rejected'] ?? 0;


// Equipment bookings earnings - Only CON status
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN eb.status = 'PEN' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN eb.status = 'CON' THEN 1 ELSE 0 END) as confirmed,
        COALESCE(SUM(CASE WHEN eb.status = 'CON' THEN eb.total_amount END),0) as booking_earnings
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    WHERE e.Owner_id = ?
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$booking_stats = $stmt->get_result()->fetch_assoc();
$total_bookings     = $booking_stats['total'] ?? 0;
$pending_bookings   = $booking_stats['pending'] ?? 0;
$confirmed_bookings = $booking_stats['confirmed'] ?? 0;
$booking_earnings   = $booking_stats['booking_earnings'] ?? 0;


// Total products and product orders earnings
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT p.product_id) as total_products,
        (SELECT COUNT(*) FROM product_orders po WHERE po.Product_id IN (SELECT product_id FROM product WHERE seller_id = ?)) as total_orders,
        COALESCE(SUM(CASE WHEN po.Status = 'COM' THEN po.total_price ELSE 0 END),0) as product_earnings
    FROM product p
    LEFT JOIN product_orders po ON p.product_id = po.Product_id
    WHERE p.seller_id = ?
");
$stmt->bind_param("ii", $owner_id, $owner_id);
$stmt->execute();
$product_stats = $stmt->get_result()->fetch_assoc();
$total_products = $product_stats['total_products'] ?? 0;
$total_product_orders = $product_stats['total_orders'] ?? 0;
$product_earnings = $product_stats['product_earnings'] ?? 0;

// Total earnings = Equipment bookings + Product orders
$total_earnings = $booking_earnings + $product_earnings;


// Recent booking requests - Last 5
$stmt = $conn->prepare("
    SELECT eb.booking_id, eb.start_date, eb.end_date, eb.total_amount, eb.status,
           e.Title as equipment_title, u.Name as customer_name
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    JOIN users u ON eb.customer_id = u.user_id
    WHERE e.Owner_id = ?
    ORDER BY eb.booking_id DESC
    LIMIT 5
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$recent_bookings = $stmt->get_result();


// Recent product orders - Last 5
$stmt = $conn->prepare("
    SELECT po.*, p.Name as product_name, u.Name as buyer_name, u.Phone as buyer_phone
    FROM product_orders po
    JOIN product p ON po.Product_id = p.product_id
    JOIN users u ON po.buyer_id = u.user_id
    WHERE p.seller_id = ?
    ORDER BY po.order_date DESC
    LIMIT 5
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$recent_orders = $stmt->get_result();

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<style>
    /* Override cards to display all 5 in one row */
    .cards {
        display: grid;
        grid-template-columns: repeat(5, 1fr) !important;
        gap: 10px !important;
    }
    
    .cards a {
        display: block;
    }
    
    .card {
        padding: 15px !important;
    }
    
    .card h3 {
        font-size: 13px !important;
        margin-bottom: 8px !important;
    }
    
    .card .count {
        font-size: 22px !important;
    }
</style>

<div class="main-content">
   
    <h1>Equipment Owner Dashboard</h1>
    <h2>Welcome <?= htmlspecialchars($_SESSION['user_name']); ?></h2>

    <!-- Statistics Cards - Clickable - All in one row -->
    <div class="cards">
        <a href="manage_equipment.php"  style="text-decoration: none;">
            <div class="card"><h3>Total Equipment</h3><div class="count"><?= $total_equipment; ?></div></div>
        </a>
        
        <a href="manage_products.php" style="text-decoration: none;">
            <div class="card"><h3>Total Products</h3><div class="count"><?= $total_products; ?></div></div>
        </a>
        
        <a href="equipment_bookings.php" style="text-decoration: none;">
            <div class="card"><h3>Total Bookings</h3><div class="count"><?= $total_bookings; ?></div></div>
        </a>
        
        <a href="product_orders.php" style="text-decoration: none;">
            <div class="card"><h3>Total Orders</h3><div class="count"><?= $total_product_orders; ?></div></div>
        </a>
        
        <a href="earnings_report.php"  style="text-decoration: none;">
            <div class="card"><h3>Total Earnings</h3><div class="count">₹<?= number_format($total_earnings, 2); ?></div></div>
        </a>
    </div>

    
    <h2>Recent Booking Requests</h2>
    <table>
        <thead>
            <tr>
                <th>Equipment</th>
                <th>Customer</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($recent_bookings->num_rows > 0): ?>
                <?php while($booking = $recent_bookings->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($booking['equipment_title']); ?></td>
                    <td><?= htmlspecialchars($booking['customer_name']); ?></td>
                    <td><?= date('M j, Y', strtotime($booking['start_date'])); ?></td>
                    <td><?= date('M j, Y', strtotime($booking['end_date'])); ?></td>
                    <td>₹<?= number_format($booking['total_amount'], 2); ?></td>
                    <td>
                        <?php
                        $status_map = [
                            'CON' => ['status-confirmed', 'Confirmed'],
                            'PEN' => ['status-pending', 'Pending'],
                            'REJ' => ['status-rejected', 'Rejected']
                        ];
                        [$cls,$txt] = $status_map[$booking['status']] ?? ['','Unknown'];
                        ?>
                        <span class="status-badge <?= $cls; ?>"><?= $txt; ?></span>
                    </td>
                    <td>
                        <a href="equipment_bookings.php">View</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:#666;">No booking requests yet</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    
    <br/>
    <h2>Recent Product Orders</h2>
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Buyer</th>
                <th>Quantity</th>
                <th>Total Price</th>
                <th>Order Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($recent_orders->num_rows > 0): ?>
                <?php while($order = $recent_orders->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($order['product_name']); ?></td>
                    <td><?= htmlspecialchars($order['buyer_name']); ?></td>
                    <td><?= number_format($order['quantity'], 2); ?></td>
                    <td>₹<?= number_format($order['total_price'], 2); ?></td>
                    <td><?= date('M j, Y', strtotime($order['order_date'])); ?></td>
                    <td>
                        <?php
                        $status_map = [
                            'PEN' => ['status-pending', 'Pending'],
                            'CON' => ['status-confirmed', 'Confirmed'],
                            'COM' => ['status-confirmed', 'Completed'],
                            'REJ' => ['status-rejected', 'Rejected']
                        ];
                        [$cls,$txt] = $status_map[$order['Status']] ?? ['','Unknown'];
                        ?>
                        <span class="status-badge <?= $cls; ?>"><?= $txt; ?></span>
                    </td>
                    <td>
                        <a href="product_orders.php">View</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:#666;">No product orders yet</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    
    
</div>


<?php 
require 'ofooter.php';

?>
