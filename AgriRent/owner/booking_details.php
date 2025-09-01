<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$booking_id = intval($_GET['id'] ?? 0);

if ($booking_id <= 0) {
    header('Location: equipment_bookings.php');
    exit();
}

// Fetch booking details
$booking = null;
$stmt = $conn->prepare("
    SELECT eb.*, e.Title as equipment_title, e.Brand, e.Model, e.Hourly_rate, e.Daily_rate,
           u.Name as customer_name, u.Email as customer_email, u.Phone as customer_phone,
           ua.address, ua.city, ua.state, ua.Pin_code,
           ec.Name as category_name, es.Subcategory_name
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    JOIN users u ON eb.customer_id = u.user_id
    LEFT JOIN user_addresses ua ON u.user_id = ua.user_id
    LEFT JOIN equipment_subcategories es ON e.Subcategories_id = es.Subcategory_id
    LEFT JOIN equipment_categories ec ON es.Category_id = ec.category_id
    WHERE eb.booking_id = ? AND e.Owner_id = ?
");
$stmt->bind_param("ii", $booking_id, $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    header('Location: equipment_bookings.php');
    exit();
}

require 'aheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>Booking Details</h1>
    <p style="color: #666; margin-bottom: 30px;">Complete information about this booking request</p>

    <div class="quick-actions" style="margin-bottom: 30px;">
        <a href="equipment_bookings.php" class="action-btn">← Back to Bookings</a>
        <?php if ($booking['status'] == 'PEN'): ?>
            <a href="equipment_bookings.php?action=approve&id=<?= $booking_id ?>" 
               onclick="return confirm('Approve this booking?')" 
               class="action-btn" style="background: #28a745;">✅ Approve</a>
            <a href="equipment_bookings.php?action=reject&id=<?= $booking_id ?>" 
               onclick="return confirm('Reject this booking?')" 
               class="action-btn" style="background: #dc3545;">❌ Reject</a>
        <?php endif; ?>
    </div>

    <div class="report-sections">
        <!-- Booking Information -->
        <div class="report-section">
            <h3>Booking Information</h3>
            <div class="booking-details">
                <div class="detail-row">
                    <strong>Booking ID:</strong> #<?= $booking['booking_id'] ?>
                </div>
                <div class="detail-row">
                    <strong>Status:</strong>
                    <?php
                    $status_map = [
                        'CON' => ['status-confirmed', 'Confirmed'],
                        'PEN' => ['status-pending', 'Pending Approval'],
                        'REJ' => ['status-rejected', 'Rejected']
                    ];
                    list($status_class, $status_text) = $status_map[$booking['status']] ?? ['', 'Unknown'];
                    ?>
                    <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                </div>
                <div class="detail-row">
                    <strong>Rental Period:</strong><br>
                    From: <?= date('M j, Y', strtotime($booking['start_date'])) ?><br>
                    To: <?= date('M j, Y', strtotime($booking['end_date'])) ?>
                </div>
                <div class="detail-row">
                    <strong>Duration:</strong> <?= $booking['Hours'] ?? 'N/A' ?> hours
                </div>
                <div class="detail-row">
                    <strong>Total Amount:</strong> <span style="font-size: 18px; color: #28a745;">₹<?= number_format($booking['total_amount'], 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Equipment Information -->
        <div class="report-section">
            <h3>Equipment Information</h3>
            <div class="booking-details">
                <div class="detail-row">
                    <strong>Equipment:</strong> <?= htmlspecialchars($booking['equipment_title']) ?>
                </div>
                <div class="detail-row">
                    <strong>Brand:</strong> <?= htmlspecialchars($booking['Brand']) ?>
                </div>
                <div class="detail-row">
                    <strong>Model:</strong> <?= htmlspecialchars($booking['Model']) ?>
                </div>
                <div class="detail-row">
                    <strong>Category:</strong> <?= htmlspecialchars($booking['Subcategory_name'] ?? 'N/A') ?>
                </div>
                <div class="detail-row">
                    <strong>Rates:</strong><br>
                    Hourly: ₹<?= number_format($booking['Hourly_rate'] ?? 0, 2) ?>/hr<br>
                    Daily: ₹<?= number_format($booking['Daily_rate'] ?? 0, 2) ?>/day
                </div>
            </div>
        </div>

        <!-- Customer Information -->
        <div class="report-section">
            <h3>Customer Information</h3>
            <div class="booking-details">
                <div class="detail-row">
                    <strong>Name:</strong> <?= htmlspecialchars($booking['customer_name']) ?>
                </div>
                <div class="detail-row">
                    <strong>Email:</strong> <?= htmlspecialchars($booking['customer_email']) ?>
                </div>
                <div class="detail-row">
                    <strong>Phone:</strong> <?= htmlspecialchars($booking['customer_phone']) ?>
                </div>
                <?php if ($booking['address']): ?>
                <div class="detail-row">
                    <strong>Address:</strong><br>
                    <?= htmlspecialchars($booking['address']) ?><br>
                    <?= htmlspecialchars($booking['city']) ?>, <?= htmlspecialchars($booking['state']) ?> - <?= htmlspecialchars($booking['Pin_code']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Contact Actions -->
    <div class="report-sections" style="margin-top: 30px;">
        <div class="report-section">
            <h3>Contact Customer</h3>
            <div class="status-list">
                <div>📞 <a href="tel:<?= $booking['customer_phone'] ?>">Call <?= htmlspecialchars($booking['customer_phone']) ?></a></div>
                <div>📧 <a href="mailto:<?= $booking['customer_email'] ?>">Email <?= htmlspecialchars($booking['customer_email']) ?></a></div>
                <div>💬 <a href="messages.php?customer=<?= $booking['customer_id'] ?>">Send Message</a></div>
            </div>
        </div>
    </div>
</div>

