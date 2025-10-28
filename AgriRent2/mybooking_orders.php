<?php
session_start();

require_once 'auth/config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle AJAX requests for modal details
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'booking_details' && isset($_GET['id'])) {
        $booking_id = (int)$_GET['id'];
        
        $query = "SELECT eb.*, e.Title, e.Brand, e.Model, e.Description, e.Hourly_rate 
                  FROM equipment_bookings eb 
                  LEFT JOIN equipment e ON eb.equipment_id = e.Equipment_id 
                  WHERE eb.booking_id = ? AND eb.customer_id = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $booking_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $booking = $result->fetch_assoc();
            
            function getStatusText($status) {
                switch($status) {
                    case 'PEN': return 'Pending';
                    case 'CON': return 'Confirmed';
                    case 'REJ': return 'Rejected';
                    case 'COM': return 'Completed';
                    default: return ucfirst(strtolower($status));
                }
            }
            
            echo '<div class="detail-row">
                    <div class="detail-label">Booking ID:</div>
                    <div class="detail-value">#EB' . str_pad($booking_id, 4, '0', STR_PAD_LEFT) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Equipment:</div>
                    <div class="detail-value">' . htmlspecialchars($booking['Title']) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Brand & Model:</div>
                    <div class="detail-value">' . htmlspecialchars($booking['Brand'] . ' ' . $booking['Model']) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Description:</div>
                    <div class="detail-value">' . htmlspecialchars($booking['Description']) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Duration:</div>
                    <div class="detail-value">' . $booking['Hours'] . ' hours</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Booking Period:</div>
                    <div class="detail-value">' . date('M d, Y', strtotime($booking['start_date'])) . ' - ' . date('M d, Y', strtotime($booking['end_date'])) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Time Slot:</div>
                    <div class="detail-value">' . htmlspecialchars($booking['time_slot']) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Hourly Rate:</div>
                    <div class="detail-value">Rs.' . number_format($booking['Hourly_rate'], 2) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Total Amount:</div>
                    <div class="detail-value">Rs.' . number_format($booking['total_amount'], 2) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">' . getStatusText($booking['status']) . '</div>
                  </div>';
        } else {
            echo '<div class="detail-row">Booking not found or access denied.</div>';
        }
        exit;
    }
    
    if ($_GET['action'] == 'order_details' && isset($_GET['id'])) {
        $order_id = (int)$_GET['id'];
        
        $query = "SELECT po.*, p.Name as product_name, p.Description, p.Price, ua.address, ua.city, ua.state, ua.Pin_code 
                  FROM product_orders po 
                  LEFT JOIN product p ON po.Product_id = p.product_id 
                  LEFT JOIN user_addresses ua ON po.delivery_address = ua.address_id 
                  WHERE po.Order_id = ? AND po.buyer_id = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $order_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();
            
            function getStatusText($status) {
                switch($status) {
                    case 'PEN': return 'Pending';
                    case 'CON': return 'Confirmed';
                    case 'REJ': return 'Rejected';
                    case 'COM': return 'Completed';
                    default: return ucfirst(strtolower($status));
                }
            }
            
            $delivery_address = $order['address'] . ', ' . $order['city'] . ', ' . $order['state'] . ' - ' . $order['Pin_code'];
            
            echo '<div class="detail-row">
                    <div class="detail-label">Order ID:</div>
                    <div class="detail-value">#PO' . str_pad($order_id, 4, '0', STR_PAD_LEFT) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Product:</div>
                    <div class="detail-value">' . htmlspecialchars($order['product_name']) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Description:</div>
                    <div class="detail-value">' . htmlspecialchars($order['Description']) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Quantity:</div>
                    <div class="detail-value">' . number_format($order['quantity'], 2) . ' units</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Unit Price:</div>
                    <div class="detail-value">Rs.' . number_format($order['Price'], 2) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Total Amount:</div>
                    <div class="detail-value">Rs.' . number_format($order['total_price'], 2) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Delivery Address:</div>
                    <div class="detail-value">' . htmlspecialchars($delivery_address) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Order Date:</div>
                    <div class="detail-value">' . date('M d, Y h:i A', strtotime($order['order_date'])) . '</div>
                  </div>
                  <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">' . getStatusText($order['Status']) . '</div>
                  </div>';
        } else {
            echo '<div class="detail-row">Order not found or access denied.</div>';
        }
        exit;
    }
}

