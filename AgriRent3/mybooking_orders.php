<?php
session_start();

require_once 'auth/config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get filter type from URL parameter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Status badge function
function getStatusBadge($status) {
    switch($status) {
        case 'PEN': return '<span class="status-pending">Pending</span>';
        case 'CON': return '<span class="status-confirmed">Confirmed</span>';
        case 'REJ': return '<span class="status-rejected">Rejected</span>';
        case 'COM': return '<span class="status-completed">Completed</span>';
        default: return '<span class="status-pending">' . ucfirst(strtolower($status)) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings & Orders - AgriRent</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Base Styles - Exact from manage_products.php */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            padding: 30px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f5f5f5;
        }

        /* Page Header Styles */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .header-left h1 {
            margin: 0 0 5px 0;
            color: #234a23;
            font-size: 28px;
            font-weight: 600;
        }

        .header-left p {
            color: #666;
            font-size: 14px;
        }

        .header-right {
            flex-shrink: 0;
        }

        /* Button Styles */
        .btn-primary {
            background: #234a23;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary:hover {
            background: #1a3a1a;
            color: white;
        }

        .btn-details {
            background: #234a23;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-details:hover {
            background: #237a23;
            transform: translateY(-1px);
        }

        /* Navigation Tabs */
        .nav-tabs {
            margin-bottom: 25px;
        }

        .nav-tabs a {
            display: inline-block;
            padding: 12px 20px;
            margin-right: 10px;
            background: white;
            color: #234a23;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
        }

        .nav-tabs a.active,
        .nav-tabs a:hover {
            background: #234a23;
            color: white;
            border-color: #234a23;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 25px;
        }

        .table-header {
            background: #234a23;
            color: white;
            padding: 15px 20px;
        }

        .table-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th {
            background: #234a23;
            color: white;
            padding: 16px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.5px;
        }

        td {
            padding: 16px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            color: #333;
        }

        tbody tr:hover {
            background-color: #f8f9fa;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges */
        .status-pending {
            background-color: #ffc107;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-confirmed {
            background-color: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-rejected {
            background-color: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed {
            background-color: #234a23;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            font-size: 20px;
            font-weight: 600;
        }

        .modal-body {
            padding: 20px;
        }

        .booking-image-section, 
        .booking-details-section, 
        .product-image-section, 
        .product-details-section {
            margin-bottom: 30px;
        }

        .booking-image-section h3, 
        .booking-details-section h3, 
        .product-image-section h3, 
        .product-details-section h3 {
            color: #234a23;
            margin-bottom: 15px;
            border-bottom: 2px solid #eee;
            padding-bottom: 5px;
            font-size: 16px;
            font-weight: 600;
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
            font-weight: 600;
        }

        .detail-row span {
            flex: 1;
            margin-left: 10px;
            color: #333;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 64px;
            color: #ddd;
            margin-bottom: 20px;
            display: block;
        }

        .empty-state strong {
            display: block;
            font-size: 18px;
            margin-bottom: 8px;
            color: #333;
        }

        .empty-state small {
            color: #999;
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
            }

            .nav-tabs a {
                display: block;
                margin-bottom: 8px;
                margin-right: 0;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px 6px;
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
</head>
<body>
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-left">
            <h1>My Bookings & Orders</h1>
            <p>Track and manage all your equipment bookings and product orders</p>
        </div>
        <div class="header-right">
            <a href="dashboard.php" class="btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <a href="mybooking_orders.php?filter=all" class="<?php echo $filter == 'all' ? 'active' : ''; ?>">
            All Records
        </a>
        <a href="mybooking_orders.php?filter=equipment" class="<?php echo $filter == 'equipment' ? 'active' : ''; ?>">
            Equipment Bookings
        </a>
        <a href="mybooking_orders.php?filter=product" class="<?php echo $filter == 'product' ? 'active' : ''; ?>">
            Product Orders
        </a>
    </div>

    <?php
    // Equipment Bookings Section - WITH CORRECT IMAGE JOIN
    if ($filter == 'all' || $filter == 'equipment') {
        echo '<div class="table-container">
                <div class="table-header">
                    <h2>Equipment Bookings</h2>
                </div>
            <table>
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Equipment</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Time Slot</th>
                        <th>Duration</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>';

        // Fetch equipment bookings with image from images table
        $query = "SELECT eb.*, e.Title, e.Brand, e.Model, img.image_url
                  FROM equipment_bookings eb 
                  LEFT JOIN equipment e ON eb.equipment_id = e.Equipment_id 
                  LEFT JOIN images img ON img.ID = e.Equipment_id AND img.image_type = 'E'
                  WHERE eb.customer_id = ? 
                  ORDER BY eb.booking_id DESC";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                while ($booking = $result->fetch_assoc()) {
                    $equipment_name = $booking['Title'] ?? 'N/A';
                    $duration = ($booking['Hours'] ?? 0) . ' hours';
                    
                    // Prepare booking data for JavaScript
                    $booking_json = htmlspecialchars(json_encode($booking), ENT_QUOTES, 'UTF-8');
                    
                    echo '<tr>
                            <td><strong>EB-' . str_pad($booking['booking_id'], 4, '0', STR_PAD_LEFT) . '</strong></td>
                            <td>' . htmlspecialchars($equipment_name) . '</td>
                            <td>' . date('M d, Y', strtotime($booking['start_date'])) . '</td>
                            <td>' . date('M d, Y', strtotime($booking['end_date'])) . '</td>
                            <td>' . ($booking['time_slot'] ?? 'N/A') . '</td>
                            <td>' . $duration . '</td>
                            <td><strong>Rs. ' . number_format($booking['total_amount'], 2) . '</strong></td>
                            <td>' . getStatusBadge($booking['status']) . '</td>
                            <td>
                                <button class="btn-details" onclick=\'viewBookingDetails(' . $booking_json . ')\'>
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </td>
                          </tr>';
                }
            } else {
                echo '<tr><td colspan="9" class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <strong>No Equipment Bookings Found</strong>
                        <small>Your equipment bookings will appear here</small>
                      </td></tr>';
            }
        } else {
            echo '<tr><td colspan="9" class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error Loading Bookings</strong>
                    <small>' . htmlspecialchars($conn->error) . '</small>
                  </td></tr>';
        }

        echo '</tbody></table></div>';
    }

    // Product Orders Section - WITH CORRECT IMAGE JOIN
    if ($filter == 'all' || $filter == 'product') {
        echo '<div class="table-container">
                <div class="table-header">
                    <h2>Product Orders</h2>
                </div>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Total Price</th>
                        <th>Order Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>';

        // Fetch product orders with image from images table
        $query = "SELECT po.*, p.Name as product_name, img.image_url
                  FROM product_orders po 
                  LEFT JOIN product p ON po.Product_id = p.product_id 
                  LEFT JOIN images img ON img.ID = p.product_id AND img.image_type = 'P'
                  WHERE po.buyer_id = ? 
                  ORDER BY po.Order_id DESC";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                while ($order = $result->fetch_assoc()) {
                    $product_name = $order['product_name'] ?? 'N/A';
                    
                    // Prepare order data for JavaScript
                    $order_json = htmlspecialchars(json_encode($order), ENT_QUOTES, 'UTF-8');
                    
                    echo '<tr>
                            <td><strong>PO-' . str_pad($order['Order_id'], 4, '0', STR_PAD_LEFT) . '</strong></td>
                            <td>' . htmlspecialchars($product_name) . '</td>
                            <td>' . number_format($order['quantity'], 2) . '</td>
                            <td><strong>Rs. ' . number_format($order['total_price'], 2) . '</strong></td>
                            <td>' . date('M d, Y', strtotime($order['order_date'])) . '</td>
                            <td>' . getStatusBadge($order['Status']) . '</td>
                            <td>
                                <button class="btn-details" onclick=\'viewOrderDetails(' . $order_json . ')\'>
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </td>
                          </tr>';
                }
            } else {
                echo '<tr><td colspan="7" class="empty-state">
                        <i class="fas fa-shopping-bag"></i>
                        <strong>No Product Orders Found</strong>
                        <small>Your product orders will appear here</small>
                      </td></tr>';
            }
        } else {
            echo '<tr><td colspan="7" class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Error Loading Orders</strong>
                    <small>' . htmlspecialchars($conn->error) . '</small>
                  </td></tr>';
        }

        echo '</tbody></table></div>';
    }
    ?>

    <!-- Booking Details Modal -->
    <div id="bookingDetailsModal" class="modal" style="display: none;">
        <div class="modal-content-large">
            <span class="close" onclick="closeBookingModal()">&times;</span>
            <h2>Booking Details</h2>
            
            <div class="modal-body">
                <div class="booking-image-section">
                    <h3>Equipment Image</h3>
                    <div id="bookingImageContainer"></div>
                </div>
                
                <div class="booking-details-section">
                    <h3>Booking Information</h3>
                    <div class="details-grid">
                        <div class="detail-row">
                            <strong>Booking ID:</strong>
                            <span id="detailBookingId"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Equipment:</strong>
                            <span id="detailEquipmentTitle"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Brand/Model:</strong>
                            <span id="detailBrandModel"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Start Date:</strong>
                            <span id="detailStartDate"></span>
                        </div>
                        <div class="detail-row">
                            <strong>End Date:</strong>
                            <span id="detailEndDate"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Time Slot:</strong>
                            <span id="detailTimeSlot"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Duration:</strong>
                            <span id="detailHours"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Total Amount:</strong>
                            <span id="detailTotalAmount"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Status:</strong>
                            <span id="detailStatus"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="modal" style="display: none;">
        <div class="modal-content-large">
            <span class="close" onclick="closeOrderModal()">&times;</span>
            <h2>Order Details</h2>
            
            <div class="modal-body">
                <div class="product-image-section">
                    <h3>Product Image</h3>
                    <div id="orderImageContainer"></div>
                </div>
                
                <div class="product-details-section">
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
                            <strong>Quantity:</strong>
                            <span id="detailQuantity"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Total Price:</strong>
                            <span id="detailTotalPrice"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Order Date:</strong>
                            <span id="detailOrderDate"></span>
                        </div>
                        <div class="detail-row">
                            <strong>Status:</strong>
                            <span id="detailOrderStatus"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
        // View booking details function
        function viewBookingDetails(booking) {
            document.getElementById('detailBookingId').textContent = 'EB-' + String(booking.booking_id).padStart(4, '0');
            document.getElementById('detailEquipmentTitle').textContent = booking.Title || 'N/A';
            document.getElementById('detailBrandModel').textContent = (booking.Brand || 'N/A') + ' ' + (booking.Model || '');
            document.getElementById('detailHours').textContent = (booking.Hours || 'N/A') + ' hours';
            document.getElementById('detailTimeSlot').textContent = booking.time_slot || 'N/A';
            
            // Format dates
            if (booking.start_date) {
                const startDate = new Date(booking.start_date);
                document.getElementById('detailStartDate').textContent = startDate.toLocaleDateString('en-US', {
                    year: 'numeric', month: 'short', day: 'numeric'
                });
            }
            
            if (booking.end_date) {
                const endDate = new Date(booking.end_date);
                document.getElementById('detailEndDate').textContent = endDate.toLocaleDateString('en-US', {
                    year: 'numeric', month: 'short', day: 'numeric'
                });
            }
            
            // Set status
            const statusSpan = document.getElementById('detailStatus');
            let statusHtml = '';
            switch(booking.status) {
                case 'PEN': statusHtml = '<span class="status-pending">Pending</span>'; break;
                case 'CON': statusHtml = '<span class="status-confirmed">Confirmed</span>'; break;
                case 'REJ': statusHtml = '<span class="status-rejected">Rejected</span>'; break;
                case 'COM': statusHtml = '<span class="status-completed">Completed</span>'; break;
                default: statusHtml = '<span class="status-pending">' + booking.status + '</span>';
            }
            statusSpan.innerHTML = statusHtml;
            
            document.getElementById('detailTotalAmount').textContent = 'Rs. ' + parseFloat(booking.total_amount).toLocaleString();
            
            // Handle image from images table
            const imageContainer = document.getElementById('bookingImageContainer');
            if (booking.image_url) {
                imageContainer.innerHTML = '<img src="../' + booking.image_url + '" alt="Equipment" style="max-width: 100%; border-radius: 8px;">';
            } else {
                imageContainer.innerHTML = '<div style="padding: 50px; background: #f8f9fa; border-radius: 8px; text-align: center; color: #666;"><i class="fas fa-image" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>No Image Available</div>';
            }
            
            document.getElementById('bookingDetailsModal').style.display = 'block';
        }

        // View order details function
        function viewOrderDetails(order) {
            document.getElementById('detailOrderId').textContent = 'PO-' + String(order.Order_id).padStart(4, '0');
            document.getElementById('detailProductName').textContent = order.product_name || 'N/A';
            document.getElementById('detailQuantity').textContent = parseFloat(order.quantity).toLocaleString();
            document.getElementById('detailTotalPrice').textContent = 'Rs. ' + parseFloat(order.total_price).toLocaleString();
            
            if (order.order_date) {
                const orderDate = new Date(order.order_date);
                document.getElementById('detailOrderDate').textContent = orderDate.toLocaleDateString('en-US', {
                    year: 'numeric', month: 'short', day: 'numeric'
                });
            }
            
            // Set status
            const statusSpan = document.getElementById('detailOrderStatus');
            let statusHtml = '';
            switch(order.Status) {
                case 'PEN': statusHtml = '<span class="status-pending">Pending</span>'; break;
                case 'CON': statusHtml = '<span class="status-confirmed">Confirmed</span>'; break;
                case 'REJ': statusHtml = '<span class="status-rejected">Rejected</span>'; break;
                case 'COM': statusHtml = '<span class="status-completed">Completed</span>'; break;
                default: statusHtml = '<span class="status-pending">' + order.Status + '</span>';
            }
            statusSpan.innerHTML = statusHtml;
            
            // Handle image from images table
            const imageContainer = document.getElementById('orderImageContainer');
            if (order.image_url) {
                imageContainer.innerHTML = '<img src="../' + order.image_url + '" alt="Product" style="max-width: 100%; border-radius: 8px;">';
            } else {
                imageContainer.innerHTML = '<div style="padding: 50px; background: #f8f9fa; border-radius: 8px; text-align: center; color: #666;"><i class="fas fa-box" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>No Image Available</div>';
            }
            
            document.getElementById('orderDetailsModal').style.display = 'block';
        }

        function closeBookingModal() {
            document.getElementById('bookingDetailsModal').style.display = 'none';
        }

        function closeOrderModal() {
            document.getElementById('orderDetailsModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('bookingDetailsModal')) {
                closeBookingModal();
            }
            if (event.target == document.getElementById('orderDetailsModal')) {
                closeOrderModal();
            }
        }
    </script>
</body>
</html>
