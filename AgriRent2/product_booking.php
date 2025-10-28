<?php
// product_booking.php
session_start();
include 'auth/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

//--- IMPROVED PRODUCT BOOKING VALIDATION FUNCTION ---//
function validateProductBooking($product_id, $quantity_requested, $exclude_booking_id = null) {
    global $conn;

    // Get product availability
    $sql = "SELECT Quantity FROM product WHERE product_id = ? AND Approval_status = 'APP'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();

    if (!$product) {
        return ['valid' => false, 'message' => 'Product not found or not available'];
    }

    $available_quantity = $product['Quantity'];

    // Get total confirmed bookings
    $sql = "SELECT SUM(quantity) as booked_quantity FROM product_bookings 
            WHERE product_id = ? AND status = 'CON'";
    
    if ($exclude_booking_id) {
        $sql .= " AND booking_id != ?";
    }

    $stmt = $conn->prepare($sql);
    if ($exclude_booking_id) {
        $stmt->bind_param('ii', $product_id, $exclude_booking_id);
    } else {
        $stmt->bind_param('i', $product_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $booking_data = $result->fetch_assoc();
    
    $booked_quantity = $booking_data['booked_quantity'] ?? 0;
    $remaining_quantity = $available_quantity - $booked_quantity;

    if ($quantity_requested > $remaining_quantity) {
        return ['valid' => false, 'message' => 'Only ' . $remaining_quantity . ' units available. You requested ' . $quantity_requested . ' units.'];
    }

    return ['valid' => true, 'message' => 'Quantity available'];
}

//--- DUPLICATE CHECK FUNCTION ---//
function checkDuplicatePendingProductBooking($product_id, $customer_id, $quantity) {
    global $conn;

    // Check if this customer already has a pending booking for the same product
    $sql = "SELECT booking_id FROM product_bookings 
            WHERE product_id = ? AND customer_id = ? AND status = 'PEN'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $product_id, $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

//--- GET PRODUCT ID FROM URL ---//
$product_id = null;
if (isset($_GET['product_id']) && !empty($_GET['product_id'])) {
    $product_id = intval($_GET['product_id']);
} elseif (isset($_GET['id']) && !empty($_GET['id'])) {
    $product_id = intval($_GET['id']);
} elseif (isset($_POST['product_id']) && !empty($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);
}

// Validate product_id
if (!$product_id || $product_id <= 0) {
    echo "<div class='alert alert-danger'>Product ID not provided or invalid!</div>";
    echo "<a href='product_list.php' class='btn btn-secondary'>← Back to Product List</a>";
    exit;
}

// Get product details
$sql = "SELECT p.*, u.Name as seller_name, s.Subcategory_name
        FROM product p 
        JOIN users u ON p.seller_id = u.user_id 
        LEFT JOIN product_subcategories s ON p.Subcategory_id = s.Subcategory_id
        WHERE p.product_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    echo "<div class='alert alert-danger'>Product not found!</div>";
    echo "<a href='product_list.php' class='btn btn-secondary'>← Back to Product List</a>";
    exit;
}

//--- IMPROVED BOOKING REQUEST PROCESSING ---//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_product') {
    $customer_id = $_SESSION['user_id'];
    $product_id = intval($_POST['product_id']);
    $quantity_requested = floatval($_POST['quantity']);
    $delivery_address = trim($_POST['delivery_address']);
    $contact_number = trim($_POST['contact_number']);
    $special_instructions = trim($_POST['special_instructions'] ?? '');

    // Validate required fields
    if (empty($quantity_requested) || empty($delivery_address) || empty($contact_number)) {
        $error_message = "Please fill in all required fields.";
    } else {
        if ($quantity_requested <= 0) {
            $error_message = "Please enter a valid quantity.";
        } else {
            // Check if seller is trying to book their own product
            if ($product['seller_id'] == $customer_id) {
                $error_message = "You cannot book your own product";
            } else {
                // Step 1: Check for duplicate pending request
                if (checkDuplicatePendingProductBooking($product_id, $customer_id, $quantity_requested)) {
                    $error_message = "You already have a pending booking request for this product. Please wait for seller approval.";
                } else {
                    // Step 2: Validate product availability
                    $validation = validateProductBooking($product_id, $quantity_requested);

                    if (!$validation['valid']) {
                        $error_message = $validation['message'];
                    } else {
                        // Step 3: Calculate total amount
                        $total_amount = $quantity_requested * $product['Price'];

                        // Step 4: Insert booking request
                        if ($total_amount > 0) {
                            // Begin transaction to ensure atomicity
                            $conn->begin_transaction();

                            try {
                                $sql = "INSERT INTO product_bookings 
                                        (product_id, customer_id, quantity, total_amount, delivery_address, contact_number, special_instructions, status, booking_date) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'PEN', NOW())";
                                $stmt = $conn->prepare($sql);
                                $stmt->bind_param('iidssss',
                                        $product_id, $customer_id, $quantity_requested, $total_amount, $delivery_address, $contact_number, $special_instructions
                                );

                                if ($stmt->execute()) {
                                    // Send notification to product seller
                                    $message = "New product order received for: " . $product['Name'] . " - Quantity: " . $quantity_requested . " " . $product['Unit'];
                                    $sql = "INSERT INTO messages (sender_id, receiver_id, Content) VALUES (?, ?, ?)";
                                    $msg_stmt = $conn->prepare($sql);
                                    $msg_stmt->bind_param('iis', $customer_id, $product['seller_id'], $message);
                                    $msg_stmt->execute();

                                    $conn->commit();
                                    $success_message = "Product booking request sent successfully! Waiting for seller approval.";
                                } else {
                                    throw new Exception("Database insert failed");
                                }
                            } catch (Exception $e) {
                                $conn->rollback();
                                $error_message = "Please try again later.";
                            }
                        }
                    }
                }
            }
        }
    }
}

