<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Helper function for proper Indian currency formatting
function formatIndianCurrency($amount) {
    if ($amount == 0) return '₹0';
    $amount = (float)$amount;
    return '₹' . number_format($amount, 2);
}

// Helper function for Indian number formatting (lakhs/crores)
function formatIndianNumber($num) {
    if ($num < 1000) {
        return number_format($num);
    } elseif ($num < 100000) {
        return number_format($num / 1000, 1) . 'K';
    } elseif ($num < 10000000) {
        return number_format($num / 100000, 2) . 'L';
    } else {
        return number_format($num / 10000000, 2) . 'Cr';
    }
}

// Export handling - MUST BE AT THE TOP
if (isset($_POST['export_data'])) {
    $export_type = $_POST['export_format'];
    $export_report = $_POST['export_report_type'];
    
    // Set appropriate headers
    switch ($export_type) {
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="agrirent_' . $export_report . '_' . date('Y-m-d') . '.csv"');
            break;
        case 'excel':
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="agrirent_' . $export_report . '_' . date('Y-m-d') . '.xls"');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
            break;
        case 'pdf':
            // Generate proper HTML for PDF
            generatePDFReport($conn, $export_report);
            exit;
            break;
    }
    
    // Generate export based on report type
    switch ($export_report) {
        case 'users':
            exportUsersData($conn, $export_type);
            break;
        case 'equipment':
            exportEquipmentData($conn, $export_type);
            break;
        case 'products':
            exportProductsData($conn, $export_type);
            break;
        case 'bookings':
            exportBookingsData($conn, $export_type);
            break;
        case 'orders':
            exportOrdersData($conn, $export_type);
            break;
        case 'subscriptions':
            exportSubscriptionsData($conn, $export_type);
            break;
        case 'revenue':
            exportRevenueData($conn, $export_type);
            break;
        case 'reviews':
            exportReviewsData($conn, $export_type);
            break;
        case 'messages':
            exportMessagesData($conn, $export_type);
            break;
        case 'complete':
            exportCompleteReport($conn, $export_type);
            break;
        default:
            echo "Invalid report type";
            break;
    }
    exit;
}

// Helper functions
function safeQuery($conn, $query) {
    $result = $conn->query($query);
    if ($result === false) {
        error_log("Database error: " . $conn->error . " Query: " . $query);
        return false;
    }
    return $result;
}

function getCount($conn, $query) {
    $result = safeQuery($conn, $query);
    if ($result === false) return 0;
    $row = $result->fetch_assoc();
    return intval($row['total'] ?? $row['count'] ?? 0);
}

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'overview';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

// Get comprehensive statistics using CORRECT table and column names from your database
$stats = [
    'users' => [
        'total' => getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type != 'A'"),
        'farmers' => getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type = 'F'"),
        'owners' => getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type = 'O'"),
        'active' => getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type != 'A' AND status = 'A'"),
    ],
    'equipment' => [
        'total' => getCount($conn, "SELECT COUNT(*) as total FROM equipment"),
        'approved' => getCount($conn, "SELECT COUNT(*) as total FROM equipment WHERE Approval_status = 'CON'"),
        'pending' => getCount($conn, "SELECT COUNT(*) as total FROM equipment WHERE Approval_status = 'PEN'"),
        'rejected' => getCount($conn, "SELECT COUNT(*) as total FROM equipment WHERE Approval_status = 'REJ'"),
    ],
    'products' => [
        'total' => getCount($conn, "SELECT COUNT(*) as total FROM product"),
        'approved' => getCount($conn, "SELECT COUNT(*) as total FROM product WHERE Approval_status = 'CON'"),
        'pending' => getCount($conn, "SELECT COUNT(*) as total FROM product WHERE Approval_status = 'PEN'"),
        'in_stock' => getCount($conn, "SELECT COUNT(*) as total FROM product WHERE Quantity > 0"),
    ],
    'bookings' => [
        'total' => getCount($conn, "SELECT COUNT(*) as total FROM equipment_bookings"),
        'confirmed' => getCount($conn, "SELECT COUNT(*) as total FROM equipment_bookings WHERE status = 'CON'"),
        'pending' => getCount($conn, "SELECT COUNT(*) as total FROM equipment_bookings WHERE status = 'PEN'"),
        'cancelled' => getCount($conn, "SELECT COUNT(*) as total FROM equipment_bookings WHERE status = 'CAN'"),
    ],
    'orders' => [
        'total' => getCount($conn, "SELECT COUNT(*) as total FROM product_orders"),
        'confirmed' => getCount($conn, "SELECT COUNT(*) as total FROM product_orders WHERE Status = 'CON'"),
        'pending' => getCount($conn, "SELECT COUNT(*) as total FROM product_orders WHERE Status = 'PEN'"),
        'cancelled' => getCount($conn, "SELECT COUNT(*) as total FROM product_orders WHERE Status = 'CAN'"),
    ],
    'subscriptions' => [
        'active' => getCount($conn, "SELECT COUNT(*) as total FROM user_subscriptions WHERE Status = 'A'"),
        'confirmed' => getCount($conn, "SELECT COUNT(*) as total FROM user_subscriptions WHERE Status = 'C'"),
        'pending' => getCount($conn, "SELECT COUNT(*) as total FROM user_subscriptions WHERE Status = 'P'"),
    ]
];

