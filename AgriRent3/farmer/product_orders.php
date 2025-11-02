<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in as Farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = $_SESSION['user_id'];
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Handle AJAX status update
if (isset($_POST['update_status'])) {
    header('Content-Type: application/json');
    
    $order_id = intval($_POST['order_id']);
    $current_status = $_POST['current_status'];
    $new_status = $_POST['new_status'];
    
    // Define allowed status transitions
    $allowed_transitions = [
        'PEN' => ['CON', 'REJ'],
        'CON' => ['COM'],
        'COM' => [],
        'REJ' => []
    ];
    
    // Check if transition is allowed
    if (isset($allowed_transitions[$current_status]) && in_array($new_status, $allowed_transitions[$current_status])) {
        $update_sql = "UPDATE product_orders po
                       JOIN product p ON po.Product_id = p.product_id
                       SET po.Status = ?
                       WHERE po.Order_id = ? AND p.seller_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sii", $new_status, $order_id, $farmer_id);
        
        if ($update_stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Order status updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating status']);
        }
        $update_stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid status transition!']);
    }
    exit();
}

// Build query based on status filter
$where_clause = "WHERE p.seller_id = ?";
$params = [$farmer_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND po.Status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Fetch product orders with details and images
$sql = "SELECT 
            po.*,
            p.Name as product_name,
            p.Price,
            p.Quantity as current_stock,
            p.Unit,
            p.seller_id,
            u.Name as buyer_name,
            u.Phone as buyer_phone,
            u.Email as buyer_email,
            ua.address,
            ua.city,
            ua.state,
            ua.Pin_code,
            i.image_url
        FROM product_orders po
        JOIN product p ON po.Product_id = p.product_id
        JOIN users u ON po.buyer_id = u.user_id
        LEFT JOIN user_addresses ua ON po.delivery_address = ua.address_id
        LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
        $where_clause
        ORDER BY po.order_date DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param($param_types, ...$params);
if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics - EARNINGS ONLY COUNT COMPLETED ORDERS
$stats_sql = "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN po.Status = 'PEN' THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN po.Status = 'CON' THEN 1 ELSE 0 END) as confirmed_orders,
                SUM(CASE WHEN po.Status = 'COM' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN po.Status = 'COM' THEN po.total_price ELSE 0 END) as total_earnings
             FROM product_orders po
             JOIN product p ON po.Product_id = p.product_id
             WHERE p.seller_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $farmer_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<style>
    /* Page Styles */
    .main-content {
        padding: 20px;
        background: #f5f7fa;
    }

    .page-header {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #f0f0f0;
    }

    .page-header h1 {
        margin: 0 0 5px 0;
        color: #234a23;
        font-size: 28px;
    }

    .page-header p {
        color: #666;
        margin: 0;
    }

    /* Statistics Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .stat-label {
        font-size: 14px;
        color: #234a23;
        margin-bottom: 10px;
    }

    .stat-value {
        font-size: 28px;
        font-weight: bold;
    }

    /* Filter Tabs */
    .filter-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .filter-tab {
        padding: 10px 20px;
        text-decoration: none;
        border: 1px solid #ddd;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s;
        background: #f8f9fa;
        color: #333;
        font-weight: 500;
    }

    .filter-tab.active {
        background: #234a23;
        color: white;
        border-color: #234a23;
    }

    .filter-tab:hover {
        background: #234a23;
        color: white;
        border-color: #234a23;
    }

    /* Table Styles */
    .orders-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    .orders-table thead {
        background: #f8f9fa;
    }

    .orders-table th {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #eee;
        font-weight: bold;
        color: #234a23;
    }

    .orders-table td {
        padding: 12px;
        border-bottom: 1px solid #eee;
    }

    .orders-table tbody tr:hover {
        background: #f8f9fa;
    }

    /* Status Badge */
    .status-badge {
        padding: 6px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
    }

    .status-pending {
        background: #fff3e0;
        color: #ff9800;
    }

    .status-confirmed {
        background: #e3f2fd;
        color: #2196f3;
    }

    .status-completed {
        background: #e8f5e9;
        color: #4caf50;
    }

    .status-rejected {
        background: #ffebee;
        color: #f44336;
    }

    /* Buttons */
    .btn-view {
        background: #17a2b8;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .btn-view:hover {
        background: #138496;
    }

    /* No Data Message */
    .no-data {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 8px;
        color: #666;
    }

    /* Modal Styles */
    .modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        display: none;
    }

    .modal-content-large {
        background-color: #fefefe;
        margin: 3% auto;
        padding: 0;
        border: none;
        border-radius: 8px;
        width: 90%;
        max-width: 700px;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        position: relative;
    }

    .modal-header {
        background: #234a23;
        color: white;
        margin: 0;
        padding: 20px;
        border-radius: 8px 8px 0 0;
    }

    .modal-body {
        padding: 20px;
    }

    .modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #eee;
        text-align: center;
        background: #f8f9fa;
        border-radius: 0 0 8px 8px;
    }

    .close-btn {
        position: absolute;
        top: 15px;
        right: 25px;
        color: #999;
        font-size: 35px;
        font-weight: bold;
        cursor: pointer;
        z-index: 1001;
    }

    .close-btn:hover {
        color: #333;
    }

    /* Detail Table */
    .detail-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 25px;
    }

    .detail-table tr {
        border-bottom: 1px solid #eee;
    }

    .detail-table td {
        padding: 10px;
        border: 1px solid #eee;
    }

    .detail-table td:first-child {
        background: #f8f9fa;
        font-weight: bold;
        color: #234a23;
        width: 30%;
    }

    .section-title {
        color: #234a23;
        margin: 25px 0 15px 0;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
        font-size: 16px;
        font-weight: 600;
    }

    .product-image {
        text-align: center;
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        margin-bottom: 20px;
    }

    .product-image img {
        max-width: 250px;
        max-height: 250px;
        border-radius: 6px;
        border: 1px solid #ddd;
    }

    /* Status Update Form */
    .status-update-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        margin-top: 20px;
    }

    .status-dropdown {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: white;
        color: #333;
        cursor: pointer;
        font-weight: 500;
        font-size: 14px;
    }

    .btn-update {
        background: #28a745;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        margin-left: 10px;
        transition: all 0.3s;
    }

    .btn-update:hover {
        background: #218838;
    }

    .btn-update:disabled {
        background: #ccc;
        cursor: not-allowed;
    }

    .btn-update.loading {
        opacity: 0.6;
        pointer-events: none;
    }

    .btn-close {
        background: #6c757d;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }

    .btn-close:hover {
        background: #5a6268;
    }

    .status-info {
        background: #e7f3ff;
        border-left: 4px solid #2196f3;
        padding: 12px;
        margin-bottom: 15px;
        border-radius: 4px;
        font-size: 13px;
        color: #0c5460;
    }

    .alert {
        padding: 12px 15px;
        border-radius: 4px;
        margin-bottom: 15px;
        font-size: 14px;
        display: none;
    }

    .alert.success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
        display: block;
    }

    .alert.error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
        display: block;
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .orders-table {
            font-size: 12px;
        }

        .orders-table th,
        .orders-table td {
            padding: 8px;
        }

        .modal-content-large {
            width: 95%;
            margin: 10% auto;
        }
    }