//--- SELLER APPROVAL/REJECTION SYSTEM ---//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_booking', 'reject_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    $action = $_POST['action'];
    $seller_id = $_SESSION['user_id'];

    // Verify seller owns the product
    $sql = "SELECT pb.*, p.seller_id, p.Name as product_name
            FROM product_bookings pb 
            JOIN product p ON pb.product_id = p.product_id 
            WHERE pb.booking_id = ? AND p.seller_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $booking_id, $seller_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        $error_message = "Invalid booking request";
    } else {
        if ($action === 'approve_booking') {
            $status = 'CON';
            $message = "Your product order for " . $booking['product_name'] . " has been approved!";
        } else {
            $status = 'REJ';
            $message = "Your product order for " . $booking['product_name'] . " has been rejected.";
        }

        // Update booking status
        $sql = "UPDATE product_bookings SET status = ? WHERE booking_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $status, $booking_id);

        if ($stmt->execute()) {
            // Send notification to customer
            $sql = "INSERT INTO messages (sender_id, receiver_id, Content) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iis', $seller_id, $booking['customer_id'], $message);
            $stmt->execute();

            $success_message = "Booking request " . ($action === 'approve_booking' ? 'approved' : 'rejected') . " successfully";
        }
    }
}

// Get seller bookings if user is seller
$seller_bookings = [];
if ($_SESSION['user_id'] == $product['seller_id']) {
    $sql = "SELECT pb.*, p.Name as product_name, u.Name as customer_name 
            FROM product_bookings pb 
            JOIN product p ON pb.product_id = p.product_id 
            JOIN users u ON pb.customer_id = u.user_id 
            WHERE p.seller_id = ? 
            ORDER BY pb.booking_id DESC 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $seller_bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get product image
$image_sql = "SELECT image_url FROM images WHERE image_type = 'P' AND ID = ? LIMIT 1";
$image_stmt = $conn->prepare($image_sql);
$image_stmt->bind_param('i', $product_id);
$image_stmt->execute();
$image_result = $image_stmt->get_result();
$product_image = $image_result->fetch_assoc();
$product_image_url = $product_image ? $product_image['image_url'] : 'assets/img/default-product.jpg';

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Order Product - <?php echo htmlspecialchars($product['Name']); ?> | AgriRent</title>

        <!-- Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <!-- Custom CSS -->
        <style>
    /* Modern Agricultural Product Booking Form Styling */
    :root {
        --primary-green: #2d4a22;
        --secondary-green: #2d4a22;
        --accent-orange: #f57c00;
        --light-gray: #f8f9fa;
        --border-color: #e9ecef;
        --text-dark: #333;
        --text-muted: #666;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, var(--light-gray) 0%, var(--border-color) 100%);
        min-height: 100vh;
    }

    .product-booking-container {
        min-height: 100vh;
    }

    /* Header Styling */
    .booking-header {
        background: linear-gradient(90deg, var(--primary-green) 0%, var(--secondary-green) 100%);
        color: white;
        padding: 2rem 0;
        margin-bottom: 0;
    }

    .booking-title {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .booking-subtitle {
        font-size: 1.2rem;
        opacity: 0.9;
    }

    /* Breadcrumb */
    .breadcrumb-section {
        background: rgba(46, 125, 50, 0.1);
        padding: 1rem 0;
    }

    .breadcrumb {
        background: transparent;
        margin-bottom: 0;
    }

    .breadcrumb-item a {
        color: var(--primary-green);
        text-decoration: none;
    }

    .breadcrumb-item.active {
        color: var(--text-muted);
    }

    /* Main Content */
    .booking-main-section {
        padding: 3rem 0;
    }

    /* Product Summary Card */
    .product-summary-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        position: sticky;
        top: 2rem;
        border: none;
    }

    .product-summary-card .card-header {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--secondary-green) 100%);
        color: white;
        padding: 1.5rem 2rem;
        border: none;
    }

    .product-summary-card .card-body {
        padding: 2rem;
    }

    .product-image {
        position: relative;
        overflow: hidden;
        border-radius: 15px;
        margin-bottom: 1.5rem;
    }

    .product-image img {
        width: 100%;
        height: 250px;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .product-image:hover img {
        transform: scale(1.05);
    }

    .product-title {
        color: var(--primary-green);
        font-weight: 700;
        font-size: 1.4rem;
        margin-bottom: 1rem;
    }

    .product-details {
        margin-bottom: 1.5rem;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-weight: 600;
        color: var(--text-muted);
    }

    .detail-value {
        color: var(--text-dark);
        font-weight: 500;
    }

    /* Pricing Info */
    .pricing-info {
        background: linear-gradient(135deg, #e8f5e8 0%, #f1f8e9 100%);
        padding: 1.5rem;
        border-radius: 15px;
        margin-bottom: 1.5rem;
    }

    .price-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .price-item:last-child {
        margin-bottom: 0;
    }

    .price-item i {
        font-size: 1.2rem;
    }

    /* Order Summary Display */
    .order-summary-display {
        background: white;
        border: 3px solid var(--primary-green);
        border-radius: 15px;
        padding: 1.5rem;
    }

    .summary-title {
        color: var(--primary-green);
        font-weight: 700;
        font-size: 1.2rem;
        margin-bottom: 1rem;
        text-align: center;
    }

    .summary-content {
        space-y: 0.5rem;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .summary-row:last-child {
        border-bottom: none;
    }

    .summary-row.total-amount {
        border-top: 3px solid var(--primary-green);
        margin-top: 1rem;
        padding-top: 1rem;
        font-weight: 700;
        font-size: 1.3rem;
        color: var(--primary-green);
    }

    /* Booking Form Card */
    .booking-form-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        border: none;
    }

    .booking-form-card .card-header {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--secondary-green) 100%);
        color: white;
        padding: 1.5rem 2rem;
        border: none;
    }

    .booking-form-card .card-body {
        padding: 2rem;
    }

    /* Form Styling */
    .product-booking-form .form-label {
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .product-booking-form .form-control {
        border: 2px solid var(--border-color);
        border-radius: 12px;
        padding: 0.875rem 1.25rem;
        font-size: 1rem;
        transition: all 0.3s ease;
    }

    .product-booking-form .form-control:focus {
        border-color: var(--primary-green);
        box-shadow: 0 0 0 0.2rem rgba(46, 125, 50, 0.25);
        transform: translateY(-1px);
    }

    /* Form Actions */
    .form-actions .btn {
        border-radius: 12px;
        padding: 1rem 2rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
        font-size: 1.1rem;
        background-color: #2d4a22;
        color: white;
    }

    .form-actions .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    }

    .form-footer {
        text-align: center;
        margin-top: 1.5rem;
        padding: 1rem;
        background: var(--light-gray);
        border-radius: 10px;
    }

    /* Seller Bookings Card */
    .seller-bookings-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        border: none;
    }

    .seller-bookings-card .card-header {
        background: linear-gradient(135deg, var(--accent-orange) 0%, #ff9800 100%);
        color: white;
        padding: 1.5rem 2rem;
        border: none;
    }

    /* Table Styling */
    .table-hover tbody tr:hover {
        background-color: rgba(46, 125, 50, 0.05);
        transform: translateX(5px);
        transition: all 0.3s ease;
    }

    .table th {
        background-color: var(--light-gray);
        border: none;
        font-weight: 600;
        color: var(--text-dark);
    }

    .table td {
        border: none;
        border-bottom: 1px solid var(--border-color);
        vertical-align: middle;
    }

    /* Alert Styling */
    .alert {
        border-radius: 12px;
        border: none;
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .alert-success {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
    }

    .alert-danger {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        color: #721c24;
    }

    .alert-info {
        background: linear-gradient(135deg, #d1ecf1 0%, #b6e3f0 100%);
        color: #0c5460;
    }

    /* Badge Styling */
    .badge {
        font-size: 0.85rem;
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .booking-title {
            font-size: 2rem;
        }

        .booking-main-section {
            padding: 2rem 0;
        }

        .product-summary-card {
            position: static;
            margin-bottom: 2rem;
        }

        .product-summary-card .card-body,
        .booking-form-card .card-body {
            padding: 1.5rem;
        }
    }

    /* Loading Animation */
    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }
        100% {
            transform: rotate(360deg);
        }
    }

    .spinner-border {
        animation: spin 1s linear infinite;
    }

    /* Card Hover Effects */
    .product-summary-card,
    .booking-form-card,
    .seller-bookings-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .product-summary-card:hover,
    .booking-form-card:hover,
    .seller-bookings-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
    }

    /* Button Group Styling */
    .btn-group .btn {
        margin: 0 2px;
    }