// Get revenue data safely
$booking_revenue_result = safeQuery($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM equipment_bookings WHERE status = 'CON' AND start_date BETWEEN '$date_from' AND '$date_to'");
$booking_revenue = $booking_revenue_result ? $booking_revenue_result->fetch_assoc()['total'] : 0;

$product_revenue_result = safeQuery($conn, "SELECT COALESCE(SUM(total_price), 0) as total FROM product_orders WHERE Status = 'CON' AND order_date BETWEEN '$date_from' AND '$date_to'");
$product_revenue = $product_revenue_result ? $product_revenue_result->fetch_assoc()['total'] : 0;

$subscription_revenue_result = safeQuery($conn, "SELECT COALESCE(SUM(p.Amount), 0) as total FROM payments p JOIN user_subscriptions us ON p.Subscription_id = us.subscription_id WHERE p.Status = 'C' AND p.payment_date BETWEEN '$date_from' AND '$date_to'");
$subscription_revenue = $subscription_revenue_result ? $subscription_revenue_result->fetch_assoc()['total'] : 0;

$total_revenue = $booking_revenue + $product_revenue + $subscription_revenue;

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <div class="page-header">
        <h1>📊 AgriRent Analytics Dashboard</h1>
        <div class="export-section">
            <button class="btn btn-export" onclick="showExportModal()">
                📤 Export Reports
            </button>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-card">
        <h3>📅 Report Filters</h3>
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>Date Range:</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-input">
                <span>to</span>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-input">
            </div>
            <div class="filter-group">
                <label>Report Type:</label>
                <select name="report_type" class="form-input">
                    <option value="overview" <?= $report_type === 'overview' ? 'selected' : '' ?>>Overview</option>
                    <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>Detailed</option>
                    <option value="revenue" <?= $report_type === 'revenue' ? 'selected' : '' ?>>Revenue Focus</option>
                </select>
            </div>
            <button type="submit" class="btn btn-filter">🔍 Apply Filters</button>
        </form>
    </div>

    <!-- Key Metrics Overview -->
    <div class="metrics-grid">
        <div class="metric-card users">
            <div class="metric-header">
                <h3>👥 Users</h3>
                <span class="metric-total"><?= formatIndianNumber($stats['users']['total']) ?></span>
            </div>
            <div class="metric-breakdown">
                <div class="metric-item">
                    <span class="metric-label">Farmers:</span>
                    <span class="metric-value"><?= formatIndianNumber($stats['users']['farmers']) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Equipment Owners:</span>
                    <span class="metric-value"><?= formatIndianNumber($stats['users']['owners']) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Active Users:</span>
                    <span class="metric-value available"><?= formatIndianNumber($stats['users']['active']) ?></span>
                </div>
            </div>
        </div>

        <div class="metric-card equipment">
            <div class="metric-header">
                <h3>🚜 Equipment</h3>
                <span class="metric-total"><?= formatIndianNumber($stats['equipment']['total']) ?></span>
            </div>
            <div class="metric-breakdown">
                <div class="metric-item">
                    <span class="metric-label">Approved:</span>
                    <span class="metric-value available"><?= formatIndianNumber($stats['equipment']['approved']) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Pending:</span>
                    <span class="metric-value pending"><?= formatIndianNumber($stats['equipment']['pending']) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Rejected:</span>
                    <span class="metric-value rejected"><?= formatIndianNumber($stats['equipment']['rejected']) ?></span>
                </div>
            </div>
        </div>

        <div class="metric-card products">
            <div class="metric-header">
                <h3>🌾 Products</h3>
                <span class="metric-total"><?= formatIndianNumber($stats['products']['total']) ?></span>
            </div>
            <div class="metric-breakdown">
                <div class="metric-item">
                    <span class="metric-label">Approved:</span>
                    <span class="metric-value available"><?= formatIndianNumber($stats['products']['approved']) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Pending:</span>
                    <span class="metric-value pending"><?= formatIndianNumber($stats['products']['pending']) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">In Stock:</span>
                    <span class="metric-value"><?= formatIndianNumber($stats['products']['in_stock']) ?></span>
                </div>
            </div>
        </div>

        <div class="metric-card revenue">
            <div class="metric-header">
                <h3>💰 Revenue</h3>
                <span class="metric-total"><?= formatIndianCurrency($total_revenue) ?></span>
            </div>
            <div class="metric-breakdown">
                <div class="metric-item">
                    <span class="metric-label">Equipment Bookings:</span>
                    <span class="metric-value"><?= formatIndianCurrency($booking_revenue) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Product Sales:</span>
                    <span class="metric-value"><?= formatIndianCurrency($product_revenue) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">Subscriptions:</span>
                    <span class="metric-value"><?= formatIndianCurrency($subscription_revenue) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Overview -->
    <div class="activity-section">
        <div class="activity-card">
            <h3>📋 Bookings Status</h3>
            <div class="status-grid">
                <div class="status-item confirmed">
                    <span class="status-count"><?= formatIndianNumber($stats['bookings']['confirmed']) ?></span>
                    <span class="status-label">Confirmed</span>
                </div>
                <div class="status-item pending">
                    <span class="status-count"><?= formatIndianNumber($stats['bookings']['pending']) ?></span>
                    <span class="status-label">Pending</span>
                </div>
                <div class="status-item cancelled">
                    <span class="status-count"><?= formatIndianNumber($stats['bookings']['cancelled']) ?></span>
                    <span class="status-label">Cancelled</span>
                </div>
            </div>
        </div>

        <div class="activity-card">
            <h3>🛒 Orders & Subscriptions</h3>
            <div class="status-grid">
                <div class="status-item confirmed">
                    <span class="status-count"><?= formatIndianNumber($stats['orders']['confirmed']) ?></span>
                    <span class="status-label">Orders</span>
                </div>
                <div class="status-item active">
                    <span class="status-count"><?= formatIndianNumber($stats['subscriptions']['active']) ?></span>
                    <span class="status-label">Active Plans</span>
                </div>
                <div class="status-item confirmed">
                    <span class="status-count"><?= formatIndianNumber($stats['subscriptions']['confirmed']) ?></span>
                    <span class="status-label">Paid Plans</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity Tables -->
    <?php if ($report_type === 'detailed'): ?>
    <div class="tables-section">
        <!-- Recent Users -->
        <div class="table-card">
            <h3>👥 Recent Users</h3>
            <?php
            $recent_users = safeQuery($conn, "
                SELECT user_id, Name, Email, User_type, Phone, status 
                FROM users 
                WHERE User_type != 'A' 
                ORDER BY user_id DESC 
                LIMIT 10
            ");
            ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Phone</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_users && $recent_users->num_rows > 0): ?>
                            <?php while ($user = $recent_users->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($user['user_id']) ?></td>
                                <td><?= htmlspecialchars($user['Name']) ?></td>
                                <td><?= htmlspecialchars($user['Email']) ?></td>
                                <td>
                                    <span class="user-type <?= $user['User_type'] === 'F' ? 'farmer' : 'owner' ?>">
                                        <?= $user['User_type'] === 'F' ? 'Farmer' : 'Equipment Owner' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($user['Phone']) ?></td>
                                <td>
                                    <span class="status-badge <?= $user['status'] === 'A' ? 'active' : 'inactive' ?>">
                                        <?= $user['status'] === 'A' ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">No users found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Equipment -->
        <div class="table-card">
            <h3>🚜 Recent Equipment</h3>
            <?php
            $recent_equipment = safeQuery($conn, "
                SELECT e.Equipment_id, e.Title, e.Brand, e.Model, e.Daily_rate, e.Hourly_rate, e.Approval_status, 
                       u.Name as owner_name, e.listed_date
                FROM equipment e 
                JOIN users u ON e.Owner_id = u.user_id 
                ORDER BY e.listed_date DESC 
                LIMIT 10
            ");
            ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Brand & Model</th>
                            <th>Daily Rate</th>
                            <th>Hourly Rate</th>
                            <th>Owner</th>
                            <th>Status</th>
                            <th>Listed Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_equipment && $recent_equipment->num_rows > 0): ?>
                            <?php while ($equipment = $recent_equipment->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($equipment['Title']) ?></td>
                                <td><?= htmlspecialchars($equipment['Brand'] . ' ' . $equipment['Model']) ?></td>
                                <td class="price-cell"><?= formatIndianCurrency($equipment['Daily_rate']) ?></td>
                                <td class="price-cell"><?= formatIndianCurrency($equipment['Hourly_rate']) ?></td>
                                <td><?= htmlspecialchars($equipment['owner_name']) ?></td>
                                <td>
                                    <span class="status-badge <?= strtolower($equipment['Approval_status']) ?>">
                                        <?php
                                        $status_map = ['CON' => 'Approved', 'PEN' => 'Pending', 'REJ' => 'Rejected'];
                                        echo $status_map[$equipment['Approval_status']] ?? $equipment['Approval_status'];
                                        ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y', strtotime($equipment['listed_date'])) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">No equipment found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Product Orders -->
        <div class="table-card">
            <h3>🛒 Recent Product Orders</h3>
            <?php
            $recent_orders = safeQuery($conn, "
                SELECT po.Order_id, p.Name as product_name, u.Name as buyer_name, 
                       po.quantity, po.total_price, po.Status, po.order_date 
                FROM product_orders po 
                JOIN product p ON po.Product_id = p.product_id 
                JOIN users u ON po.buyer_id = u.user_id 
                ORDER BY po.order_date DESC 
                LIMIT 10
            ");
            ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Product</th>
                            <th>Buyer</th>
                            <th>Quantity</th>
                            <th>Total Price</th>
                            <th>Status</th>
                            <th>Order Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_orders && $recent_orders->num_rows > 0): ?>
                            <?php while ($order = $recent_orders->fetch_assoc()): ?>
                            <tr>
                                <td>#<?= htmlspecialchars($order['Order_id']) ?></td>
                                <td><?= htmlspecialchars($order['product_name']) ?></td>
                                <td><?= htmlspecialchars($order['buyer_name']) ?></td>
                                <td><?= number_format($order['quantity']) ?></td>
                                <td class="price-cell"><?= formatIndianCurrency($order['total_price']) ?></td>
                                <td>
                                    <span class="status-badge <?= strtolower($order['Status']) ?>">
                                        <?php
                                        $status_map = ['CON' => 'Confirmed', 'PEN' => 'Pending', 'CAN' => 'Cancelled'];
                                        echo $status_map[$order['Status']] ?? $order['Status'];
                                        ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">No orders found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Export Modal -->
<div id="exportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📤 Export AgriRent Data</h3>
            <span class="close" onclick="hideExportModal()">&times;</span>
        </div>
        <form method="POST" class="export-form">
            <div class="form-group">
                <label>Select Data to Export:</label>
                <select name="export_report_type" class="form-input" required>
                    <option value="">Choose Report Type...</option>
                    <option value="complete">📊 Complete Website Report</option>
                    <option value="users">👥 Users Data</option>
                    <option value="equipment">🚜 Equipment Listings</option>
                    <option value="products">🌾 Product Listings</option>
                    <option value="bookings">📅 Equipment Bookings</option>
                    <option value="orders">🛒 Product Orders</option>
                    <option value="subscriptions">💳 User Subscriptions</option>
                    <option value="reviews">⭐ Reviews & Ratings</option>
                    <option value="messages">💬 User Messages</option>
                    <option value="revenue">💰 Revenue Report</option>
                </select>
            </div>
            <div class="form-group">
                <label>Select Export Format:</label>
                <div class="format-options">
                    <label class="format-option">
                        <input type="radio" name="export_format" value="csv" required>
                        <span class="format-label">
                            📊 CSV (Excel Compatible)
                            <small>Best for data analysis and Excel</small>
                        </span>
                    </label>
                    <label class="format-option">
                        <input type="radio" name="export_format" value="excel" required>
                        <span class="format-label">
                            📈 Excel (.xls)
                            <small>Direct Excel spreadsheet format</small>
                        </span>
                    </label>
                    <label class="format-option">
                        <input type="radio" name="export_format" value="pdf" required>
                        <span class="format-label">
                            📄 PDF Document
                            <small>Professional printable format</small>
                        </span>
                    </label>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-cancel" onclick="hideExportModal()">Cancel</button>
                <button type="submit" name="export_data" class="btn btn-export">🚀 Generate & Download</button>
            </div>
        </form>
    </div>
</div>

<style>
.main-content {
    margin-left: 250px;
    padding: 25px;
    background-color: #f8f9fa;
    min-height: 100vh;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-header h1 {
    color: #234a23;
    font-size: 32px;
    font-weight: 600;
    margin: 0;
}

.btn {
    padding: 12px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.btn-export {
    background: #28a745;
    color: white;
}

.btn-export:hover {
    background: #218838;
    transform: translateY(-2px);
}

.filter-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.filter-form {
    display: flex;
    gap: 20px;
    align-items: end;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.form-input {
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.btn-filter {
    background: #007bff;
    color: white;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.metric-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.metric-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    border-bottom: 2px solid #f8f9fa;
    padding-bottom: 15px;
}

.metric-total {
    font-size: 28px;
    font-weight: 700;
    color: #234a23;
}

.metric-breakdown {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.metric-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.metric-value.available { color: #28a745; }
.metric-value.pending { color: #ffc107; }
.metric-value.rejected { color: #dc3545; }

.activity-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.activity-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.status-item {
    text-align: center;
    padding: 15px;
    border-radius: 8px;
    background: #f8f9fa;
}

.status-item.confirmed {
    background: rgba(40, 167, 69, 0.1);
    border-left: 4px solid #28a745;
}

.status-item.pending {
    background: rgba(255, 193, 7, 0.1);
    border-left: 4px solid #ffc107;
}

.status-item.cancelled {
    background: rgba(220, 53, 69, 0.1);
    border-left: 4px solid #dc3545;
}

.status-item.active {
    background: rgba(23, 162, 184, 0.1);
    border-left: 4px solid #17a2b8;
}

.status-count {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #234a23;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background: white;
    margin: 5% auto;
    width: 90%;
    max-width: 500px;
    border-radius: 12px;
    overflow: hidden;
}

.modal-header {
    background: #234a23;
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.export-form {
    padding: 25px;
}

.format-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.format-option {
    display: flex;
    align-items: center;
    padding: 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.format-option:hover {
    border-color: #234a23;
    background: rgba(35, 74, 35, 0.05);
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

.btn-cancel {
    background: #6c757d;
    color: white;
}

.table-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.data-table th {
    background: #f8f9fa;
    font-weight: 600;
}

.price-cell {
    font-weight: 600;
    color: #28a745;
    font-family: 'Courier New', monospace;
}

.user-type.farmer {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.user-type.owner {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.status-badge.active {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.status-badge.inactive {
    background: rgba(220, 53, 69, 0.1);
    color: #721c24;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.status-badge.con {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.status-badge.pen {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.status-badge.rej {
    background: rgba(220, 53, 69, 0.1);
    color: #721c24;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    
    .activity-section {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function showExportModal() {
    document.getElementById('exportModal').style.display = 'block';
}

function hideExportModal() {
    document.getElementById('exportModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('exportModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

document.querySelectorAll('input[name="export_format"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.format-option').forEach(option => {
            option.classList.remove('selected');
        });
        this.closest('.format-option').classList.add('selected');
    });
});
</script>

<?php

// PDF Generation Function
function generatePDFReport($conn, $report_type) {
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>AgriRent Report - ' . ucfirst($report_type) . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
                line-height: 1.4;
                margin: 20px;
                color: #333;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #234a23;
                padding-bottom: 20px;
            }
            .header h1 {
                color: #234a23;
                font-size: 24px;
                margin: 0;
            }
            .header p {
                color: #666;
                margin: 5px 0;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            th, td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            th {
                background-color: #f8f9fa;
                font-weight: bold;
                color: #234a23;
            }
            .price {
                text-align: right;
                font-weight: bold;
                color: #28a745;
            }
            .status {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .status.approved { background: #d4edda; color: #155724; }
            .status.pending { background: #fff3cd; color: #856404; }
            .status.rejected { background: #f8d7da; color: #721c24; }
            .footer {
                margin-top: 30px;
                text-align: center;
                font-size: 10px;
                color: #666;
                border-top: 1px solid #ddd;
                padding-top: 10px;
            }
        </style>
        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 1000);
            }
        </script>
    </head>
    <body>';
    
    echo '<div class="header">
            <h1>🌾 AgriRent - ' . ucfirst($report_type) . ' Report</h1>
            <p>Generated on: ' . date('d M Y, h:i A') . '</p>
            <p>Report Date Range: ' . ($_GET['date_from'] ?? date('Y-m-01')) . ' to ' . ($_GET['date_to'] ?? date('Y-m-t')) . '</p>
          </div>';
    
    switch ($report_type) {
        case 'equipment':
            generateEquipmentPDFReport($conn);
            break;
        case 'products':
            generateProductsPDFReport($conn);
            break;
        case 'bookings':
            generateBookingsPDFReport($conn);
            break;
        case 'orders':
            generateOrdersPDFReport($conn);
            break;
        case 'users':
            generateUsersPDFReport($conn);
            break;
        case 'revenue':
            generateRevenuePDFReport($conn);
            break;
        default:
            generateCompletePDFReport($conn);
            break;
    }
    
    echo '<div class="footer">
            <p>This report was generated by AgriRent Administration System</p>
            <p>© ' . date('Y') . ' AgriRent. All rights reserved.</p>
          </div>';
    
    echo '</body></html>';
}

function generateEquipmentPDFReport($conn) {
    echo '<h2>Equipment Listings Report</h2>';
    
    $query = "SELECT e.Equipment_id, e.Title, e.Brand, e.Model, e.Year, e.Daily_rate, e.Hourly_rate, 
                     u.Name as owner_name, e.Approval_status, e.listed_date 
              FROM equipment e 
              JOIN users u ON e.Owner_id = u.user_id 
              ORDER BY e.listed_date DESC 
              LIMIT 50";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        echo '<table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Equipment</th>
                        <th>Brand & Model</th>
                        <th>Year</th>
                        <th>Daily Rate</th>
                        <th>Hourly Rate</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Listed Date</th>
                    </tr>
                </thead>
                <tbody>';
        
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Approved', 'PEN' => 'Pending', 'REJ' => 'Rejected'];
            $status = $status_map[$row['Approval_status']] ?? $row['Approval_status'];
            $status_class = strtolower($row['Approval_status'] === 'CON' ? 'approved' : ($row['Approval_status'] === 'PEN' ? 'pending' : 'rejected'));
            
            echo '<tr>
                    <td>' . $row['Equipment_id'] . '</td>
                    <td>' . htmlspecialchars($row['Title']) . '</td>
                    <td>' . htmlspecialchars($row['Brand'] . ' ' . $row['Model']) . '</td>
                    <td>' . ($row['Year'] ?? 'N/A') . '</td>
                    <td class="price">' . formatIndianCurrency($row['Daily_rate']) . '</td>
                    <td class="price">' . formatIndianCurrency($row['Hourly_rate']) . '</td>
                    <td>' . htmlspecialchars($row['owner_name']) . '</td>
                    <td><span class="status ' . $status_class . '">' . $status . '</span></td>
                    <td>' . date('d M Y', strtotime($row['listed_date'])) . '</td>
                  </tr>';
        }
        
        echo '</tbody></table>';
    } else {
        echo '<p>No equipment listings found.</p>';
    }
}

function generateProductsPDFReport($conn) {
    echo '<h2>Product Listings Report</h2>';
    
    $query = "SELECT p.product_id, p.Name, p.Price, p.Quantity, p.Unit, 
                     u.Name as seller_name, p.Approval_status, p.listed_date 
              FROM product p 
              JOIN users u ON p.seller_id = u.user_id 
              ORDER BY p.listed_date DESC 
              LIMIT 50";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        echo '<table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product Name</th>
                        <th>Price per Unit</th>
                        <th>Quantity</th>
                        <th>Unit</th>
                        <th>Seller</th>
                        <th>Status</th>
                        <th>Listed Date</th>
                    </tr>
                </thead>
                <tbody>';
        
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Approved', 'PEN' => 'Pending', 'REJ' => 'Rejected'];
            $status = $status_map[$row['Approval_status']] ?? $row['Approval_status'];
            $status_class = strtolower($row['Approval_status'] === 'CON' ? 'approved' : ($row['Approval_status'] === 'PEN' ? 'pending' : 'rejected'));
            
            echo '<tr>
                    <td>' . $row['product_id'] . '</td>
                    <td>' . htmlspecialchars($row['Name']) . '</td>
                    <td class="price">' . formatIndianCurrency($row['Price']) . '</td>
                    <td>' . number_format($row['Quantity']) . '</td>
                    <td>' . htmlspecialchars($row['Unit']) . '</td>
                    <td>' . htmlspecialchars($row['seller_name']) . '</td>
                    <td><span class="status ' . $status_class . '">' . $status . '</span></td>
                    <td>' . date('d M Y', strtotime($row['listed_date'])) . '</td>
                  </tr>';
        }
        
        echo '</tbody></table>';
    } else {
        echo '<p>No product listings found.</p>';
    }
}

function generateBookingsPDFReport($conn) {
    echo '<h2>Equipment Bookings Report</h2>';
    
    $query = "SELECT eb.booking_id, e.Title as equipment_name, u.Name as customer_name, 
                     eb.start_date, eb.end_date, eb.Hours, eb.total_amount, eb.status, eb.time_slot 
              FROM equipment_bookings eb 
              JOIN equipment e ON eb.equipment_id = e.Equipment_id 
              JOIN users u ON eb.customer_id = u.user_id 
              ORDER BY eb.start_date DESC 
              LIMIT 50";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        echo '<table>
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Equipment</th>
                        <th>Customer</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Hours</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Time Slot</th>
                    </tr>
                </thead>
                <tbody>';
        
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Confirmed', 'PEN' => 'Pending', 'CAN' => 'Cancelled'];
            $status = $status_map[$row['status']] ?? $row['status'];
            $status_class = strtolower($row['status'] === 'CON' ? 'approved' : ($row['status'] === 'PEN' ? 'pending' : 'rejected'));
            
            echo '<tr>
                    <td>#' . $row['booking_id'] . '</td>
                    <td>' . htmlspecialchars($row['equipment_name']) . '</td>
                    <td>' . htmlspecialchars($row['customer_name']) . '</td>
                    <td>' . date('d M Y', strtotime($row['start_date'])) . '</td>
                    <td>' . date('d M Y', strtotime($row['end_date'])) . '</td>
                    <td>' . number_format($row['Hours']) . ' hrs</td>
                    <td class="price">' . formatIndianCurrency($row['total_amount']) . '</td>
                    <td><span class="status ' . $status_class . '">' . $status . '</span></td>
                    <td>' . ($row['time_slot'] ?? 'N/A') . '</td>
                  </tr>';
        }
        
        echo '</tbody></table>';
    } else {
        echo '<p>No bookings found.</p>';
    }
}

function generateOrdersPDFReport($conn) {
    echo '<h2>Product Orders Report</h2>';
    
    $query = "SELECT po.Order_id, p.Name as product_name, u.Name as buyer_name, 
                     po.quantity, po.total_price, po.Status, po.order_date 
              FROM product_orders po 
              JOIN product p ON po.Product_id = p.product_id 
              JOIN users u ON po.buyer_id = u.user_id 
              ORDER BY po.order_date DESC 
              LIMIT 50";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        echo '<table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Product</th>
                        <th>Buyer</th>
                        <th>Quantity</th>
                        <th>Total Price</th>
                        <th>Status</th>
                        <th>Order Date</th>
                    </tr>
                </thead>
                <tbody>';
        
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Confirmed', 'PEN' => 'Pending', 'CAN' => 'Cancelled'];
            $status = $status_map[$row['Status']] ?? $row['Status'];
            $status_class = strtolower($row['Status'] === 'CON' ? 'approved' : ($row['Status'] === 'PEN' ? 'pending' : 'rejected'));
            
            echo '<tr>
                    <td>#' . $row['Order_id'] . '</td>
                    <td>' . htmlspecialchars($row['product_name']) . '</td>
                    <td>' . htmlspecialchars($row['buyer_name']) . '</td>
                    <td>' . number_format($row['quantity']) . '</td>
                    <td class="price">' . formatIndianCurrency($row['total_price']) . '</td>
                    <td><span class="status ' . $status_class . '">' . $status . '</span></td>
                    <td>' . date('d M Y', strtotime($row['order_date'])) . '</td>
                  </tr>';
        }
        
        echo '</tbody></table>';
    } else {
        echo '<p>No orders found.</p>';
    }
}

function generateUsersPDFReport($conn) {
    echo '<h2>Users Report</h2>';
    
    $query = "SELECT user_id, Name, Email, Phone, User_type, status 
              FROM users 
              WHERE User_type != 'A' 
              ORDER BY user_id DESC 
              LIMIT 50";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        echo '<table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>User Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';
        
        while ($row = $result->fetch_assoc()) {
            $user_type = $row['User_type'] === 'F' ? 'Farmer' : 'Equipment Owner';
            $status = $row['status'] === 'A' ? 'Active' : 'Inactive';
            $status_class = $row['status'] === 'A' ? 'approved' : 'rejected';
            
            echo '<tr>
                    <td>' . $row['user_id'] . '</td>
                    <td>' . htmlspecialchars($row['Name']) . '</td>
                    <td>' . htmlspecialchars($row['Email']) . '</td>
                    <td>' . htmlspecialchars($row['Phone']) . '</td>
                    <td>' . $user_type . '</td>
                    <td><span class="status ' . $status_class . '">' . $status . '</span></td>
                  </tr>';
        }
        
        echo '</tbody></table>';
    } else {
        echo '<p>No users found.</p>';
    }
}

function generateRevenuePDFReport($conn) {
    echo '<h2>Revenue Report</h2>';
    
    // Summary section
    $booking_revenue_result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM equipment_bookings WHERE status = 'CON'");
    $booking_revenue = $booking_revenue_result ? $booking_revenue_result->fetch_assoc()['total'] : 0;
    
    $product_revenue_result = $conn->query("SELECT COALESCE(SUM(total_price), 0) as total FROM product_orders WHERE Status = 'CON'");
    $product_revenue = $product_revenue_result ? $product_revenue_result->fetch_assoc()['total'] : 0;
    
    $subscription_revenue_result = $conn->query("SELECT COALESCE(SUM(Amount), 0) as total FROM payments WHERE Status = 'C'");
    $subscription_revenue = $subscription_revenue_result ? $subscription_revenue_result->fetch_assoc()['total'] : 0;
    
    $total_revenue = $booking_revenue + $product_revenue + $subscription_revenue;
    
    echo '<h3>Revenue Summary</h3>
          <table style="max-width: 400px;">
            <tr><th>Source</th><th class="price">Amount</th></tr>
            <tr><td>Equipment Bookings</td><td class="price">' . formatIndianCurrency($booking_revenue) . '</td></tr>
            <tr><td>Product Sales</td><td class="price">' . formatIndianCurrency($product_revenue) . '</td></tr>
            <tr><td>Subscriptions</td><td class="price">' . formatIndianCurrency($subscription_revenue) . '</td></tr>
            <tr style="border-top: 2px solid #234a23; font-weight: bold;"><td>Total Revenue</td><td class="price">' . formatIndianCurrency($total_revenue) . '</td></tr>
          </table>';
}

function generateCompletePDFReport($conn) {
    echo '<h2>Complete Website Report</h2>';
    
    // Summary statistics
    $total_users = getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type != 'A'");
    $total_equipment = getCount($conn, "SELECT COUNT(*) as total FROM equipment");
    $total_products = getCount($conn, "SELECT COUNT(*) as total FROM product");
    $total_bookings = getCount($conn, "SELECT COUNT(*) as total FROM equipment_bookings");
    $total_orders = getCount($conn, "SELECT COUNT(*) as total FROM product_orders");
    
    echo '<h3>Platform Overview</h3>
          <table style="max-width: 400px;">
            <tr><th>Category</th><th>Count</th></tr>
            <tr><td>Total Users</td><td>' . formatIndianNumber($total_users) . '</td></tr>
            <tr><td>Equipment Listings</td><td>' . formatIndianNumber($total_equipment) . '</td></tr>
            <tr><td>Product Listings</td><td>' . formatIndianNumber($total_products) . '</td></tr>
            <tr><td>Total Bookings</td><td>' . formatIndianNumber($total_bookings) . '</td></tr>
            <tr><td>Total Orders</td><td>' . formatIndianNumber($total_orders) . '</td></tr>
          </table>';
    
    // Revenue summary
    generateRevenuePDFReport($conn);
}

// Export Functions with CORRECT price formatting
function exportUsersData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['User ID', 'Name', 'Email', 'Phone', 'User Type', 'Status']);
    
    $query = "SELECT user_id, Name, Email, Phone, User_type, status FROM users WHERE User_type != 'A' ORDER BY user_id DESC";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch users data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No users found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            $user_type = $row['User_type'] === 'F' ? 'Farmer' : 'Equipment Owner';
            $status = $row['status'] === 'A' ? 'Active' : 'Inactive';
            
            fputcsv($output, [
                $row['user_id'],
                $row['Name'],
                $row['Email'],
                $row['Phone'],
                $user_type,
                $status
            ]);
        }
    }
    
    fclose($output);
}

function exportEquipmentData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Equipment ID', 'Title', 'Brand', 'Model', 'Year', 'Daily Rate (INR)', 'Hourly Rate (INR)', 'Owner Name', 'Status', 'Listed Date']);
    
    $query = "SELECT e.Equipment_id, e.Title, e.Brand, e.Model, e.Year, e.Daily_rate, e.Hourly_rate, 
                     u.Name as owner_name, e.Approval_status, e.listed_date 
              FROM equipment e 
              JOIN users u ON e.Owner_id = u.user_id 
              ORDER BY e.listed_date DESC";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch equipment data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No equipment found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Approved', 'PEN' => 'Pending', 'REJ' => 'Rejected'];
            $status = $status_map[$row['Approval_status']] ?? $row['Approval_status'];
            
            fputcsv($output, [
                $row['Equipment_id'],
                $row['Title'],
                $row['Brand'],
                $row['Model'],
                $row['Year'],
                number_format($row['Daily_rate'], 2),  // Proper decimal format
                number_format($row['Hourly_rate'], 2), // Proper decimal format
                $row['owner_name'],
                $status,
                date('Y-m-d H:i:s', strtotime($row['listed_date']))
            ]);
        }
    }
    
    fclose($output);
}

function exportProductsData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Product ID', 'Name', 'Price per Unit (INR)', 'Quantity', 'Unit', 'Seller Name', 'Status', 'Listed Date']);
    
    $query = "SELECT p.product_id, p.Name, p.Price, p.Quantity, p.Unit, 
                     u.Name as seller_name, p.Approval_status, p.listed_date 
              FROM product p 
              JOIN users u ON p.seller_id = u.user_id 
              ORDER BY p.listed_date DESC";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch products data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No products found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Approved', 'PEN' => 'Pending', 'REJ' => 'Rejected'];
            $status = $status_map[$row['Approval_status']] ?? $row['Approval_status'];
            
            fputcsv($output, [
                $row['product_id'],
                $row['Name'],
                number_format($row['Price'], 2),  // Proper decimal format
                $row['Quantity'],
                $row['Unit'],
                $row['seller_name'],
                $status,
                date('Y-m-d H:i:s', strtotime($row['listed_date']))
            ]);
        }
    }
    
    fclose($output);
}