// Get filter type from URL parameter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Status mapping for display
function getStatusText($status) {
    switch($status) {
        case 'PEN': return 'Pending';
        case 'CON': return 'Confirmed';
        case 'REJ': return 'Rejected';
        case 'COM': return 'Completed';
        default: return ucfirst(strtolower($status));
    }
}

function getStatusBadge($status) {
    switch($status) {
        case 'PEN': return '<span class="status-pending">Pending</span>';
        case 'CON': return '<span class="status-confirmed">Confirmed</span>';
        case 'REJ': return '<span class="status-cancelled">Rejected</span>';
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
    <style>
        /* Exact same styling as bookings.php */
        body {
            font-family: Arial, sans-serif;    
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: #343a40;
            color: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }

        .header p {
            margin: 0;
            opacity: 0.8;
        }

        .nav-tabs {
            background: white;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .nav-tabs a {
            display: inline-block;
            padding: 10px 20px;
            margin-right: 10px;
            background: #f8f9fa;
            color: #495057;
            text-decoration: none;
            border-radius: 3px;
            border: 1px solid #dee2e6;
        }

        .nav-tabs a.active,
        .nav-tabs a:hover {
            background: #007bff;
            color: white;
        }

        .back-btn {
            background: #6c757d;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 3px;
            margin-bottom: 20px;
            display: inline-block;
            font-size: 14px;
        }

        .back-btn:hover {
            background: #545b62;
            color: white;
        }

        .table-container {
            background: white;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .table-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
        }

        .table-header h2 {
            margin: 0;
            font-size: 18px;
            color: #495057;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #007bff;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
            color: #495057;
            font-size: 14px;
        }

        tr:nth-child(even) {
            background: #f8f9fa;
        }

        tr:hover {
            background: #e9ecef;
        }

        .status-pending {
            background: #ffc107;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-confirmed {
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-cancelled {
            background: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-completed {
            background: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }

        .view-btn {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .view-btn:hover {
            background: #138496;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-style: italic;
        }

        .note {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            border-radius: 5px;
            color: #0c5460;
        }

        .note strong {
            font-weight: 600;
        }

        /* Modal styling - same as bookings.php */
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
            background-color: white;
            margin: 10% auto;
            padding: 0;
            border-radius: 5px;
            width: 90%;
            max-width: 600px;
            position: relative;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .modal-header {
            background: #007bff;
            color: white;
            padding: 15px 20px;
            border-radius: 5px 5px 0 0;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }

        .close {
            position: absolute;
            right: 15px;
            top: 10px;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: white;
            background: none;
            border: none;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 20px;
        }

        .detail-row {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }

        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }

        .detail-value {
            color: #6c757d;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .nav-tabs a {
                display: block;
                margin-bottom: 5px;
                margin-right: 0;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px 10px;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
        
        <div class="header">
            <h1>My Bookings & Orders</h1>
            <p>Track all your equipment bookings and product orders</p>
        </div>

        <div class="nav-tabs">
            <a href="mybooking_orders.php?filter=all" class="<?php echo $filter == 'all' ? 'active' : ''; ?>">All Records</a>
            <a href="mybooking_orders.php?filter=equipment" class="<?php echo $filter == 'equipment' ? 'active' : ''; ?>">Equipment Bookings</a>
            <a href="mybooking_orders.php?filter=product" class="<?php echo $filter == 'product' ? 'active' : ''; ?>">Product Orders</a>
        </div>

        <?php
        // Equipment Bookings Section
        if ($filter == 'all' || $filter == 'equipment') {
            echo '<div class="table-container">
                <div class="table-header">
                    <h2>Equipment Bookings</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Equipment</th>
                            <th>Brand & Model</th>
                            <th>Duration</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            $equipment_query = "SELECT eb.*, e.Title, e.Brand, e.Model, e.Description, e.Hourly_rate 
                               FROM equipment_bookings eb 
                               LEFT JOIN equipment e ON eb.equipment_id = e.Equipment_id 
                               WHERE eb.customer_id = ? 
                               ORDER BY eb.booking_id DESC";
            
            $stmt = $conn->prepare($equipment_query);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $equipment_result = $stmt->get_result();
                
                if ($equipment_result->num_rows > 0) {
                    while ($booking = $equipment_result->fetch_assoc()) {
                        $equipment_name = htmlspecialchars($booking['Title'] ?? 'Equipment');
                        $brand_model = htmlspecialchars(($booking['Brand'] ?? '') . ' ' . ($booking['Model'] ?? ''));
                        $duration = date('M d', strtotime($booking['start_date'])) . ' - ' . date('M d, Y', strtotime($booking['end_date'])) . '<br>' . 
                                   (isset($booking['Hours']) ? $booking['Hours'] : '0') . ' hours';
                        
                        echo '<tr>
                            <td>B-' . $booking['booking_id'] . '</td>
                            <td>' . $equipment_name . '</td>
                            <td>' . $brand_model . '</td>
                            <td>' . $duration . '</td>
                            <td>Rs.' . number_format($booking['total_amount'], 2) . '</td>
                            <td>' . getStatusBadge($booking['status']) . '</td>
                            <td><button class="view-btn" onclick="viewBookingDetail(' . $booking['booking_id'] . ')">View</button></td>
                        </tr>';
                    }
                } else {
                    echo '<tr><td colspan="7" class="no-data">No equipment bookings found</td></tr>';
                }
                $stmt->close();
            } else {
                echo '<tr><td colspan="7" class="no-data">Error loading equipment bookings</td></tr>';
            }
            
            echo '</tbody></table></div>';
        }

        // Product Orders Section
        if ($filter == 'all' || $filter == 'product') {
            echo '<div class="table-container">
                <div class="table-header">
                    <h2>Product Orders</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Order Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            $product_query = "SELECT po.*, p.Name as product_name, p.Description, p.Price 
                             FROM product_orders po 
                             LEFT JOIN product p ON po.Product_id = p.product_id 
                             WHERE po.buyer_id = ? 
                             ORDER BY po.Order_id DESC";
            
            $stmt = $conn->prepare($product_query);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $product_result = $stmt->get_result();
                
                if ($product_result->num_rows > 0) {
                    while ($order = $product_result->fetch_assoc()) {
                        $product_name = htmlspecialchars($order['product_name'] ?? 'Product');
                        
                        echo '<tr>
                            <td>P-' . $order['Order_id'] . '</td>
                            <td>' . $product_name . '</td>
                            <td>' . number_format($order['quantity'], 2) . ' units</td>
                            <td>' . date('M d, Y', strtotime($order['order_date'])) . '</td>
                            <td>Rs.' . number_format($order['total_price'], 2) . '</td>
                            <td>' . getStatusBadge($order['Status']) . '</td>
                            <td><button class="view-btn" onclick="viewOrderDetail(' . $order['Order_id'] . ')">View</button></td>
                        </tr>';
                    }
                } else {
                    echo '<tr><td colspan="7" class="no-data">No product orders found</td></tr>';
                }
                $stmt->close();
            } else {
                echo '<tr><td colspan="7" class="no-data">Error loading product orders</td></tr>';
            }
            
            echo '</tbody></table></div>';
        }

        $conn->close();
        ?>

        <div class="note">
            <strong>Note:</strong> You can view your bookings and orders here. For any changes or cancellations, please contact the equipment owner or our support team directly.
        </div>
    </div>

    <!-- Modal for booking details -->
    <div id="bookingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Booking Details</h3>
                <button class="close" onclick="closeModal('bookingModal')">&times;</button>
            </div>
            <div class="modal-body" id="bookingDetails">
                Loading...
            </div>
        </div>
    </div>

    <!-- Modal for order details -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Order Details</h3>
                <button class="close" onclick="closeModal('orderModal')">&times;</button>
            </div>
            <div class="modal-body" id="orderDetails">
                Loading...
            </div>
        </div>
    </div>

    <script>
        function viewBookingDetail(bookingId) {
            document.getElementById('bookingModal').style.display = 'block';
            
            // Fetch booking details via AJAX
            fetch('mybooking_orders.php?action=booking_details&id=' + bookingId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('bookingDetails').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('bookingDetails').innerHTML = 'Error loading booking details';
                });
        }

        function viewOrderDetail(orderId) {
            document.getElementById('orderModal').style.display = 'block';
            
            // Fetch order details via AJAX
            fetch('mybooking_orders.php?action=order_details&id=' + orderId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('orderDetails').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('orderDetails').innerHTML = 'Error loading order details';
                });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const bookingModal = document.getElementById('bookingModal');
            const orderModal = document.getElementById('orderModal');
            if (event.target == bookingModal) {
                bookingModal.style.display = 'none';
            }
            if (event.target == orderModal) {
                orderModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
