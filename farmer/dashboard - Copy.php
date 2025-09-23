<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header("Location: ../login.php");
    exit();
}

// Get farmer ID from session
$farmer_id = $_SESSION['user_id'];

// Product statistics for farmer
$total_products = $conn->query("SELECT COUNT(*) as c FROM product WHERE seller_id = $farmer_id")->fetch_assoc()['c'];
$approved_products = $conn->query("SELECT COUNT(*) as c FROM product WHERE seller_id = $farmer_id AND Approval_status = 'CON'")->fetch_assoc()['c'];
$pending_products = $conn->query("SELECT COUNT(*) as c FROM product WHERE seller_id = $farmer_id AND Approval_status = 'PEN'")->fetch_assoc()['c'];
$rejected_products = $conn->query("SELECT COUNT(*) as c FROM product WHERE seller_id = $farmer_id AND Approval_status = 'REJ'")->fetch_assoc()['c'];

// Equipment booking statistics
$total_bookings = $conn->query("SELECT COUNT(*) as c FROM equipment_bookings WHERE customer_id = $farmer_id")->fetch_assoc()['c'];
$pending_bookings = $conn->query("SELECT COUNT(*) as c FROM equipment_bookings WHERE customer_id = $farmer_id AND status = 'PEN'")->fetch_assoc()['c'];
$confirmed_bookings = $conn->query("SELECT COUNT(*) as c FROM equipment_bookings WHERE customer_id = $farmer_id AND status = 'CON'")->fetch_assoc()['c'];