function exportBookingsData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Booking ID', 'Equipment', 'Customer', 'Start Date', 'End Date', 'Hours', 'Total Amount (INR)', 'Status', 'Time Slot']);
    
    $query = "SELECT eb.booking_id, e.Title as equipment_name, u.Name as customer_name, 
                     eb.start_date, eb.end_date, eb.Hours, eb.total_amount, eb.status, eb.time_slot 
              FROM equipment_bookings eb 
              JOIN equipment e ON eb.equipment_id = e.Equipment_id 
              JOIN users u ON eb.customer_id = u.user_id 
              ORDER BY eb.start_date DESC";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch bookings data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No bookings found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Confirmed', 'PEN' => 'Pending', 'CAN' => 'Cancelled'];
            $status = $status_map[$row['status']] ?? $row['status'];
            
            fputcsv($output, [
                $row['booking_id'],
                $row['equipment_name'],
                $row['customer_name'],
                $row['start_date'],
                $row['end_date'],
                $row['Hours'],
                number_format($row['total_amount'], 2),  // Proper decimal format
                $status,
                $row['time_slot'] ?? 'N/A'
            ]);
        }
    }
    
    fclose($output);
}

function exportOrdersData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Order ID', 'Product', 'Buyer', 'Quantity', 'Total Price (INR)', 'Status', 'Order Date']);
    
    $query = "SELECT po.Order_id, p.Name as product_name, u.Name as buyer_name, 
                     po.quantity, po.total_price, po.Status, po.order_date 
              FROM product_orders po 
              JOIN product p ON po.Product_id = p.product_id 
              JOIN users u ON po.buyer_id = u.user_id 
              ORDER BY po.order_date DESC";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch orders data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No orders found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            $status_map = ['CON' => 'Confirmed', 'PEN' => 'Pending', 'CAN' => 'Cancelled'];
            $status = $status_map[$row['Status']] ?? $row['Status'];
            
            fputcsv($output, [
                $row['Order_id'],
                $row['product_name'],
                $row['buyer_name'],
                $row['quantity'],
                number_format($row['total_price'], 2),  // Proper decimal format
                $status,
                date('Y-m-d H:i:s', strtotime($row['order_date']))
            ]);
        }
    }
    
    fclose($output);
}

