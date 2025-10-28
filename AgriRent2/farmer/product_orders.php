<?php
session_start();
require_once '../auth/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle status updates with quantity reduction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $order_id = (int)$_POST['order_id'];
    $action = $_POST['action'];
    
    $new_status = '';
    switch ($action) {
        case 'confirm':
            $new_status = 'CON';
            break;
        case 'reject':
            $new_status = 'REJ';
            break;
        case 'complete':
            $new_status = 'COM';
            break;
        case 'cancel':
            $new_status = 'CAN';
            break;
    }
    
    if ($new_status) {
        // Start transaction for data consistency
        $conn->begin_transaction();
        
        try {
            // Get current order details with product information
            $order_query = "SELECT po.*, p.Quantity as current_stock, p.seller_id, p.Name as product_name
                           FROM product_orders po 
                           JOIN product p ON po.Product_id = p.product_id 
                           WHERE po.Order_id = ? AND p.seller_id = ?";
            
            $order_stmt = $conn->prepare($order_query);
            if (!$order_stmt) {
                throw new Exception("Database preparation failed");
            }
            
            $order_stmt->bind_param("ii", $order_id, $owner_id);
            $order_stmt->execute();
            $order_data = $order_stmt->get_result()->fetch_assoc();
            $order_stmt->close();
            
            if (!$order_data) {
                throw new Exception("Order not found or you don't have permission to modify it");
            }
            
            $current_status = $order_data['Status'];
            $order_quantity = $order_data['quantity'];
            $current_stock = $order_data['current_stock'];
            $product_id = $order_data['Product_id'];
            
            // Handle quantity changes based on status transitions
            $quantity_message = '';
            
            // Confirming order (PEN -> CON) - Reduce stock
            if ($current_status === 'PEN' && $new_status === 'CON') {
                if ($current_stock >= $order_quantity) {
                    $new_stock = $current_stock - $order_quantity;
                    
                    $update_stock = $conn->prepare("UPDATE product SET Quantity = ? WHERE product_id = ?");
                    $update_stock->bind_param("di", $new_stock, $product_id);
                    
                    if (!$update_stock->execute()) {
                        throw new Exception("Failed to update product stock");
                    }
                    $update_stock->close();
                    
                    $quantity_message = " Stock reduced by {$order_quantity} units.";
                } else {
                    throw new Exception("Insufficient stock! Available: {$current_stock}, Required: {$order_quantity}");
                }
            }
            
            // Rejecting/Cancelling confirmed order (CON -> REJ/CAN) - Restore stock
            if ($current_status === 'CON' && ($new_status === 'REJ' )) {
                $new_stock = $current_stock + $order_quantity;
                
                $restore_stock = $conn->prepare("UPDATE product SET Quantity = ? WHERE product_id = ?");
                $restore_stock->bind_param("di", $new_stock, $product_id);
                
                if (!$restore_stock->execute()) {
                    throw new Exception("Failed to restore product stock");
                }
                $restore_stock->close();
                
                $quantity_message = " Stock restored by {$order_quantity} units.";
            }
            
            // Update order status
            $update_order = $conn->prepare("UPDATE product_orders SET Status = ? WHERE Order_id = ?");
            $update_order->bind_param("si", $new_status, $order_id);
            
            if (!$update_order->execute()) {
                throw new Exception("Failed to update order status");
            }
            $update_order->close();
            
            // Commit transaction
            $conn->commit();
            
            $status_names = [
                'PEN' => 'Pending', 'CON' => 'Confirmed', 'REJ' => 'Rejected', 
                'COM' => 'Completed'
            ];
            
            $message = "Order #{$order_id} status updated to {$status_names[$new_status]}.{$quantity_message}";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=" . ($_GET['status'] ?? 'all') . 
               ($message ? "&msg=" . urlencode($message) : "") . 
               ($error ? "&err=" . urlencode($error) : ""));
        exit();
    }
}

// Display messages from URL parameters
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $error = $_GET['err'];
}

