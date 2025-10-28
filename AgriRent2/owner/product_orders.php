<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$message = '';

// Handle order actions (approve/reject/complete/reapprove)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    $action = $_GET['action'];

    if (in_array($action, ['approve', 'reject', 'complete', 'reapprove'])) {
        $new_status = '';
        
        // Determine new status based on action
        if ($action == 'approve') {
            $new_status = 'CON';
        } elseif ($action == 'reject') {
            $new_status = 'REJ';
        } elseif ($action == 'complete') {
            $new_status = 'COM';
        } elseif ($action == 'reapprove') {
            $new_status = 'CON';  // Re-approval changes status back to confirmed
        }

        // Verify order belongs to this owner before updating
        $verify_stmt = $conn->prepare("SELECT po.Order_id, po.Status FROM product_orders po 
                                      JOIN product p ON po.Product_id = p.product_id 
                                      WHERE po.Order_id = ? AND p.seller_id = ?");
        $verify_stmt->bind_param('ii', $order_id, $owner_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();

        if ($verify_result->num_rows > 0) {
            $order_data = $verify_result->fetch_assoc();
            
            // Check validation rules
            if ($action == 'complete' && $order_data['Status'] != 'CON') {
                $message = 'Error: Only confirmed orders can be marked as complete.';
            } elseif ($action == 'reapprove' && $order_data['Status'] != 'REJ') {
                $message = 'Error: Only rejected orders can be re-approved.';
            } else {
                $update_stmt = $conn->prepare('UPDATE product_orders SET Status = ? WHERE Order_id = ?');
                $update_stmt->bind_param('si', $new_status, $order_id);

                if ($update_stmt->execute()) {
                    if ($action == 'approve') {
                        $message = 'Order approved successfully.';
                    } elseif ($action == 'reject') {
                        $message = 'Order rejected successfully.';
                    } elseif ($action == 'complete') {
                        $message = 'Order marked as complete successfully.';
                    } elseif ($action == 'reapprove') {
                        $message = 'Order re-approved successfully.';
                    }
                } else {
                    $message = 'Error updating order status.';
                }
                $update_stmt->close();
            }
        }
        $verify_stmt->close();

        header('Location: product_orders.php' . ($message ? '?msg=' . urlencode($message) : ''));
        exit();
    }
}

// Display message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Filter by status
$status_filter = $_GET['status'] ?? 'all';
$where_clause = "WHERE p.seller_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND po.Status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Fetch orders for this owner's products
$orders = [];
try {
    $query = "SELECT DISTINCT po.Order_id, po.Product_id, po.buyer_id, po.quantity, 
                     po.total_price, po.delivery_address, po.Status, po.order_date,
                     p.Name as product_name, p.Price as unit_price, p.Unit,
                     u.Name as customer_name, u.Phone as customer_phone, u.Email as customer_email,
                     ua.address, ua.city, ua.state, ua.Pin_code,
                     ps.Subcategory_name, pc.Category_name,
                     (SELECT i.image_url FROM images i WHERE i.image_type = 'P' AND i.ID = p.product_id LIMIT 1) as image_url
              FROM product_orders po 
              JOIN product p ON po.Product_id = p.product_id 
              JOIN users u ON po.buyer_id = u.user_id 
              LEFT JOIN user_addresses ua ON po.delivery_address = ua.address_id
              LEFT JOIN product_subcategories ps ON p.Subcategory_id = ps.Subcategory_id
              LEFT JOIN product_categories pc ON ps.Category_id = pc.Category_id
              $where_clause 
              ORDER BY po.Order_id DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Orders fetch error: " . $e->getMessage());
}

// Calculate statistics
$total_orders = count($orders);
$pending_orders = count(array_filter($orders, fn($o) => $o['Status'] == 'PEN'));
$confirmed_orders = count(array_filter($orders, fn($o) => $o['Status'] == 'CON'));
$completed_orders = count(array_filter($orders, fn($o) => $o['Status'] == 'COM'));
$rejected_orders = count(array_filter($orders, fn($o) => $o['Status'] == 'REJ'));
$total_revenue = array_sum(array_map(fn($o) => ($o['Status'] == 'CON' || $o['Status'] == 'COM') ? $o['total_price'] : 0, $orders));

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">
<div class="main-content">
    <h1>Product Order Requests</h1>
    <p style="color: #666; margin-bottom: 30px;">Manage order requests for your products</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="cards" style="margin-bottom: 30px;">
        <div class="card">
            <h3>Total Orders</h3>
            <div class="count"><?= $total_orders ?></div>
        </div>
        <div class="card">
            <h3>Pending Orders</h3>
            <div class="count"><?= $pending_orders ?></div>
        </div>
        <div class="card">
            <h3>Confirmed Orders</h3>
            <div class="count"><?= $confirmed_orders ?></div>
        </div>
        <div class="card">
            <h3>Completed Orders</h3>
            <div class="count"><?= $completed_orders ?></div>
        </div>
        <div class="card">
            <h3>Rejected Orders</h3>
            <div class="count"><?= $rejected_orders ?></div>
        </div>
        <div class="card">
            <h3>Total Revenue</h3>
            <div class="count">₹<?= number_format($total_revenue, 2) ?></div>
        </div>
    </div>

    <!-- Filter and Actions -->
    <div class="quick-actions" style="margin-bottom: 20px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
        <form method="GET" style="display: inline-block;">
            <select name="status" onchange="this.form.submit()" style="padding: 10px; border-radius: 4px; border: 1px solid #ddd; background: white;">
                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Orders</option>
                <option value="PEN" <?= $status_filter == 'PEN' ? 'selected' : '' ?>>Pending</option>
                <option value="CON" <?= $status_filter == 'CON' ? 'selected' : '' ?>>Confirmed</option>
                <option value="COM" <?= $status_filter == 'COM' ? 'selected' : '' ?>>Completed</option>
                <option value="REJ" <?= $status_filter == 'REJ' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </form>
        
        <!-- Live Search Box -->
        <div style="display: flex; align-items: center; gap: 10px;">
            <input type="text" id="orderSearch" placeholder="Search orders..." style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 300px;">
            <button type="button" id="clearSearch" style="padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; background: white; cursor: pointer;">Clear</button>
        </div>
        
        <span style="color: #666; font-size: 14px;">
            Showing <?= count($orders) ?> order(s)
        </span>
    </div>

    <!-- Orders Table -->
    <?php if (count($orders) > 0): ?>
        <table id="ordersTable">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Order Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($orders as $order):
                    $status_map = [
                        'CON' => ['status-confirmed', 'Confirmed'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected'],
                        'COM' => ['status-completed', 'Completed']
                    ];
                    list($status_class, $status_text) = $status_map[$order['Status']] ?? ['', 'Unknown'];
                    
                    $unit_text = $order['Unit'] === 'K' ? 'Kg' : ($order['Unit'] === 'L' ? 'Liter' : 'Piece');
                    ?>
                    <tr class="order-row">
                        <td><strong><?= htmlspecialchars($order['product_name']) ?></strong></td>
                        <td><?= htmlspecialchars($order['customer_name']) ?></td>
                        <td><?= htmlspecialchars($order['customer_phone']) ?></td>
                        <td><?= number_format($order['quantity'], 2) ?> <?= $unit_text ?></td>
                        <td>₹<?= number_format($order['unit_price'], 2) ?></td>
                        <td>₹<?= number_format($order['total_price'], 2) ?></td>
                        <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                        <td>
                            <!-- Always show Details button -->
                            <button type="button" onclick="viewOrderDetails(<?= htmlspecialchars(json_encode($order)) ?>)" 
                                    style="color: #17a2b8; font-weight: 600; background: none; border: none; cursor: pointer; text-decoration: underline; padding: 5px;">
                                Details
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px; background: white; border-radius: 8px;">
            <h3 style="color: #666; margin-bottom: 15px;">
                <?= $status_filter !== 'all' ? 'No orders found with status: ' . strtoupper($status_filter) : 'No Product Orders Yet' ?>
            </h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?= $status_filter !== 'all' ? 'Try changing the filter or wait for new orders.' : 'When farmers order your products, they will appear here for your approval.' ?>
            </p>
            <a href="add_product.php" class="action-btn">➕ Add More Products</a>
        </div>
    <?php endif; ?>
</div>

<!-- Order Details Modal -->
<div id="orderDetailsModal" class="modal" style="display: none;">
    <div class="modal-content-large">
        <span class="close" onclick="closeOrderModal()">&times;</span>
        <h2 id="modalTitle">Order Details</h2>
        
        <div class="modal-body">
            <div class="order-image-section">
                <h3>Product Image</h3>
                <div id="orderImageContainer">
                    <!-- Image will be inserted here -->
                </div>
            </div>
            
            <div class="order-details-section">
                <h3>Order Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Order ID:</strong>
                        <span id="detailOrderId"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Product:</strong>
                        <span id="detailProductName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Category:</strong>
                        <span id="detailCategory"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Quantity Ordered:</strong>
                        <span id="detailQuantity"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Unit Price:</strong>
                        <span id="detailUnitPrice"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Total Amount:</strong>
                        <span id="detailTotalAmount"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong>
                        <span id="detailStatus"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Order Date:</strong>
                        <span id="detailOrderDate"></span>
                    </div>
                </div>
            </div>
            
            <div class="customer-details-section">
                <h3>Customer Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Customer Name:</strong>
                        <span id="detailCustomerName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Phone Number:</strong>
                        <span id="detailCustomerPhone"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Email:</strong>
                        <span id="detailCustomerEmail"></span>
                    </div>
                </div>
            </div>
            
            <div class="delivery-details-section">
                <h3>Delivery Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Delivery Address:</strong>
                        <span id="detailDeliveryAddress"></span>
                    </div>
                </div>
            </div>
            
            <div class="modal-actions">
                <div id="orderActions">
                    <!-- Action buttons will be inserted here based on status -->
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Live search styling */
.order-row.hidden {
    display: none !important;
}

#orderSearch:focus {
    outline: none;
    border-color: #234a23;
}

#clearSearch:hover {
    background: #f5f5f5;
    color: #234a23;
}