function exportSubscriptionsData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Subscription ID', 'User Name', 'Plan Name', 'Start Date', 'End Date', 'Status', 'Amount Paid (INR)']);
    
    $query = "SELECT us.subscription_id, u.Name as user_name, sp.Plan_name, 
                     us.start_date, us.end_date, us.Status,
                     p.Amount as paid_amount
              FROM user_subscriptions us 
              JOIN users u ON us.user_id = u.user_id 
              JOIN subscription_plans sp ON us.plan_id = sp.plan_id 
              LEFT JOIN payments p ON us.subscription_id = p.Subscription_id 
              ORDER BY us.subscription_id DESC";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch subscriptions data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No subscriptions found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            $status_map = ['A' => 'Active', 'C' => 'Confirmed', 'P' => 'Pending'];
            $status = $status_map[$row['Status']] ?? $row['Status'];
            
            fputcsv($output, [
                $row['subscription_id'],
                $row['user_name'],
                $row['Plan_name'],
                $row['start_date'] ?? 'N/A',
                $row['end_date'] ?? 'N/A',
                $status,
                $row['paid_amount'] ? number_format($row['paid_amount'], 2) : 'N/A'
            ]);
        }
    }
    
    fclose($output);
}

function exportReviewsData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Review ID', 'Reviewer', 'Type', 'Rating', 'Comment', 'Created Date']);
    
    $query = "SELECT r.Review_id, u.Name as reviewer_name, r.Review_type, r.Rating, r.comment, r.created_date 
              FROM reviews r 
              JOIN users u ON r.Reviewer_id = u.user_id 
              ORDER BY r.created_date DESC";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch reviews data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No reviews found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            $type = $row['Review_type'] === 'E' ? 'Equipment' : 'Product';
            
            fputcsv($output, [
                $row['Review_id'],
                $row['reviewer_name'],
                $type,
                $row['Rating'] . '/5 stars',
                $row['comment'] ?? 'No comment',
                date('Y-m-d H:i:s', strtotime($row['created_date']))
            ]);
        }
    }
    
    fclose($output);
}