// Product orders received
$product_orders = $conn->query("SELECT COUNT(*) as c FROM product_orders po 
                               JOIN product p ON po.Product_id = p.product_id 
                               WHERE p.seller_id = $farmer_id")->fetch_assoc()['c'];
$pending_orders = $conn->query("SELECT COUNT(*) as c FROM product_orders po 
                               JOIN product p ON po.Product_id = p.product_id 
                               WHERE p.seller_id = $farmer_id AND po.Status = 'PEN'")->fetch_assoc()['c'];

// Earnings calculation from product sales
$total_earnings = $conn->query("SELECT COALESCE(SUM(po.total_price), 0) as earnings 
                               FROM product_orders po 
                               JOIN product p ON po.Product_id = p.product_id 
                               WHERE p.seller_id = $farmer_id AND po.Status = 'CON'")->fetch_assoc()['earnings'];

// Messages count
$unread_messages = $conn->query("SELECT COUNT(*) as c FROM messages WHERE receiver_id = $farmer_id AND is_read = FALSE")->fetch_assoc()['c'];

// Subscription status
$active_subscription = $conn->query("SELECT COUNT(*) as c FROM user_subscriptions WHERE user_id = $farmer_id AND Status = 'A'")->fetch_assoc()['c'];

// Recent product listings
$recent_products = $conn->query("SELECT product_id, Name, Price, Quantity, Unit, Approval_status, listed_date 
                                FROM product 
                                WHERE seller_id = $farmer_id 
                                ORDER BY listed_date DESC 
                                LIMIT 5");

// Recent equipment bookings
$recent_bookings = $conn->query("SELECT eb.booking_id, eb.start_date, eb.end_date, eb.total_amount, eb.status,
                                        e.Title as equipment_title, u.Name as owner_name
                                FROM equipment_bookings eb
                                JOIN equipment e ON eb.equipment_id = e.Equipment_id
                                JOIN users u ON e.Owner_id = u.user_id
                                WHERE eb.customer_id = $farmer_id
                                ORDER BY eb.booking_id DESC
                                LIMIT 5");


$recent_orders = $conn->query("SELECT po.Order_id, po.quantity, po.total_price, po.Status, po.order_date,
                                      p.Name as product_name, u.Name as buyer_name
                              FROM product_orders po
                              JOIN product p ON po.Product_id = p.product_id
                              JOIN users u ON po.buyer_id = u.user_id
                              WHERE p.seller_id = $farmer_id
                              ORDER BY po.order_date DESC
                              LIMIT 5");

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../assets/css/admin.css">

<div class="main-content">
   
    <h1>Farmer Dashboard</h1>
    <h2>Welcome <?= $_SESSION['user_name']?></h2>

    <!-- Statistics Cards -->
    <div class="cards">
        <div class="card">
            <h3>My Products</h3>
            <div class="count"><?php echo $total_products; ?></div>
        </div>
        
        <div class="card">
            <h3>Approved Products</h3>
            <div class="count"><?php echo $approved_products; ?></div>
        </div>
        
        <div class="card">
            <h3>Equipment Bookings</h3>
            <div class="count"><?php echo $total_bookings; ?></div>
        </div>
        
        <div class="card">
            <h3>Product Orders</h3>
            <div class="count"><?php echo $product_orders; ?></div>
        </div>
        
        <div class="card">
            <h3>Total Earnings</h3>
            <div class="count">₹<?php echo number_format($total_earnings, 2); ?></div>
        </div>
        
        <div class="card">
            <h3>Messages</h3>
            <div class="count"><?php echo $unread_messages; ?></div>
        </div>
    </div>

   </br></br>

   
    
    <h2>Recent Product Orders Received</h2>
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Buyer</th>
                <th>Quantity</th>
                <th>Total Price</th>
                <th>Status</th>
                <th>Order Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($recent_orders->num_rows > 0): ?>
                <?php while($order = $recent_orders->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($order['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                    <td><?php echo $order['quantity']; ?></td>
                    <td>₹<?php echo number_format($order['total_price'], 2); ?></td>
                    <td>
                        <?php 
                        $status_class = '';
                        $status_text = '';
                        switch($order['Status']) {
                            case 'CON':
                                $status_class = 'status-confirmed';
                                $status_text = 'Confirmed';
                                break;
                            case 'PEN':
                                $status_class = 'status-pending';
                                $status_text = 'Pending';
                                break;
                            case 'CAN':
                                $status_class = 'status-rejected';
                                $status_text = 'Cancelled';
                                break;
                        }
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </td>
                    <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                    <td>
                        <a href="order_details.php?id=<?php echo $order['Order_id']; ?>">Details</a>
                        <?php if($order['Status'] == 'PEN'): ?>
                        | <a href="approve_order.php?id=<?php echo $order['Order_id']; ?>&action=approve" onclick="return confirm('Approve this order?')">Approve</a>
                        | <a href="approve_order.php?id=<?php echo $order['Order_id']; ?>&action=reject" onclick="return confirm('Reject this order?')">Reject</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #666;">No product orders received yet</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
 
    </br></br>
  
      
    <h2>Recent Equipment Bookings</h2>
    <table>
        <thead>
            <tr>
                <th>Equipment</th>
                <th>Owner</th>
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
                    <td><?php echo htmlspecialchars($booking['equipment_title']); ?></td>
                    <td><?php echo htmlspecialchars($booking['owner_name']); ?></td>
                    <td><?php echo date('M j, Y', strtotime($booking['start_date'])); ?></td>
                    <td><?php echo date('M j, Y', strtotime($booking['end_date'])); ?></td>
                    <td>₹<?php echo number_format($booking['total_amount'], 2); ?></td>
                    <td>
                        <?php 
                        $status_class = '';
                        $status_text = '';
                        switch($booking['status']) {
                            case 'CON':
                                $status_class = 'status-confirmed';
                                $status_text = 'Confirmed';
                                break;
                            case 'PEN':
                                $status_class = 'status-pending';
                                $status_text = 'Pending';
                                break;
                            case 'REJ':
                                $status_class = 'status-rejected';
                                $status_text = 'Rejected';
                                break;
                        }
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </td>
                    <td>
                        <a href="booking_details.php?id=<?php echo $booking['booking_id']; ?>">Details</a>
                        <?php if($booking['status'] == 'PEN'): ?>
                        | <a href="cancel_booking.php?id=<?php echo $booking['booking_id']; ?>" onclick="return confirm('Are you sure?')">Cancel</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #666;">No equipment bookings yet</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</be></br>
    <h2>Recent Product Listings</h2>
    <table>
        <thead>
            <tr>
                <th>Product Name</th>
                <th>Price</th>
                <th>Quantity</th>
                <th>Unit</th>
                <th>Status</th>
                <th>Listed Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($recent_products->num_rows > 0): ?>
                <?php while($product = $recent_products->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($product['Name']); ?></td>
                    <td>₹<?php echo number_format($product['Price'], 2); ?></td>
                    <td><?php echo $product['Quantity']; ?></td>
                    <td><?php echo ($product['Unit'] == 'K') ? 'kg' : 'liter'; ?></td>
                    <td>
                        <?php 
                        $status_class = '';
                        $status_text = '';
                        switch($product['Approval_status']) {
                            case 'CON':
                                $status_class = 'status-confirmed';
                                $status_text = 'Approved';
                                break;
                            case 'PEN':
                                $status_class = 'status-pending';
                                $status_text = 'Pending';
                                break;
                            case 'REJ':
                                $status_class = 'status-rejected';
                                $status_text = 'Rejected';
                                break;
                        }
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </td>
                    <td><?php echo date('M j, Y', strtotime($product['listed_date'])); ?></td>
                    <td>
                        <a href="edit_product.php?id=<?php echo $product['product_id']; ?>">Edit</a> |
                        <a href="view_product.php?id=<?php echo $product['product_id']; ?>">View</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #666;">No products listed yet</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
 </br></br>
  
  
   
    <div class="report-sections">
        <div class="report-section">
            <h3>Product Status Summary</h3>
            <div class="status-list">
                <div>✅ Approved Products: <strong><?php echo $approved_products; ?></strong></div>
                <div>⏳ Pending Products: <strong><?php echo $pending_products; ?></strong></div>
                <div>❌ Rejected Products: <strong><?php echo $rejected_products; ?></strong></div>
            </div>
        </div>
        
        <div class="report-section">
            <h3>Equipment Booking Summary</h3>
            <div class="status-list">
                <div>📅 Total Bookings: <strong><?php echo $total_bookings; ?></strong></div>
                <div>⏳ Pending: <strong><?php echo $pending_bookings; ?></strong></div>
                <div>✅ Confirmed: <strong><?php echo $confirmed_bookings; ?></strong></div>
            </div>
        </div>
        
        <div class="report-section">
            <h3>Orders Summary</h3>
            <div class="status-list">
                <div>📦 Total Orders: <strong><?php echo $product_orders; ?></strong></div>
                <div>⏳ Pending Orders: <strong><?php echo $pending_orders; ?></strong></div>
                <div>💰 Total Earnings: <strong>₹<?php echo number_format($total_earnings, 2); ?></strong></div>
            </div>
        </div>
        
        <div class="report-section">
            <h3>Account Status</h3>
            <div class="status-list">
                <div>📋 Subscription: <strong><?php echo $active_subscription > 0 ? 'Active' : 'Inactive'; ?></strong></div>
                <div>💬 Unread Messages: <strong><?php echo $unread_messages; ?></strong></div>
                <?php if($active_subscription == 0): ?>
                <div style="color: #e74c3c; font-weight: bold;">⚠️ Subscribe to access all features</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require 'ffooter.php';?>