/* Completed status styling */
.status-completed {
    background-color: #234a23;
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

/* Modal Styles */
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.modal-content-large {
    background-color: #fefefe;
    margin: 2% auto;
    padding: 0;
    border: none;
    border-radius: 8px;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
}

.modal-content-large h2 {
    background: #234a23;
    color: white;
    margin: 0;
    padding: 20px;
    border-radius: 8px 8px 0 0;
}

.modal-body {
    padding: 20px;
}

.order-image-section, .order-details-section, .customer-details-section, .delivery-details-section {
    margin-bottom: 30px;
}

.order-image-section h3, .order-details-section h3, .customer-details-section h3, .delivery-details-section h3 {
    color: #234a23;
    margin-bottom: 15px;
    border-bottom: 2px solid #eee;
    padding-bottom: 5px;
}

#orderImageContainer {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 5px;
}

#orderImageContainer img {
    max-width: 100%;
    max-height: 300px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    object-fit: cover;
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}

.detail-row {
    display: flex;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
    border-left: 3px solid #234a23;
}

.detail-row strong {
    min-width: 150px;
    color: #234a23;
}

.detail-row span {
    flex: 1;
    margin-left: 10px;
}

.modal-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #eee;
    text-align: center;
}

.modal-actions a {
    display: inline-block;
    margin: 0 5px;
    padding: 10px 20px;
    text-decoration: none;
    border-radius: 4px;
    font-weight: bold;
}