$status_filter = $_GET['status'] ?? 'all';
$valid_statuses = ['all', 'PEN', 'CON', 'REJ', 'COM', 'CAN'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

$where_status = "";
$params = [$owner_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_status = " AND po.Status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Enhanced query with stock information and delivery address details
$orders_sql = "SELECT po.*, p.Name AS product_name, p.Quantity as current_stock, p.Unit,
                      u.name AS buyer_name, u.phone AS buyer_phone, u.email AS buyer_email,
                      ua.address, ua.city, ua.state, ua.Pin_code
               FROM product_orders po 
               JOIN product p ON po.Product_id = p.product_id 
               JOIN users u ON po.buyer_id = u.user_id 
               LEFT JOIN user_addresses ua ON po.delivery_address = ua.address_id
               WHERE p.seller_id = ?" . $where_status . "
               ORDER BY po.order_date DESC";

$orders_stmt = $conn->prepare($orders_sql);
if (!$orders_stmt) {
    die("Prepare failed: " . $conn->error);
}
$orders_stmt->bind_param($param_types, ...$params);
$orders_stmt->execute();
$orders_result = $orders_stmt->get_result();

// Updated stats query
$stats_sql = "SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN po.Status = 'PEN' THEN 1 ELSE 0 END) as pending_orders,
    SUM(CASE WHEN po.Status = 'CON' THEN 1 ELSE 0 END) as confirmed_orders,
    SUM(CASE WHEN po.Status = 'COM' THEN 1 ELSE 0 END) as completed_orders,
    SUM(CASE WHEN po.Status IN ('CON', 'COM') THEN po.total_price ELSE 0 END) as total_revenue
    FROM product_orders po 
    JOIN product p ON po.Product_id = p.product_id 
    WHERE p.seller_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
if (!$stats_stmt) {
    die("Prepare failed: " . $conn->error);
}
$stats_stmt->bind_param("i", $owner_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

if (!$stats) {
    $stats = [
        'total_orders' => 0, 'pending_orders' => 0, 'confirmed_orders' => 0,
        'completed_orders' => 0, 'total_revenue' => 0
    ];
}

function getStatusInfo($status) {
    $status_info = [
        'PEN' => ['class' => 'status-pending', 'text' => 'Pending'],
        'CON' => ['class' => 'status-confirmed', 'text' => 'Confirmed'],
        'COM' => ['class' => 'status-confirmed', 'text' => 'Completed'],
        'REJ' => ['class' => 'status-rejected', 'text' => 'Rejected']
        
    ];
    return $status_info[$status] ?? ['class' => 'status-pending', 'text' => $status];
}

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../assets/css/admin.css">

<div class="main-content">
    <h1>Product Order Management</h1>
    <h2>Welcome <?= $_SESSION['user_name'] ?? 'Farmer' ?></h2>

    <?php if ($message): ?>
        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
            ✅ <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="cards">
        <div class="card">
            <h3>Total Orders</h3>
            <div class="count"><?php echo $stats['total_orders']; ?></div>
        </div>
        
        <div class="card">
            <h3>Pending Orders</h3>
            <div class="count"><?php echo $stats['pending_orders']; ?></div>
        </div>
        
        <div class="card">
            <h3>Confirmed Orders</h3>
            <div class="count"><?php echo $stats['confirmed_orders']; ?></div>
        </div>
        
        <div class="card">
            <h3>Completed Orders</h3>
            <div class="count"><?php echo $stats['completed_orders']; ?></div>
        </div>
        
        <div class="card">
            <h3>Total Revenue</h3>
            <div class="count">₹<?php echo number_format($stats['total_revenue'], 2); ?></div>
        </div>
    </div>

    <br><br>

    <!-- Filter Buttons -->
    <div style="margin-bottom: 20px;">
        <h3>Filter Orders by Status:</h3>
        <?php 
        $statuses = [
            'all' => 'All Orders',
            'PEN' => 'Pending',
            'CON' => 'Confirmed', 
            'COM' => 'Completed',
            'REJ' => 'Rejected'
            
        ];
        foreach ($statuses as $key => $label): 
            $active_style = ($status_filter === $key) ? 'background: #234a23; color: white;' : 'background: #f8f9fa; color: #333;';
        ?>
            <a href="?status=<?= $key ?>" style="<?= $active_style ?> padding: 8px 15px; margin: 5px; text-decoration: none; border-radius: 5px; border: 1px solid #ddd;"><?= $label ?></a>
        <?php endforeach; ?>
    </div>

    <h2>Product Order Requests</h2>
    <table>
        <thead>
            <tr>
                <th>Order ID</th>
                <th>Product</th>
                <th>Customer</th>
                <th>Contact</th>
                <th>Quantity</th>
                <th>Current Stock</th>
                <th>Total Price</th>
                <th>Delivery Address</th>
                <th>Order Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($orders_result && $orders_result->num_rows > 0): ?>
                <?php while($order = $orders_result->fetch_assoc()): 
                    $status_info = getStatusInfo($order['Status']);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($order['Order_id']) ?></strong></td>
                    <td>
                        <strong><?= htmlspecialchars($order['product_name']) ?></strong><br>
                    </td>
                    <td><?= htmlspecialchars($order['buyer_name']) ?></td>
                    <td>
                        <?= htmlspecialchars($order['buyer_phone']) ?><br>
                    </td>
                    <td><?= number_format($order['quantity'], 2) ?> <?= strtoupper($order['Unit'] ?? '') ?></td>
                    <td>
                        <?php 
                        $stock_color = $order['current_stock'] >= $order['quantity'] ? 'color: green;' : 'color: red;';
                        ?>
                        <span style="<?= $stock_color ?>">
                            <strong><?= number_format($order['current_stock'], 2) ?></strong> <?= strtoupper($order['Unit'] ?? '') ?>
                        </span>
                        <?php if ($order['current_stock'] < $order['quantity'] && $order['Status'] === 'PEN'): ?>
                            <br><small style="color: red;"> Insufficient Stock</small>
                        <?php endif; ?>
                    </td>
                    <td>₹<?= number_format($order['total_price'], 2) ?></td>
                    <td>
                        <?php if ($order['address']): ?>
                            <div style="max-width: 200px;">
                                <?= htmlspecialchars($order['address']) ?><br>
                                <small><?= htmlspecialchars($order['city'] . ', ' . $order['state']) ?> - <?= htmlspecialchars($order['Pin_code']) ?></small>
                            </div>
                        <?php else: ?>
                            <small style="color: #666;">Address not available</small>
                        <?php endif; ?>
                    </td>
                    <td><?= date('M j, Y', strtotime($order['order_date'])) ?><br><small><?= date('g:i A', strtotime($order['order_date'])) ?></small></td>
                    <td>
                        <span class="status-badge <?= $status_info['class'] ?>">
                            <?= $status_info['text'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($order['Status'] === 'PEN'): ?>
                            <?php if ($order['current_stock'] >= $order['quantity']): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirmAction('confirm', <?= $order['Order_id'] ?>, <?= $order['quantity'] ?>)">
                                    <input type="hidden" name="order_id" value="<?= $order['Order_id'] ?>" />
                                    <input type="hidden" name="action" value="confirm" />
                                    <button type="submit" style="background: #28a745; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">Confirm</button>
                                </form>
                            <?php else: ?>
                                <button disabled style="background: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 3px;" title="Insufficient stock">No Stock</button>
                            <?php endif; ?>
                            |
                            <form method="POST" style="display:inline;" onsubmit="return confirmAction('reject', <?= $order['Order_id'] ?>)">
                                <input type="hidden" name="order_id" value="<?= $order['Order_id'] ?>" />
                                <input type="hidden" name="action" value="reject" />
                                <button type="submit" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">Reject</button>
                            </form>
                        <?php elseif ($order['Status'] === 'CON'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirmAction('complete', <?= $order['Order_id'] ?>)">
                                <input type="hidden" name="order_id" value="<?= $order['Order_id'] ?>" />
                                <input type="hidden" name="action" value="complete" />
                                <button type="submit" style="background: #17a2b8; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">Complete</button>
                            </form>
                            |
                            <form method="POST" style="display:inline;" onsubmit="return confirmAction('cancel', <?= $order['Order_id'] ?>, <?= $order['quantity'] ?>)">
                                <input type="hidden" name="order_id" value="<?= $order['Order_id'] ?>" />
                                <input type="hidden" name="action" value="cancel" />
                                <button type="submit" style="background: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">Cancel</button>
                            </form>
                        <?php else: ?>
                            <a href="order_details.php?id=<?= $order['Order_id']; ?>">View Details</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" style="text-align: center; color: #666; padding: 20px;">
                        <?= $status_filter !== 'all' ? 'No orders found with status: ' . strtoupper($status_filter) : 'No product orders received yet' ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <br><br>
</div>

<script>
// Enhanced confirmation with stock information
function confirmAction(action, orderId, quantity = null) {
    let message = '';
    
    switch(action) {
        case 'confirm':
            message = `Are you sure you want to CONFIRM order #${orderId}?\n\nThis will reduce your product stock by ${quantity} units.`;
            break;
        case 'reject':
            message = `Are you sure you want to REJECT order #${orderId}?`;
            break;
        case 'complete':
            message = `Mark order #${orderId} as COMPLETED?`;
            break;
        case 'cancel':
            message = `Cancel order #${orderId}?\n\nThis will restore ${quantity} units to your stock.`;
            break;
        default:
            message = `Proceed with this action for order #${orderId}?`;
    }
    
    return confirm(message);
}

// Auto-refresh every 60 seconds
setTimeout(() => { 
    if (!document.hidden) {
        location.reload(); 
    }
}, 60000);

// Auto-hide messages after 5 seconds
setTimeout(() => {
    const messages = document.querySelectorAll('[style*="background: #d4edda"], [style*="background: #f8d7da"]');
    messages.forEach(msg => {
        msg.style.transition = 'opacity 0.5s ease';
        msg.style.opacity = '0';
        setTimeout(() => msg.remove(), 500);
    });
}, 5000);
</script>

<?php require 'ffooter.php'; ?>
