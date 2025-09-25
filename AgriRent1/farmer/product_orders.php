<?php
session_start();
require_once('../auth/config.php');

// Only allow logged-in sellers to access this page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'S') {
    header('Location: ../login.php');
    exit();
}

$seller_id = (int) $_SESSION['user_id'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';

// Build WHERE clause for filtering
$where_conditions = ['p.seller_id = ?'];
$params = [$seller_id];
$param_types = 'i';

if ($status_filter !== 'all') {
    $where_conditions[] = 'po.Status = ?';
    $params[] = $status_filter;
    $param_types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch order statistics
$stats_query = "SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN po.Status = 'PEN' THEN 1 ELSE 0 END) as pending_orders,
    SUM(CASE WHEN po.Status = 'CON' THEN 1 ELSE 0 END) as confirmed_orders,
    SUM(CASE WHEN po.Status = 'CON' THEN po.total_price ELSE 0 END) as total_revenue
FROM product_orders po 
JOIN product p ON po.Product_id = p.product_id 
WHERE p.seller_id = ?";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param('i', $seller_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Fetch orders with product and buyer information
$orders_query = "SELECT po.Order_id, po.Product_id, po.buyer_id, po.quantity, po.total_price, 
                        po.Status, po.order_date,
                        p.Name as product_name, p.Unit, p.Price,
                        u.Name as buyer_name, u.Phone as buyer_phone, u.Email as buyer_email
                 FROM product_orders po 
                 JOIN product p ON po.Product_id = p.product_id 
                 JOIN users u ON po.buyer_id = u.user_id 
                 $where_clause 
                 ORDER BY po.order_date DESC";

$orders_stmt = $conn->prepare($orders_query);
$orders_stmt->bind_param($param_types, ...$params);
$orders_stmt->execute();
$orders_result = $orders_stmt->get_result();
$orders = [];
while ($order = $orders_result->fetch_assoc()) {
    $orders[] = $order;
}
$orders_stmt->close();

// Status mapping
function getStatusText($status) {
    $status_map = [
        'PEN' => ['text' => 'Pending', 'class' => 'status-pending'],
        'CON' => ['text' => 'Confirmed', 'class' => 'status-confirmed'],
        'CAN' => ['text' => 'Cancelled', 'class' => 'status-cancelled']
    ];
    return $status_map[$status] ?? ['text' => $status, 'class' => 'status-unknown'];
}

include 'sheader.php';
include 'seller_nav.php';
?>

<link rel="stylesheet" href="../assets/css/seller.css">

<div class="main-content">
    <div class="page-header">
        <h1>📦 Product Order Requests</h1>
        <p>Manage order requests for your products</p>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-info">
                <h3>Total Orders</h3>
                <div class="stat-number"><?= $stats['total_orders'] ?? 0 ?></div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-info">
                <h3>Pending Requests</h3>
                <div class="stat-number"><?= $stats['pending_orders'] ?? 0 ?></div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-info">
                <h3>Confirmed Orders</h3>
                <div class="stat-number"><?= $stats['confirmed_orders'] ?? 0 ?></div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-info">
                <h3>Total Revenue</h3>
                <div class="stat-number">₹<?= number_format($stats['total_revenue'] ?? 0, 2) ?></div>
            </div>
        </div>
    </div>

    <!-- Filter Options -->
    <div class="filter-section">
        <form method="GET" style="display: flex; align-items: center; gap: 15px; margin-bottom: 30px;">
            <label for="status" style="font-weight: 600;">Filter by Status:</label>
            <select name="status" id="status" onchange="this.form.submit()" 
                    style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Orders</option>
                <option value="PEN" <?= $status_filter === 'PEN' ? 'selected' : '' ?>>Pending</option>
                <option value="CON" <?= $status_filter === 'CON' ? 'selected' : '' ?>>Confirmed</option>
                <option value="CAN" <?= $status_filter === 'CAN' ? 'selected' : '' ?>>Cancelled</option>
            </select>
            
            <?php if ($status_filter !== 'all'): ?>
                <a href="product_orders.php" style="padding: 8px 12px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-size: 14px;">Clear Filter</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Orders Table -->
    <div class="table-container">
        <?php if (count($orders) > 0): ?>
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total Amount</th>
                        <th>Order Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php $status_info = getStatusText($order['Status']); ?>
                        <tr>
                            <td><?= htmlspecialchars($order['product_name']) ?></td>
                            <td><?= htmlspecialchars($order['buyer_name']) ?></td>
                            <td><?= htmlspecialchars($order['buyer_phone']) ?></td>
                            <td><?= number_format($order['quantity'], 2) ?> <?= strtoupper($order['Unit']) ?></td>
                            <td>₹<?= number_format($order['Price'], 2) ?></td>
                            <td>₹<?= number_format($order['total_price'], 2) ?></td>
                            <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                            <td>
                                <span class="status-badge <?= $status_info['class'] ?>">
                                    <?= $status_info['text'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="order_details.php?id=<?= $order['Order_id'] ?>" class="btn-view">
                                        View Details
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-orders">
                <div class="no-orders-icon">📦</div>
                <h3><?= $status_filter !== 'all' ? 'No orders found with status: ' . strtoupper($status_filter) : 'No Order Requests Yet' ?></h3>
                <p><?= $status_filter !== 'all' ? 'Try changing the filter or wait for new orders.' : 'When farmers order your products, they will appear here for your approval.' ?></p>
                <a href="add_product.php" class="btn btn-primary">
                    ➕ Add More Products
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.main-content {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 30px;
}

.page-header h1 {
    color: #28a745;
    margin-bottom: 10px;
}

.page-header p {
    color: #666;
    margin: 0;
}

/* Statistics Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 15px;
}

.stat-icon {
    font-size: 2.5em;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    border-radius: 50%;
}

.stat-info h3 {
    margin: 0 0 5px 0;
    font-size: 0.9em;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-number {
    font-size: 1.8em;
    font-weight: 700;
    color: #28a745;
}

/* Table Styling */
.table-container {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow-x: auto;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table th {
    background: #28a745;
    color: white;
    padding: 15px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.orders-table td {
    padding: 15px 12px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
    vertical-align: middle;
}

.orders-table tr:hover {
    background: #f8f9fa;
}

/* Status Badges */
.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.status-confirmed {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

.btn-view {
    background: #17a2b8;
    color: white;
    padding: 6px 12px;
    text-decoration: none;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    transition: background-color 0.3s ease;
}

.btn-view:hover {
    background: #138496;
    color: white;
    text-decoration: none;
}

/* Empty State */
.no-orders {
    text-align: center;
    padding: 60px 20px;
}

.no-orders-icon {
    font-size: 4em;
    margin-bottom: 20px;
    opacity: 0.5;
}

.no-orders h3 {
    color: #666;
    margin-bottom: 10px;
}

.no-orders p {
    color: #999;
    margin-bottom: 30px;
    line-height: 1.6;
}

.btn {
    display: inline-block;
    padding: 12px 24px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: #28a745;
    color: white;
}

.btn-primary:hover {
    background: #218838;
    color: white;
    text-decoration: none;
    transform: translateY(-1px);
}

/* Responsive Design */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .orders-table {
        font-size: 12px;
    }
    
    .orders-table th,
    .orders-table td {
        padding: 10px 8px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<?php include 'sfooter.php'; ?>