.btn-approve {
    background: #234a23;
    color: white;
}

.btn-reject {
    background: #dc3545;
    color: white;
}

.btn-complete {
    background: #234a23;
    color: white;
}

.btn-reapprove {
    background: #ffc107;
    color: #212529;
}

.btn-approve:hover {
    background: #235a23;
    color: white;
}

.btn-reject:hover {
    background: #dc4545;
    color: white;
}

.btn-complete:hover {
    background: #233a23;
    color: white;
}

.btn-reapprove:hover {
    background: #ffc207;
    color: #212529;
}

.close {
    position: absolute;
    top: 15px;
    right: 25px;
    color: white;
    font-size: 35px;
    font-weight: bold;
    cursor: pointer;
    z-index: 1001;
}

.close:hover {
    color: #ccc;
}

/* Responsive adjustments for search */
@media (max-width: 768px) {
    .quick-actions {
        flex-direction: column;
        align-items: stretch !important;
        gap: 15px !important;
    }
    
    #orderSearch {
        width: 100% !important;
    }
    
    .quick-actions > div {
        width: 100%;
    }
    
    .modal-content-large {
        width: 95%;
        margin: 1% auto;
    }
    
    .detail-row {
        flex-direction: column;
    }
    
    .detail-row strong {
        min-width: auto;
        margin-bottom: 5px;
    }
    
    .detail-row span {
        margin-left: 0;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Live search functionality
    $('#orderSearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#ordersTable tbody tr.order-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
    });
    
    // Clear search functionality
    $('#clearSearch').on('click', function() {
        $('#orderSearch').val('');
        $('#ordersTable tbody tr.order-row').removeClass('hidden');
        $('#orderSearch').focus();
    });
    
    // Auto hide message after 5 seconds
    if ($('.message').length > 0) {
        setTimeout(function() {
            $('.message').fadeOut(1000, function() {
                $(this).remove();
            });
        }, 5000);
    }
});