function exportMessagesData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Message ID', 'Sender', 'Receiver', 'Content', 'Read Status', 'Sent At']);
    
    $query = "SELECT m.message_id, 
                     s.Name as sender_name, 
                     r.Name as receiver_name, 
                     m.Content, m.is_read, m.sent_at 
              FROM messages m 
              JOIN users s ON m.sender_id = s.user_id 
              JOIN users r ON m.receiver_id = r.user_id 
              ORDER BY m.sent_at DESC 
              LIMIT 1000";
    $result = $conn->query($query);
    
    if ($result === false) {
        fputcsv($output, ['Error: Could not fetch messages data - ' . $conn->error]);
        fclose($output);
        return;
    }
    
    if ($result->num_rows === 0) {
        fputcsv($output, ['No messages found']);
    } else {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['message_id'],
                $row['sender_name'],
                $row['receiver_name'],
                substr($row['Content'], 0, 100) . (strlen($row['Content']) > 100 ? '...' : ''),
                $row['is_read'] ? 'Read' : 'Unread',
                date('Y-m-d H:i:s', strtotime($row['sent_at']))
            ]);
        }
    }
    
    fclose($output);
}

function exportRevenueData($conn, $format) {
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Revenue Type', 'Amount (INR)', 'Date', 'Source', 'Status']);
    
    // Equipment bookings revenue
    $booking_query = "SELECT 'Equipment Booking' as type, total_amount as amount, 
                             start_date as date, 'Booking Revenue' as source, status 
                      FROM equipment_bookings 
                      WHERE status = 'CON' 
                      ORDER BY start_date DESC";
    $booking_result = $conn->query($booking_query);
    
    if ($booking_result && $booking_result->num_rows > 0) {
        while ($row = $booking_result->fetch_assoc()) {
            fputcsv($output, [
                $row['type'],
                number_format($row['amount'], 2),
                $row['date'],
                $row['source'],
                'Confirmed'
            ]);
        }
    }
    
    // Product orders revenue
    $order_query = "SELECT 'Product Sale' as type, total_price as amount, 
                           order_date as date, 'Product Revenue' as source, Status 
                    FROM product_orders 
                    WHERE Status = 'CON' 
                    ORDER BY order_date DESC";
    $order_result = $conn->query($order_query);
    
    if ($order_result && $order_result->num_rows > 0) {
        while ($row = $order_result->fetch_assoc()) {
            fputcsv($output, [
                $row['type'],
                number_format($row['amount'], 2),
                $row['date'],
                $row['source'],
                'Confirmed'
            ]);
        }
    }
    
    // Subscription payments revenue
    $payment_query = "SELECT 'Subscription Payment' as type, p.Amount as amount, 
                             p.payment_date as date, 'Subscription Revenue' as source, p.Status 
                      FROM payments p 
                      WHERE p.Status = 'C' 
                      ORDER BY p.payment_date DESC";
    $payment_result = $conn->query($payment_query);
    
    if ($payment_result && $payment_result->num_rows > 0) {
        while ($row = $payment_result->fetch_assoc()) {
            fputcsv($output, [
                $row['type'],
                number_format($row['amount'], 2),
                date('Y-m-d', strtotime($row['date'])),
                $row['source'],
                'Confirmed'
            ]);
        }
    }
    
    fclose($output);
}