</style>
    </head>

    <body>
        <?php
include 'includes/header.php';
include 'includes/navigation.php';
?>
        <div class="product-booking-container">
            <!-- Main Booking Section -->
            <div class="booking-main-section">
                <div class="container">
                    <nav style="margin-bottom: 20px;">
                        <a href="index.php" style="color: #666; text-decoration: none;">Home</a> › 
                        <a href="products.php" style="color: #666; text-decoration: none;">Products</a> ›
                        <a href="product_details.php?id=<?= urlencode($product['product_id']) ?>" 
                           style="color: #666; text-decoration: none;">
                               <?= htmlspecialchars($product['Name']) ?>
                        </a> ›
                        <span style="color: #234a23; font-weight: bold;">Order Form</span>
                    </nav>
                    <div class="row">
                        <!-- Left Side - Product Summary -->
                        <div class="col-lg-5 col-md-12 mb-4">
                            <div class="product-summary-card card">
                                <div class="card-header">
                                    <h4 class="mb-0">
                                        <i class="fas fa-shopping-cart me-2"></i>
                                        Product Summary
                                    </h4>
                                </div>
                                <div class="card-body">
                                    <div class="product-info">
                                        <div class="product-image">
                                            <img src="<?php echo htmlspecialchars($product_image_url); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['Name']); ?>" 
                                                 class="img-fluid">
                                        </div>

                                        <h5 class="product-title"><?php echo htmlspecialchars($product['Name']); ?></h5>

                                        <div class="product-details">
                                            <div class="detail-row">
                                                <span class="detail-label">Category:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($product['Subcategory_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Available Quantity:</span>
                                                <span class="detail-value"><?php echo number_format($product['Quantity'], 2); ?> <?php echo $product['Unit']; ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Unit:</span>
                                                <span class="detail-value"><?php echo $product['Unit']; ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Seller:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($product['seller_name']); ?></span>
                                            </div>
                                        </div>

                                        <div class="pricing-info">
                                            <h6 class="mb-3 text-center text-success">
                                                <i class="fas fa-tag me-2"></i>
                                                Pricing Information
                                            </h6>
                                            <div class="price-item">
                                                <i class="fas fa-rupee-sign text-success"></i>
                                                <span>Price: <strong>₹<?php echo number_format($product['Price'], 2); ?>/<?php echo $product['Unit']; ?></strong></span>
                                            </div>
                                        </div>

                                        <!-- Order Summary Display -->
                                        <div class="order-summary-display">
                                            <h6 class="summary-title">
                                                <i class="fas fa-calculator me-2"></i>
                                                Order Summary
                                            </h6>
                                            <div class="summary-content">
                                                <div class="summary-row">
                                                    <span><i class="fas fa-cube me-2"></i>Quantity:</span>
                                                    <span id="quantity-display" class="fw-bold">0 <?php echo $product['Unit']; ?></span>
                                                </div>
                                                <div class="summary-row">
                                                    <span><i class="fas fa-money-bill-wave me-2"></i>Unit Price:</span>
                                                    <span id="price-display" class="fw-bold">₹<?php echo number_format($product['Price'], 2); ?>/<?php echo $product['Unit']; ?></span>
                                                </div>
                                                <div class="summary-row total-amount">
                                                    <span><i class="fas fa-rupee-sign me-2"></i>Total Amount:</span>
                                                    <span id="total-amount-display" class="fw-bold">₹0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Side - Booking Form -->
                        <div class="col-lg-7 col-md-12">
                            <div class="booking-form-card card">
                                <div class="card-header">
                                    <h4 class="mb-0">
                                        <i class="fas fa-shopping-bag me-2"></i>
                                        Order This Product
                                    </h4>
                                </div>
                                <div class="card-body">
                                    <!-- Success/Error Messages -->
                                    <?php if (isset($success_message)): ?>
                                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                                            <i class="fas fa-check-circle me-2"></i>
                                            <?php echo $success_message; ?>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (isset($error_message)): ?>
                                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <?php echo $error_message; ?>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Booking Form -->
                                    <?php if ($_SESSION['user_id'] != $product['seller_id'] && $product['Approval_status'] == 'APP'): ?>
                                        <form id="orderForm" method="POST" action="" class="product-booking-form">
                                            <input type="hidden" name="action" value="book_product">
                                            <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

                                            <!-- Quantity -->
                                            <div class="form-group mb-3">
                                                <label for="quantity" class="form-label">
                                                    <i class="fas fa-cube me-2"></i>
                                                    Quantity (<?php echo $product['Unit']; ?>) *
                                                </label>
                                                <input type="number" 
                                                       class="form-control" 
                                                       name="quantity" 
                                                       id="quantity" 
                                                       required 
                                                       min="0.01" 
                                                       max="<?php echo $product['Quantity']; ?>"
                                                       step="0.01"
                                                       placeholder="Enter quantity needed">
                                                <div class="form-text">
                                                    Maximum available: <?php echo number_format($product['Quantity'], 2); ?> <?php echo $product['Unit']; ?>
                                                </div>
                                            </div>

                                            <!-- Delivery Address -->
                                            <div class="form-group mb-3">
                                                <label for="delivery_address" class="form-label">
                                                    <i class="fas fa-map-marker-alt me-2"></i>
                                                    Delivery Address *
                                                </label>
                                                <textarea class="form-control" 
                                                          name="delivery_address" 
                                                          id="delivery_address" 
                                                          rows="3" 
                                                          required
                                                          placeholder="Enter complete delivery address"></textarea>
                                            </div>

                                            <!-- Contact Number -->
                                            <div class="form-group mb-3">
                                                <label for="contact_number" class="form-label">
                                                    <i class="fas fa-phone me-2"></i>
                                                    Contact Number *
                                                </label>
                                                <input type="tel" 
                                                       class="form-control" 
                                                       name="contact_number" 
                                                       id="contact_number" 
                                                       required
                                                       pattern="[0-9]{10}"
                                                       placeholder="Enter 10-digit mobile number">
                                            </div>

                                            <!-- Special Instructions -->
                                            <div class="form-group mb-3">
                                                <label for="special_instructions" class="form-label">
                                                    <i class="fas fa-comment me-2"></i>
                                                    Special Instructions (Optional)
                                                </label>
                                                <textarea class="form-control" 
                                                          name="special_instructions" 
                                                          id="special_instructions" 
                                                          rows="2"
                                                          placeholder="Any special requirements or instructions"></textarea>
                                            </div>

                                            <!-- Submit Button -->
                                            <div class="form-actions mt-4">
                                                <button type="submit" id="submitBtn" class="btn btn-success btn-lg w-100">
                                                    <i class="fas fa-paper-plane me-2"></i>
                                                    Send Order Request
                                                    <span class="spinner-border spinner-border-sm ms-2 d-none" id="loading-spinner" role="status" aria-hidden="true"></span>
                                                </button>
                                            </div>

                                            <div class="form-footer">
                                                <p class="text-muted mb-0">
                                                    <i class="fas fa-shield-alt me-2"></i>
                                                    Your order request will be sent to the seller for approval. 
                                                    You will receive a notification once the seller responds to your request.
                                                </p>
                                            </div>
                                        </form>

                                    <?php elseif ($_SESSION['user_id'] == $product['seller_id']): ?>
                                        <div class="alert alert-info text-center">
                                            <i class="fas fa-info-circle me-2 fs-4"></i>
                                            <h5 class="mt-2">You own this product</h5>
                                            <p>You cannot order your own product. <br>Check your dashboard for orders from other users.</p>
                                            <a href="farmer_dashboard.php" class="btn btn-primary mt-2">
                                                <i class="fas fa-tachometer-alt me-2"></i>
                                                Go to Dashboard
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning text-center">
                                            <i class="fas fa-exclamation-triangle me-2 fs-4"></i>
                                            <h5 class="mt-2">Product Not Available</h5>
                                            <p>This product is currently not available for ordering. <br>Please contact the seller for more information.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Seller Order Requests (if seller) -->
                            <?php if (!empty($seller_bookings)): ?>
                                <div class="seller-bookings-card card mt-4">
                                    <div class="card-header">
                                        <h5 class="mb-0">
                                            <i class="fas fa-list-alt me-2"></i>
                                            Recent Order Requests
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Customer</th>
                                                        <th>Product</th>
                                                        <th>Quantity</th>
                                                        <th>Amount</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($seller_bookings as $booking): ?>
                                                        <?php
                                                        $status_class = [
                                                            'CON' => 'bg-success',
                                                            'PEN' => 'bg-warning text-dark',
                                                            'REJ' => 'bg-danger'
                                                                ][$booking['status']] ?? 'bg-secondary';

                                                        $status_text = [
                                                            'CON' => 'Confirmed',
                                                            'PEN' => 'Pending',
                                                            'REJ' => 'Rejected'
                                                                ][$booking['status']] ?? 'Unknown';
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($booking['customer_name']); ?></strong>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($booking['product_name']); ?></td>
                                                            <td><?php echo number_format($booking['quantity'], 2); ?></td>
                                                            <td><strong>₹<?php echo number_format($booking['total_amount'], 2); ?></strong></td>
                                                            <td>
                                                                <span class="badge <?php echo $status_class; ?>">
                                                                    <?php echo $status_text; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($booking['status'] === 'PEN'): ?>
                                                                    <div class="btn-group" role="group" aria-label="Order actions">
                                                                        <form method="POST" style="display: inline;">
                                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['booking_id']; ?>">
                                                                            <input type="hidden" name="action" value="approve_booking">
                                                                            <button type="submit" 
                                                                                    class="btn btn-sm btn-success" 
                                                                                    onclick="return confirm('Are you sure you want to approve this order?')"
                                                                                    title="Approve Order">
                                                                                <i class="fas fa-check"></i>
                                                                            </button>
                                                                        </form>
                                                                        <form method="POST" style="display: inline;">
                                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['booking_id']; ?>">
                                                                            <input type="hidden" name="action" value="reject_booking">
                                                                            <button type="submit" 
                                                                                    class="btn btn-sm btn-danger" 
                                                                                    onclick="return confirm('Are you sure you want to reject this order?')"
                                                                                    title="Reject Order">
                                                                                <i class="fas fa-times"></i>
                                                                            </button>
                                                                        </form>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <span class="text-muted">
                                                                        <i class="fas fa-check-circle"></i>
                                                                        Processed
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

        <!-- Custom JavaScript -->
        <script>
            let isSubmitting = false;

            // Auto calculate amount when quantity changes
            function autoCalculateAmount() {
                const quantity = parseFloat(document.getElementById('quantity').value) || 0;
                const unitPrice = <?php echo $product['Price']; ?>;
                const totalAmount = quantity * unitPrice;

                document.getElementById('quantity-display').textContent = quantity.toFixed(2) + ' <?php echo $product['Unit']; ?>';
                document.getElementById('total-amount-display').textContent = '₹' + totalAmount.toFixed(2);
            }

            // Add event listeners
            document.getElementById('quantity').addEventListener('input', function() {
                const max = parseFloat(this.getAttribute('max'));
                const value = parseFloat(this.value);
                
                if (value > max) {
                    alert('Maximum available quantity is ' + max + ' <?php echo $product['Unit']; ?>');
                    this.value = max;
                }
                
                autoCalculateAmount();
            });

            // Form submission handling
            document.getElementById('orderForm').addEventListener('submit', function (e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }

                const quantity = parseFloat(document.getElementById('quantity').value);
                const maxQuantity = <?php echo $product['Quantity']; ?>;
                
                if (quantity <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid quantity');
                    return false;
                }
                
                if (quantity > maxQuantity) {
                    e.preventDefault();
                    alert('Quantity cannot exceed available stock of ' + maxQuantity + ' <?php echo $product['Unit']; ?>');
                    return false;
                }

                isSubmitting = true;
                const submitBtn = document.getElementById('submitBtn');
                const spinner = document.getElementById('loading-spinner');

                submitBtn.disabled = true;
                spinner.classList.remove('d-none');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing Order...';
            });

            // Initialize
            document.addEventListener('DOMContentLoaded', function() {
                autoCalculateAmount();
            });

            // Auto-hide alerts after 5 seconds
            setTimeout(function () {
                const alerts = document.querySelectorAll('.alert.fade.show');
                alerts.forEach(function (alert) {
                    const closeBtn = alert.querySelector('.btn-close');
                    if (closeBtn) {
                        closeBtn.click();
                    }
                });
            }, 5000);
        </script>
    </body>
</html>

<?php include 'includes/footer.php'; ?>