// View order details function
function viewOrderDetails(order) {
    // Populate order details
    document.getElementById('detailOrderId').textContent = '#' + order.Order_id;
    document.getElementById('detailProductName').textContent = order.product_name || 'N/A';
    document.getElementById('detailCategory').textContent = (order.Category_name || 'N/A') + 
        (order.Subcategory_name ? ' - ' + order.Subcategory_name : '');
    
    // Unit text
    const unitText = order.Unit === 'K' ? 'Kg' : (order.Unit === 'L' ? 'Liter' : 'Piece');
    document.getElementById('detailQuantity').textContent = parseFloat(order.quantity).toFixed(2) + ' ' + unitText;
    document.getElementById('detailUnitPrice').textContent = '₹' + parseFloat(order.unit_price).toFixed(2) + '/' + unitText;
    document.getElementById('detailTotalAmount').innerHTML = '<span style="color: #28a745; font-weight: bold; font-size: 18px;">₹' + 
        parseFloat(order.total_price).toFixed(2) + '</span>';
    
    // Format order date
    const orderDate = new Date(order.order_date);
    document.getElementById('detailOrderDate').textContent = orderDate.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric'
    });
    
    // Set status with color
    const statusSpan = document.getElementById('detailStatus');
    const statusMap = {
        'PEN': { class: 'status-pending', text: 'Pending' },
        'CON': { class: 'status-confirmed', text: 'Confirmed' },
        'REJ': { class: 'status-rejected', text: 'Rejected' },
        'COM': { class: 'status-completed', text: 'Completed' }
    };
    const statusInfo = statusMap[order.Status] || { class: '', text: 'Unknown' };
    statusSpan.innerHTML = `<span class="status-badge ${statusInfo.class}">${statusInfo.text}</span>`;
    
    // Populate customer details
    document.getElementById('detailCustomerName').textContent = order.customer_name || 'N/A';
    document.getElementById('detailCustomerPhone').innerHTML = order.customer_phone ? 
        `<a href="tel:${order.customer_phone}" style="color: #0d6efd; text-decoration: none;">${order.customer_phone}</a>` : 'N/A';
    document.getElementById('detailCustomerEmail').innerHTML = order.customer_email ? 
        `<a href="mailto:${order.customer_email}" style="color: #0d6efd; text-decoration: none;">${order.customer_email}</a>` : 'N/A';
    
    // Format delivery address
    let addressText = 'N/A';
    if (order.address) {
        addressText = order.address;
        if (order.city) addressText += '<br/>' + order.city;
        if (order.state) addressText += ', ' + order.state;
        if (order.Pin_code) addressText += ' - ' + order.Pin_code;
    }
    document.getElementById('detailDeliveryAddress').innerHTML = addressText;
    
    // Handle product image
    const imageContainer = document.getElementById('orderImageContainer');
    if (order.image_url) {
        imageContainer.innerHTML = '<img src="../' + order.image_url + '" alt="Product Image">';
    } else {
        imageContainer.innerHTML = '<div style="padding: 50px; background: #f0f0f0; border-radius: 5px; color: #666;"><i class="fas fa-image" style="font-size: 48px; margin-bottom: 10px;"></i><br>No Image Available</div>';
    }
    
    // Handle action buttons based on status
    const actionsContainer = document.getElementById('orderActions');
    let actionsHTML = '';
    
    const currentStatus = new URLSearchParams(window.location.search).get('status') || 'all';
    
    if (order.Status === 'PEN') {
        actionsHTML += '<a href="?action=approve&id=' + order.Order_id + '&status=' + currentStatus + '" class="btn-approve" onclick="return confirm(\'Approve this order?\')">Approve Order</a>';
        actionsHTML += '<a href="?action=reject&id=' + order.Order_id + '&status=' + currentStatus + '" class="btn-reject" onclick="return confirm(\'Reject this order?\')">Reject Order</a>';
    } else if (order.Status === 'CON') {
        actionsHTML += '<a href="?action=complete&id=' + order.Order_id + '&status=' + currentStatus + '" class="btn-complete" onclick="return confirm(\'Mark as complete?\')">Mark Complete</a>';
        actionsHTML += '<a href="?action=reject&id=' + order.Order_id + '&status=' + currentStatus + '" class="btn-reject" onclick="return confirm(\'Reject this order?\')">Reject Order</a>';
    } else if (order.Status === 'REJ') {
        actionsHTML += '<a href="?action=reapprove&id=' + order.Order_id + '&status=' + currentStatus + '" class="btn-reapprove" onclick="return confirm(\'Re-approve this rejected order?\')">Re-Approve Order</a>';
    }
    
    actionsContainer.innerHTML = actionsHTML;
    
    // Show modal
    document.getElementById('orderDetailsModal').style.display = 'block';
}

function closeOrderModal() {
    document.getElementById('orderDetailsModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('orderDetailsModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('orderDetailsModal');
        if (modal.style.display === 'block') {
            closeOrderModal();
        }
    }
});
</script>

<?php
require 'ofooter.php';
?>