function exportCompleteReport($conn, $format) {
    $output = fopen('php://output', 'w');
    
    // Add header
    fputcsv($output, ['AGRIRENT COMPLETE WEBSITE REPORT']);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Users summary
    fputcsv($output, ['USERS SUMMARY']);
    fputcsv($output, ['Category', 'Count']);
    
    $total_users = getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type != 'A'");
    $farmers = getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type = 'F'");
    $owners = getCount($conn, "SELECT COUNT(*) as total FROM users WHERE User_type = 'O'");
    
    fputcsv($output, ['Total Users', formatIndianNumber($total_users)]);
    fputcsv($output, ['Farmers', formatIndianNumber($farmers)]);
    fputcsv($output, ['Equipment Owners', formatIndianNumber($owners)]);
    fputcsv($output, []);
    
    // Equipment summary  
    fputcsv($output, ['EQUIPMENT SUMMARY']);
    fputcsv($output, ['Category', 'Count']);
    
    $total_equipment = getCount($conn, "SELECT COUNT(*) as total FROM equipment");
    $approved_equipment = getCount($conn, "SELECT COUNT(*) as total FROM equipment WHERE Approval_status = 'CON'");
    
    fputcsv($output, ['Total Equipment', formatIndianNumber($total_equipment)]);
    fputcsv($output, ['Approved Equipment', formatIndianNumber($approved_equipment)]);
    fputcsv($output, []);
    
    // Revenue summary
    fputcsv($output, ['REVENUE SUMMARY']);
    fputcsv($output, ['Type', 'Amount (INR)']);
    
    $booking_revenue_result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM equipment_bookings WHERE status = 'CON'");
    $booking_revenue = $booking_revenue_result ? $booking_revenue_result->fetch_assoc()['total'] : 0;
    
    $product_revenue_result = $conn->query("SELECT COALESCE(SUM(total_price), 0) as total FROM product_orders WHERE Status = 'CON'");
    $product_revenue = $product_revenue_result ? $product_revenue_result->fetch_assoc()['total'] : 0;
    
    $subscription_revenue_result = $conn->query("SELECT COALESCE(SUM(Amount), 0) as total FROM payments WHERE Status = 'C'");
    $subscription_revenue = $subscription_revenue_result ? $subscription_revenue_result->fetch_assoc()['total'] : 0;
    
    fputcsv($output, ['Equipment Booking Revenue', number_format($booking_revenue, 2)]);
    fputcsv($output, ['Product Sales Revenue', number_format($product_revenue, 2)]);
    fputcsv($output, ['Subscription Revenue', number_format($subscription_revenue, 2)]);
    fputcsv($output, ['Total Revenue', number_format($booking_revenue + $product_revenue + $subscription_revenue, 2)]);
    
    fclose($output);
}

require 'footer.php';
?>