</style>

<div class="main-content">
    <div class="page-header">
        <h1>Product Order Management</h1>
        <p>Manage customer orders for your agricultural products</p>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value" style="color: #234a23;"><?= $stats['total_orders'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending Orders</div>
            <div class="stat-value" style="color: #234a23;"><?= $stats['pending_orders'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Confirmed Orders</div>
            <div class="stat-value" style="color: #234a23;"><?= $stats['confirmed_orders'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Completed Orders</div>
            <div class="stat-value" style="color: #234a23;"><?= $stats['completed_orders'] ?? 0 ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Earnings</div>
            <div class="stat-value" style="color: #234a23;">₹<?= number_format($stats['total_earnings'] ?? 0, 2) ?></div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <?php
        $statuses = ['all' => 'All Orders', 'PEN' => 'Pending', 'CON' => 'Confirmed', 'COM' => 'Completed', 'REJ' => 'Rejected'];
        foreach ($statuses as $key => $label):
            $active = ($status_filter === $key) ? 'active' : '';
        ?>
            <a href="?status=<?= $key ?>" class="filter-tab <?= $active ?>">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Orders Table -->
    <?php if (count($orders) > 0): ?>
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Quantity</th>
                    <th>Total Price</th>
                    <th>Order Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $status_map = [
                        'PEN' => ['text' => 'Pending', 'class' => 'status-pending'],
                        'CON' => ['text' => 'Confirmed', 'class' => 'status-confirmed'],
                        'COM' => ['text' => 'Completed', 'class' => 'status-completed'],
                        'REJ' => ['text' => 'Rejected', 'class' => 'status-rejected']
                    ];
                    $current_status = $status_map[$order['Status']] ?? $status_map['PEN'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($order['Order_id']) ?></td>
                        <td>
                            <strong><?= htmlspecialchars($order['product_name']) ?></strong><br>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($order['buyer_name']) ?></strong><br>
                        </td>
                        <td><?= number_format($order['quantity'], 2) ?> <?= strtoupper($order['Unit'] ?? 'UNIT') ?></td>
                        <td><strong>₹<?= number_format($order['total_price'], 2) ?></strong></td>
                        <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                        <td><span class="status-badge <?= $current_status['class'] ?>"><?= $current_status['text'] ?></span></td>
                        <td>
                            <button type="button" class="btn-view" onclick="viewOrder(<?= htmlspecialchars(json_encode($order)) ?>)">
                                View Details
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">
            <h3>No Orders Found</h3>
            <p><?= $status_filter !== 'all' ? 'No orders found with status: ' . strtoupper($status_filter) : 'No product orders received yet' ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- Order Details Modal -->
<div id="orderModal" class="modal">
    <div class="modal-content-large">
        <span class="close-btn" onclick="closeOrderModal()">×</span>
        
        <h2 class="modal-header">Order Details</h2>

        <div class="modal-body">
            
            <!-- Alert Messages -->
            <div id="alertMessage" class="alert"></div>

            <!-- Product Image -->
            <div class="product-image" id="productImageContainer">
                <img id="detailProductImage" src="" alt="Product">
            </div>

            <!-- Order Summary -->
            <h3 class="section-title">Order Summary</h3>
            <table class="detail-table">
                <tr>
                    <td>Order ID</td>
                    <td id="detailOrderId"></td>
                </tr>
                <tr>
                    <td>Product Name</td>
                    <td id="detailProductName"></td>
                </tr>
                <tr>
                    <td>Price per Unit</td>
                    <td id="detailPrice"></td>
                </tr>
                <tr>
                    <td>Order Quantity</td>
                    <td id="detailQuantity"></td>
                </tr>
                <tr>
                    <td>Total Price</td>
                    <td id="detailTotalPrice"></td>
                </tr>
                <tr>
                    <td>Order Date</td>
                    <td id="detailOrderDate"></td>
                </tr>
                <tr>
                    <td>Status</td>
                    <td id="detailStatus"></td>
                </tr>
            </table>

            <!-- Customer Information -->
            <h3 class="section-title">Customer Information</h3>
            <table class="detail-table">
                <tr>
                    <td>Customer Name</td>
                    <td id="detailBuyerName"></td>
                </tr>
                <tr>
                    <td>Phone</td>
                    <td id="detailBuyerPhone"></td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td id="detailBuyerEmail"></td>
                </tr>
            </table>

            <!-- Delivery Address -->
            <h3 class="section-title">Delivery Address</h3>
            <table class="detail-table">
                <tr>
                    <td>Address</td>
                    <td id="detailAddress"></td>
                </tr>
                <tr>
                    <td>City</td>
                    <td id="detailCity"></td>
                </tr>
                <tr>
                    <td>State</td>
                    <td id="detailState"></td>
                </tr>
                <tr>
                    <td>Pin Code</td>
                    <td id="detailPinCode"></td>
                </tr>
            </table>

            <!-- Status Update Form -->
            <div class="status-update-section" id="statusUpdateSection">
                <h3 class="section-title">Update Order Status</h3>
                <div class="status-info" id="statusInfo"></div>
                
                <form id="statusUpdateForm" onsubmit="updateOrderStatus(event)">
                    <input type="hidden" name="order_id" id="statusOrderId">
                    <input type="hidden" name="current_status" id="currentStatus">
                    
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <select name="new_status" id="statusSelect" class="status-dropdown" required>
                            <option value="">-- Select Status --</option>
                        </select>
                        
                        <button type="submit" class="btn-update" id="updateBtn">Update Status</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Actions -->
        <div class="modal-footer">
            <button type="button" class="btn-close" onclick="closeOrderModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Define status workflow
const statusWorkflow = {
    'PEN': { 
        allowed: ['CON', 'REJ'], 
        info: 'Pending order - You can Confirm or Reject this order'
    },
    'CON': { 
        allowed: ['COM'], 
        info: 'Confirmed order - You can mark it as Completed'
    },
    'COM': { 
        allowed: [], 
        info: 'Completed order - This order cannot be changed'
    },
    'REJ': { 
        allowed: [], 
        info: 'Rejected order - This order cannot be changed'
    }
};

const statusLabels = {
    'PEN': 'Pending',
    'CON': 'Confirmed',
    'COM': 'Completed',
    'REJ': 'Rejected'
};

function viewOrder(order) {
    document.getElementById('detailOrderId').textContent = order.Order_id;
    document.getElementById('detailProductName').textContent = order.product_name || 'N/A';
    document.getElementById('detailPrice').textContent = '₹' + parseFloat(order.Price).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('detailQuantity').textContent = parseFloat(order.quantity).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (order.Unit || 'UNIT');
    document.getElementById('detailTotalPrice').textContent = '₹' + parseFloat(order.total_price).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('detailOrderDate').textContent = new Date(order.order_date).toLocaleDateString('en-IN', { year: 'numeric', month: 'long', day: 'numeric' });
    
    // Status with styling
    const statusMap = {
        'PEN': '<span class="status-badge status-pending">Pending</span>',
        'CON': '<span class="status-badge status-confirmed">Confirmed</span>',
        'COM': '<span class="status-badge status-completed">Completed</span>',
        'REJ': '<span class="status-badge status-rejected">Rejected</span>'
    };
    document.getElementById('detailStatus').innerHTML = statusMap[order.Status] || statusMap['PEN'];
    
    // Customer Information
    document.getElementById('detailBuyerName').textContent = order.buyer_name || 'N/A';
    document.getElementById('detailBuyerPhone').textContent = order.buyer_phone || 'N/A';
    document.getElementById('detailBuyerEmail').textContent = order.buyer_email || 'N/A';
    
    // Delivery Address
    document.getElementById('detailAddress').textContent = order.address || 'Not available';
    document.getElementById('detailCity').textContent = order.city || 'N/A';
    document.getElementById('detailState').textContent = order.state || 'N/A';
    document.getElementById('detailPinCode').textContent = order.Pin_code || 'N/A';
    
    // Product Image
    if (order.image_url) {
        document.getElementById('detailProductImage').src = '../' + order.image_url;
    } else {
        document.getElementById('detailProductImage').src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="250" height="250"%3E%3Crect fill="%23f5f5f5" width="250" height="250"/%3E%3Ctext x="50%" y="50%" text-anchor="middle" dy=".3em" fill="%23999" font-size="16" font-family="Arial"%3ENo Image Available%3C/text%3E%3C/svg%3E';
    }
    
    // Set status workflow
    const currentStatus = order.Status;
    const workflow = statusWorkflow[currentStatus];
    const allowedStatuses = workflow.allowed;
    
    // Update form
    document.getElementById('statusOrderId').value = order.Order_id;
    document.getElementById('currentStatus').value = currentStatus;
    
    // Populate status dropdown
    const statusSelect = document.getElementById('statusSelect');
    statusSelect.innerHTML = '<option value="">-- Select Status --</option>';
    
    if (allowedStatuses.length > 0) {
        allowedStatuses.forEach(status => {
            const option = document.createElement('option');
            option.value = status;
            option.textContent = statusLabels[status];
            statusSelect.appendChild(option);
        });
        document.getElementById('updateBtn').disabled = false;
    } else {
        document.getElementById('updateBtn').disabled = true;
    }
    
    // Show status info
    document.getElementById('statusInfo').textContent = workflow.info;
    
    // Show/Hide update section
    if (allowedStatuses.length === 0) {
        document.getElementById('statusUpdateSection').style.opacity = '0.6';
    } else {
        document.getElementById('statusUpdateSection').style.opacity = '1';
    }
    
    // Clear alert
    document.getElementById('alertMessage').innerHTML = '';
    
    // Show modal
    document.getElementById('orderModal').style.display = 'block';
}

function updateOrderStatus(event) {
    event.preventDefault();
    
    const orderId = document.getElementById('statusOrderId').value;
    const currentStatus = document.getElementById('currentStatus').value;
    const newStatus = document.getElementById('statusSelect').value;
    const updateBtn = document.getElementById('updateBtn');
    
    if (!newStatus) {
        showAlert('Please select a status', 'error');
        return;
    }
    
    // Show loading state
    updateBtn.classList.add('loading');
    updateBtn.textContent = 'Updating...';
    
    // Send AJAX request
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'update_status=1&order_id=' + orderId + '&current_status=' + currentStatus + '&new_status=' + newStatus
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showAlert(data.message, 'error');
            updateBtn.classList.remove('loading');
            updateBtn.textContent = 'Update Status';
        }
    })
    .catch(error => {
        showAlert('Error updating status: ' + error, 'error');
        updateBtn.classList.remove('loading');
        updateBtn.textContent = 'Update Status';
    });
}

function showAlert(message, type) {
    const alertDiv = document.getElementById('alertMessage');
    alertDiv.textContent = message;
    alertDiv.className = 'alert ' + type;
}

function closeOrderModal() {
    document.getElementById('orderModal').style.display = 'none';
}

window.onclick = function(event) {
    const orderModal = document.getElementById('orderModal');
    if (event.target == orderModal) {
        orderModal.style.display = 'none';
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeOrderModal();
    }
});
</script>

<?php require 'ffooter.php'; ?>
